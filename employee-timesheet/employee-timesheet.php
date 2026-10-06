<?php
/**
 * Plugin Name:       Employee Timesheet & Tips
 * Plugin URI:        https://example.com/employee-timesheet
 * Description:       Ewidencja godzin pracy pracowników (SPA na Vue 3): rejestracja godzin z zaokrągleniem do 15 minut, stawki godzinowe (ogólna + zmiana na dany dzień), integracja z grafikiem (sugerowana godzina rozpoczęcia) i z wtyczką napiwków, podsumowania wypłat oraz widok kalendarza.
 * Version:           1.1.0
 * Author:             Senior Full-Stack Dev
 * Text Domain:        employee-timesheet
 * Domain Path:        /languages
 * Requires at least:  5.9
 * Requires PHP:       7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Brak dostępu bezpośredniego.
}

define( 'EHTT_VERSION', '1.1.0' );
define( 'EHTT_PLUGIN_FILE', __FILE__ );
define( 'EHTT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EHTT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EHTT_DB_VERSION', '1.0.0' );

/** Nazwa capability, którą mają administratorzy / kierownicy zmiany. */
define( 'EHTT_MANAGE_CAP', 'ehtt_manage_timesheets' );

require_once EHTT_PLUGIN_DIR . 'includes/class-ehtt-db.php';
require_once EHTT_PLUGIN_DIR . 'includes/class-ehtt-helpers.php';
require_once EHTT_PLUGIN_DIR . 'includes/class-ehtt-integrations.php';
require_once EHTT_PLUGIN_DIR . 'includes/class-ehtt-rest.php';
require_once EHTT_PLUGIN_DIR . 'includes/class-ehtt-admin.php';
require_once EHTT_PLUGIN_DIR . 'includes/class-ehtt-shortcode.php';

/**
 * Aktywacja wtyczki: tworzy tabele i domyślne opcje oraz capability.
 */
function ehtt_activate_plugin() {
	EHTT_DB::create_tables();

	$defaults = array(
		'global_hourly_rate'     => 30.00,
		'round_minutes'          => 15,
		'kitchen_deduction_pct'  => 3.0,
		'bar_deduction_pct'      => 3.0,
		'currency'               => 'zł',
		'tips_payment_method'    => 'cash', // cash | transfer
	);
	if ( false === get_option( 'ehtt_settings' ) ) {
		add_option( 'ehtt_settings', $defaults );
	}

	// Capability dla administratora oraz roli "manager", jeśli istnieje.
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( EHTT_MANAGE_CAP );
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'ehtt_activate_plugin' );

/**
 * Deaktywacja - nie usuwamy danych, tylko czyścimy reguły przepisywania.
 */
function ehtt_deactivate_plugin() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ehtt_deactivate_plugin' );

/**
 * Ładowanie tłumaczeń.
 */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'employee-timesheet', false, dirname( plugin_basename( EHTT_PLUGIN_FILE ) ) . '/languages' );
} );

/**
 * Rejestracja skryptów/stylów SPA (ładowane tylko tam, gdzie jest shortcode/strona admina).
 */
function ehtt_register_assets() {
	// Vue 3 hostowane LOKALNIE w wtyczce (nie z CDN unpkg.com) - żeby działanie
	// aplikacji nie zależało od dostępności zewnętrznego serwera. Chwilowa
	// niedostępność CDN (blokada przez firewall/ad-block, przerwa w działaniu
	// CDN, wolne połączenie z timeoutem) była najbardziej prawdopodobną
	// przyczyną sporadycznego "gubienia się" wyglądu/działania aplikacji.
	wp_register_script(
		'vue3',
		EHTT_PLUGIN_URL . 'assets/vendor/vue/vue.global.prod.js',
		array(),
		'3.4.21',
		true
	);

	// Font Awesome hostowane LOKALNIE w wtyczce (nie z CDN cdnjs.cloudflare.com)
	// - z tego samego powodu co Vue wyżej.
	wp_register_style(
		'ehtt-fontawesome',
		EHTT_PLUGIN_URL . 'assets/vendor/fontawesome/css/all.min.css',
		array(),
		'6.0.0'
	);

	wp_register_script(
		'ehtt-app',
		EHTT_PLUGIN_URL . 'assets/js/app.js',
		array( 'vue3', 'wp-api-fetch' ),
		EHTT_VERSION,
		true
	);

	wp_register_style(
		'ehtt-app-style',
		EHTT_PLUGIN_URL . 'assets/css/app.css',
		array( 'ehtt-fontawesome' ),
		EHTT_VERSION
	);
}
add_action( 'init', 'ehtt_register_assets' );
