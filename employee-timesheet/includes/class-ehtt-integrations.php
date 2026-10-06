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
 * NAPIWKI — zob. get_tips_breakdown():
 *   karta + serwis (brutto, do przelewu): ręczny wpis kierownika albo Napiwki;
 *   rozliczenie netto po przelewie: podatek i udziały baru i kuchni wg Napiwków
 *   (dla wpisu ręcznego — procenty z ustawień); barman / kucharz: udział z puli;
 *   gotówka (poza przelewem): Napiwki, a gdy ich brak — wpis kelnera;
 *   premia kelnera: do przelewu, bez odliczeń.
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
	 * Rozliczenie napiwków osoby za dzień.
	 *
	 * Karta i serwis to kwoty BRUTTO, które trafiają do przelewu; po otrzymaniu
	 * przelewu kelner rozlicza je na netto (podatek, udział baru i kuchni) —
	 * Ewidencja pokazuje to rozliczenie informacyjnie (settlement_*).
	 * Gotówka nie trafia do przelewu. Premia trafia do przelewu i nie podlega
	 * żadnym odliczeniom. Gotówka wpisana przez kelnera jest pomijana, jeśli
	 * na ten dzień jest już gotówka z modułu Napiwków.
	 *
	 * @return array<string,mixed> zob. empty_breakdown().
	 */
	public static function get_tips_breakdown( $user_id, $date, $settings = null ) {
		$settings = $settings ? $settings : EHTT_Helpers::get_settings();
		$out      = self::empty_breakdown();

		// 1) Karta + serwis: ręczny wpis kierownika albo moduł Napiwków.
		$manual = self::get_manual_tips( $user_id, $date );
		if ( null !== $manual ) {
			$out['card']        = (float) $manual; // ręczna kwota = karta + serwis brutto
			$out['bar_cut']     = $out['card'] * ( (float) $settings['bar_deduction_pct'] / 100 );
			$out['kitchen_cut'] = $out['card'] * ( (float) $settings['kitchen_deduction_pct'] / 100 );
			$out['source']      = 'manual';
		} elseif ( self::tips_active() ) {
			$waiter = self::waiter_tips( $user_id, $date );
			$staff  = self::staff_share( $user_id, $date );
			if ( $waiter ) {
				foreach ( array( 'card', 'service', 'tax', 'bar_cut', 'kitchen_cut', 'cash', 'cash_given' ) as $k ) {
					$out[ $k ] += $waiter[ $k ];
				}
				if ( $waiter['has_cash'] ) {
					$out['cash_source'] = 'napiwki';
				}
			}
			if ( $staff ) {
				// Udział barmana / kucharza z puli — bez odliczeń.
				$out['card']    += $staff['card'];
				$out['service'] += $staff['service'];
				$out['cash']    += $staff['cash'];
				if ( $staff['cash'] > 0 ) {
					$out['cash_source'] = 'napiwki';
				}
			}
			if ( $waiter || $staff ) {
				$out['source'] = 'napiwki';
			}
		}

		// 2) Informacyjne wpisy kelnera: gotówka i premia.
		$extras             = self::get_extras( $user_id, $date );
		$out['cash_manual'] = $extras['cash'];
		$out['bonus']       = (float) $extras['bonus'];
		if ( null !== $extras['cash'] ) {
			if ( 'napiwki' === $out['cash_source'] ) {
				$out['cash_manual_ignored'] = true;
			} else {
				$out['cash']        += (float) $extras['cash'];
				$out['cash_source']  = 'kelner';
			}
		}

		// 3) Sumy.
		$out['settlement_gross'] = $out['card'] + $out['service'];
		$out['settlement_net']   = $out['settlement_gross'] - $out['tax'] - $out['bar_cut'] - $out['kitchen_cut'];
		$out['cash_net']         = $out['cash'] - $out['cash_given'];
		$out['transfer']         = $out['settlement_gross'] + $out['bonus'];
		$out['gross']            = $out['settlement_gross'] + $out['cash'] + $out['bonus'];
		$out['net']              = $out['settlement_net'] + $out['cash_net'] + $out['bonus'];

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
			'source'              => null,  // napiwki | manual | null (karta + serwis)
			'card'                => 0.0,   // brutto — do przelewu
			'service'             => 0.0,   // brutto — do przelewu
			'tax'                 => 0.0,   // rozliczenie po przelewie
			'bar_cut'             => 0.0,
			'kitchen_cut'         => 0.0,
			'settlement_gross'    => 0.0,   // karta + serwis
			'settlement_net'      => 0.0,   // karta + serwis po podatku i udziałach
			'cash'                => 0.0,   // gotówka 100% (Napiwki) albo wpis kelnera
			'cash_given'          => 0.0,   // gotówka oddana do baru i kuchni (Napiwki)
			'cash_net'            => 0.0,   // gotówka, która zostaje
			'cash_source'         => null,  // napiwki | kelner | null
			'cash_manual'         => null,  // wpis kelnera (może być pominięty)
			'cash_manual_ignored' => false,
			'bonus'               => 0.0,   // premia — do przelewu, bez odliczeń
			'transfer'            => 0.0,   // karta + serwis + premia (brutto)
			'gross'               => 0.0,   // karta + serwis + gotówka + premia
			'net'                 => 0.0,   // rozliczenie netto + gotówka netto + premia
		);
	}

	/**
	 * Kelner — dane z Napiwków i te same wzory co snspa_get_day_data() (kelnerSelf).
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

		return array(
			'card'        => $card,
			'service'     => $service,
			'tax'         => ( $card - $k_net ) + ( $service - $s_net ),
			// Udziały baru i kuchni z karty i serwisu (rozliczane po przelewie).
			'bar_cut'     => $k_net * $pct['card']['bar'] + $s_net * $pct['service']['bar'],
			'kitchen_cut' => $k_net * $pct['card']['kitchen'] + $s_net * $pct['service']['kitchen'],
			// Gotówka: 100% i kwota fizycznie oddana do baru i kuchni.
			'cash'        => $cash100,
			'cash_given'  => (float) $cash_bar + (float) $cash_ktc,
			'has_cash'    => null !== $cash_bar || null !== $cash_ktc,
		);
	}

	/**
	 * Barman / kucharz / pomoc — udział z puli działu wyliczony przez Napiwki.
	 */
	private static function staff_share( $user_id, $date ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, SUM(calc_card) AS card, SUM(calc_service) AS service, SUM(calc_cash) AS cash
				 FROM {$wpdb->prefix}sn_staff_work WHERE user_id = %d AND date = %s",
				$user_id,
				$date
			),
			ARRAY_A
		);
		if ( ! $row || ! (int) $row['n'] ) {
			return null;
		}
		return array(
			'card'    => (float) $row['card'],
			'service' => (float) $row['service'],
			'cash'    => (float) $row['cash'],
		);
	}

	/** Zgodność wsteczna: kwota netto napiwków dnia. */
	public static function get_daily_tips( $user_id, $date ) {
		return self::get_tips_breakdown( $user_id, $date )['net'];
	}

	/* ---------------------------------------------------------------
	 * Wpisy kelnera: gotówka i premia (informacyjne)
	 * ------------------------------------------------------------- */

	public static function is_waiter( $user_id ) {
		$user = get_userdata( $user_id );
		return $user && in_array( 'kelner', (array) $user->roles, true );
	}

	/** @return array{cash:?float,bonus:?float} */
	public static function get_extras( $user_id, $date ) {
		global $wpdb;
		$table = EHTT_DB::table_extras();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT cash, bonus FROM {$table} WHERE user_id = %d AND extra_date = %s", $user_id, $date ),
			ARRAY_A
		);
		return array(
			'cash'  => ( $row && null !== $row['cash'] ) ? (float) $row['cash'] : null,
			'bonus' => ( $row && null !== $row['bonus'] ) ? (float) $row['bonus'] : null,
		);
	}

	/** Zapis wpisów kelnera; null w obu polach usuwa wiersz. */
	public static function set_extras( $user_id, $date, $cash, $bonus ) {
		global $wpdb;
		$table = EHTT_DB::table_extras();
		if ( null === $cash && null === $bonus ) {
			$wpdb->delete( $table, array( 'user_id' => $user_id, 'extra_date' => $date ) );
			return;
		}
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND extra_date = %s", $user_id, $date )
		);
		$data = array(
			'cash'       => $cash,
			'bonus'      => $bonus,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing ) );
		} else {
			$data['user_id']    = $user_id;
			$data['extra_date'] = $date;
			$wpdb->insert( $table, $data );
		}
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
