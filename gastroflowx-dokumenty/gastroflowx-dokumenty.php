<?php
/**
 * Plugin Name:       Dokumenty GastroFlowX
 * Plugin URI:        https://gastroflowx.pl/
 * Description:       Moduł systemu GastroFlowx: zbiorcze raporty dobowe z utargu (zdjęcia raportów z kasy fiskalnej i terminala) składane w jeden plik PDF bezpośrednio w przeglądarce. Kalendarz dni, kompresja zdjęć (Canvas API), zmiana kolejności stron (przeciągnij i upuść), opcjonalne Archiwum. Shortcode: [gastroflowx_dokumenty_app]
 * Version:           1.0.2
 * Author:            GastroFlowX
 * Text Domain:       gastroflowx-dokumenty
 * Domain Path:       /languages
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * License:           GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFXDOK_VERSION', '1.0.2' );
define( 'GFXDOK_DB_VERSION', '1.0.0' );
define( 'GFXDOK_PLUGIN_FILE', __FILE__ );
define( 'GFXDOK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFXDOK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Nazwa shortcode'u — do wklejenia w GastroFlowx → Moduły. */
define( 'GFXDOK_SHORTCODE', 'gastroflowx_dokumenty_app' );

/** Namespace REST API. */
define( 'GFXDOK_REST_NS', 'gfx-dokumenty/v1' );

require_once GFXDOK_PLUGIN_DIR . 'includes/class-gfxdok-settings.php';
require_once GFXDOK_PLUGIN_DIR . 'includes/class-gfxdok-db.php';
require_once GFXDOK_PLUGIN_DIR . 'includes/class-gfxdok-storage.php';
require_once GFXDOK_PLUGIN_DIR . 'includes/class-gfxdok-rest.php';
require_once GFXDOK_PLUGIN_DIR . 'includes/class-gfxdok-shortcode.php';
require_once GFXDOK_PLUGIN_DIR . 'includes/class-gfxdok-admin.php';

/**
 * Aktywacja: tabele (dbDelta), domyślne opcje, zabezpieczony katalog na pliki.
 */
function gfxdok_activate() {
	GFXDOK_DB::create_tables();
	GFXDOK_Settings::install_defaults();
	GFXDOK_Storage::ensure_base_dir();
}
register_activation_hook( __FILE__, 'gfxdok_activate' );

/**
 * Aktualizacja schematu bazy po podmianie plików wtyczki (bez ponownej aktywacji).
 */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'gfxdok_db_version' ) !== GFXDOK_DB_VERSION ) {
		GFXDOK_DB::create_tables();
	}
	load_plugin_textdomain( 'gastroflowx-dokumenty', false, dirname( plugin_basename( GFXDOK_PLUGIN_FILE ) ) . '/languages' );
} );

/**
 * Rejestracja (NIE ładowanie) zasobów. Faktyczne wp_enqueue_* odbywa się
 * wyłącznie w callbacku shortcode'u — dzięki temu moduł działa zarówno jako
 * samodzielny shortcode, jak i osadzony dynamicznie przez do_shortcode()
 * wewnątrz [gastroflowx_app].
 */
function gfxdok_register_assets() {
	// Uchwyt "vue3" jest wspólny dla modułów GastroFlowx — jeśli inna wtyczka
	// zarejestrowała go wcześniej, WordPress zignoruje tę rejestrację.
	if ( ! wp_script_is( 'vue3', 'registered' ) ) {
		wp_register_script( 'vue3', 'https://unpkg.com/vue@3.4.21/dist/vue.global.prod.js', array(), '3.4.21', true );
	}
	wp_register_script( 'gfxdok-jspdf', 'https://unpkg.com/jspdf@2.5.2/dist/jspdf.umd.min.js', array(), '2.5.2', true );
	wp_register_style( 'gfxdok-fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1' );

	wp_register_style( 'gfxdok-style', GFXDOK_PLUGIN_URL . 'assets/style.css', array(), GFXDOK_VERSION );
	wp_register_script( 'gfxdok-app', GFXDOK_PLUGIN_URL . 'assets/app.js', array( 'vue3', 'gfxdok-jspdf' ), GFXDOK_VERSION, true );
}
add_action( 'init', 'gfxdok_register_assets' );

/**
 * Fatal error w trakcie obsługi NASZEGO endpointu → czysty JSON zamiast
 * strony HTML "Wystąpił krytyczny błąd" (która psuje parsowanie we froncie).
 */
function gfxdok_fatal_shutdown_handler() {
	$error = error_get_last();
	if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
		return;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	if ( strpos( urldecode( $uri ), GFXDOK_REST_NS ) === false ) {
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
	echo wp_json_encode( array(
		'code'    => 'gfxdok_fatal',
		'message' => 'Krytyczny błąd PHP: ' . $error['message'] . ' (' . basename( $error['file'] ) . ':' . $error['line'] . ')',
		'data'    => array( 'status' => 500 ),
	) );
	exit;
}
register_shutdown_function( 'gfxdok_fatal_shutdown_handler' );
