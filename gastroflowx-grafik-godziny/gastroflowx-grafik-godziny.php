<?php
/**
 * Plugin Name:       GastroFlowx — Grafik → Godziny
 * Description:       Łącznik: Ewidencja Godzin (employee-timesheet) podpowiada godzinę rozpoczęcia pracy na podstawie opublikowanej zmiany z Grafiku Pracy (restaurant-scheduler).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            GastroFlowx
 * Text Domain:       gastroflowx-grafik-godziny
 *
 * Kolejność źródeł podpowiedzi:
 *   1. ręczny wpis kierownika w Ewidencji Godzin (sekcja „Zarządzanie”) —
 *      świadome nadpisanie zawsze wygrywa,
 *   2. zmiana z Grafiku (ten łącznik),
 *   3. brak podpowiedzi.
 * Ewidencja Godzin dostarcza wartość ręczną filtrem `ehtt_suggested_start_time`
 * z priorytetem 100, więc ten łącznik działa później (110) i wypełnia tylko
 * puste miejsce. Żadna z wtyczek nie wymaga zmian w kodzie.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFX_Grafik_Godziny {

	public static function init() {
		add_filter( 'ehtt_suggested_start_time', array( __CLASS__, 'suggest_start_from_schedule' ), 110, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'missing_dependency_notice' ) );
	}

	/**
	 * @param string|null $suggested Wartość z wcześniejszych filtrów (ręczny wpis).
	 * @param int         $user_id
	 * @param string      $date      RRRR-MM-DD
	 * @return string|null "HH:MM:SS" najwcześniejszej zmiany pracownika tego dnia.
	 */
	public static function suggest_start_from_schedule( $suggested, $user_id, $date ) {
		if ( null !== $suggested && '' !== $suggested ) {
			return $suggested;
		}
		if ( ! defined( 'RS_TABLE_SHIFTS' ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return $suggested;
		}

		global $wpdb;
		$table = $wpdb->prefix . RS_TABLE_SHIFTS;

		// Tylko zmiany widoczne dla pracownika: bez wersji roboczych z
		// auto-generowania i bez znaczników „Nieobecny” / „Dostępny”
		// (te same reguły co RS_Shift_Status::is_real_visible_shift()).
		$start = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT start_time FROM {$table}
				 WHERE user_id = %d AND shift_date = %s
				   AND status NOT IN ('draft', 'absent', 'available')
				 ORDER BY start_time ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nazwa tabeli ze stałej.
				(int) $user_id,
				$date
			)
		);

		return $start ? $start : $suggested;
	}

	public static function missing_dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$missing = array();
		if ( ! defined( 'RS_TABLE_SHIFTS' ) ) {
			$missing[] = 'Grafik Pracy (restaurant-scheduler)';
		}
		if ( ! class_exists( 'EHTT_Integrations' ) ) {
			$missing[] = 'Ewidencja Godzin (employee-timesheet)';
		}
		if ( $missing ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html( 'GastroFlowx — Grafik → Godziny: aby łącznik działał, aktywuj: ' . implode( ', ', $missing ) . '.' )
				. '</p></div>';
		}
	}
}

GFX_Grafik_Godziny::init();
