<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log historii wysyłki powiadomień push. Zapisywany AUTOMATYCZNIE dla
 * wszystkich trzech wtyczek (Panel, Grafik, Napiwki), bo wszystkie
 * przechodzą przez wspólny mechanizm `gfx_dispatch_push` w GFX_Push —
 * nikt inny nie musi dodawać własnego logowania.
 *
 * Konwencja dbDelta, jak istniejące tabele w tych wtyczkach, z limitem /
 * przycinaniem najstarszych wpisów, żeby tabela nie rosła bez końca.
 */
class GFX_Push_Log {

	const TABLE    = 'gfx_push_log';
	const MAX_ROWS = 5000;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			category VARCHAR(50) NOT NULL,
			subcategory VARCHAR(100) NULL,
			recipient_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			recipient_name VARCHAR(255) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL,
			error_detail TEXT NULL,
			PRIMARY KEY  (id),
			KEY category (category),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function add( $category, $subcategory, $user_id, $name, $title, $status, $detail = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'         => current_time( 'mysql' ),
				'category'           => sanitize_text_field( (string) $category ),
				'subcategory'        => $subcategory ? sanitize_text_field( (string) $subcategory ) : null,
				'recipient_user_id'  => absint( $user_id ),
				'recipient_name'     => sanitize_text_field( (string) $name ),
				'title'              => sanitize_text_field( (string) $title ),
				'status'             => sanitize_text_field( (string) $status ),
				'error_detail'       => $detail ? wp_strip_all_tags( (string) $detail ) : '',
			)
		);
		self::maybe_prune();
	}

	protected static function maybe_prune() {
		global $wpdb;
		$table = self::table_name();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- brak zmiennych.
		if ( $count > self::MAX_ROWS ) {
			$excess = $count - self::MAX_ROWS;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $excess ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * @param array{category?:string,status?:string,page?:int,per_page?:int} $args
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$table = self::table_name();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['category'] ) ) {
			$where[]  = 'category = %s';
			$params[] = $args['category'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		$where_sql = implode( ' AND ', $where );

		$per_page = ! empty( $args['per_page'] ) ? (int) $args['per_page'] : 25;
		$page     = ! empty( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql          = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params_full  = array_merge( $params, array( $per_page, $offset ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $params_full ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	public static function clear() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- brak zmiennych.
	}

	/**
	 * Formatuje datę zapisaną przez `current_time( 'mysql' )` (czyli już w
	 * czasie LOKALNYM witryny) bez przepuszczania jej przez mysql2date() /
	 * wp_date() — te funkcje zakładają, że podana wartość jest w GMT i SAME
	 * doliczają offset strefy czasowej, co dla wartości już lokalnej
	 * powoduje przesunięcie godzin (np. o 2h, w zależności od strefy).
	 * Zamiast tego po prostu parsujemy i re-formatujemy ten sam napis, bez
	 * żadnej konwersji strefy czasowej.
	 */
	public static function format_local_datetime( $mysql_datetime, $format = 'Y-m-d H:i:s' ) {
		if ( empty( $mysql_datetime ) ) {
			return '';
		}
		$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime );
		if ( ! $dt ) {
			return $mysql_datetime; // Nie udało się sparsować - pokaż surową wartość, niż nic.
		}
		return $dt->format( $format );
	}
}
