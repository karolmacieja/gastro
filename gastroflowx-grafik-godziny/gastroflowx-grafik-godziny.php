<?php
/**
 * Plugin Name:       GastroFlowx — Grafik i Napiwki → Godziny
 * Description:       Łącznik dla Ewidencji Godzin (employee-timesheet): podpowiada godzinę rozpoczęcia z Grafiku Pracy (restaurant-scheduler) i pobiera napiwki dnia z Systemu Napiwków (system-napiwkow-spa).
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            GastroFlowx
 * Text Domain:       gastroflowx-grafik-godziny
 *
 * Kolejność źródeł (dla godziny startu i dla napiwków):
 *   1. ręczny wpis kierownika w Ewidencji Godzin (sekcja „Zarządzanie”) —
 *      świadome nadpisanie zawsze wygrywa,
 *   2. Grafik / Napiwki (ten łącznik),
 *   3. brak danych.
 * Ewidencja dostarcza wartości ręczne filtrami `ehtt_suggested_start_time`
 * i `ehtt_daily_tips_amount` z priorytetem 100, więc łącznik działa później
 * (110) i wypełnia tylko puste miejsce. Żadna z wtyczek nie wymaga zmian.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFX_Grafik_Godziny {

	public static function init() {
		add_filter( 'ehtt_suggested_start_time', array( __CLASS__, 'suggest_start_from_schedule' ), 110, 3 );
		add_filter( 'ehtt_daily_tips_amount', array( __CLASS__, 'tips_from_napiwki' ), 110, 3 );

		// Kwota z Napiwków jest już PO podziale na bar i kuchnię (i po podatku),
		// więc Ewidencja nie może odejmować swoich procentów drugi raz.
		add_filter( 'option_ehtt_settings', array( __CLASS__, 'disable_double_deduction' ) );
		add_filter( 'default_option_ehtt_settings', array( __CLASS__, 'disable_double_deduction' ) );

		add_action( 'admin_notices', array( __CLASS__, 'missing_dependency_notice' ) );
	}

	/* ---------------------------------------------------------------
	 * GRAFIK → godzina rozpoczęcia
	 * ------------------------------------------------------------- */

	/**
	 * @return string|null "HH:MM:SS" najwcześniejszej opublikowanej zmiany tego dnia.
	 */
	public static function suggest_start_from_schedule( $suggested, $user_id, $date ) {
		if ( null !== $suggested && '' !== $suggested ) {
			return $suggested;
		}
		if ( ! defined( 'RS_TABLE_SHIFTS' ) || ! self::valid_date( $date ) ) {
			return $suggested;
		}

		global $wpdb;
		$table = $wpdb->prefix . RS_TABLE_SHIFTS;

		// Bez wersji roboczych z auto-generowania i bez znaczników
		// „Nieobecny” / „Dostępny” (jak RS_Shift_Status::is_real_visible_shift()).
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

	/* ---------------------------------------------------------------
	 * NAPIWKI → napiwki dnia
	 * ------------------------------------------------------------- */

	public static function napiwki_active() {
		return function_exists( 'snspa_tax_settings' )
			&& function_exists( 'snspa_is_tax_exempt' )
			&& function_exists( 'snspa_get_day_percent_overrides' )
			&& function_exists( 'snspa_percent_from_day_map' )
			&& function_exists( 'snspa_cash100_from_row' );
	}

	/**
	 * Wypłata napiwków danej osoby za dany dzień, policzona tak samo jak
	 * w module Napiwków:
	 *  - jako kelner: (karta netto + serwis netto + gotówka 100%) minus pule
	 *    baru i kuchni wg procentów obowiązujących tego dnia (z wyjątkami),
	 *  - jako barman / kucharz / pomoc: wyliczona kwota z gotówki, karty
	 *    i serwisu (calc_cash + calc_card + calc_service).
	 * Osoba może mieć oba udziały tego samego dnia (np. kelner pomagający
	 * na barze) — wtedy kwoty się sumują.
	 *
	 * @return float|null null, gdy w Napiwkach nie ma żadnych danych tej osoby z tego dnia.
	 */
	public static function tips_from_napiwki( $amount, $user_id, $date ) {
		if ( null !== $amount ) {
			return $amount;
		}
		if ( ! self::napiwki_active() || ! self::valid_date( $date ) ) {
			return $amount;
		}

		$user_id = (int) $user_id;
		$waiter  = self::waiter_payout( $user_id, $date );
		$staff   = self::staff_payout( $user_id, $date );

		if ( null === $waiter && null === $staff ) {
			return $amount;
		}
		return round( (float) $waiter + (float) $staff, 2 );
	}

	private static function waiter_payout( $user_id, $date ) {
		global $wpdb;
		$p = $wpdb->prefix;

		$card     = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}sn_waiter_tips WHERE waiter_id = %d AND date = %s", $user_id, $date ) );
		$service  = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}sn_waiter_service WHERE waiter_id = %d AND date = %s", $user_id, $date ) );
		$cash_bar = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}sn_waiter_cash WHERE waiter_id = %d AND date = %s AND dept = 'bar'", $user_id, $date ) );
		$cash_ktc = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}sn_waiter_cash WHERE waiter_id = %d AND date = %s AND dept = 'kuchnia'", $user_id, $date ) );

		if ( null === $card && null === $service && null === $cash_bar && null === $cash_ktc ) {
			return null;
		}

		$tax    = snspa_tax_settings();
		$exempt = snspa_is_tax_exempt( $user_id, $date );
		$k_net  = ( $tax['cardActive'] && ! $exempt ) ? (float) $card * ( 1 - $tax['rate'] ) : (float) $card;
		$s_net  = ( $tax['servActive'] && ! $exempt ) ? (float) $service * ( 1 - $tax['rate'] ) : (float) $service;

		$pct     = snspa_percent_from_day_map( snspa_get_day_percent_overrides( $date ), $user_id );
		$cash100 = snspa_cash100_from_row( (float) $cash_bar, (float) $cash_ktc, $pct['card']['bar'], $pct['card']['kitchen'] );

		// Karta i gotówka — procentem KARTY, serwis — procentem SERWISU (jak w Napiwkach).
		$card_and_cash = $k_net + $cash100;
		$pool_bar      = $card_and_cash * $pct['card']['bar'] + $s_net * $pct['service']['bar'];
		$pool_ktc      = $card_and_cash * $pct['card']['kitchen'] + $s_net * $pct['service']['kitchen'];

		return ( $k_net + $s_net + $cash100 ) - $pool_bar - $pool_ktc;
	}

	private static function staff_payout( $user_id, $date ) {
		global $wpdb;
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(calc_cash + calc_card + calc_service) FROM {$wpdb->prefix}sn_staff_work WHERE user_id = %d AND date = %s",
				$user_id,
				$date
			)
		);
		return null === $sum ? null : (float) $sum;
	}

	public static function disable_double_deduction( $value ) {
		if ( ! self::napiwki_active() ) {
			return $value;
		}
		$value                          = is_array( $value ) ? $value : array();
		$value['kitchen_deduction_pct'] = 0;
		$value['bar_deduction_pct']     = 0;
		return $value;
	}

	/* ---------------------------------------------------------------
	 * Pomocnicze
	 * ------------------------------------------------------------- */

	private static function valid_date( $date ) {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date );
	}

	public static function missing_dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$missing = array();
		if ( ! class_exists( 'EHTT_Integrations' ) ) {
			$missing[] = 'Ewidencja Godzin (employee-timesheet)';
		}
		if ( ! defined( 'RS_TABLE_SHIFTS' ) ) {
			$missing[] = 'Grafik Pracy (restaurant-scheduler) — bez niego nie ma podpowiedzi godziny startu';
		}
		if ( ! self::napiwki_active() ) {
			$missing[] = 'System Napiwków (system-napiwkow-spa) — bez niego napiwki wpisuje się ręcznie';
		}
		if ( $missing ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html( 'GastroFlowx — Grafik i Napiwki → Godziny: nieaktywne: ' . implode( '; ', $missing ) . '.' )
				. '</p></div>';
		}
	}
}

GFX_Grafik_Godziny::init();
