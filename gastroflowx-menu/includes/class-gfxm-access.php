<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uprawnienia modułu + blokada wp-admin (jak w reszcie systemu GastroFlowx).
 *
 * Dostęp ma: administrator, KAŻDY z ról wybranych w „GastroFlowx → Menu
 * (dostęp)” (domyślnie: menu_manager oraz manager, jeśli istnieje) oraz
 * każdy z uprawnieniem gfxm_manage_menu. Widoczność kafelka w panelu Huba
 * i tak kontroluje macierz „Dostęp ról” Huba — tu pilnujemy danych/REST.
 */
class GFXM_Access {

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_upload_for_module_users' ), 10, 4 );

		// Hub (GFX_Access) już blokuje wp-admin; dublujemy tylko, gdy Hub
		// jest wyłączony — żeby moduł działał bezpiecznie także samodzielnie.
		if ( ! class_exists( 'GFX_Access' ) ) {
			add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
			add_action( 'admin_init', array( __CLASS__, 'block_wp_admin' ) );
		}
	}

	public static function allowed_roles() {
		$roles = get_option( 'gfxm_roles', array( 'menu_manager' ) );
		return is_array( $roles ) ? array_values( array_map( 'sanitize_key', $roles ) ) : array();
	}

	public static function is_admin_user( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && $user->exists() && in_array( 'administrator', (array) $user->roles, true );
	}

	/** Czy (bieżący) użytkownik może korzystać z modułu Menu. */
	public static function user_has_access( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		$ok = self::is_admin_user( $user )
			|| (bool) array_intersect( (array) $user->roles, self::allowed_roles() )
			|| ! empty( $user->allcaps[ GFXM_CAP ] );

		/** Filtr dla integracji (np. gdy Hub doda własną logikę uprawnień). */
		return (bool) apply_filters( 'gfxm_user_has_access', $ok, $user );
	}

	/**
	 * Natywny Media Uploader WP wymaga uprawnienia upload_files. Dajemy je
	 * dynamicznie tylko użytkownikom modułu (bez trwałej modyfikacji ról).
	 * Upload z modala idzie przez async-upload.php z DOING_AJAX, więc blokada
	 * wp-admin Huba go nie przechwytuje.
	 */
	public static function grant_upload_for_module_users( $allcaps, $caps, $args, $user ) {
		if ( in_array( 'upload_files', (array) $caps, true ) && empty( $allcaps['upload_files'] ) ) {
			static $checking = false;
			if ( $checking ) {
				return $allcaps;
			}
			$checking = true;
			if ( $user instanceof WP_User && (bool) array_intersect( (array) $user->roles, self::allowed_roles() ) ) {
				$allcaps['upload_files'] = true;
			}
			$checking = false;
		}
		return $allcaps;
	}

	public static function hide_admin_bar( $show ) {
		if ( is_user_logged_in() && ! current_user_can( 'administrator' ) ) {
			return false;
		}
		return $show;
	}

	public static function block_wp_admin() {
		if ( wp_doing_ajax() || current_user_can( 'administrator' ) ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}
