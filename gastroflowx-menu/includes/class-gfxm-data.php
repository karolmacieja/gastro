<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warstwa danych: dania i wersje (własne tabele), kategorie i ustawienia (opcje).
 * Wszystkie wejścia są tu sanityzowane — REST tylko przekazuje żądania.
 */
class GFXM_Data {

	/* ---------------- Kategorie ---------------- */

	public static function categories() {
		$cats = get_option( 'gfxm_categories', array() );
		$cats = is_array( $cats ) ? array_values( $cats ) : array();
		foreach ( $cats as $i => $c ) {
			$cats[ $i ] = array_merge(
				array( 'sub_pl' => '', 'sub_en' => '', 'column' => 0 === $i % 2 ? 'left' : 'right', 'align' => 'top', 'dx' => 0, 'gap' => '' ),
				GFXM_Install::default_sub_offsets( isset( $c['id'] ) ? $c['id'] : '' ),
				$c
			);
		}
		return $cats;
	}

	public static function category_ids() {
		return wp_list_pluck( self::categories(), 'id' );
	}

	/**
	 * Zapisuje pełną, uporządkowaną listę kategorii. Nowe kategorie (bez id)
	 * dostają id z nazwy. Usunięcie kategorii, do której są przypisane dania,
	 * jest blokowane — żeby nie „zgubić” dań z bazy.
	 */
	public static function save_categories( $input ) {
		if ( ! is_array( $input ) ) {
			return new WP_Error( 'gfxm_bad_categories', 'Nieprawidłowa lista kategorii.', array( 'status' => 400 ) );
		}
		$clean = array();
		$used  = array();
		foreach ( $input as $row ) {
			$name_pl = isset( $row['name_pl'] ) ? sanitize_text_field( $row['name_pl'] ) : '';
			$name_en = isset( $row['name_en'] ) ? sanitize_text_field( $row['name_en'] ) : '';
			if ( '' === $name_pl ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
			if ( '' === $id ) {
				$id = sanitize_key( remove_accents( $name_pl ) );
				$id = $id ? substr( $id, 0, 32 ) : 'kat';
			}
			$base = $id;
			$n    = 2;
			while ( isset( $used[ $id ] ) ) {
				$id = $base . '-' . $n++;
			}
			$used[ $id ] = true;
			$clean[]     = array(
				'id'       => $id,
				'name_pl'  => $name_pl,
				'name_en'  => $name_en,
				'sub_pl'   => isset( $row['sub_pl'] ) ? sanitize_text_field( $row['sub_pl'] ) : '',
				'sub_en'   => isset( $row['sub_en'] ) ? sanitize_text_field( $row['sub_en'] ) : '',
				'column'   => ( isset( $row['column'] ) && 'right' === $row['column'] ) ? 'right' : 'left',
				'align'    => ( isset( $row['align'] ) && 'bottom' === $row['align'] ) ? 'bottom' : 'top',
				// Podtytuł względem nazwy kategorii (px): przesunięcie w prawo i od linii bazowej.
				'sub_dx'   => isset( $row['sub_dx'] ) && is_numeric( $row['sub_dx'] ) ? max( -300, min( 600, round( (float) $row['sub_dx'], 2 ) ) ) : 0,
				'sub_dy'   => isset( $row['sub_dy'] ) && is_numeric( $row['sub_dy'] ) ? max( -200, min( 200, round( (float) $row['sub_dy'], 2 ) ) ) : 0,
				'sub_pt'   => isset( $row['sub_pt'] ) && is_numeric( $row['sub_pt'] ) && (float) $row['sub_pt'] > 0 ? max( 5, min( 60, round( (float) $row['sub_pt'], 1 ) ) ) : 0,
				// Przesunięcie całej kategorii w poziomie (px) i własny odstęp
				// nazwa → pierwsze danie (px; puste = z Ustawień PDF).
				'dx'       => isset( $row['dx'] ) && is_numeric( $row['dx'] ) ? max( -200, min( 200, round( (float) $row['dx'], 2 ) ) ) : 0,
				'gap'      => isset( $row['gap'] ) && is_numeric( $row['gap'] ) ? max( 0, min( 300, round( (float) $row['gap'], 2 ) ) ) : '',
			);
		}
		if ( empty( $clean ) ) {
			return new WP_Error( 'gfxm_no_categories', 'Musi istnieć co najmniej jedna kategoria.', array( 'status' => 400 ) );
		}

		global $wpdb;
		$table   = GFXM_Install::dishes_table();
		$removed = array_diff( self::category_ids(), array_keys( $used ) );
		foreach ( $removed as $rid ) {
			$cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE category_id = %s", $rid ) ); // phpcs:ignore
			if ( $cnt > 0 ) {
				return new WP_Error(
					'gfxm_category_in_use',
					sprintf( 'Nie można usunąć kategorii „%s” — jest do niej przypisanych dań: %d. Najpierw przenieś lub usuń te dania.', $rid, $cnt ),
					array( 'status' => 409 )
				);
			}
		}
		update_option( 'gfxm_categories', $clean );
		return $clean;
	}

	/* ---------------- Dania ---------------- */

	public static function format_dish( $row ) {
		return array(
			'id'          => (int) $row->id,
			'category_id' => (string) $row->category_id,
			'name_pl'     => (string) $row->name_pl,
			'name_en'     => (string) $row->name_en,
			'desc_pl'     => (string) $row->desc_pl,
			'desc_en'     => (string) $row->desc_en,
			'sort_order'  => (int) $row->sort_order,
			'updated_at'  => (string) $row->updated_at,
		);
	}

	public static function dishes() {
		global $wpdb;
		$table = GFXM_Install::dishes_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC" ); // phpcs:ignore
		return array_map( array( __CLASS__, 'format_dish' ), (array) $rows );
	}

	public static function dishes_by_id() {
		$out = array();
		foreach ( self::dishes() as $d ) {
			$out[ $d['id'] ] = $d;
		}
		return $out;
	}

	private static function sanitize_dish( $p ) {
		$cat = isset( $p['category_id'] ) ? sanitize_key( $p['category_id'] ) : '';
		if ( ! in_array( $cat, self::category_ids(), true ) ) {
			return new WP_Error( 'gfxm_bad_category', 'Wybierz poprawną kategorię.', array( 'status' => 400 ) );
		}
		$data = array(
			'category_id' => $cat,
			'name_pl'     => isset( $p['name_pl'] ) ? sanitize_text_field( $p['name_pl'] ) : '',
			'name_en'     => isset( $p['name_en'] ) ? sanitize_text_field( $p['name_en'] ) : '',
			'desc_pl'     => isset( $p['desc_pl'] ) ? sanitize_textarea_field( $p['desc_pl'] ) : '',
			'desc_en'     => isset( $p['desc_en'] ) ? sanitize_textarea_field( $p['desc_en'] ) : '',
		);
		if ( '' === $data['name_pl'] ) {
			return new WP_Error( 'gfxm_no_name', 'Nazwa dania (PL) jest wymagana.', array( 'status' => 400 ) );
		}
		return $data;
	}

	public static function save_dish( $p, $id = 0 ) {
		global $wpdb;
		$table = GFXM_Install::dishes_table();
		$data  = self::sanitize_dish( $p );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['updated_at'] = current_time( 'mysql' );
		if ( $id ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
			if ( ! $exists ) {
				return new WP_Error( 'gfxm_not_found', 'Nie znaleziono dania.', array( 'status' => 404 ) );
			}
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$max                = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM {$table}" ); // phpcs:ignore
			$data['sort_order'] = $max + 1;
			$data['created_at'] = $data['updated_at'];
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}
		if ( $wpdb->last_error ) {
			return new WP_Error( 'gfxm_db', 'Błąd bazy danych: ' . $wpdb->last_error, array( 'status' => 500 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
		return self::format_dish( $row );
	}

	/** Usuwa danie i czyści jego id ze wszystkich zapisanych wersji. */
	public static function delete_dish( $id ) {
		global $wpdb;
		$wpdb->delete( GFXM_Install::dishes_table(), array( 'id' => (int) $id ) );
		foreach ( self::versions() as $v ) {
			$changed = false;
			foreach ( $v['items'] as $cat => $ids ) {
				$filtered = array_values( array_diff( $ids, array( (int) $id ) ) );
				if ( count( $filtered ) !== count( $ids ) ) {
					$v['items'][ $cat ] = $filtered;
					$changed            = true;
				}
			}
			if ( $changed ) {
				$wpdb->update( GFXM_Install::versions_table(), array( 'items' => wp_json_encode( $v['items'] ) ), array( 'id' => $v['id'] ) );
			}
		}
		return true;
	}

	/**
	 * Usuwa CAŁĄ bazę dań i czyści listy dań we wszystkich zapisanych wersjach
	 * (same wersje — nazwy, język, szablon — zostają).
	 *
	 * @return int liczba usuniętych dań
	 */
	public static function delete_all_dishes() {
		global $wpdb;
		$table = GFXM_Install::dishes_table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore
		foreach ( self::versions() as $v ) {
			$empty = array();
			foreach ( array_keys( $v['items'] ) as $cat ) {
				$empty[ $cat ] = array();
			}
			$wpdb->update( GFXM_Install::versions_table(), array( 'items' => wp_json_encode( (object) $empty ) ), array( 'id' => $v['id'] ) );
		}
		return $count;
	}

	/** Kolumny CSV (kolejność bez nagłówka) i rozpoznawane nazwy nagłówków. */
	const CSV_COLUMNS = array(
		'category_id' => array( 'kategoria', 'category', 'kat', 'category_id' ),
		'name_pl'     => array( 'nazwa_pl', 'nazwa', 'name_pl', 'nazwa pl' ),
		'desc_pl'     => array( 'opis_pl', 'opis', 'desc_pl', 'opis pl' ),
		'name_en'     => array( 'nazwa_en', 'name_en', 'name', 'nazwa en' ),
		'desc_en'     => array( 'opis_en', 'desc_en', 'description', 'opis en' ),
	);

	private static function csv_key( $s ) {
		$s = strtolower( remove_accents( trim( (string) $s ) ) );
		return preg_replace( '/[^a-z0-9]+/', '', $s );
	}

	/**
	 * Import dań z CSV: kategoria;nazwa_pl;opis_pl;nazwa_en;opis_en.
	 * Separator „;”, „,” albo tabulator (wykrywany z pierwszego wiersza),
	 * pola w cudzysłowach mogą zawierać separator i Enter. Nagłówek jest
	 * opcjonalny. Kategoria = id („mains”) albo nazwa PL/EN („Główne”).
	 * Błędne wiersze są pomijane i raportowane, poprawne — dodawane.
	 *
	 * @return array|WP_Error { added, deleted, errors: [ { line, message } ] }
	 */
	public static function import_csv( $csv, $replace = false ) {
		$csv = (string) $csv;
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv ); // BOM z Excela
		if ( ! seems_utf8( $csv ) ) {
			$conv = function_exists( 'mb_convert_encoding' ) ? @mb_convert_encoding( $csv, 'UTF-8', 'Windows-1250' ) : false; // phpcs:ignore
			$csv  = $conv ? $conv : $csv;
		}
		$csv = str_replace( array( "\r\n", "\r" ), "\n", $csv );
		if ( '' === trim( $csv ) ) {
			return new WP_Error( 'gfxm_csv_empty', 'Plik CSV jest pusty.', array( 'status' => 400 ) );
		}
		$first = strtok( $csv, "\n" );
		$cands = array( ';' => substr_count( $first, ';' ), ',' => substr_count( $first, ',' ), "\t" => substr_count( $first, "\t" ) );
		arsort( $cands );
		$delim = key( $cands );

		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $csv );
		rewind( $fh );
		$rows = array();
		while ( false !== ( $r = fgetcsv( $fh, 0, $delim, '"', '' ) ) ) {
			$rows[] = $r;
		}
		fclose( $fh );
		if ( count( $rows ) > 2000 ) {
			return new WP_Error( 'gfxm_csv_big', 'Za dużo wierszy (maks. 2000).', array( 'status' => 400 ) );
		}

		// Mapowanie kolumn: z nagłówka albo pozycyjnie.
		$map  = array_keys( self::CSV_COLUMNS );
		$head = isset( $rows[0] ) ? array_map( array( __CLASS__, 'csv_key' ), $rows[0] ) : array();
		$start = 0;
		$found = array();
		foreach ( $head as $i => $h ) {
			foreach ( self::CSV_COLUMNS as $field => $names ) {
				if ( in_array( $h, array_map( array( __CLASS__, 'csv_key' ), $names ), true ) && ! in_array( $field, $found, true ) ) {
					$found[ $i ] = $field;
					break;
				}
			}
		}
		if ( in_array( 'category_id', $found, true ) && in_array( 'name_pl', $found, true ) ) {
			$map   = $found;
			$start = 1;
		}

		// Kategorie: id, nazwa PL, nazwa EN (bez wielkości liter i ogonków).
		$cats = array();
		foreach ( self::categories() as $c ) {
			// Także po podtytule („desery”, „na początek”), gdy nie koliduje z nazwą innej kategorii.
			foreach ( array( $c['id'], $c['name_pl'], isset( $c['name_en'] ) ? $c['name_en'] : '', isset( $c['sub_pl'] ) ? $c['sub_pl'] : '' ) as $k ) {
				if ( '' !== self::csv_key( $k ) && ! isset( $cats[ self::csv_key( $k ) ] ) ) {
					$cats[ self::csv_key( $k ) ] = $c['id'];
				}
			}
		}

		$valid  = array();
		$errors = array();
		for ( $n = $start; $n < count( $rows ); $n++ ) {
			$r = $rows[ $n ];
			if ( ! array_filter( array_map( 'trim', (array) $r ), 'strlen' ) ) {
				continue; // pusty wiersz
			}
			$row = array( 'category_id' => '', 'name_pl' => '', 'desc_pl' => '', 'name_en' => '', 'desc_en' => '' );
			foreach ( $map as $i => $field ) {
				if ( isset( $r[ $i ] ) ) {
					$row[ $field ] = trim( $r[ $i ] );
				}
			}
			$ck = self::csv_key( $row['category_id'] );
			if ( ! isset( $cats[ $ck ] ) ) {
				$errors[] = array( 'line' => $n + 1, 'message' => 'nieznana kategoria „' . $row['category_id'] . '”' );
				continue;
			}
			if ( '' === $row['name_pl'] ) {
				$errors[] = array( 'line' => $n + 1, 'message' => 'brak nazwy PL' );
				continue;
			}
			$row['category_id'] = $cats[ $ck ];
			$valid[]            = $row;
		}
		if ( ! $valid ) {
			return new WP_Error( 'gfxm_csv_none', 'W pliku nie ma poprawnych dań.' . ( $errors ? ' Pierwszy błąd — wiersz ' . $errors[0]['line'] . ': ' . $errors[0]['message'] . '.' : '' ), array( 'status' => 400 ) );
		}

		// Zastąpienie bazy dopiero, gdy wiadomo, że jest co zaimportować.
		$deleted = $replace ? self::delete_all_dishes() : 0;
		$added   = 0;
		foreach ( $valid as $row ) {
			$res = self::save_dish( $row );
			if ( is_wp_error( $res ) ) {
				$errors[] = array( 'line' => 0, 'message' => $row['name_pl'] . ': ' . $res->get_error_message() );
			} else {
				$added++;
			}
		}
		return array( 'added' => $added, 'deleted' => $deleted, 'errors' => $errors );
	}

	/* ---------------- Wersje standardowe ---------------- */

	public static function format_version( $row ) {
		$items = json_decode( (string) $row->items, true );
		$items = is_array( $items ) ? $items : array();
		foreach ( $items as $k => $ids ) {
			$items[ $k ] = array_values( array_map( 'intval', (array) $ids ) );
		}
		$author = $row->updated_by ? get_userdata( (int) $row->updated_by ) : null;
		return array(
			'id'             => (int) $row->id,
			'name'           => (string) $row->name,
			'lang'           => 'en' === $row->lang ? 'en' : 'pl',
			'items'          => $items,
			'extra_text'     => (string) $row->extra_text,
			'extra_position' => 'top' === $row->extra_position ? 'top' : 'bottom',
			'template'       => GFXM_PDF::template_key( isset( $row->template ) ? $row->template : 'columns' ),
			'updated_at'     => (string) $row->updated_at,
			'updated_by'     => $author ? $author->display_name : '',
		);
	}

	public static function versions() {
		global $wpdb;
		$table = GFXM_Install::versions_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC" ); // phpcs:ignore
		return array_map( array( __CLASS__, 'format_version' ), (array) $rows );
	}

	public static function version( $id ) {
		global $wpdb;
		$table = GFXM_Install::versions_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
		return $row ? self::format_version( $row ) : null;
	}

	/**
	 * Normalizuje dane wersji (także niezapisanego szkicu do podglądu PDF).
	 * items = { category_id: [dish_id, ...] } w kolejności wybranej przez użytkownika.
	 */
	public static function sanitize_version( $p, $require_name = true ) {
		$name = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
		if ( $require_name && '' === $name ) {
			return new WP_Error( 'gfxm_no_name', 'Podaj nazwę wersji.', array( 'status' => 400 ) );
		}
		$valid_cats = self::category_ids();
		$items      = array();
		$raw        = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
		foreach ( $raw as $cat => $ids ) {
			$cat = sanitize_key( $cat );
			if ( ! in_array( $cat, $valid_cats, true ) ) {
				continue;
			}
			$items[ $cat ] = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		}
		return array(
			'name'           => $name,
			'lang'           => ( isset( $p['lang'] ) && 'en' === $p['lang'] ) ? 'en' : 'pl',
			'items'          => $items,
			'extra_text'     => isset( $p['extra_text'] ) ? sanitize_textarea_field( $p['extra_text'] ) : '',
			'extra_position' => ( isset( $p['extra_position'] ) && 'top' === $p['extra_position'] ) ? 'top' : 'bottom',
			'template'       => GFXM_PDF::template_key( isset( $p['template'] ) ? $p['template'] : 'columns' ),
		);
	}

	public static function save_version( $p, $id = 0 ) {
		global $wpdb;
		$table = GFXM_Install::versions_table();
		$v     = self::sanitize_version( $p );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$count = 0;
		foreach ( $v['items'] as $ids ) {
			$count += count( $ids );
		}
		if ( 0 === $count ) {
			return new WP_Error( 'gfxm_empty', 'Wybierz co najmniej jedno danie.', array( 'status' => 400 ) );
		}
		$row = array(
			'name'           => $v['name'],
			'lang'           => $v['lang'],
			'items'          => wp_json_encode( $v['items'] ),
			'extra_text'     => $v['extra_text'],
			'extra_position' => $v['extra_position'],
			'template'       => $v['template'],
			'updated_by'     => get_current_user_id(),
			'updated_at'     => current_time( 'mysql' ),
		);
		if ( $id ) {
			if ( ! self::version( $id ) ) {
				return new WP_Error( 'gfxm_not_found', 'Nie znaleziono wersji.', array( 'status' => 404 ) );
			}
			$wpdb->update( $table, $row, array( 'id' => $id ) );
		} else {
			$row['created_by'] = get_current_user_id();
			$row['created_at'] = $row['updated_at'];
			$wpdb->insert( $table, $row );
			$id = (int) $wpdb->insert_id;
		}
		if ( $wpdb->last_error ) {
			return new WP_Error( 'gfxm_db', 'Błąd bazy danych: ' . $wpdb->last_error, array( 'status' => 500 ) );
		}
		return self::version( $id );
	}

	public static function delete_version( $id ) {
		global $wpdb;
		$wpdb->delete( GFXM_Install::versions_table(), array( 'id' => (int) $id ) );
		return true;
	}

	/* ---------------- Ustawienia ---------------- */

	public static function settings() {
		$def = GFXM_Install::default_settings();
		$s   = get_option( 'gfxm_settings', array() );
		$s   = is_array( $s ) ? $s : array();
		foreach ( array( 'header', 'footer', 'layout' ) as $k ) {
			$s[ $k ] = array_merge( $def[ $k ], isset( $s[ $k ] ) && is_array( $s[ $k ] ) ? $s[ $k ] : array() );
		}
		$sp         = isset( $s['split'] ) && is_array( $s['split'] ) ? $s['split'] : array();
		$s['split'] = array();
		foreach ( array( 'header', 'footer', 'layout' ) as $k ) {
			$s['split'][ $k ] = array_merge( $def['split'][ $k ], isset( $sp[ $k ] ) && is_array( $sp[ $k ] ) ? $sp[ $k ] : array() );
		}
		// URL zawsze świeży z biblioteki mediów (np. po zmianie domeny).
		foreach ( array( 'header', 'footer' ) as $k ) {
			$s[ $k ]['url'] = $s[ $k ]['id'] ? (string) wp_get_attachment_url( (int) $s[ $k ]['id'] ) : '';
			$meta           = $s[ $k ]['id'] ? wp_get_attachment_metadata( (int) $s[ $k ]['id'] ) : array();
			$s[ $k ]['natural_w'] = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			$s[ $k ]['natural_h'] = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		}
		return $s;
	}

	private static function sanitize_logo( $in ) {
		$in    = is_array( $in ) ? $in : array();
		$id    = isset( $in['id'] ) ? absint( $in['id'] ) : 0;
		if ( $id && ! wp_attachment_is_image( $id ) ) {
			$id = 0;
		}
		$wu = isset( $in['width_unit'] ) && in_array( $in['width_unit'], array( 'px', '%', 'auto' ), true ) ? $in['width_unit'] : '%';
		$hu = isset( $in['height_unit'] ) && in_array( $in['height_unit'], array( 'px', '%', 'auto' ), true ) ? $in['height_unit'] : 'auto';
		if ( 'auto' === $wu && 'auto' === $hu ) {
			$wu = '%';
		}
		return array(
			'id'          => $id,
			'width'       => isset( $in['width'] ) ? max( 0, min( 2000, (float) $in['width'] ) ) : 0,
			'width_unit'  => $wu,
			'height'      => isset( $in['height'] ) ? max( 0, min( 2000, (float) $in['height'] ) ) : 0,
			'height_unit' => $hu,
		);
	}

	public static function save_settings( $p ) {
		$def    = GFXM_Install::default_settings();
		$layout = array();
		foreach ( $def['layout'] as $k => $v ) {
			$in = isset( $p['layout'][ $k ] ) ? $p['layout'][ $k ] : $v;
			if ( 'heading_weight' === $k ) {
				$layout[ $k ] = in_array( $in, GFXM_PDF::heading_weights(), true ) ? $in : $v;
			} elseif ( 'name_weight' === $k ) {
				$layout[ $k ] = in_array( $in, GFXM_PDF::name_weights(), true ) ? $in : $v;
			} elseif ( substr( $k, -6 ) === '_track' ) {
				$layout[ $k ] = max( -0.1, min( 0.5, round( (float) $in, 3 ) ) );
			} elseif ( substr( $k, -3 ) === '_pt' ) {
				$layout[ $k ] = max( 5, min( 60, round( (float) $in, 1 ) ) );
			} elseif ( 'text_lh' === $k ) {
				$layout[ $k ] = max( 0.8, min( 3, round( (float) $in, 3 ) ) );
			} elseif ( 'sub_font' === $k ) {
				$layout[ $k ] = GFXM_PDF::sub_font_key( $in );
			} elseif ( 'fit_page' === $k || 'sub_upper' === $k ) {
				$layout[ $k ] = $in ? 1 : 0;
			} else {
				// Prowadnice i odstępy w px (A4 794 × 1123) — z ułamkami, żeby dało się
				// trafić co do 0,1 pt w szablon z InDesigna.
				$layout[ $k ] = max( 0, min( 1100, round( (float) $in, 2 ) ) );
			}
		}
		// Szablon „split”: tylko geometria i rozmiary logo (obraz wspólny).
		$sp_in  = isset( $p['split'] ) && is_array( $p['split'] ) ? $p['split'] : array();
		$split  = array( 'layout' => array() );
		foreach ( $def['split']['layout'] as $k => $v ) {
			$in                        = isset( $sp_in['layout'][ $k ] ) ? $sp_in['layout'][ $k ] : $v;
			$split['layout'][ $k ] = 'cut_line' === $k ? ( $in ? 1 : 0 ) : max( 0, min( 1100, (int) $in ) );
		}
		foreach ( array( 'header', 'footer' ) as $k ) {
			$logo        = self::sanitize_logo( array_merge( isset( $sp_in[ $k ] ) ? (array) $sp_in[ $k ] : array(), array( 'id' => 0 ) ) );
			$split[ $k ] = array_intersect_key( $logo, array_flip( array( 'width', 'width_unit', 'height', 'height_unit' ) ) );
		}
		update_option(
			'gfxm_settings',
			array(
				'header' => self::sanitize_logo( isset( $p['header'] ) ? $p['header'] : array() ),
				'footer' => self::sanitize_logo( isset( $p['footer'] ) ? $p['footer'] : array() ),
				'layout' => $layout,
				'split'  => $split,
			)
		);
		return self::settings();
	}

	/** Status czcionek — który plik z assets/fonts/ jest użyty dla każdej roli. */
	public static function font_status() {
		$L   = self::settings()['layout'];
		$out = array();
		foreach ( GFXM_PDF::resolve_fonts( $L['heading_weight'], $L['name_weight'], $L['sub_font'] ) as $role => $f ) {
			$out[] = array(
				'role'     => $role,
				'label'    => $f['label'],
				'file'     => $f['file'],
				'fallback' => (bool) $f['fallback'],
				'wanted'   => self::wanted_font( $role, GFXM_PDF::font_roles( $L['heading_weight'], $L['name_weight'], $L['sub_font'] )[ $role ]['files'][0], $L['name_weight'] ),
			);
		}
		return $out;
	}

	private static function wanted_font( $role, $first, $name_weight = 'Bold' ) {
		if ( '@' !== $first[0] ) {
			return $first;
		}
		return 'bold' === $role ? 'FabrikatMono-' . $name_weight . '.ttf' : 'FabrikatMono-Regular.ttf';
	}

	/** URL-e czcionek dla podglądu w przeglądarce (te same pliki co w PDF). */
	public static function font_urls() {
		$fonts = GFXM_PDF::resolve_fonts( 'Regular' );
		$out   = array(
			'headLh'       => GFXM_PDF::HEAD_LH,
			'headMt'       => GFXM_PDF::HEAD_MT,
			'headBl'       => GFXM_PDF::HEAD_BL,
			'sub'          => array(),
			'dy'           => array(
				'heading' => GFXM_PDF::baseline_dy( 'Poppins' ),
				'script'  => GFXM_PDF::baseline_dy( $fonts['script']['file'] ),
				'body'    => GFXM_PDF::baseline_dy( $fonts['body']['file'] ),
				'bold'    => GFXM_PDF::baseline_dy( $fonts['bold']['file'] ),
			),
			'script'       => $fonts['script']['url'],
			'scriptFactor' => $fonts['script']['factor'],
			'body'         => $fonts['body']['url'],
			'bold'         => $fonts['bold']['url'],
			'name'         => array(),
			'heading'      => array(),
		);
		// Podtytuły — URL pliku dla każdego kroju z listy, żeby przełącznik
		// w Ustawieniach PDF działał w podglądzie od razu, bez zapisu.
		foreach ( array_keys( GFXM_PDF::sub_fonts() ) as $key ) {
			$f                  = GFXM_PDF::resolve_fonts( 'Regular', 'Bold', $key );
			$out['sub'][ $key ] = array( 'url' => $f['script']['url'], 'factor' => $f['script']['factor'], 'file' => $f['script']['file'] );
		}
		foreach ( GFXM_PDF::name_weights() as $w ) {
			$f                   = GFXM_PDF::resolve_fonts( 'Regular', $w );
			$out['name'][ $w ]   = $f['bold']['url'];
			$out['nameDy'][ $w ] = GFXM_PDF::baseline_dy( $f['bold']['file'] );
		}
		foreach ( GFXM_PDF::heading_weights() as $w ) {
			if ( file_exists( GFXM_PDF::fonts_dir() . 'Poppins-' . $w . '.ttf' ) ) {
				$out['heading'][ $w ] = GFXM_URL . 'assets/fonts/Poppins-' . $w . '.ttf';
			}
		}
		return $out;
	}
}
