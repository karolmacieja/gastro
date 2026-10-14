<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API modułu. Namespace: gfx-dokumenty/v1
 *
 * Autoryzacja: ciasteczko sesji + nonce `wp_rest` (nagłówek X-WP-Nonce, a dla
 * <img src> / linków do pobrania parametr `_wpnonce`). Każdy endpoint sprawdza
 * uprawnienie modułu (rola), nie tylko samo zalogowanie. Każdy zapis dodatkowo
 * jawnie weryfikuje nonce.
 *
 * Kompresja zdjęć i składanie PDF odbywają się w przeglądarce. Serwer:
 *  - przy WYŁĄCZONYM Archiwum — dostaje wyłącznie metadane ("wygenerowano:
 *    kto, kiedy, ile stron"), żadnych plików;
 *  - przy WŁĄCZONYM Archiwum — przechowuje skompresowane zdjęcia (+miniatury)
 *    i wygenerowany PDF, żeby dzień można było później przejrzeć i edytować.
 */
class GFXDOK_REST {

	const MAX_IMAGE_BYTES = 12582912;  // 12 MB (po kompresji zwykle 150–600 KB)
	const MAX_THUMB_BYTES = 2097152;   // 2 MB
	const MAX_IMAGES_DAY  = 60;
	const DATE_RE         = '(?P<date>\d{4}-\d{2}-\d{2})';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns = GFXDOK_REST_NS;

		register_rest_route( $ns, '/bootstrap', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'bootstrap' ),
			'permission_callback' => array( __CLASS__, 'perm_access' ),
		) );

		register_rest_route( $ns, '/settings', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_settings' ),
			'permission_callback' => array( __CLASS__, 'perm_manage_write' ),
		) );

		register_rest_route( $ns, '/month', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_month' ),
			'permission_callback' => array( __CLASS__, 'perm_access' ),
			'args'                => array(
				'month' => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}$' ),
			),
		) );

		register_rest_route( $ns, '/archive', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_archive' ),
			'permission_callback' => array( __CLASS__, 'perm_access' ),
			'args'                => array(
				'year' => array( 'required' => false, 'type' => 'integer' ),
			),
		) );

		register_rest_route( $ns, '/archive/purge', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'purge_archive' ),
			'permission_callback' => array( __CLASS__, 'perm_manage_write' ),
		) );

		register_rest_route( $ns, '/day/' . self::DATE_RE, array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_day' ),
				'permission_callback' => array( __CLASS__, 'perm_access' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_day' ),
				'permission_callback' => array( __CLASS__, 'perm_manage_write' ),
			),
		) );

		register_rest_route( $ns, '/day/' . self::DATE_RE . '/images', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'upload_image' ),
			'permission_callback' => array( __CLASS__, 'perm_access_write' ),
		) );

		register_rest_route( $ns, '/day/' . self::DATE_RE . '/finalize', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'finalize_day' ),
			'permission_callback' => array( __CLASS__, 'perm_access_write' ),
		) );

		register_rest_route( $ns, '/day/' . self::DATE_RE . '/pdf', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'stream_pdf' ),
			'permission_callback' => array( __CLASS__, 'perm_access' ),
		) );

		register_rest_route( $ns, '/images/(?P<id>\d+)/file', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'stream_image' ),
			'permission_callback' => array( __CLASS__, 'perm_access' ),
			'args'                => array(
				'size' => array( 'required' => false, 'type' => 'string', 'enum' => array( 'thumb', 'full' ) ),
			),
		) );
	}

	/* ===================================================================
	 * Uprawnienia
	 * ================================================================= */

	protected static function nonce_ok( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	protected static function denied( $msg = 'Brak uprawnień do modułu Dokumenty.' ) {
		return new WP_Error( 'gfxdok_forbidden', $msg, array( 'status' => is_user_logged_in() ? 403 : 401 ) );
	}

	public static function perm_access( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return self::denied( 'Zaloguj się, aby korzystać z modułu.' );
		}
		return GFXDOK_Settings::user_can_access() ? true : self::denied();
	}

	public static function perm_access_write( WP_REST_Request $request ) {
		$base = self::perm_access( $request );
		if ( true !== $base ) {
			return $base;
		}
		return self::nonce_ok( $request ) ? true : new WP_Error( 'gfxdok_nonce', 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.', array( 'status' => 403 ) );
	}

	public static function perm_manage_write( WP_REST_Request $request ) {
		$base = self::perm_access_write( $request );
		if ( true !== $base ) {
			return $base;
		}
		return GFXDOK_Settings::user_can_manage() ? true : self::denied( 'Ta operacja wymaga uprawnień kierownika.' );
	}

	/* ===================================================================
	 * Pomocnicze
	 * ================================================================= */

	protected static function valid_date( $date ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m ) ) {
			return false;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return false;
		}
		// Raport dobowy nie może dotyczyć przyszłości (1 dzień tolerancji na strefy czasowe).
		$limit = gmdate( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS );
		return $date <= $limit;
	}

	protected static function date_or_error( WP_REST_Request $request ) {
		$date = (string) $request['date'];
		if ( ! self::valid_date( $date ) ) {
			return new WP_Error( 'gfxdok_date', 'Nieprawidłowa data raportu.', array( 'status' => 400 ) );
		}
		return $date;
	}

	protected static function user_name( $user_id ) {
		static $cache = array();
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return '';
		}
		if ( ! isset( $cache[ $user_id ] ) ) {
			$u = get_userdata( $user_id );
			if ( $u ) {
				$full = trim( $u->first_name . ' ' . $u->last_name );
				$cache[ $user_id ] = '' !== $full ? $full : $u->display_name;
			} else {
				$cache[ $user_id ] = '';
			}
		}
		return $cache[ $user_id ];
	}

	protected static function nonce() {
		static $n = null;
		if ( null === $n ) {
			$n = wp_create_nonce( 'wp_rest' );
		}
		return $n;
	}

	protected static function image_url( $id, $size ) {
		return add_query_arg(
			array( 'size' => $size, '_wpnonce' => self::nonce() ),
			rest_url( GFXDOK_REST_NS . '/images/' . (int) $id . '/file' )
		);
	}

	protected static function pdf_url( $date, $download = false ) {
		$args = array( '_wpnonce' => self::nonce() );
		if ( $download ) {
			$args['download'] = 1;
		}
		return add_query_arg( $args, rest_url( GFXDOK_REST_NS . '/day/' . $date . '/pdf' ) );
	}

	protected static function format_image( $img ) {
		return array(
			'id'       => (int) $img->id,
			'width'    => (int) $img->width,
			'height'   => (int) $img->height,
			'bytes'    => (int) $img->bytes,
			'thumbUrl' => self::image_url( $img->id, 'thumb' ),
			'fullUrl'  => self::image_url( $img->id, 'full' ),
		);
	}

	protected static function format_report( $r, $with_archive ) {
		$has_pdf = $with_archive && ! empty( $r->pdf_file );
		return array(
			'date'          => $r->report_date,
			'status'        => $r->status,
			'pages'         => (int) $r->pages,
			'imageCount'    => $with_archive && isset( $r->image_count ) ? (int) $r->image_count : 0,
			'hasPdf'        => $has_pdf,
			'pdfSize'       => $has_pdf ? (int) $r->pdf_size : 0,
			'pdfName'       => (string) $r->pdf_name,
			'pdfUrl'        => $has_pdf ? self::pdf_url( $r->report_date ) : '',
			'pdfDownload'   => $has_pdf ? self::pdf_url( $r->report_date, true ) : '',
			'generatedAt'   => $r->generated_at ? mysql_to_rfc3339( $r->generated_at ) : null,
			'generatedBy'   => self::user_name( $r->generated_by ),
		);
	}

	/** Usuwa pliki i wiersz zdjęcia. */
	protected static function remove_image( $img ) {
		GFXDOK_Storage::delete( $img->file );
		GFXDOK_Storage::delete( $img->thumb_file );
		GFXDOK_DB::delete_image_row( $img->id );
	}

	/**
	 * Waliduje plik JPEG z $_FILES (po kompresji w przeglądarce zawsze JPEG).
	 *
	 * @return array|WP_Error [tmp_name, width, height, size]
	 */
	protected static function validate_jpeg( $file, $max_bytes ) {
		if ( empty( $file ) || ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'gfxdok_upload', 'Nie otrzymano pliku zdjęcia (sprawdź limit rozmiaru przesyłanych plików na serwerze).', array( 'status' => 400 ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'gfxdok_upload', 'Nieprawidłowy plik.', array( 'status' => 400 ) );
		}
		$size = (int) filesize( $file['tmp_name'] );
		if ( $size <= 0 || $size > $max_bytes ) {
			return new WP_Error( 'gfxdok_size', 'Plik zdjęcia jest za duży.', array( 'status' => 413 ) );
		}
		$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] ) {
			return new WP_Error( 'gfxdok_type', 'Dozwolone są wyłącznie zdjęcia JPEG.', array( 'status' => 415 ) );
		}
		if ( $info[0] > 6000 || $info[1] > 12000 ) {
			return new WP_Error( 'gfxdok_dims', 'Zdjęcie ma zbyt dużą rozdzielczość.', array( 'status' => 400 ) );
		}
		return array(
			'tmp_name' => $file['tmp_name'],
			'width'    => (int) $info[0],
			'height'   => (int) $info[1],
			'size'     => $size,
		);
	}

	/* ===================================================================
	 * Endpointy
	 * ================================================================= */

	public static function bootstrap( WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'settings'  => GFXDOK_Settings::get(),
			'canManage' => GFXDOK_Settings::user_can_manage(),
			'today'     => current_time( 'Y-m-d' ),
			'maxUpload' => (int) wp_max_upload_size(),
		) );
	}

	public static function save_settings( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		return rest_ensure_response( array( 'settings' => GFXDOK_Settings::update( (array) $params ) ) );
	}

	public static function get_month( WP_REST_Request $request ) {
		$month = (string) $request['month'];
		list( $y, $m ) = array_map( 'intval', explode( '-', $month ) );
		if ( $m < 1 || $m > 12 || $y < 2000 || $y > 2100 ) {
			return new WP_Error( 'gfxdok_month', 'Nieprawidłowy miesiąc.', array( 'status' => 400 ) );
		}
		$from    = sprintf( '%04d-%02d-01', $y, $m );
		$to      = sprintf( '%04d-%02d-%02d', $y, $m, gfxdok_days_in_month( $m, $y ) );
		$archive = GFXDOK_Settings::archive_enabled();
		$rows    = GFXDOK_DB::get_reports_between( $from, $to );

		$days = array();
		foreach ( $rows as $r ) {
			if ( 'draft' === $r->status && ( ! $archive || ! (int) $r->image_count ) ) {
				continue;
			}
			$days[ $r->report_date ] = self::format_report( $r, $archive );
		}
		return rest_ensure_response( array( 'month' => $month, 'days' => (object) $days ) );
	}

	public static function get_archive( WP_REST_Request $request ) {
		if ( ! GFXDOK_Settings::archive_enabled() ) {
			return rest_ensure_response( array( 'enabled' => false, 'years' => array(), 'days' => array() ) );
		}
		$years = GFXDOK_DB::archive_years();
		$year  = (int) $request->get_param( 'year' );
		if ( ! $year ) {
			$year = $years ? $years[0] : (int) current_time( 'Y' );
		}
		$rows = GFXDOK_DB::get_reports_between( sprintf( '%04d-01-01', $year ), sprintf( '%04d-12-31', $year ) );
		$days = array();
		foreach ( $rows as $r ) {
			if ( empty( $r->pdf_file ) && ! (int) $r->image_count ) {
				continue;
			}
			$item           = self::format_report( $r, true );
			$imgs           = array_slice( GFXDOK_DB::get_images( $r->id ), 0, 4 );
			$item['thumbs'] = array_map( function ( $i ) {
				return self::image_url( $i->id, 'thumb' );
			}, $imgs );
			$days[] = $item;
		}
		return rest_ensure_response( array(
			'enabled' => true,
			'year'    => $year,
			'years'   => $years,
			'days'    => $days,
		) );
	}

	public static function get_day( WP_REST_Request $request ) {
		$date = self::date_or_error( $request );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		$archive = GFXDOK_Settings::archive_enabled();
		$r       = GFXDOK_DB::get_report_by_date( $date );
		$images  = array();
		$report  = null;
		if ( $r ) {
			$r->image_count = 0;
			if ( $archive ) {
				$rows           = GFXDOK_DB::get_images( $r->id );
				$images         = array_map( array( __CLASS__, 'format_image' ), $rows );
				$r->image_count = count( $rows );
			}
			if ( 'draft' !== $r->status || $images ) {
				$report = self::format_report( $r, $archive );
			}
		}
		return rest_ensure_response( array(
			'date'    => $date,
			'archive' => $archive,
			'report'  => $report,
			'images'  => $images,
		) );
	}

	public static function delete_day( WP_REST_Request $request ) {
		$date = self::date_or_error( $request );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		$r = GFXDOK_DB::get_report_by_date( $date );
		if ( $r ) {
			foreach ( GFXDOK_DB::get_images( $r->id ) as $img ) {
				self::remove_image( $img );
			}
			GFXDOK_Storage::delete( $r->pdf_file );
			GFXDOK_DB::delete_report( $r->id );
			GFXDOK_Storage::cleanup_day_dir( $date );
		}
		return rest_ensure_response( array( 'deleted' => true, 'date' => $date ) );
	}

	/**
	 * Przyjmuje JEDNO skompresowane zdjęcie (pole `file`) + miniaturę (`thumb`).
	 * Tylko przy włączonym Archiwum.
	 */
	public static function upload_image( WP_REST_Request $request ) {
		if ( ! GFXDOK_Settings::archive_enabled() ) {
			return new WP_Error( 'gfxdok_archive_off', 'Archiwum jest wyłączone — zdjęcia nie są zapisywane na serwerze.', array( 'status' => 409 ) );
		}
		$date = self::date_or_error( $request );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		$files = $request->get_file_params();
		$main  = self::validate_jpeg( isset( $files['file'] ) ? $files['file'] : null, self::MAX_IMAGE_BYTES );
		if ( is_wp_error( $main ) ) {
			return $main;
		}
		$thumb = null;
		if ( ! empty( $files['thumb'] ) ) {
			$thumb = self::validate_jpeg( $files['thumb'], self::MAX_THUMB_BYTES );
			if ( is_wp_error( $thumb ) ) {
				$thumb = null; // miniatura jest opcjonalna — w razie problemu użyjemy pełnego zdjęcia
			}
		}

		$report = GFXDOK_DB::get_or_create_report( $date );
		if ( ! $report ) {
			return new WP_Error( 'gfxdok_db', 'Nie udało się utworzyć wpisu dnia w bazie.', array( 'status' => 500 ) );
		}
		if ( count( GFXDOK_DB::get_images( $report->id ) ) >= self::MAX_IMAGES_DAY ) {
			return new WP_Error( 'gfxdok_limit', sprintf( 'Limit %d zdjęć na dzień.', self::MAX_IMAGES_DAY ), array( 'status' => 400 ) );
		}

		$rel = GFXDOK_Storage::store_upload( $main['tmp_name'], $date, 'jpg' );
		if ( is_wp_error( $rel ) ) {
			return $rel;
		}
		$thumb_rel = '';
		if ( $thumb ) {
			$t = GFXDOK_Storage::store_upload( $thumb['tmp_name'], $date, 'jpg', '-thumb' );
			$thumb_rel = is_wp_error( $t ) ? '' : $t;
		}

		$id = GFXDOK_DB::insert_image( array(
			'report_id'   => (int) $report->id,
			'file'        => $rel,
			'thumb_file'  => $thumb_rel,
			'width'       => $main['width'],
			'height'      => $main['height'],
			'bytes'       => $main['size'] + ( $thumb ? $thumb['size'] : 0 ),
			'sort_order'  => GFXDOK_DB::next_sort_order( $report->id ),
			'uploaded_by' => get_current_user_id(),
		) );
		if ( ! $id ) {
			GFXDOK_Storage::delete( $rel );
			GFXDOK_Storage::delete( $thumb_rel );
			return new WP_Error( 'gfxdok_db', 'Nie udało się zapisać zdjęcia w bazie.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'image' => self::format_image( GFXDOK_DB::get_image( $id ) ) ) );
	}

	/**
	 * Zamknięcie dnia po wygenerowaniu PDF w przeglądarce.
	 *
	 * Archiwum WŁ.:  pola `order` (JSON z ID zdjęć w kolejności stron), `pdf`
	 *               (plik), `filename`. Zdjęcia dnia spoza `order` są usuwane
	 *               (to obsługuje kasowanie/obracanie zdjęć przy edycji dnia).
	 * Archiwum WYŁ.: tylko `pages` + `filename` — zapis metadanych, bez plików.
	 */
	public static function finalize_day( WP_REST_Request $request ) {
		$date = self::date_or_error( $request );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		$filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );
		$filename = function_exists( 'mb_substr' ) ? mb_substr( $filename, 0, 180 ) : substr( $filename, 0, 180 );
		$now      = current_time( 'mysql' );
		$user_id  = get_current_user_id();

		/* ---------- Archiwum wyłączone: tylko metadane ---------- */
		if ( ! GFXDOK_Settings::archive_enabled() ) {
			$pages  = max( 0, min( 500, (int) $request->get_param( 'pages' ) ) );
			$report = GFXDOK_DB::get_or_create_report( $date );
			$data   = array(
				'generated_by' => $user_id,
				'generated_at' => $now,
				'pdf_name'     => $filename,
			);
			// Dzień zarchiwizowany wcześniej (gdy Archiwum było włączone) — nie ruszamy jego plików.
			if ( empty( $report->pdf_file ) ) {
				$data['status'] = 'generated';
				$data['pages']  = $pages;
			}
			GFXDOK_DB::update_report( $report->id, $data );
			return rest_ensure_response( array( 'report' => self::format_report( GFXDOK_DB::get_report_by_date( $date ), false ) ) );
		}

		/* ---------- Archiwum włączone ---------- */
		$report = GFXDOK_DB::get_report_by_date( $date );
		if ( ! $report ) {
			return new WP_Error( 'gfxdok_no_images', 'Brak zdjęć tego dnia na serwerze.', array( 'status' => 400 ) );
		}

		$order = json_decode( (string) $request->get_param( 'order' ), true );
		$order = is_array( $order ) ? array_values( array_unique( array_map( 'intval', $order ) ) ) : array();

		$images = array();
		foreach ( GFXDOK_DB::get_images( $report->id ) as $img ) {
			$images[ (int) $img->id ] = $img;
		}
		$order = array_values( array_filter( $order, function ( $id ) use ( $images ) {
			return isset( $images[ $id ] );
		} ) );
		if ( ! $order ) {
			return new WP_Error( 'gfxdok_order', 'Raport musi zawierać co najmniej jedno zdjęcie.', array( 'status' => 400 ) );
		}

		// PDF
		$files = $request->get_file_params();
		$pdf   = isset( $files['pdf'] ) ? $files['pdf'] : null;
		if ( empty( $pdf ) || ! empty( $pdf['error'] ) || empty( $pdf['tmp_name'] ) || ! is_uploaded_file( $pdf['tmp_name'] ) ) {
			return new WP_Error( 'gfxdok_pdf', 'Nie otrzymano pliku PDF (może przekraczać limit przesyłania na serwerze: ' . size_format( wp_max_upload_size() ) . ').', array( 'status' => 400 ) );
		}
		$fh   = @fopen( $pdf['tmp_name'], 'rb' ); // phpcs:ignore
		$head = $fh ? fread( $fh, 5 ) : ''; // phpcs:ignore
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore
		}
		if ( '%PDF-' !== $head ) {
			return new WP_Error( 'gfxdok_pdf_type', 'Przesłany plik nie jest dokumentem PDF.', array( 'status' => 415 ) );
		}
		$pdf_size = (int) filesize( $pdf['tmp_name'] );
		$pdf_rel  = GFXDOK_Storage::store_upload( $pdf['tmp_name'], $date, 'pdf', '-raport' );
		if ( is_wp_error( $pdf_rel ) ) {
			return $pdf_rel;
		}

		// Usuń zdjęcia, których nie ma w nowej kolejności (skasowane / obrócone przy edycji).
		foreach ( $images as $id => $img ) {
			if ( ! in_array( $id, $order, true ) ) {
				self::remove_image( $img );
			}
		}
		foreach ( $order as $pos => $id ) {
			GFXDOK_DB::set_image_order( $id, $pos );
		}

		$old_pdf = $report->pdf_file;
		GFXDOK_DB::update_report( $report->id, array(
			'status'       => 'archived',
			'pages'        => count( $order ),
			'pdf_file'     => $pdf_rel,
			'pdf_size'     => $pdf_size,
			'pdf_name'     => $filename,
			'generated_by' => $user_id,
			'generated_at' => $now,
		) );
		if ( $old_pdf && $old_pdf !== $pdf_rel ) {
			GFXDOK_Storage::delete( $old_pdf );
		}

		$fresh              = GFXDOK_DB::get_report_by_date( $date );
		$fresh->image_count = count( $order );
		return rest_ensure_response( array(
			'report' => self::format_report( $fresh, true ),
			'images' => array_map( array( __CLASS__, 'format_image' ), GFXDOK_DB::get_images( $report->id ) ),
		) );
	}

	public static function purge_archive( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params['confirm'] ) || 'USUN' !== $params['confirm'] ) {
			return new WP_Error( 'gfxdok_confirm', 'Brak potwierdzenia operacji.', array( 'status' => 400 ) );
		}
		GFXDOK_Storage::purge_all();
		GFXDOK_DB::purge_archive();
		return rest_ensure_response( array( 'purged' => true ) );
	}

	public static function stream_pdf( WP_REST_Request $request ) {
		$date = self::date_or_error( $request );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		if ( ! GFXDOK_Settings::archive_enabled() ) {
			return new WP_Error( 'gfxdok_archive_off', 'Archiwum jest wyłączone.', array( 'status' => 404 ) );
		}
		$r = GFXDOK_DB::get_report_by_date( $date );
		if ( ! $r || empty( $r->pdf_file ) ) {
			return new WP_Error( 'gfxdok_404', 'Brak zapisanego pliku PDF dla tego dnia.', array( 'status' => 404 ) );
		}
		$abs = GFXDOK_Storage::abs_path( $r->pdf_file );
		if ( ! is_file( $abs ) ) {
			return new WP_Error( 'gfxdok_404', 'Plik PDF nie istnieje na serwerze.', array( 'status' => 404 ) );
		}
		$name = $r->pdf_name ? $r->pdf_name : 'raport_dobowy_' . $date . '.pdf';
		GFXDOK_Storage::send_file( $abs, 'application/pdf', $name, $request->get_param( 'download' ) ? 'attachment' : 'inline', false );
	}

	public static function stream_image( WP_REST_Request $request ) {
		if ( ! GFXDOK_Settings::archive_enabled() ) {
			return new WP_Error( 'gfxdok_archive_off', 'Archiwum jest wyłączone.', array( 'status' => 404 ) );
		}
		$img = GFXDOK_DB::get_image( (int) $request['id'] );
		if ( ! $img ) {
			return new WP_Error( 'gfxdok_404', 'Nie znaleziono zdjęcia.', array( 'status' => 404 ) );
		}
		$rel = ( 'thumb' === $request->get_param( 'size' ) && $img->thumb_file ) ? $img->thumb_file : $img->file;
		$abs = GFXDOK_Storage::abs_path( $rel );
		if ( ! is_file( $abs ) ) {
			return new WP_Error( 'gfxdok_404', 'Plik zdjęcia nie istnieje na serwerze.', array( 'status' => 404 ) );
		}
		GFXDOK_Storage::send_file( $abs, 'image/jpeg', 'zdjecie-' . (int) $img->id . '.jpg', 'inline', true );
	}
}

/** cal_days_in_month() wymaga rozszerzenia calendar — nie zawsze dostępne na hostingu. */
function gfxdok_days_in_month( $month, $year ) {
	return (int) gmdate( 't', gmmktime( 0, 0, 0, (int) $month, 1, (int) $year ) );
}

GFXDOK_REST::init();
