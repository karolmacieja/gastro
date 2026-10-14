<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Własne tabele (dbDelta):
 *  - gfxdok_reports — jeden wiersz na dzień (data raportu, PDF, kto/kiedy),
 *  - gfxdok_images  — zdjęcia przypisane do dnia, z kolejnością stron.
 *
 * Przy wyłączonym Archiwum tabela reports przechowuje wyłącznie metadane
 * ("raport wygenerowany: kto, kiedy, ile stron") — bez żadnych plików.
 */
class GFXDOK_DB {

	public static function table_reports() {
		global $wpdb;
		return $wpdb->prefix . 'gfxdok_reports';
	}

	public static function table_images() {
		global $wpdb;
		return $wpdb->prefix . 'gfxdok_images';
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$reports = self::table_reports();
		$images  = self::table_images();

		$sql = "CREATE TABLE {$reports} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			report_date DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'generated',
			pages INT UNSIGNED NOT NULL DEFAULT 0,
			pdf_file VARCHAR(255) NULL,
			pdf_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			pdf_name VARCHAR(190) NOT NULL DEFAULT '',
			generated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			generated_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY report_date (report_date)
		) {$charset};
CREATE TABLE {$images} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			report_id BIGINT UNSIGNED NOT NULL,
			file VARCHAR(255) NOT NULL,
			thumb_file VARCHAR(255) NOT NULL DEFAULT '',
			width INT UNSIGNED NOT NULL DEFAULT 0,
			height INT UNSIGNED NOT NULL DEFAULT 0,
			bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sort_order INT UNSIGNED NOT NULL DEFAULT 0,
			uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY report_id (report_id)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'gfxdok_db_version', GFXDOK_DB_VERSION );
	}

	/* --------------------------- Raporty --------------------------- */

	public static function get_report_by_date( $date ) {
		global $wpdb;
		$t = self::table_reports();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE report_date = %s", $date ) );
	}

	public static function get_or_create_report( $date ) {
		global $wpdb;
		$row = self::get_report_by_date( $date );
		if ( $row ) {
			return $row;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::table_reports(),
			array(
				'report_date' => $date,
				'status'      => 'draft',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return self::get_report_by_date( $date );
	}

	public static function update_report( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::table_reports(), $data, array( 'id' => (int) $id ) );
	}

	public static function delete_report( $id ) {
		global $wpdb;
		$wpdb->delete( self::table_images(), array( 'report_id' => (int) $id ), array( '%d' ) );
		$wpdb->delete( self::table_reports(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function get_reports_between( $from, $to ) {
		global $wpdb;
		$r = self::table_reports();
		$i = self::table_images();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, (SELECT COUNT(*) FROM {$i} im WHERE im.report_id = r.id) AS image_count
			 FROM {$r} r
			 WHERE r.report_date BETWEEN %s AND %s
			 ORDER BY r.report_date DESC",
			$from,
			$to
		) );
	}

	public static function archive_years() {
		global $wpdb;
		$r = self::table_reports();
		$i = self::table_images();
		$years = $wpdb->get_col(
			"SELECT DISTINCT YEAR(r.report_date) AS y FROM {$r} r
			 WHERE r.pdf_file IS NOT NULL OR EXISTS (SELECT 1 FROM {$i} im WHERE im.report_id = r.id)
			 ORDER BY y DESC"
		);
		return array_map( 'intval', (array) $years );
	}

	/* --------------------------- Zdjęcia --------------------------- */

	public static function get_images( $report_id ) {
		global $wpdb;
		$t = self::table_images();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE report_id = %d ORDER BY sort_order ASC, id ASC",
			$report_id
		) );
	}

	public static function get_image( $id ) {
		global $wpdb;
		$t = self::table_images();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	public static function insert_image( $data ) {
		global $wpdb;
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table_images(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function set_image_order( $id, $order ) {
		global $wpdb;
		$wpdb->update( self::table_images(), array( 'sort_order' => (int) $order ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}

	public static function delete_image_row( $id ) {
		global $wpdb;
		$wpdb->delete( self::table_images(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function next_sort_order( $report_id ) {
		global $wpdb;
		$t   = self::table_images();
		$max = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$t} WHERE report_id = %d", $report_id ) );
		return null === $max ? 0 : ( (int) $max + 1 );
	}

	public static function stats() {
		global $wpdb;
		$r = self::table_reports();
		$i = self::table_images();
		return array(
			'days'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$r} WHERE pdf_file IS NOT NULL" ),
			'images' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$i}" ),
			'bytes'  => (int) $wpdb->get_var( "SELECT COALESCE(SUM(bytes),0) FROM {$i}" ) + (int) $wpdb->get_var( "SELECT COALESCE(SUM(pdf_size),0) FROM {$r}" ),
		);
	}

	/** Czyści Archiwum z bazy (pliki usuwa GFXDOK_Storage::purge_all). Metadane "wygenerowano" zostają. */
	public static function purge_archive() {
		global $wpdb;
		$r = self::table_reports();
		$i = self::table_images();
		$wpdb->query( "DELETE FROM {$i}" );
		$wpdb->query( "DELETE FROM {$r} WHERE status = 'draft'" );
		$wpdb->query( "UPDATE {$r} SET pdf_file = NULL, pdf_size = 0, status = 'generated'" );
	}
}
