<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralna integracja z Firebase Cloud Messaging (FCM) dla całego
 * ekosystemu GastroFlowx — zastępuje wcześniejszą integrację z OneSignal.
 *
 * Ten panel (gastroflowx-hub) jest JEDYNYM miejscem, w którym konfiguruje
 * się FCM (konto serwisowe + konfiguracja Web SDK) oraz JEDYNYM miejscem
 * inicjalizującym kliencki Firebase SDK — na KAŻDEJ stronie frontendu, dla
 * KAŻDEGO zalogowanego użytkownika.
 *
 * WAŻNE — inaczej niż przy OneSignal: ze względu na dużo większą złożoność
 * konfiguracji FCM (konto serwisowe, kilka wartości Web SDK), Grafik i
 * Napiwki NIE mają już własnej, duplikowanej konfiguracji fallback. Jeśli
 * ten panel jest nieaktywny/nieskonfigurowany, wysyłka push jest po prostu
 * pomijana (inne kanały - e-mail - działają nadal); to świadoma decyzja,
 * żeby nie zmuszać do wklejania konta serwisowego w trzech miejscach.
 *
 * Uwierzytelnianie do FCM HTTP v1 API wymaga tokenu OAuth2 uzyskanego przez
 * podpisanie JWT prywatnym kluczem konta serwisowego (RS256) i wymiany go
 * na access_token w Google OAuth. Implementacja poniżej robi to bez
 * zewnętrznych bibliotek (Composer), korzystając wyłącznie z wbudowanego
 * OpenSSL w PHP.
 */
class GFX_Push {

	const CRON_HOOK      = 'gfx_daily_birthday_check';
	const TOKEN_TRANSIENT = 'gfx_fcm_access_token';

	/** @var GFX_Push|null Instancja utworzona w gfx_init_plugin(). */
	protected static $instance = null;

	public static function instance() {
		return self::$instance;
	}

	public function __construct() {
		self::$instance = $this;
		add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_daily_check' ) );

		// Flaga "push jest zarządzany centralnie" MUSI trafić do <head> jak
		// najwcześniej — informuje resztę ekosystemu, że nie powinien nic
		// sam inicjalizować.
		add_action( 'wp_head', array( $this, 'print_managed_flag' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_sdk' ), 90 );

		add_action( 'admin_post_gfx_fcm_test', array( $this, 'handle_test_notification' ) );
		add_action( 'admin_post_gfx_fcm_check_birthdays_now', array( $this, 'handle_check_birthdays_now' ) );
		add_action( 'admin_post_gfx_push_toggle_debug', array( $this, 'handle_toggle_debug' ) );

		// Wspólny mechanizm wysyłki dla innych wtyczek (Grafik, Napiwki).
		add_filter( 'gfx_dispatch_push', array( $this, 'filter_dispatch_push' ), 10, 2 );
		add_filter( 'gfx_user_push_consent', array( $this, 'filter_user_push_consent' ), 10, 2 );
		add_filter( 'gfx_push_is_managed', array( $this, 'filter_is_managed' ), 10, 1 );

		// Powitalne powiadomienie przy PIERWSZEJ zgodzie na nowym urządzeniu.
		add_action( 'gfx_push_new_device_consent', array( $this, 'send_welcome_notification' ), 10, 2 );
	}

	public function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$first_run = strtotime( 'tomorrow 09:00', current_time( 'timestamp' ) );
			wp_schedule_event( $first_run, 'daily', self::CRON_HOOK );
		}
	}

	public static function clear_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Czy panel jest aktywny I skonfigurowany na tyle, żeby wysyłać push
	 * przez FCM (używane też przez Grafik/Napiwki, żeby wiedzieć, że mają
	 * NIE próbować niczego same).
	 */
	public function is_active() {
		if ( '1' !== get_option( 'gfx_fcm_enabled', '0' ) ) {
			return false;
		}
		$required = array(
			'gfx_fcm_service_account_json',
			'gfx_fcm_web_api_key',
			'gfx_fcm_auth_domain',
			'gfx_fcm_project_id',
			'gfx_fcm_messaging_sender_id',
			'gfx_fcm_app_id',
			'gfx_fcm_vapid_key',
		);
		foreach ( $required as $opt ) {
			if ( ! get_option( $opt, '' ) ) {
				return false;
			}
		}
		return true;
	}

	public function filter_is_managed( $default ) {
		return $this->is_active() ? true : $default;
	}

	/* =========================================================
	 *  URODZINY (cron)
	 * ========================================================= */

	public function run_daily_check() {
		if ( ! $this->is_active() ) {
			return;
		}
		if ( ! class_exists( 'GFX_Birthdays' ) ) {
			return;
		}

		$today_people = GFX_Birthdays::today_list();
		if ( empty( $today_people ) ) {
			return;
		}

		$all_user_ids = array_map( 'intval', get_users( array( 'fields' => 'ID' ) ) );

		foreach ( $today_people as $person ) {
			$birthday_user_id = (int) $person['user_id'];

			// 1) Życzenia urodzinowe - WYŁĄCZNIE dla osoby, która dziś je obchodzi.
			apply_filters(
				'gfx_dispatch_push',
				null,
				array(
					'type'        => 'birthday_wish',
					'vars'        => array( 'solenizant' => $person['name'] ),
					'user_ids'    => array( $birthday_user_id ),
					'title'       => '🎂 Wszystkiego najlepszego!',
					'message'     => sprintf(
						/* translators: %s: imię i nazwisko */
						__( 'Wszystkiego najlepszego, %s! Niech ten dzień będzie wyjątkowy 🎉', 'gastroflowx-hub' ),
						$person['name']
					),
					'category'    => __( 'Urodziny', 'gastroflowx-hub' ),
					'subcategory' => __( 'Życzenia', 'gastroflowx-hub' ),
				)
			);

			// 2) Ogłoszenie dla RESZTY zespołu (wszyscy oprócz solenizanta/ki),
			// żeby nie zapomnieli złożyć życzeń osobiście.
			$team_ids = array_values( array_diff( $all_user_ids, array( $birthday_user_id ) ) );
			if ( ! empty( $team_ids ) ) {
				apply_filters(
					'gfx_dispatch_push',
					null,
					array(
						'type'        => 'birthday_team',
						'vars'        => array( 'solenizant' => $person['name'] ),
						'user_ids'    => $team_ids,
						'title'       => '🎉 Urodziny w zespole!',
						'message'     => sprintf(
							/* translators: %s: imię i nazwisko */
							__( '%s obchodzi dziś urodziny! Nie zapomnij złożyć życzeń 🎂', 'gastroflowx-hub' ),
							$person['name']
						),
						'category'    => __( 'Urodziny', 'gastroflowx-hub' ),
						'subcategory' => __( 'Ogłoszenie', 'gastroflowx-hub' ),
					)
				);
			}
		}
	}

	/**
	 * Ręczne wywołanie tej samej logiki, co codzienny cron - żeby dało się
	 * zweryfikować od razu, czy dopasowanie daty i wysyłka działają, bez
	 * czekania na jutro (albo na to, że akurat prawdziwy cron trafi w
	 * dobry moment). Wynik (ile osób znaleziono) trafia do tego samego
	 * transientu co wynik testu, więc admin dostaje jasny komunikat.
	 */
	public function handle_check_birthdays_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-hub' ) );
		}
		check_admin_referer( 'gfx_fcm_check_birthdays_now' );

		$user  = wp_get_current_user();
		$found = class_exists( 'GFX_Birthdays' ) ? GFX_Birthdays::today_list() : array();

		if ( empty( $found ) ) {
			set_transient(
				'gfx_fcm_test_result_' . $user->ID,
				array( 'ok' => true, 'msg' => __( 'Sprawdzono: dziś nikt w zespole nie ma urodzin (albo nikt nie ma ustawionej daty urodzenia) - nic nie wysłano.', 'gastroflowx-hub' ) ),
				MINUTE_IN_SECONDS
			);
		} elseif ( ! $this->is_active() ) {
			set_transient(
				'gfx_fcm_test_result_' . $user->ID,
				array( 'ok' => false, 'msg' => __( 'FCM nie jest skonfigurowany/włączony, więc mimo znalezionych urodzin nic nie zostało wysłane.', 'gastroflowx-hub' ) ),
				MINUTE_IN_SECONDS
			);
		} else {
			$this->run_daily_check();
			$names = wp_list_pluck( $found, 'name' );
			set_transient(
				'gfx_fcm_test_result_' . $user->ID,
				array(
					'ok'  => true,
					'msg' => sprintf(
						/* translators: %s: lista imion i nazwisk */
						__( 'Sprawdzono: dziś urodziny ma: %s. Wysyłka uruchomiona - sprawdź wynik w Historii wysyłki.', 'gastroflowx-hub' ),
						implode( ', ', $names )
					),
				),
				MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'gastroflowx-integrations', 'gfx_test_sent' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Implementacja filtra `gfx_dispatch_push` — wołana przez WŁASNY kod
	 * (urodziny, test) oraz przez Grafik/Napiwki. Zwraca `null`, jeśli
	 * panel nie jest aktywny (sygnał: "nic nie wysyłaj"), albo tablicę ze
	 * statystykami wysyłki.
	 */
	public function filter_dispatch_push( $result, $args ) {
		if ( ! $this->is_active() ) {
			return $result;
		}

		$user_ids    = array_values( array_unique( array_map( 'absint', (array) ( $args['user_ids'] ?? array() ) ) ) );
		$title       = (string) ( $args['title'] ?? '' );
		$message     = (string) ( $args['message'] ?? '' );
		$category    = (string) ( $args['category'] ?? __( 'Inne', 'gastroflowx-hub' ) );
		$subcategory = isset( $args['subcategory'] ) ? $args['subcategory'] : null;
		$url         = isset( $args['url'] ) ? $args['url'] : null;
		$vars        = isset( $args['vars'] ) && is_array( $args['vars'] ) ? $args['vars'] : array();

		// Szablon treści + ikona (GastroFlowx → Powiadomienia push → Szablony i ikony).
		$type_key = GFX_Push_Templates::resolve_key( $args );
		GFX_Push_Templates::remember( $type_key, $args );
		$icon  = GFX_Push_Templates::icon_url( $type_key, $args['icon'] ?? '', $args['fallback_icon'] ?? '' );
		$badge = GFX_Push_Templates::badge_url( $args['fallback_badge'] ?? '' );

		$stats = array( 'sent' => 0, 'skipped' => 0, 'errors' => 0 );

		$orig_title   = $title;
		$orig_message = $message;

		foreach ( $user_ids as $uid ) {
			$user = get_userdata( $uid );
			$name = $user ? $user->display_name : ( '#' . $uid );

			$rendered = GFX_Push_Templates::render( $type_key, $orig_title, $orig_message, $vars, $uid );
			$title    = $rendered['title'];
			$message  = $rendered['message'];

			$consent = self::user_consent_status( $uid );
			if ( true !== $consent ) {
				$stats['skipped']++;
				$reason = ( false === $consent )
					? __( 'Brak zgody na powiadomienia push.', 'gastroflowx-hub' )
					: __( 'Status zgody nieznany — użytkownik nigdy nie otworzył panelu z aktywną integracją push.', 'gastroflowx-hub' );
				if ( class_exists( 'GFX_Push_Log' ) ) {
					GFX_Push_Log::add( $category, $subcategory, $uid, $name, $title, __( 'Pominięto', 'gastroflowx-hub' ), $reason );
				}
				continue;
			}

			$send_result = $this->send_to_user( $uid, $title, $message, $url, $icon, $badge );

			if ( is_wp_error( $send_result ) ) {
				$stats['errors']++;
				if ( class_exists( 'GFX_Push_Log' ) ) {
					GFX_Push_Log::add( $category, $subcategory, $uid, $name, $title, __( 'Błąd', 'gastroflowx-hub' ), $send_result->get_error_message() );
				}
			} else {
				$stats['sent']++;
				if ( class_exists( 'GFX_Push_Log' ) ) {
					GFX_Push_Log::add( $category, $subcategory, $uid, $name, $title, __( 'Wysłano', 'gastroflowx-hub' ), '' );
				}
			}
		}

		return array( 'handled' => true, 'stats' => $stats );
	}

	public function filter_user_push_consent( $default, $user_id ) {
		if ( ! $this->is_active() ) {
			return $default;
		}
		$status = self::user_consent_status( absint( $user_id ) );
		return null === $status ? false : $status;
	}

	protected static function user_consent_status( $user_id ) {
		if ( class_exists( 'GFX_Push_Consent' ) ) {
			return GFX_Push_Consent::user_consent_status( $user_id );
		}
		return null;
	}

	/**
	 * Wysyła powiadomienie do JEDNEJ osoby — przez WSZYSTKIE jej znane,
	 * zgodne (consent=true) tokeny FCM naraz (osobne wywołanie API per
	 * token, bo FCM HTTP v1 nie ma odpowiednika OneSignal "alias"/wielu
	 * urządzeń jednym wywołaniem). Sukces = przynajmniej jeden token
	 * dostarczony poprawnie.
	 */
	public function send_to_user( $user_id, $title, $message, $url = null, $icon = '', $badge = '' ) {
		$devices = class_exists( 'GFX_Push_Consent' ) ? GFX_Push_Consent::devices_for_user( $user_id ) : array();
		$tokens  = array();
		foreach ( $devices as $device ) {
			if ( ! empty( $device['consent'] ) && ! empty( $device['token'] ) ) {
				$tokens[] = $device['token'];
			}
		}
		$tokens = array_values( array_unique( $tokens ) );

		if ( empty( $tokens ) ) {
			return new WP_Error( 'gfx_fcm_no_token', __( 'Brak zapisanego tokenu FCM dla tego urządzenia/osoby.', 'gastroflowx-hub' ) );
		}

		$errors = array();
		$any_ok = false;

		foreach ( $tokens as $device_token ) {
			$result = $this->send_to_token( $device_token, $title, $message, $url, $icon, $badge );
			if ( is_wp_error( $result ) ) {
				// Token wygasł/nieprawidłowy - usuwamy go, żeby przyszłe
				// wysyłki nie próbowały już go używać (odpowiednik
				// czyszczenia wygasłych subskrypcji Web Push).
				if ( 'gfx_fcm_token_dead' === $result->get_error_code() && class_exists( 'GFX_Push_Consent' ) ) {
					GFX_Push_Consent::forget_token( $user_id, $device_token );
				}
				$errors[] = $result->get_error_message();
			} else {
				$any_ok = true;
			}
		}

		if ( $any_ok ) {
			return true;
		}

		return new WP_Error( 'gfx_fcm_error', implode( '; ', array_unique( $errors ) ) );
	}

	/**
	 * Wysyła powiadomienie do JEDNEGO, konkretnego tokenu FCM (jedno
	 * urządzenie) - używane zarówno przez send_to_user() (w pętli po
	 * wszystkich urządzeniach danej osoby), jak i przy powitalnym
	 * powiadomieniu po nowej zgodzie (tam celowo TYLKO to jedno, nowe
	 * urządzenie, a nie wszystkie zgodne urządzenia tej osoby).
	 */
	public function send_to_token( $device_token, $title, $message, $url = null, $icon = '', $badge = '' ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$project_id = get_option( 'gfx_fcm_project_id', '' );

		// UWAGA: wysyłamy jako "data", nie "notification". Payload typu
		// "notification" powoduje, że przeglądarka SAMA automatycznie
		// wyświetla powiadomienie na poziomie service workera - a NASZ
		// kod (onBackgroundMessage/onMessage w push-manager.js oraz
		// w firebase-messaging-sw.js) robi to jeszcze raz, co dawało
		// zawsze dokładnie dwa powiadomienia na urządzenie. Payload
		// typu "data" nie jest automatycznie wyświetlany przez nikogo -
		// wyświetla go wyłącznie nasz kod, dokładnie jeden raz.
		$payload = array(
			'message' => array(
				'token' => $device_token,
				'data'  => array(
					'title' => (string) $title,
					'body'  => (string) $message,
					'link'  => $url ? esc_url_raw( $url ) : home_url(),
					// Wartości w "data" muszą być stringami (wymóg FCM HTTP v1).
					'icon'  => $icon ? esc_url_raw( $icon ) : '',
					'badge' => $badge ? esc_url_raw( $badge ) : '',
				),
			),
		);

		$response = wp_remote_post(
			'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $project_id ) . '/messages:send',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$fcm_error_code = $body['error']['status'] ?? '';
		$error_text     = ! empty( $body['error']['message'] ) ? $body['error']['message'] : sprintf( 'HTTP %d', $code );
		$is_dead_token  = in_array( $fcm_error_code, array( 'UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND' ), true );

		return new WP_Error( $is_dead_token ? 'gfx_fcm_token_dead' : 'gfx_fcm_error', $error_text );
	}

	/**
	 * Powitalne powiadomienie wysyłane, gdy ktoś PIERWSZY RAZ zgadza się na
	 * powiadomienia na danym urządzeniu (nie przy każdym odświeżeniu
	 * tokenu). Wołane z GFX_Push_Consent::handle_report() przez akcję
	 * `gfx_push_new_device_consent`. Wysyłane TYLKO na to jedno, nowe
	 * urządzenie - nie do wszystkich zgodnych urządzeń tej osoby.
	 */
	public function send_welcome_notification( $user_id, $token ) {
		if ( ! $this->is_active() ) {
			return;
		}
		if ( '1' !== get_option( 'gfx_fcm_welcome_enabled', '1' ) ) {
			return;
		}
		$rendered = GFX_Push_Templates::render( 'welcome', '', '', array(), $user_id );
		$title    = $rendered['title'];
		$body     = $rendered['message'];

		$user   = get_userdata( $user_id );
		$name   = $user ? $user->display_name : ( '#' . $user_id );
		$result = $this->send_to_token( $token, $title, $body, null, GFX_Push_Templates::icon_url( 'welcome' ), GFX_Push_Templates::badge_url() );

		if ( class_exists( 'GFX_Push_Log' ) ) {
			if ( is_wp_error( $result ) ) {
				GFX_Push_Log::add( __( 'Powitalne', 'gastroflowx-hub' ), null, $user_id, $name, $title, __( 'Błąd', 'gastroflowx-hub' ), $result->get_error_message() );
			} else {
				GFX_Push_Log::add( __( 'Powitalne', 'gastroflowx-hub' ), null, $user_id, $name, $title, __( 'Wysłano', 'gastroflowx-hub' ), '' );
			}
		}
	}

	/**
	 * Zwraca (i cache'uje na czas życia tokenu) token OAuth2 wymagany do
	 * wysyłki FCM HTTP v1. Podpisuje JWT (RS256) prywatnym kluczem z konta
	 * serwisowego i wymienia go w Google OAuth na access_token.
	 */
	protected function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}

		$creds = $this->service_account_credentials();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		$now    = time();
		$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
		$claims = array(
			'iss'   => $creds['client_email'],
			'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'exp'   => $now + 3600,
			'iat'   => $now,
		);

		$segments = array(
			self::base64url_encode( wp_json_encode( $header ) ),
			self::base64url_encode( wp_json_encode( $claims ) ),
		);
		$unsigned = implode( '.', $segments );

		$signature = '';
		$private_key = openssl_pkey_get_private( $creds['private_key'] );
		if ( ! $private_key ) {
			return new WP_Error( 'gfx_fcm_bad_key', __( 'Nie udało się wczytać prywatnego klucza z konta serwisowego FCM - sprawdź, czy JSON jest poprawny.', 'gastroflowx-hub' ) );
		}
		$signed = openssl_sign( $unsigned, $signature, $private_key, 'sha256WithRSAEncryption' );
		if ( ! $signed ) {
			return new WP_Error( 'gfx_fcm_sign_failed', __( 'Nie udało się podpisać JWT dla FCM.', 'gastroflowx-hub' ) );
		}

		$jwt = $unsigned . '.' . self::base64url_encode( $signature );

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && ! empty( $body['access_token'] ) ) {
			$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3000;
			set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );
			return $body['access_token'];
		}

		$error_text = ! empty( $body['error_description'] ) ? $body['error_description'] : sprintf( 'HTTP %d', $code );
		return new WP_Error( 'gfx_fcm_oauth_failed', sprintf( /* translators: %s: szczegóły błędu */ __( 'Nie udało się uzyskać tokenu OAuth2 dla FCM: %s', 'gastroflowx-hub' ), $error_text ) );
	}

	protected function service_account_credentials() {
		$raw = get_option( 'gfx_fcm_service_account_json', '' );
		if ( ! $raw ) {
			return new WP_Error( 'gfx_fcm_not_configured', __( 'FCM nie jest skonfigurowany (brak konta serwisowego).', 'gastroflowx-hub' ) );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return new WP_Error( 'gfx_fcm_bad_service_account', __( 'Zapisany JSON konta serwisowego FCM jest niepoprawny lub niekompletny.', 'gastroflowx-hub' ) );
		}
		return $data;
	}

	public static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/* =========================================================
	 *  FRONTEND: Firebase SDK na KAŻDEJ stronie, dla KAŻDEGO
	 *  zalogowanego użytkownika.
	 * ========================================================= */

	public function print_managed_flag() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		$managed = $this->is_active();
		echo '<script>window.gfxPushManaged = ' . ( $managed ? 'true' : 'false' ) . ';</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- literalny bool.

		// Diagnostyka na urządzeniach bez zdalnego debugowania (np. iPhone
		// bez Maca): pływająca konsola JS widoczna wprost na ekranie
		// telefonu, żeby zobaczyć błędy logowane przez push-manager.js bez
		// podłączania do komputera. Włączana ALBO przez ?gfx_push_debug=1
		// w adresie (działa w zwykłej karcie Safari), ALBO trwale per konto
		// (działa też w zainstalowanej aplikacji, gdzie nie ma paska
		// adresu do edycji) - patrz GFX_Push_Admin. Tylko dla administratorów.
		$debug_via_url     = isset( $_GET['gfx_push_debug'] );
		$debug_via_account = get_user_meta( get_current_user_id(), 'gfx_push_debug_enabled', true );
		if ( ( $debug_via_url || $debug_via_account ) && current_user_can( 'manage_options' ) ) {
			echo '<script src="https://cdn.jsdelivr.net/npm/eruda"></script><script>eruda.init();</script>' . "\n";
		}
	}

	public function maybe_enqueue_sdk() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		if ( ! $this->is_active() ) {
			return;
		}
		$user_id = get_current_user_id();

		$firebase_config = array(
			'apiKey'            => get_option( 'gfx_fcm_web_api_key', '' ),
			'authDomain'        => get_option( 'gfx_fcm_auth_domain', '' ),
			'projectId'         => get_option( 'gfx_fcm_project_id', '' ),
			'storageBucket'     => get_option( 'gfx_fcm_storage_bucket', '' ),
			'messagingSenderId' => get_option( 'gfx_fcm_messaging_sender_id', '' ),
			'appId'             => get_option( 'gfx_fcm_app_id', '' ),
		);

		wp_enqueue_script( 'firebase-app', 'https://www.gstatic.com/firebasejs/10.13.0/firebase-app-compat.js', array(), null, true );
		wp_enqueue_script( 'firebase-messaging', 'https://www.gstatic.com/firebasejs/10.13.0/firebase-messaging-compat.js', array( 'firebase-app' ), null, true );

		wp_enqueue_script( 'gfx-push-manager', GFX_PLUGIN_URL . 'assets/js/push-manager.js', array( 'firebase-messaging' ), GFX_VERSION, true );
		wp_localize_script(
			'gfx-push-manager',
			'gfxPushConfig',
			array(
				'restUrl'        => esc_url_raw( rest_url( GFX_REST_NS . '/push-consent' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'firebaseConfig' => $firebase_config,
				'vapidKey'       => get_option( 'gfx_fcm_vapid_key', '' ),
				'wpUserId'       => (string) $user_id,
			)
		);
	}

	/**
	 * Wysyła testowe powiadomienie z poziomu "GastroFlowx → Integracje" —
	 * bezpośrednio do bieżącego admina, z pominięciem filtrowania po
	 * zgodzie (to jest jawny, ręczny test).
	 */
	public function handle_test_notification() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-hub' ) );
		}
		check_admin_referer( 'gfx_fcm_test' );

		$user     = wp_get_current_user();
		$rendered = GFX_Push_Templates::render( 'test', '', '', array(), $user->ID );
		$title    = $rendered['title'];
		$body     = $rendered['message'];

		if ( ! $this->is_active() ) {
			set_transient(
				'gfx_fcm_test_result_' . $user->ID,
				array( 'ok' => false, 'msg' => __( 'FCM nie jest skonfigurowany (uzupełnij i włącz wszystkie pola powyżej).', 'gastroflowx-hub' ) ),
				MINUTE_IN_SECONDS
			);
		} else {
			$result = $this->send_to_user( $user->ID, $title, $body, null, GFX_Push_Templates::icon_url( 'test' ), GFX_Push_Templates::badge_url() );
			if ( is_wp_error( $result ) ) {
				if ( class_exists( 'GFX_Push_Log' ) ) {
					GFX_Push_Log::add( __( 'Test', 'gastroflowx-hub' ), null, $user->ID, $user->display_name, $title, __( 'Błąd', 'gastroflowx-hub' ), $result->get_error_message() );
				}
				set_transient( 'gfx_fcm_test_result_' . $user->ID, array( 'ok' => false, 'msg' => $result->get_error_message() ), MINUTE_IN_SECONDS );
			} else {
				if ( class_exists( 'GFX_Push_Log' ) ) {
					GFX_Push_Log::add( __( 'Test', 'gastroflowx-hub' ), null, $user->ID, $user->display_name, $title, __( 'Wysłano', 'gastroflowx-hub' ), '' );
				}
				set_transient(
					'gfx_fcm_test_result_' . $user->ID,
					array( 'ok' => true, 'msg' => __( 'Żądanie zostało poprawnie przyjęte przez Firebase. Jeśli Ty (bieżący użytkownik) masz aktywną zgodę na powiadomienia w tej przeglądarce, powinno dotrzeć w ciągu kilku sekund.', 'gastroflowx-hub' ) ),
					MINUTE_IN_SECONDS
				);
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'gastroflowx-integrations', 'gfx_test_sent' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Włącza/wyłącza trwałą (per konto) konsolę diagnostyczną (Eruda) - w
	 * odróżnieniu od ?gfx_push_debug=1 w adresie, działa też w
	 * zainstalowanej aplikacji na iOS, gdzie nie ma paska adresu do
	 * edycji.
	 */
	public function handle_toggle_debug() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-hub' ) );
		}
		check_admin_referer( 'gfx_push_toggle_debug' );
		$user_id      = get_current_user_id();
		$currently_on = (bool) get_user_meta( $user_id, 'gfx_push_debug_enabled', true );
		update_user_meta( $user_id, 'gfx_push_debug_enabled', $currently_on ? '' : '1' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'gastroflowx-integrations', 'gfx_debug_toggled' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Generuje w katalogu głównym witryny `firebase-messaging-sw.js` —
	 * odpowiednik OneSignalSDKWorker.js, wymagany przez Firebase do obsługi
	 * powiadomień w tle. Treść zależy od konfiguracji, więc regenerujemy
	 * go zarówno przy aktywacji, jak i przy każdym zapisie ustawień
	 * integracji.
	 */
	public static function generate_service_worker_file() {
		self::remove_legacy_onesignal_files();

		$config = array(
			'apiKey'            => get_option( 'gfx_fcm_web_api_key', '' ),
			'authDomain'        => get_option( 'gfx_fcm_auth_domain', '' ),
			'projectId'         => get_option( 'gfx_fcm_project_id', '' ),
			'storageBucket'     => get_option( 'gfx_fcm_storage_bucket', '' ),
			'messagingSenderId' => get_option( 'gfx_fcm_messaging_sender_id', '' ),
			'appId'             => get_option( 'gfx_fcm_app_id', '' ),
		);

		if ( ! $config['apiKey'] || ! $config['projectId'] ) {
			return; // Jeszcze nieskonfigurowane - nie ma czego wpisać do service workera.
		}

		$content = "importScripts('https://www.gstatic.com/firebasejs/10.13.0/firebase-app-compat.js');\n"
			. "importScripts('https://www.gstatic.com/firebasejs/10.13.0/firebase-messaging-compat.js');\n\n"
			. 'firebase.initializeApp(' . wp_json_encode( $config ) . ");\n\n"
			. "const messaging = firebase.messaging();\n\n"
			. "messaging.onBackgroundMessage(function (payload) {\n"
			. "  const data = payload.data || {};\n"
			. "  const title = data.title || 'GastroFlowx';\n"
			. "  const body = data.body || '';\n"
			. "  const options = { body: body, data: { link: data.link || '/' } };\n"
			. "  if (data.icon) { options.icon = data.icon; }\n"
			. "  if (data.badge) { options.badge = data.badge; }\n"
			. "  self.registration.showNotification(title, options);\n"
			. "});\n\n"
			. "self.addEventListener('notificationclick', function (event) {\n"
			. "  event.notification.close();\n"
			. "  const link = (event.notification.data && event.notification.data.link) || '/';\n"
			. "  event.waitUntil(clients.openWindow(link));\n"
			. "});\n\n"
			. "// Niektóre przeglądarki (głównie desktopowe Chrome/Edge) wymagają\n"
			. "// obecności handlera 'fetch' w service workerze jako jednego z\n"
			. "// warunków uznania strony za instalowalną jako PWA (ikonka/przycisk\n"
			. "// 'Zainstaluj' w pasku adresu). To zwykły przelot bez cache'owania -\n"
			. "// nie zmienia zachowania strony, jest tu wyłącznie dla instalowalności.\n"
			. "self.addEventListener('fetch', function (event) {\n"
			. "  event.respondWith(fetch(event.request));\n"
			. "});\n";

		$path = trailingslashit( ABSPATH ) . 'firebase-messaging-sw.js';
		if ( is_writable( ABSPATH ) ) {
			@file_put_contents( $path, $content ); // phpcs:ignore -- best effort, cicha porażka jest OK, admin może wgrać ręcznie.
		}
	}

	/**
	 * Usuwa pliki service workera pozostałe po wcześniejszej integracji
	 * z OneSignal (od migracji na Firebase Cloud Messaging są martwym
	 * kodem). Jeśli zostaną na serwerze, przeglądarka, która zdążyła je
	 * wcześniej zarejestrować, może wciąż odpytywać stare API OneSignal —
	 * w tym ich domyślne powiadomienie powitalne "Thanks for subscribing!",
	 * które nie ma nic wspólnego z tą wtyczką.
	 */
	public static function remove_legacy_onesignal_files() {
		foreach ( array( 'OneSignalSDKWorker.js', 'OneSignalSDKUpdaterWorker.js' ) as $filename ) {
			$path = trailingslashit( ABSPATH ) . $filename;
			if ( file_exists( $path ) && is_writable( $path ) ) {
				@unlink( $path ); // phpcs:ignore -- best effort, cicha porażka jest OK, admin może usunąć ręcznie przez FTP.
			}
		}
	}
}
