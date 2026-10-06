<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Źródła danych Ewidencji Godzin — wbudowane połączenie z Grafikiem Pracy
 * (restaurant-scheduler) i Systemem Napiwków (system-napiwkow-spa).
 *
 * GODZINY (podpowiedź rozpoczęcia i zakończenia), kolejność źródeł:
 *   1. ręczny wpis kierownika (tabela ehtt_schedule),
 *   2. Grafik — opublikowane zmiany pracownika z danego dnia
 *      (najwcześniejszy początek, najpóźniejszy koniec; bez wersji roboczych
 *      i znaczników „Nieobecny” / „Dostępny”),
 *   3. brak podpowiedzi.
 *
 * NAPIWKI (brutto → netto), kolejność źródeł:
 *   1. ręczny wpis kierownika (tabela ehtt_tips) — kwota brutto, od której
 *      Ewidencja odejmuje procenty z ustawień (kuchnia / bar),
 *   2. Napiwki — rozliczenie liczone tymi samymi wzorami co moduł Napiwków:
 *      kelner: brutto = karta + serwis + gotówka 100%, minus podatek,
 *      minus pula baru i kuchni (z wyjątkami procentowymi dnia) = netto;
 *      barman / kucharz / pomoc: udział z puli (gotówka + karta + serwis),
 *      brutto = netto, bo nic nie oddaje,
 *   3. brak napiwków.
 *
 * Każde źródło działa niezależnie — brak Grafiku lub Napiwków wyłącza
 * tylko jego część. Filtry `ehtt_suggested_start_time`, `ehtt_suggested_end_time`
 * i `ehtt_tips_breakdown` pozwalają nadpisać wynik innej wtyczce.
 */
class EHTT_Integrations {

	/* ---------------------------------------------------------------
	 * Dostępność modułów
	 * ------------------------------------------------------------- */

	public static function schedule_active() {
		return defined( 'RS_TABLE_SHIFTS' );
	}

	public static function tips_active() {
		return function_exists( 'snspa_tax_settings' )
			&& function_exists( 'snspa_is_tax_exempt' )
			&& function_exists( 'snspa_get_day_percent_overrides' )
			&& function_exists( 'snspa_percent_from_day_map' )
			&& function_exists( 'snspa_cash100_from_row' );
	}

	public static function status() {
		return array(
			'schedule' => self::schedule_active(),
			'tips'     => self::tips_active(),
		);
	}

	/* ---------------------------------------------------------------
	 * GODZINY
	 * ------------------------------------------------------------- */

	/**
	 * @return array{start:?string,end:?string,source:?string} godziny "HH:MM"
	 *         zaokrąglone do siatki, source: manual | grafik | null.
	 */
	public static function get_suggested_times( $user_id, $date ) {
		global $wpdb;
		$start  = null;
		$end    = null;
		$source = null;

		$table  = EHTT_DB::table_schedule();
		$manual = $wpdb->get_row(
			$wpdb->prepare( "SELECT suggested_start, suggested_end FROM {$table} WHERE user_id = %d AND schedule_date = %s", $user_id, $date ),
			ARRAY_A
		);
		if ( $manual && $manual['suggested_start'] ) {
			$start  = $manual['suggested_start'];
			$end    = $manual['suggested_end'];
			$source = 'manual';
		} elseif ( self::schedule_active() ) {
			$shifts = $wpdb->prefix . RS_TABLE_SHIFTS;
			$row    = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT MIN(start_time) AS s, MAX(end_time) AS e FROM {$shifts}
					 WHERE user_id = %d AND shift_date = %s
					   AND status NOT IN ('draft', 'absent', 'available')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nazwa tabeli ze stałej.
					$user_id,
					$date
				),
				ARRAY_A
			);
			if ( $row && $row['s'] ) {
				$start  = $row['s'];
				$end    = $row['e'];
				$source = 'grafik';
			}
		}

		$start = apply_filters( 'ehtt_suggested_start_time', $start, $user_id, $date );
		$end   = apply_filters( 'ehtt_suggested_end_time', $end, $user_id, $date );

		$step = (int) EHTT_Helpers::get_settings()['round_minutes'];
		return array(
			'start'  => self::normalize_time( $start, $step ),
			'end'    => self::normalize_time( $end, $step ),
			'source' => $source,
		);
	}

	/** Zgodność wsteczna. */
	public static function get_suggested_start_time( $user_id, $date ) {
		return self::get_suggested_times( $user_id, $date )['start'];
	}

	private static function normalize_time( $value, $step ) {
		if ( ! $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return $ts ? EHTT_Helpers::round_time_to_step( date( 'H:i', $ts ), $step ? $step : 15 ) : null;
	}

	/* ---------------------------------------------------------------
	 * NAPIWKI
	 * ------------------------------------------------------------- */

	/**
	 * Pełne rozliczenie napiwków osoby za dzień.
	 *
	 * @return array{gross:float,tax:float,bar_cut:float,kitchen_cut:float,net:float,
	 *               source:?string,card:float,service:float,cash100:float,share:float}
	 */
	public static function get_tips_breakdown( $user_id, $date, $settings = null ) {
		$settings = $settings ? $settings : EHTT_Helpers::get_settings();
		$out      = self::empty_breakdown();

		$manual = self::get_manual_tips( $user_id, $date );
		if ( null !== $manual ) {
			$gross              = (float) $manual;
			$out['gross']       = $gross;
			$out['kitchen_cut'] = round( $gross * ( (float) $settings['kitchen_deduction_pct'] / 100 ), 2 );
			$out['bar_cut']     = round( $gross * ( (float) $settings['bar_deduction_pct'] / 100 ), 2 );
			$out['net']         = $gross - $out['kitchen_cut'] - $out['bar_cut'];
			$out['source']      = 'manual';
		} elseif ( self::tips_active() ) {
			$waiter = self::waiter_tips( $user_id, $date );
			$share  = self::staff_share( $user_id, $date );
			if ( $waiter || null !== $share ) {
				if ( $waiter ) {
					foreach ( $waiter as $k => $v ) {
						$out[ $k ] += $v;
					}
				}
				if ( null !== $share ) {
					$out['share'] += $share;
					$out['gross'] += $share;
					$out['net']   += $share;
				}
				$out['source'] = 'napiwki';
			}
		}

		$out = apply_filters( 'ehtt_tips_breakdown', $out, $user_id, $date );
		foreach ( $out as $k => $v ) {
			if ( is_float( $v ) || is_int( $v ) ) {
				$out[ $k ] = round( (float) $v, 2 );
			}
		}
		return $out;
	}

	public static function empty_breakdown() {
		return array(
			'gross'       => 0.0,
			'tax'         => 0.0,
			'bar_cut'     => 0.0,
			'kitchen_cut' => 0.0,
			'net'         => 0.0,
			'source'      => null,
			'card'        => 0.0,
			'service'     => 0.0,
			'cash100'     => 0.0,
			'share'       => 0.0,
		);
	}

	/**
	 * Kelner — te same wzory co snspa_get_day_data() (kelnerSelf) w Napiwkach.
	 */
	private static function waiter_tips( $user_id, $date ) {
		global $wpdb;
		$p = $wpdb->prefix;

		$card     = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}sn_waiter_tips WHERE waiter_id = %d AND date = %s", $user_id, $date ) );
		$service  = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}sn_waiter_service WHERE waiter_id = %d AND date = %s", $user_id, $date ) );
		$cash_bar = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}sn_waiter_cash WHERE waiter_id = %d AND date = %s AND dept = 'bar'", $user_id, $date ) );
		$cash_ktc = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$p}sn_waiter_cash WHERE waiter_id = %d AND date = %s AND dept = 'kuchnia'", $user_id, $date ) );

		if ( null === $card && null === $service && null === $cash_bar && null === $cash_ktc ) {
			return null;
		}
		$card    = (float) $card;
		$service = (float) $service;

		$tax    = snspa_tax_settings();
		$exempt = snspa_is_tax_exempt( $user_id, $date );
		$k_net  = ( $tax['cardActive'] && ! $exempt ) ? $card * ( 1 - $tax['rate'] ) : $card;
		$s_net  = ( $tax['servActive'] && ! $exempt ) ? $service * ( 1 - $tax['rate'] ) : $service;

		$pct     = snspa_percent_from_day_map( snspa_get_day_percent_overrides( $date ), $user_id );
		$cash100 = snspa_cash100_from_row( (float) $cash_bar, (float) $cash_ktc, $pct['card']['bar'], $pct['card']['kitchen'] );

		// Karta i gotówka — procentem KARTY, serwis — procentem SERWISU.
		$card_and_cash = $k_net + $cash100;
		$pool_bar      = $card_and_cash * $pct['card']['bar'] + $s_net * $pct['service']['bar'];
		$pool_ktc      = $card_and_cash * $pct['card']['kitchen'] + $s_net * $pct['service']['kitchen'];
		$gross         = $card + $service + $cash100;
		$tax_cut       = ( $card - $k_net ) + ( $service - $s_net );

		return array(
			'gross'       => $gross,
			'tax'         => $tax_cut,
			'bar_cut'     => $pool_bar,
			'kitchen_cut' => $pool_ktc,
			'net'         => $gross - $tax_cut - $pool_bar - $pool_ktc,
			'card'        => $card,
			'service'     => $service,
			'cash100'     => $cash100,
		);
	}

	/**
	 * Barman / kucharz / pomoc — udział z puli działu wyliczony przez Napiwki.
	 */
	private static function staff_share( $user_id, $date ) {
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

	/** Zgodność wsteczna: kwota netto napiwków dnia. */
	public static function get_daily_tips( $user_id, $date ) {
		return self::get_tips_breakdown( $user_id, $date )['net'];
	}

	/* ---------------------------------------------------------------
	 * Wpisy ręczne kierownika
	 * ------------------------------------------------------------- */

	public static function get_manual_tips( $user_id, $date ) {
		global $wpdb;
		$table = EHTT_DB::table_tips();
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT amount FROM {$table} WHERE user_id = %d AND tip_date = %s", $user_id, $date ) );
		return null !== $value ? (float) $value : null;
	}

	/**
	 * Zapisuje ręczne godziny podpowiedzi; pusty start usuwa wpis (wraca Grafik).
	 */
	public static function set_manual_schedule( $user_id, $date, $start_time, $end_time = null ) {
		global $wpdb;
		$table = EHTT_DB::table_schedule();
		if ( empty( $start_time ) ) {
			$wpdb->delete( $table, array( 'user_id' => $user_id, 'schedule_date' => $date ) );
			return;
		}
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND schedule_date = %s", $user_id, $date )
		);
		$data = array(
			'suggested_start' => $start_time,
			'suggested_end'   => $end_time,
		);
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing ) );
		} else {
			$data['user_id']       = $user_id;
			$data['schedule_date'] = $date;
			$wpdb->insert( $table, $data );
		}
	}

	/**
	 * Zapisuje ręczne napiwki (kwota brutto); null usuwa wpis (wracają Napiwki).
	 */
	public static function set_manual_tips( $user_id, $date, $amount ) {
		global $wpdb;
		$table = EHTT_DB::table_tips();
		if ( null === $amount ) {
			$wpdb->delete( $table, array( 'user_id' => $user_id, 'tip_date' => $date ) );
			return;
		}
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND tip_date = %s", $user_id, $date )
		);
		if ( $existing ) {
			$wpdb->update( $table, array( 'amount' => $amount ), array( 'id' => $existing ) );
		} else {
			$wpdb->insert( $table, array( 'user_id' => $user_id, 'tip_date' => $date, 'amount' => $amount ) );
		}
	}
}
