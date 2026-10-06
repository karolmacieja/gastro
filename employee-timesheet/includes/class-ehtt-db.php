<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warstwa dostępu do własnych tabel bazy danych.
 */
class EHTT_DB {

	public static function table_entries() {
		global $wpdb;
		return $wpdb->prefix . 'ehtt_time_entries';
	}

	public static function table_rate_overrides() {
		global $wpdb;
		return $wpdb->prefix . 'ehtt_rate_overrides';
	}

	public static function table_schedule() {
		global $wpdb;
		return $wpdb->prefix . 'ehtt_schedule';
	}

	public static function table_tips() {
		global $wpdb;
		return $wpdb->prefix . 'ehtt_tips';
	}

	/**
	 * Tworzy wszystkie wymagane tabele (idempotentnie, przez dbDelta).
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$entries = self::table_entries();
		$rates   = self::table_rate_overrides();
		$sched   = self::table_schedule();
		$tips    = self::table_tips();

		$sql = "CREATE TABLE {$entries} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			entry_date DATE NOT NULL,
			suggested_start TIME NULL,
			start_time TIME NOT NULL,
			end_time TIME NOT NULL,
			hours_decimal DECIMAL(5,2) NOT NULL DEFAULT 0,
			hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
			note VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_date (user_id, entry_date),
			KEY entry_date_idx (entry_date)
		) {$charset_collate};

		CREATE TABLE {$rates} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			override_date DATE NOT NULL,
			hourly_rate DECIMAL(10,2) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_date (user_id, override_date)
		) {$charset_collate};

		CREATE TABLE {$sched} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			schedule_date DATE NOT NULL,
			suggested_start TIME NOT NULL,
			suggested_end TIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_date (user_id, schedule_date)
		) {$charset_collate};

		CREATE TABLE {$tips} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			tip_date DATE NOT NULL,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY user_date (user_id, tip_date)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'ehtt_db_version', EHTT_DB_VERSION );
	}
}
