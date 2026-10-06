<?php
// Uruchamiany tylko przez WordPress podczas odinstalowania wtyczki.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'ehtt_time_entries',
	$wpdb->prefix . 'ehtt_rate_overrides',
	$wpdb->prefix . 'ehtt_schedule',
	$wpdb->prefix . 'ehtt_tips',
);

/**
 * Usunięcie tabel następuje TYLKO jeśli w wp-config.php zdefiniowano:
 * define( 'EHTT_REMOVE_DATA_ON_UNINSTALL', true );
 * Domyślnie dane pozostają zachowane, aby uniknąć przypadkowej utraty
 * historii godzin i napiwków pracowników.
 */
if ( defined( 'EHTT_REMOVE_DATA_ON_UNINSTALL' ) && true === EHTT_REMOVE_DATA_ON_UNINSTALL ) {
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
	}
	delete_option( 'ehtt_settings' );
	delete_option( 'ehtt_db_version' );
}
