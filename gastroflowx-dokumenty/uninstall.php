<?php
/**
 * Odinstalowanie Dokumenty GastroFlowX. Dane (w tym pliki Archiwum — raporty
 * finansowe) są usuwane TYLKO, jeśli administrator zaznaczył to w
 * wp-admin → Dokumenty → „Odinstalowanie”.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$gfxdok_access = get_option( 'gfxdok_access', array() );
if ( empty( $gfxdok_access['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gfxdok_images" );  // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gfxdok_reports" ); // phpcs:ignore

delete_option( 'gfxdok_settings' );
delete_option( 'gfxdok_access' );
delete_option( 'gfxdok_db_version' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-gfxdok-storage.php';
GFXDOK_Storage::remove_base_dir();
