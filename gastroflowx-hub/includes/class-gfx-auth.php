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

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
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
