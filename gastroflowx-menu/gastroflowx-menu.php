<?php
/**
 * Plugin Name:       Menu GastroFlowX
 * Plugin URI:        https://gastroflowx.pl
 * Description:       Moduł GastroFlowx: baza dań (PL/EN), kreator menu, „Wersje Standardowe” i generowanie PDF (Dompdf) — w całości z frontu. Shortcode: [gastroflowx_menu_app]
 * Version:           1.6.2
 * Author:            GastroFlowx
 * Text Domain:       gastroflowx-menu
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFXM_VERSION', '1.6.2' );
define( 'GFXM_DB_VERSION', '1.6.1' );
define( 'GFXM_FILE', __FILE__ );
define( 'GFXM_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFXM_URL', plugin_dir_url( __FILE__ ) );
define( 'GFXM_REST_NS', 'gastroflowx-menu/v1' );
define( 'GFXM_SHORTCODE', 'gastroflowx_menu_app' );
define( 'GFXM_CAP', 'gfxm_manage_menu' );

require_once GFXM_DIR . 'includes/class-gfxm-install.php';
require_once GFXM_DIR . 'includes/class-gfxm-data.php';
require_once GFXM_DIR . 'includes/class-gfxm-access.php';
require_once GFXM_DIR . 'includes/class-gfxm-pdf.php';
require_once GFXM_DIR . 'includes/class-gfxm-rest.php';
require_once GFXM_DIR . 'includes/class-gfxm-shortcode.php';
require_once GFXM_DIR . 'includes/class-gfxm-admin.php';

register_activation_hook( __FILE__, array( 'GFXM_Install', 'activate' ) );

/**
 * Ostatnia linia obrony (jak w Weranda Lunch): gdy podczas obsługi NASZEGO
 * endpointu REST wystąpi fatal, którego nie da się złapać try/catch
 * (np. brak pamięci przy renderze Dompdf), zwracamy czysty JSON zamiast
 * strony HTML „Wystąpił krytyczny błąd”, która psuje parsowanie we froncie.
 */
function gfxm_fatal_shutdown_handler() {
	$error = error_get_last();
	if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
		return;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ( strpos( $uri, GFXM_REST_NS ) === false && strpos( $uri, rawurlencode( GFXM_REST_NS ) ) === false ) {
		return;
	}
	if ( ! headers_sent() ) {
		header_remove();
		status_header( 500 );
		header( 'Content-Type: application/json; charset=UTF-8' );
	}
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	echo wp_json_encode(
		array(
			'code'    => 'gfxm_fatal',
			'message' => 'Krytyczny błąd PHP: ' . $error['message'] . ' (' . basename( $error['file'] ) . ':' . $error['line'] . ')',
			'data'    => array( 'status' => 500 ),
		)
	);
	exit;
}
register_shutdown_function( 'gfxm_fatal_shutdown_handler' );

function gfxm_init_plugin() {
	load_plugin_textdomain( 'gastroflowx-menu', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	add_action( 'init', array( 'GFXM_Install', 'maybe_upgrade' ), 20 );
	GFXM_Access::init();
	GFXM_Rest::init();
	GFXM_Shortcode::init();
	GFXM_Admin::init();
}
add_action( 'plugins_loaded', 'gfxm_init_plugin' );
