<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ukrywa pasek administracyjny WP i blokuje dostęp do /wp-admin/ dla
 * wszystkich ról poza administratorem. Pracownicy mają korzystać wyłącznie
 * z panelu [gastroflowx_app] na froncie.
 */
class GFX_Access {

	public function __construct() {
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_for_non_admins' ) );
		add_action( 'admin_init', array( $this, 'block_wp_admin_for_non_admins' ) );
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 10, 3 );
	}

	public function hide_admin_bar_for_non_admins( $show ) {
		if ( is_user_logged_in() && ! current_user_can( 'administrator' ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * admin-ajax.php również wywołuje 'admin_init', dlatego musimy je
	 * jawnie wykluczyć — inaczej żadna wtyczka (w tym nasza) nie mogłaby
	 * wykonywać zapytań AJAX dla pracowników.
	 */
	public function block_wp_admin_for_non_admins() {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( current_user_can( 'administrator' ) ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Po zalogowaniu (np. przez natywny /wp-login.php, awaryjnie) pracownicy
	 * zawsze trafiają na stronę główną zamiast do /wp-admin/. Nasz własny
	 * formularz logowania w [gastroflowx_app] i tak przeładowuje bieżącą
	 * stronę, więc ten filtr jest głównie zabezpieczeniem na wszelki wypadek.
	 */
	public function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || empty( $user->roles ) ) {
			return $redirect_to;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return $redirect_to;
		}
		return home_url( '/' );
	}
}
