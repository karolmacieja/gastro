<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [ehtt_timesheet] - osadza SPA na dowolnej stronie/wpisie frontowym.
 */
class EHTT_Shortcode {

	public static function init() {
		add_shortcode( 'ehtt_timesheet', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Zaloguj się, aby zobaczyć swoją ewidencję godzin.', 'employee-timesheet' ) . '</p>';
		}

		wp_enqueue_script( 'vue3' );
		wp_enqueue_script( 'ehtt-app' );
		wp_enqueue_style( 'ehtt-app-style' );
		EHTT_Admin::localize();

		return '<div id="ehtt-app" class="ehtt-frontend-wrap"></div>';
	}
}
EHTT_Shortcode::init();
