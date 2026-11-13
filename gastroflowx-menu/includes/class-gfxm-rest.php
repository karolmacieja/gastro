<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API modułu: namespace gastroflowx-menu/v1.
 * Każdy endpoint: zalogowany + dostęp do modułu (rola/uprawnienie) + ważny
 * nonce wp_rest w nagłówku X-WP-Nonce. Błędy zawsze jako JSON (wrappery
 * safe()/safe_permission() jak w Weranda Lunch).
 */
class GFXM_Rest {

	const NS = GFXM_REST_NS;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'safe_register_routes' ) );
	}

	public static function safe_register_routes() {
		try {
			self::register_routes();
		} catch ( \Throwable $e ) {
			error_log( 'GastroFlowx Menu: błąd rejestracji tras REST: ' . $e->getMessage() ); // phpcs:ignore
		}
	}

	private static function safe( $callback ) {
		return function ( WP_REST_Request $req ) use ( $callback ) {
			try {
				return call_user_func( $callback, $req );
			} catch ( \Throwable $e ) {
				error_log( 'GastroFlowx Menu REST error: ' . $e->getMessage() ); // phpcs:ignore
				return new WP_Error( 'gfxm_exception', 'Błąd serwera: ' . $e->getMessage(), array( 'status' => 500 ) );
			}
		};
	}

	private static function safe_permission( $callback ) {
		return function ( WP_REST_Request $req ) use ( $callback ) {
			try {
				return call_user_func( $callback, $req );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'gfxm_permission_exception', 'Błąd serwera przy sprawdzaniu uprawnień.', array( 'status' => 500 ) );
			}
		};
	}

	/**
	 * Sprawdzenie uprawnień: zalogowanie → nonce → dostęp do modułu.
	 * Nonce weryfikujemy jawnie (nie tylko przez mechanizm cookie-auth WP),
	 * zgodnie z wymaganiem „nonce na każdym zapisie”.
	 */
	public static function check_access( WP_REST_Request $req ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'gfxm_not_logged_in', 'Musisz być zalogowany.', array( 'status' => 401 ) );
		}
		$nonce = $req->get_header( 'x_wp_nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'gfxm_bad_nonce', 'Sesja wygasła — odśwież stronę.', array( 'status' => 403 ) );
		}
		if ( ! GFXM_Access::user_has_access() ) {
			return new WP_Error( 'gfxm_forbidden', 'Brak uprawnień do modułu Menu.', array( 'status' => 403 ) );
		}
		return true;
	}

	private static function route( $path, $method, $cb ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => self::safe( array( __CLASS__, $cb ) ),
				'permission_callback' => self::safe_permission( array( __CLASS__, 'check_access' ) ),
			)
		);
	}

	public static function register_routes() {
		self::route( '/bootstrap', 'GET', 'bootstrap' );

		self::route( '/dishes', 'POST', 'create_dish' );
		self::route( '/dishes/(?P<id>\d+)', 'POST', 'update_dish' );
		self::route( '/dishes/(?P<id>\d+)', 'DELETE', 'delete_dish' );

		self::route( '/categories', 'POST', 'save_categories' );

		self::route( '/versions', 'POST', 'create_version' );
		self::route( '/versions/(?P<id>\d+)', 'POST', 'update_version' );
		self::route( '/versions/(?P<id>\d+)', 'DELETE', 'delete_version' );

		self::route( '/settings', 'POST', 'save_settings' );

		// PDF: { ids: [..] } — zapisane wersje w podanej kolejności (łączone),
		// albo { draft: {...} } — podgląd niezapisanego stanu kreatora.
		self::route( '/pdf', 'POST', 'pdf' );
	}

	private static function body( WP_REST_Request $req ) {
		$json = $req->get_json_params();
		return is_array( $json ) ? $json : $req->get_body_params();
	}

	/* ---------------- Handlery ---------------- */

	public static function bootstrap() {
		return rest_ensure_response(
			array(
				'categories' => GFXM_Data::categories(),
				'dishes'     => GFXM_Data::dishes(),
				'versions'   => GFXM_Data::versions(),
				'settings'   => GFXM_Data::settings(),
				'fonts'      => GFXM_Data::font_status(),
			)
		);
	}

	public static function create_dish( WP_REST_Request $req ) {
		return rest_ensure_response( GFXM_Data::save_dish( self::body( $req ) ) );
	}

	public static function update_dish( WP_REST_Request $req ) {
		return rest_ensure_response( GFXM_Data::save_dish( self::body( $req ), (int) $req['id'] ) );
	}

	public static function delete_dish( WP_REST_Request $req ) {
		GFXM_Data::delete_dish( (int) $req['id'] );
		// Zwracamy odświeżone wersje — mogły stracić usunięte danie.
		return rest_ensure_response( array( 'deleted' => true, 'versions' => GFXM_Data::versions() ) );
	}

	public static function save_categories( WP_REST_Request $req ) {
		$b = self::body( $req );
		return rest_ensure_response( GFXM_Data::save_categories( isset( $b['categories'] ) ? $b['categories'] : null ) );
	}

	public static function create_version( WP_REST_Request $req ) {
		return rest_ensure_response( GFXM_Data::save_version( self::body( $req ) ) );
	}

	public static function update_version( WP_REST_Request $req ) {
		return rest_ensure_response( GFXM_Data::save_version( self::body( $req ), (int) $req['id'] ) );
	}

	public static function delete_version( WP_REST_Request $req ) {
		GFXM_Data::delete_version( (int) $req['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public static function save_settings( WP_REST_Request $req ) {
		$settings = GFXM_Data::save_settings( self::body( $req ) );
		return rest_ensure_response( array( 'settings' => $settings, 'fonts' => GFXM_Data::font_status() ) );
	}

	public static function pdf( WP_REST_Request $req ) {
		$b        = self::body( $req );
		$versions = array();

		if ( ! empty( $b['draft'] ) && is_array( $b['draft'] ) ) {
			$v = GFXM_Data::sanitize_version( $b['draft'], false );
			if ( '' === $v['name'] ) {
				$v['name'] = 'podglad';
			}
			$versions[] = $v;
		} else {
			$ids = isset( $b['ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $b['ids'] ) ) ) ) : array();
			if ( count( $ids ) > 30 ) {
				return new WP_Error( 'gfxm_too_many', 'Maksymalnie 30 wersji w jednym pliku.', array( 'status' => 400 ) );
			}
			foreach ( $ids as $id ) {
				$v = GFXM_Data::version( $id );
				if ( $v ) {
					$versions[] = $v;
				}
			}
		}
		if ( empty( $versions ) ) {
			return new WP_Error( 'gfxm_nothing', 'Nie wybrano żadnej wersji menu.', array( 'status' => 400 ) );
		}

		$bytes = GFXM_PDF::render( $versions );
		GFXM_PDF::stream( $bytes, GFXM_PDF::filename( $versions ) ); // exit
		return null;
	}
}
