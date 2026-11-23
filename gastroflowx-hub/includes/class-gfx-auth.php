<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logowanie/wylogowanie przez REST API (bez natywnego /wp-login.php),
 * z obsługą "Zapamiętaj mnie" oraz aktualizacja danych konta.
 */
class GFX_Auth {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			GFX_REST_NS,
			'/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'login' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			GFX_REST_NS,
			'/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			GFX_REST_NS,
			'/account',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_account' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			GFX_REST_NS,
			'/avatar',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_avatar' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
	}

	public function login( WP_REST_Request $req ) {
		$login    = sanitize_text_field( $req->get_param( 'login' ) );
		$password = (string) $req->get_param( 'password' );
		$remember = (bool) $req->get_param( 'remember' );

		if ( empty( $login ) || empty( $password ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Podaj login i hasło.', 'gastroflowx-hub' ) ), 400 );
		}

		// Konta z 2FA w Wordfence — logowanie dwuetapowe (hasło, potem kod).
		$candidate = get_user_by( 'login', $login );
		if ( ! $candidate && is_email( $login ) ) {
			$candidate = get_user_by( 'email', $login );
		}
		if ( class_exists( 'GFX_Two_Factor' ) && GFX_Two_Factor::user_has_2fa( $candidate ) ) {
			return $this->login_two_factor( $candidate, $login, $password, $remember, (string) $req->get_param( 'code' ) );
		}

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			if ( 'gfx_account_blocked' === $user->get_error_code() ) {
				// Konto zablokowane — pokazujemy komunikat z ustawień zamiast „złe hasło”.
				return new WP_REST_Response( array( 'success' => false, 'blocked' => true, 'message' => $user->get_error_message() ), 403 );
			}
			if ( class_exists( 'GFX_Two_Factor' ) && GFX_Two_Factor::is_code_required_error( $user ) ) {
				// Wordfence wymaga kodu, choć nie wykryliśmy 2FA wcześniej — poproś o kod.
				return $this->ask_for_code();
			}
			if ( class_exists( 'GFX_Two_Factor' ) && GFX_Two_Factor::is_wfls_error( $user ) ) {
				// Inne komunikaty Wordfence (np. wymagane włączenie 2FA, weryfikacja e-mailem).
				return new WP_REST_Response( array( 'success' => false, 'message' => GFX_Two_Factor::plain_message( $user ) ), 403 );
			}
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nieprawidłowy login lub hasło.', 'gastroflowx-hub' ) ), 401 );
		}

		wp_set_current_user( $user->ID );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'redirect' => remove_query_arg( array() ),
			),
			200
		);
	}

	private function ask_for_code( $message = '', $status = 200 ) {
		return new WP_REST_Response(
			array(
				'success'   => false,
				'twoFactor' => true,
				'message'   => $message ? $message : __( 'Wpisz kod z aplikacji uwierzytelniającej (6 cyfr).', 'gastroflowx-hub' ),
			),
			$status
		);
	}

	/**
	 * Logowanie konta z 2FA w Wordfence.
	 * Krok 1 (bez kodu): sprawdzenie hasła przez pełny łańcuch „authenticate”
	 * (blokady Wordfence, blokada konta w hubie) — przy poprawnym haśle prośba o kod.
	 * Krok 2 (z kodem): ponowne uwierzytelnienie z kodem w polu `wfls-token`
	 * (weryfikuje Wordfence), a jeśli Wordfence nie egzekwował kodu w kroku 1 —
	 * weryfikacja kodu funkcją Wordfence. Dopiero potem ciasteczko logowania.
	 */
	private function login_two_factor( WP_User $candidate, $login, $password, $remember, $code ) {
		$code = preg_replace( '/[\s-]+/', '', $code );
		$key  = GFX_Two_Factor::state_key( $candidate );
		$fail = function ( $err ) {
			if ( 'gfx_account_blocked' === $err->get_error_code() ) {
				return new WP_REST_Response( array( 'success' => false, 'blocked' => true, 'message' => $err->get_error_message() ), 403 );
			}
			if ( GFX_Two_Factor::is_wfls_error( $err ) && ! GFX_Two_Factor::is_code_required_error( $err ) ) {
				return new WP_REST_Response( array( 'success' => false, 'twoFactor' => true, 'message' => GFX_Two_Factor::plain_message( $err ) ), 401 );
			}
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nieprawidłowy login lub hasło.', 'gastroflowx-hub' ) ), 401 );
		};

		// Limit prób kodu: 5 błędnych → 15 minut przerwy dla tego konta.
		if ( get_transient( $key . '_lock' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Zbyt wiele błędnych kodów. Spróbuj ponownie za 15 minut.', 'gastroflowx-hub' ) ), 429 );
		}
		$bad_code = function () use ( $key ) {
			$n = (int) get_transient( $key . '_fails' ) + 1;
			if ( $n >= 5 ) {
				delete_transient( $key );
				delete_transient( $key . '_fails' );
				set_transient( $key . '_lock', 1, 15 * MINUTE_IN_SECONDS );
				return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Zbyt wiele błędnych kodów. Spróbuj ponownie za 15 minut.', 'gastroflowx-hub' ) ), 429 );
			}
			set_transient( $key . '_fails', $n, 15 * MINUTE_IN_SECONDS );
			return $this->ask_for_code( __( 'Nieprawidłowy kod. Spróbuj ponownie.', 'gastroflowx-hub' ), 401 );
		};

		$mode = get_transient( $key );
		if ( '' === $code || ! $mode ) {
			GFX_Two_Factor::set_request_token( null );
			$res = wp_authenticate( $login, $password );
			if ( is_wp_error( $res ) && ! GFX_Two_Factor::is_code_required_error( $res ) ) {
				return $fail( $res );
			}
			// Hasło sprawdzamy też sami — prośba o kod tylko po poprawnym haśle.
			if ( ! wp_check_password( $password, $candidate->user_pass, $candidate->ID ) ) {
				return $fail( new WP_Error( 'incorrect_password', '' ) );
			}
			// 'wfls' = Wordfence sam wymaga i sprawdza kod; 'self' = sprawdzamy funkcją Wordfence.
			set_transient( $key, is_wp_error( $res ) ? 'wfls' : 'self', 10 * MINUTE_IN_SECONDS );
			return $this->ask_for_code();
		}

		if ( 'wfls' === $mode ) {
			GFX_Two_Factor::set_request_token( $code );
			$user = wp_authenticate( $login, $password );
			GFX_Two_Factor::set_request_token( null );
			if ( is_wp_error( $user ) ) {
				if ( GFX_Two_Factor::is_wfls_error( $user ) ) {
					return $bad_code(); // zły kod — komunikat i licznik prób
				}
				return $fail( $user );
			}
		} else {
			$user = wp_authenticate( $login, $password );
			if ( is_wp_error( $user ) ) {
				return $fail( $user );
			}
			$ok = GFX_Two_Factor::validate( $user, $code );
			if ( null === $ok ) {
				// Nie da się zweryfikować kodu — nie wpuszczamy bez 2FA.
				return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nie można zweryfikować kodu w panelu. Zaloguj się przez stronę logowania WordPress (wp-login.php) albo skontaktuj się z administratorem.', 'gastroflowx-hub' ) ), 403 );
			}
			if ( ! $ok ) {
				return $bad_code();
			}
		}

		delete_transient( $key );
		delete_transient( $key . '_fails' );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		wp_set_current_user( $user->ID );
		do_action( 'wp_login', $user->user_login, $user ); // jak wp_signon()

		return new WP_REST_Response( array( 'success' => true, 'redirect' => remove_query_arg( array() ) ), 200 );
	}

	public function logout( WP_REST_Request $req ) {
		wp_logout();
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	public function update_account( WP_REST_Request $req ) {
		$user_id = get_current_user_id();

		$first_name = sanitize_text_field( $req->get_param( 'first_name' ) );
		$last_name  = sanitize_text_field( $req->get_param( 'last_name' ) );
		$email      = sanitize_email( $req->get_param( 'email' ) );
		$birthdate  = sanitize_text_field( $req->get_param( 'birthdate' ) );

		if ( $email && ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nieprawidłowy adres e-mail.', 'gastroflowx-hub' ) ), 400 );
		}

		$update = array( 'ID' => $user_id );
		if ( $first_name ) {
			$update['first_name'] = $first_name;
		}
		if ( $last_name ) {
			$update['last_name'] = $last_name;
		}
		if ( $email ) {
			$existing = email_exists( $email );
			if ( $existing && (int) $existing !== (int) $user_id ) {
				return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Ten adres e-mail jest już używany.', 'gastroflowx-hub' ) ), 400 );
			}
			$update['user_email'] = $email;
		}

		$result = wp_update_user( $update );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => $result->get_error_message() ), 400 );
		}

		if ( $birthdate ) {
			update_user_meta( $user_id, 'user_birth_date', $birthdate ); // Wspólne pole z wtyczką Napiwków.
		}
		// Zgoda na pokazywanie urodzin w module Urodziny i powiadomienia urodzinowe.
		// Nie dotyczy Napiwków — tam data urodzenia służy do ulgi podatkowej niezależnie od zgody.
		if ( null !== $req->get_param( 'birthday_consent' ) && class_exists( 'GFX_Birthdays' ) ) {
			GFX_Birthdays::set_consent( $user_id, in_array( (string) $req->get_param( 'birthday_consent' ), array( '1', 'true', 'on' ), true ) );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Upload zdjęcia profilowego (self-service, dla dowolnego zalogowanego
	 * użytkownika, niezależnie od jego capability 'upload_files' — to jest
	 * świadome: pracownicy bez uprawnień do biblioteki mediów WP mogą mimo
	 * to ustawić swoje własne zdjęcie profilowe w panelu). Walidacja typu
	 * pliku i rozmiaru wykonywana ręcznie zamiast polegać na uprawnieniach.
	 */
	public function upload_avatar( WP_REST_Request $req ) {
		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nie przesłano pliku.', 'gastroflowx-hub' ) ), 400 );
		}

		$file = $_FILES['file'];

		if ( ! empty( $file['error'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Błąd podczas przesyłania pliku.', 'gastroflowx-hub' ) ), 400 );
		}

		$max_size = 3 * 1024 * 1024; // 3 MB
		if ( $file['size'] > $max_size ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Plik jest za duży (maks. 3 MB).', 'gastroflowx-hub' ) ), 400 );
		}

		$allowed_mimes = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'gif'      => 'image/gif',
		);

		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nieobsługiwany format pliku. Dozwolone: JPG, PNG, WEBP, GIF.', 'gastroflowx-hub' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$overrides = array( 'test_form' => false, 'mimes' => $allowed_mimes );
		$moved     = wp_handle_upload( $file, $overrides );

		if ( isset( $moved['error'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => $moved['error'] ), 400 );
		}

		$user_id    = get_current_user_id();
		$attachment = array(
			'post_mime_type' => $moved['type'],
			'post_title'     => sanitize_file_name( basename( $moved['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $moved['file'] );
		if ( is_wp_error( $attach_id ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Nie udało się zapisać zdjęcia.', 'gastroflowx-hub' ) ), 500 );
		}

		$attach_data = wp_generate_attachment_metadata( $attach_id, $moved['file'] );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		$old_id = (int) get_user_meta( $user_id, 'gfx_avatar_id', true );
		update_user_meta( $user_id, 'gfx_avatar_id', $attach_id );

		if ( $old_id && $old_id !== $attach_id ) {
			wp_delete_attachment( $old_id, true ); // Sprzątamy poprzednie zdjęcie profilowe.
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'url'     => wp_get_attachment_image_url( $attach_id, 'thumbnail' ),
			),
			200
		);
	}
}
