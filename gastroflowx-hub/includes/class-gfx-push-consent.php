<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Śledzi REALNY status zgody na powiadomienia push per użytkownik +
 * urządzenie oraz token rejestracyjny FCM tego urządzenia. Stan NIGDY nie
 * jest ustawiany ręcznie — jedyne źródło prawdy to to, co JS
 * (assets/js/push-manager.js) zgłasza na podstawie rzeczywistego stanu
 * uprawnień/tokenu Firebase w przeglądarce, identyfikowanej trwałym ID
 * urządzenia zapisanym w localStorage.
 *
 * Dane przechowywane w user meta `gfx_push_devices` jako mapa:
 *   device_id => [ 'consent' => bool, 'token' => string|null, 'last_seen' => mysql datetime ]
 */
class GFX_Push_Consent {

	const META_KEY = 'gfx_push_devices';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			GFX_REST_NS,
			'/push-consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_report' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'device_id' => array( 'required' => true ),
					'consent'   => array( 'required' => true ),
				),
			)
		);
	}

	public function handle_report( WP_REST_Request $req ) {
		$user_id   = get_current_user_id();
		$device_id = sanitize_text_field( (string) $req->get_param( 'device_id' ) );
		$consent   = (bool) $req->get_param( 'consent' );
		$token     = $req->get_param( 'token' );
		$token     = $token ? sanitize_text_field( (string) $token ) : null;

		if ( ! $user_id || ! $device_id ) {
			return new WP_REST_Response( array( 'error' => 'missing_data' ), 400 );
		}

		$devices = self::devices_for_user( $user_id );

		// Czy to PIERWSZA zgoda na TYM urządzeniu (a nie rutynowe
		// odświeżenie tokenu przy kolejnym otwarciu)? Decyduje o tym, czy
		// wysłać powitalne powiadomienie poniżej.
		$previous       = isset( $devices[ $device_id ] ) ? $devices[ $device_id ] : null;
		$was_consented  = $previous && ! empty( $previous['consent'] );
		$is_new_consent = $consent && ! $was_consented;

		// Ten sam token FCM oznacza to samo, realne urządzenie/przeglądarkę
		// - jeśli już istnieje pod INNYM device_id (np. bo localStorage
		// zostało wyczyszczone/zresetowane między testami), usuwamy stary
		// wpis, żeby nie wysyłać push DWA RAZY do tego samego urządzenia.
		if ( $token ) {
			foreach ( $devices as $existing_id => $existing ) {
				if ( $existing_id !== $device_id && ! empty( $existing['token'] ) && $existing['token'] === $token ) {
					unset( $devices[ $existing_id ] );
				}
			}
		}

		$devices[ $device_id ] = array(
			'consent'   => $consent,
			'token'     => $token,
			'last_seen' => current_time( 'mysql' ),
		);
		update_user_meta( $user_id, self::META_KEY, $devices );

		if ( $is_new_consent && $token ) {
			/**
			 * Odpalane, gdy ktoś PIERWSZY RAZ zgadza się na powiadomienia
			 * na danym urządzeniu (nie przy każdym rutynowym odświeżeniu
			 * tokenu) - GFX_Push nasłuchuje tego, żeby wysłać edytowalne
			 * w panelu powitalne powiadomienie.
			 */
			do_action( 'gfx_push_new_device_consent', $user_id, $token );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function devices_for_user( $user_id ) {
		$devices = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $devices ) ? $devices : array();
	}

	/**
	 * Usuwa zapisany token z urządzenia (wszystkich urządzeń tej osoby,
	 * które akurat go miały) po tym, jak FCM zgłosił, że jest
	 * nieprawidłowy/wygasły - żeby przyszłe wysyłki nie próbowały go już
	 * używać.
	 */
	public static function forget_token( $user_id, $token ) {
		$devices = self::devices_for_user( $user_id );
		$changed = false;
		foreach ( $devices as $device_id => $device ) {
			if ( isset( $device['token'] ) && $device['token'] === $token ) {
				$devices[ $device_id ]['token']   = null;
				$devices[ $device_id ]['consent'] = false;
				$changed = true;
			}
		}
		if ( $changed ) {
			update_user_meta( $user_id, self::META_KEY, $devices );
		}
	}

	/**
	 * true  = przynajmniej jedno znane urządzenie ma aktywną zgodę I zapisany token.
	 * false = znamy status i zgody nie ma na ŻADNYM znanym urządzeniu.
	 * null  = nic nie wiemy (użytkownik nigdy nie odwiedził żadnej strony
	 *         z aktywną integracją push tego panelu).
	 */
	public static function user_consent_status( $user_id ) {
		$devices = self::devices_for_user( $user_id );
		if ( empty( $devices ) ) {
			return null;
		}
		foreach ( $devices as $device ) {
			if ( ! empty( $device['consent'] ) && ! empty( $device['token'] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function active_device_count( $user_id ) {
		$devices = self::devices_for_user( $user_id );
		$active  = 0;
		foreach ( $devices as $device ) {
			if ( ! empty( $device['consent'] ) && ! empty( $device['token'] ) ) {
				$active++;
			}
		}
		return $active;
	}

	public static function last_seen( $user_id ) {
		$devices = self::devices_for_user( $user_id );
		$latest  = '';
		foreach ( $devices as $device ) {
			if ( ! empty( $device['last_seen'] ) && $device['last_seen'] > $latest ) {
				$latest = $device['last_seen'];
			}
		}
		return $latest;
	}

	/**
	 * Czyści całą zapisaną historię urządzeń jednej osoby (np. po testach,
	 * albo gdy zmieniła telefon/przeglądarkę i stare wpisy tylko
	 * zaśmiecają listę). Osoba zacznie od zera - status wróci do
	 * "Nieznany", dopóki znowu nie odwiedzi strony z aktywną integracją.
	 */
	public static function clear_devices_for_user( $user_id ) {
		delete_user_meta( $user_id, self::META_KEY );
	}

	/**
	 * Czyści zapisane urządzenia WSZYSTKICH użytkowników naraz (np. po
	 * migracji dostawcy push - stare tokeny/Player ID i tak są już
	 * nieaktualne).
	 */
	public static function clear_all_devices() {
		global $wpdb;
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => self::META_KEY ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}
}
