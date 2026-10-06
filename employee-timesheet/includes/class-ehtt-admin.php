<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strona w WP-Admin, na której osadzone jest SPA (dostępne tylko dla osób
 * z uprawnieniem zarządzania - dla pracowników przewidziany jest shortcode
 * do osadzenia na dowolnej stronie frontowej, np. w intranecie).
 */
class EHTT_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Ewidencja godzin', 'employee-timesheet' ),
			__( 'Ewidencja godzin', 'employee-timesheet' ),
			'read', // każdy zalogowany widzi swoje dane; kontrolę szczegółową robi REST API
			'employee-timesheet',
			array( __CLASS__, 'render_page' ),
			'dashicons-clock',
			26
		);
	}

	public static function render_page() {
		wp_enqueue_script( 'vue3' );
		wp_enqueue_script( 'ehtt-app' );
		wp_enqueue_style( 'ehtt-app-style' );
		self::localize();
		echo '<div id="ehtt-app" class="ehtt-admin-wrap"></div>';
	}

	public static function localize() {
		wp_localize_script( 'ehtt-app', 'EHTT_CONFIG', array(
			'restUrl' => esc_url_raw( rest_url( 'ehtt/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'userId'  => get_current_user_id(),
			'i18n'    => array(
				'appTitle' => __( 'Ewidencja godzin i napiwków', 'employee-timesheet' ),
			),
		) );
	}
}
EHTT_Admin::init();
