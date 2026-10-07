<?php
/**
 * Plugin Name:       GastroFlowx — Panel Pracownika (SPA Hub)
 * Plugin URI:        https://gastroflowx.local
 * Description:       Spina wtyczki: Grafik pracy (restaurant-scheduler), Napiwki (system-napiwkow-spa), Lunch (weranda-lunch), Kolorowanki (weranda-kolorowanki) i Godziny pracy (employee-timesheet) w jeden spójny panel SPA z logowaniem, dostępem opartym o role oraz ustawieniami marki (nazwa, logo).
 * Version:           2.4.0
 * Author:            Senior Full-Stack Dev
 * Text Domain:       gastroflowx-hub
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFX_VERSION', '2.4.0' );
define( 'GFX_DB_VERSION', '2.3.1' );
define( 'GFX_PLUGIN_FILE', __FILE__ );
define( 'GFX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GFX_REST_NS', 'gfx/v1' );

require_once GFX_PLUGIN_DIR . 'includes/class-gfx-modules.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-consent.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-consent-admin.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-settings.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-auth.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-access.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-birthdays.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-push-log.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-push-consent.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-push-templates.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-fcm.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-push-admin.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-pwa.php';
require_once GFX_PLUGIN_DIR . 'includes/class-gfx-shell.php';

/**
 * Aktywacja: domyślne opcje + tabela historii wysyłki push.
 */
function gfx_activate_plugin() {
	if ( false === get_option( 'gfx_restaurant_name' ) ) {
		update_option( 'gfx_restaurant_name', 'GastroFlowx' );
	}
	if ( false === get_option( 'gfx_logo_id' ) ) {
		update_option( 'gfx_logo_id', 0 );
	}
	if ( false === get_option( 'gfx_permissions' ) ) {
		// Administrator ma zawsze pełny dostęp (wymuszane też w kodzie).
		update_option(
			'gfx_permissions',
			array(
				'administrator' => array( 'lunch' => 1, 'colors' => 1, 'tips' => 1, 'schedule' => 1, 'hours' => 1, 'birthdays' => 1 ),
			)
		);
	}
	if ( class_exists( 'GFX_Push' ) ) {
		GFX_Push::generate_service_worker_file();
	}
	if ( class_exists( 'GFX_Push_Log' ) ) {
		GFX_Push_Log::install();
	}
	if ( class_exists( 'GFX_Consent' ) ) {
		GFX_Consent::install_table();
	}
	if ( false === get_option( 'gfx_privacy_policy_version' ) ) {
		update_option( 'gfx_privacy_policy_version', '1.0' );
	}
	if ( false === get_option( 'gfx_privacy_policy_url' ) ) {
		update_option( 'gfx_privacy_policy_url', '' );
	}
	// Reguła przekierowania dla /manifest.webmanifest (PWA) musi zostać
	// zapisana do bazy - bez tego zwracałoby 404.
	if ( class_exists( 'GFX_PWA' ) ) {
		( new GFX_PWA() )->register_rewrite();
	}
	flush_rewrite_rules( false );
	update_option( 'gfx_db_version', GFX_DB_VERSION );
}
register_activation_hook( __FILE__, 'gfx_activate_plugin' );

/**
 * Migracja automatyczna przy podniesieniu wersji wtyczki — nie wymaga
 * ręcznej dezaktywacji/aktywacji, żeby nowa tabela historii pojawiła się
 * także na instalacjach zaktualizowanych "na żywo" (np. przez FTP/git pull).
 */
function gfx_maybe_upgrade_db() {
	if ( get_option( 'gfx_db_version' ) !== GFX_DB_VERSION ) {
		if ( class_exists( 'GFX_Push_Log' ) ) {
			GFX_Push_Log::install();
		}
		if ( class_exists( 'GFX_Consent' ) ) {
			GFX_Consent::install_table();
		}
		if ( false === get_option( 'gfx_privacy_policy_version' ) ) {
			update_option( 'gfx_privacy_policy_version', '1.0' );
		}
		if ( false === get_option( 'gfx_privacy_policy_url' ) ) {
			update_option( 'gfx_privacy_policy_url', '' );
		}
		if ( class_exists( 'GFX_Push' ) ) {
			// Sprząta pliki service workera pozostałe po integracji z
			// OneSignal (od wersji 2.0.0 nieużywane) - bez tego stara
			// przeglądarka, która wcześniej je zarejestrowała, mogłaby
			// wciąż odpytywać OneSignal (w tym ich domyślne powiadomienie
			// "Thanks for subscribing!").
			GFX_Push::remove_legacy_onesignal_files();
			// Regeneruje firebase-messaging-sw.js - jego treść się zmieniła
			// (payload "data" zamiast "notification", żeby powiadomienia
			// nie wyświetlały się podwójnie), więc istniejące instalacje
			// muszą dostać nową wersję pliku automatycznie, bez potrzeby
			// ponownego zapisywania ustawień integracji.
			GFX_Push::generate_service_worker_file();
		}
		// Reguła przekierowania dla /manifest.webmanifest (PWA, od 2.1.0)
		// musi trafić do bazy również na instalacjach aktualizowanych "na
		// żywo" (np. przez FTP), nie tylko przy świeżej aktywacji. UWAGA:
		// ta funkcja odpala się na plugins_loaded - zbyt wcześnie, żeby
		// add_rewrite_rule() (wywoływane na init) już zarejestrowało regułę,
		// więc flush musi poczekać na init (i to na priorytet PO tym, na
		// którym GFX_PWA rejestruje regułę - domyślnie 10).
		add_action( 'init', 'flush_rewrite_rules', 20 );
		update_option( 'gfx_db_version', GFX_DB_VERSION );
	}
}
add_action( 'plugins_loaded', 'gfx_maybe_upgrade_db', 5 );

function gfx_deactivate_plugin() {
	if ( class_exists( 'GFX_Push' ) ) {
		GFX_Push::clear_cron();
	}
	flush_rewrite_rules( false );
}
register_deactivation_hook( __FILE__, 'gfx_deactivate_plugin' );

/**
 * Inicjalizacja klas.
 */
function gfx_init_plugin() {
	new GFX_Settings();
	new GFX_Auth();
	new GFX_Access();
	new GFX_Consent();
	new GFX_Consent_Admin();
	new GFX_Push_Consent();
	new GFX_Push();
	new GFX_Push_Admin();
	new GFX_PWA();
	new GFX_Shell();
}
add_action( 'plugins_loaded', 'gfx_init_plugin' );
