<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API dla SPA (Vue 3). Namespace: ehtt/v1
 */
class EHTT_REST {

	const NS = 'ehtt/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/me', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_me' ),
			'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
		) );

		register_rest_route( self::NS, '/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_settings' ),
				'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_settings' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
			),
		) );

		register_rest_route( self::NS, '/employees', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_employees' ),
			'permission_callback' => array( __CLASS__, 'permission_manage' ),
		) );

		register_rest_route( self::NS, '/day', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_day' ),
				'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_day' ),
				'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_day' ),
				'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
			),
		) );

		register_rest_route( self::NS, '/month', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_month' ),
			'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
		) );

		register_rest_route( self::NS, '/rate-override', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_rate_override' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_rate_override' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
			),
		) );

		register_rest_route( self::NS, '/user-rate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'set_user_rate' ),
			'permission_callback' => array( __CLASS__, 'permission_manage' ),
		) );

		register_rest_route( self::NS, '/manual-schedule', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'set_manual_schedule' ),
			'permission_callback' => array( __CLASS__, 'permission_manage' ),
		) );

		register_rest_route( self::NS, '/manual-tips', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'set_manual_tips' ),
			'permission_callback' => array( __CLASS__, 'permission_manage' ),
		) );
	}

	/* ---------------------------- Uprawnienia ---------------------------- */

	public static function permission_logged_in() {
		return is_user_logged_in();
	}

	public static function permission_manage() {
		return is_user_logged_in() && EHTT_Helpers::current_user_can_manage();
	}

	/**
	 * Zwraca ID docelowego użytkownika dla żądania: jeśli podano user_id
	 * i wywołujący ma uprawnienia zarządzania - używa go, w przeciwnym razie
	 * zwraca ID aktualnie zalogowanego użytkownika (pracownik widzi tylko siebie).
	 */
	private static function resolve_target_user_id( WP_REST_Request $request ) {
		$requested = (int) $request->get_param( 'user_id' );
		$current   = get_current_user_id();
		if ( $requested && EHTT_Helpers::current_user_can_manage() ) {
			return $requested;
		}
		return $current;
	}

	/* ------------------------------ Handlery ------------------------------ */

	public static function get_me() {
		$user = wp_get_current_user();
		return rest_ensure_response( array(
			'id'         => $user->ID,
			'name'       => $user->display_name,
			'can_manage'   => EHTT_Helpers::current_user_can_manage(),
			'settings'     => EHTT_Helpers::get_settings(),
			'integrations' => EHTT_Integrations::status(),
		) );
	}

	public static function get_settings() {
		return rest_ensure_response( EHTT_Helpers::get_settings() );
	}

	public static function update_settings( WP_REST_Request $request ) {
		$allowed = array( 'global_hourly_rate', 'round_minutes', 'kitchen_deduction_pct', 'bar_deduction_pct', 'currency', 'tips_payment_method' );
		$payload = array();
		foreach ( $allowed as $key ) {
			if ( $request->has_param( $key ) ) {
				$payload[ $key ] = $request->get_param( $key );
			}
		}
		$updated = EHTT_Helpers::update_settings( $payload );
		return rest_ensure_response( $updated );
	}

	public static function get_employees() {
		return rest_ensure_response( EHTT_Helpers::get_employees() );
	}

	/**
	 * Buduje pełny obraz dnia: wpis (jeśli istnieje), sugerowaną godzinę z grafiku,
	 * napiwki, obowiązującą stawkę oraz wyliczone godziny/zarobek.
	 */
	public static function get_day( WP_REST_Request $request ) {
		global $wpdb;

		$date    = sanitize_text_field( $request->get_param( 'date' ) );
		if ( ! self::valid_date( $date ) ) {
			return new WP_Error( 'ehtt_invalid_date', 'Nieprawidłowy format daty (YYYY-MM-DD).', array( 'status' => 400 ) );
		}
		$user_id = self::resolve_target_user_id( $request );

		$table = EHTT_DB::table_entries();
		$entry = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND entry_date = %s", $user_id, $date ),
			ARRAY_A
		);

		$suggested     = EHTT_Integrations::get_suggested_times( $user_id, $date );
		$rate          = EHTT_Helpers::resolve_hourly_rate( $user_id, $date );
		$settings      = EHTT_Helpers::get_settings();
		$tip_breakdown = EHTT_Integrations::get_tips_breakdown( $user_id, $date, $settings );

		// Bez zapisanego wpisu formularz od razu dostaje godziny z Grafiku.
		$start_time = $entry ? substr( $entry['start_time'], 0, 5 ) : $suggested['start'];
		$end_time   = $entry ? substr( $entry['end_time'], 0, 5 ) : $suggested['end'];

		$hours = $entry ? (float) $entry['hours_decimal'] : 0.0;
		$used_rate = $entry ? (float) $entry['hourly_rate'] : $rate;

		$hours_earnings = round( $hours * $used_rate, 2 );
		$total_earnings = round( $hours_earnings + $tip_breakdown['net'], 2 );

		return rest_ensure_response( array(
			'date'             => $date,
			'user_id'          => $user_id,
			'entry_exists'     => (bool) $entry,
			'suggested_start'  => $suggested['start'],
			'suggested_end'    => $suggested['end'],
			'suggested_source' => $suggested['source'],
			'start_time'       => $start_time,
			'end_time'         => $end_time,
			'hours_decimal'    => $hours,
			'hours_formatted'  => EHTT_Helpers::format_hours( $hours ),
			'hourly_rate'      => $used_rate,
			'hours_earnings'   => $hours_earnings,
			'total_earnings'   => $total_earnings,
			'note'             => $entry ? $entry['note'] : '',
			'tips'             => $tip_breakdown,
		) );
	}

	public static function save_day( WP_REST_Request $request ) {
		global $wpdb;

		$date = sanitize_text_field( $request->get_param( 'date' ) );
		if ( ! self::valid_date( $date ) ) {
			return new WP_Error( 'ehtt_invalid_date', 'Nieprawidłowy format daty (YYYY-MM-DD).', array( 'status' => 400 ) );
		}
		$user_id = self::resolve_target_user_id( $request );

		$start_time = sanitize_text_field( $request->get_param( 'start_time' ) );
		$end_time   = sanitize_text_field( $request->get_param( 'end_time' ) );
		$note       = sanitize_text_field( (string) $request->get_param( 'note' ) );

		if ( empty( $start_time ) || empty( $end_time ) ) {
			return new WP_Error( 'ehtt_missing_time', 'Godzina rozpoczęcia i zakończenia są wymagane.', array( 'status' => 400 ) );
		}

		$settings = EHTT_Helpers::get_settings();

		// Twarde wymuszenie siatki 15-minutowej niezależnie od tego, co przyszło z frontendu
		// (np. gdyby ktoś wpisał ręcznie 19:12, zapisane zostanie 19:15).
		$start_time = EHTT_Helpers::round_time_to_step( $start_time, $settings['round_minutes'] );
		$end_time   = EHTT_Helpers::round_time_to_step( $end_time, $settings['round_minutes'] );

		$hours    = EHTT_Helpers::calculate_hours( $start_time, $end_time, $settings['round_minutes'] );
		$rate     = EHTT_Helpers::resolve_hourly_rate( $user_id, $date );

		$suggested_start = EHTT_Integrations::get_suggested_times( $user_id, $date )['start'];

		$table = EHTT_DB::table_entries();
		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND entry_date = %s", $user_id, $date )
		);

		$data = array(
			'user_id'         => $user_id,
			'entry_date'      => $date,
			'suggested_start' => $suggested_start,
			'start_time'      => $start_time . ':00',
			'end_time'        => $end_time . ':00',
			'hours_decimal'   => $hours,
			'hourly_rate'     => $rate,
			'note'            => $note,
			'updated_at'      => current_time( 'mysql' ),
		);

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => $existing_id ) );
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $data );
		}

		return self::get_day( $request );
	}

	public static function delete_day( WP_REST_Request $request ) {
		global $wpdb;
		$date    = sanitize_text_field( $request->get_param( 'date' ) );
		$user_id = self::resolve_target_user_id( $request );
		$table   = EHTT_DB::table_entries();
		$wpdb->delete( $table, array( 'user_id' => $user_id, 'entry_date' => $date ) );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Podsumowanie miesiąca: dane dla każdego dnia (na potrzeby widoku kalendarza)
	 * + zsumowane totale (godziny, zarobek z godzin, napiwki brutto/netto, kuchnia, bar, przelew).
	 */
	public static function get_month( WP_REST_Request $request ) {
		global $wpdb;

		$year  = (int) $request->get_param( 'year' );
		$month = (int) $request->get_param( 'month' );
		if ( ! $year || ! $month || $month < 1 || $month > 12 ) {
			return new WP_Error( 'ehtt_invalid_month', 'Nieprawidłowy rok/miesiąc.', array( 'status' => 400 ) );
		}
		$user_id = self::resolve_target_user_id( $request );

		$days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$start_date    = sprintf( '%04d-%02d-01', $year, $month );
		$end_date      = sprintf( '%04d-%02d-%02d', $year, $month, $days_in_month );

		$table = EHTT_DB::table_entries();
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND entry_date BETWEEN %s AND %s",
				$user_id,
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		$entries_by_date = array();
		foreach ( $entries as $e ) {
			$entries_by_date[ $e['entry_date'] ] = $e;
		}

		$settings = EHTT_Helpers::get_settings();

		$days = array();
		$totals = array(
			'hours'          => 0.0,
			'hours_earnings' => 0.0,
			'tips_gross'     => 0.0,
			'tips_tax'       => 0.0,
			'tips_kitchen'   => 0.0,
			'tips_bar'       => 0.0,
			'tips_net'       => 0.0,
		);

		for ( $d = 1; $d <= $days_in_month; $d++ ) {
			$date = sprintf( '%04d-%02d-%02d', $year, $month, $d );
			$entry = isset( $entries_by_date[ $date ] ) ? $entries_by_date[ $date ] : null;

			$hours = $entry ? (float) $entry['hours_decimal'] : 0.0;
			$rate  = $entry ? (float) $entry['hourly_rate'] : 0.0;
			$hours_earnings = round( $hours * $rate, 2 );

			$tip_breakdown = EHTT_Integrations::get_tips_breakdown( $user_id, $date, $settings );

			$days[] = array(
				'date'            => $date,
				'day'             => $d,
				'has_entry'       => (bool) $entry,
				'hours_decimal'   => $hours,
				'hours_formatted' => EHTT_Helpers::format_hours( $hours ),
				'hourly_rate'     => $rate,
				'hours_earnings'  => $hours_earnings,
				'tips'            => $tip_breakdown,
			);

			$totals['hours']          += $hours;
			$totals['hours_earnings'] += $hours_earnings;
			$totals['tips_gross']     += $tip_breakdown['gross'];
			$totals['tips_tax']       += $tip_breakdown['tax'];
			$totals['tips_kitchen']   += $tip_breakdown['kitchen_cut'];
			$totals['tips_bar']       += $tip_breakdown['bar_cut'];
			$totals['tips_net']       += $tip_breakdown['net'];
		}

		foreach ( $totals as $k => $v ) {
			$totals[ $k ] = round( $v, 2 );
		}

		$totals['hours_formatted'] = EHTT_Helpers::format_hours( $totals['hours'] );
		$totals['total_earnings']  = round( $totals['hours_earnings'] + $totals['tips_net'], 2 );

		// Wysokość przelewu na konto vs wypłata w gotówce (napiwki), zależnie od ustawień.
		if ( 'transfer' === $settings['tips_payment_method'] ) {
			$totals['transfer_amount'] = round( $totals['hours_earnings'] + $totals['tips_net'], 2 );
			$totals['cash_amount']     = 0.0;
		} else {
			$totals['transfer_amount'] = $totals['hours_earnings'];
			$totals['cash_amount']     = $totals['tips_net'];
		}

		return rest_ensure_response( array(
			'year'    => $year,
			'month'   => $month,
			'user_id' => $user_id,
			'days'    => $days,
			'totals'  => $totals,
		) );
	}

	public static function get_rate_override( WP_REST_Request $request ) {
		global $wpdb;
		$date    = sanitize_text_field( $request->get_param( 'date' ) );
		$user_id = (int) $request->get_param( 'user_id' );
		$table   = EHTT_DB::table_rate_overrides();
		$rate = $wpdb->get_var(
			$wpdb->prepare( "SELECT hourly_rate FROM {$table} WHERE user_id = %d AND override_date = %s", $user_id, $date )
		);
		return rest_ensure_response( array(
			'user_id' => $user_id,
			'date'    => $date,
			'rate'    => null !== $rate ? (float) $rate : null,
			'resolved_rate' => EHTT_Helpers::resolve_hourly_rate( $user_id, $date ),
		) );
	}

	public static function set_rate_override( WP_REST_Request $request ) {
		$date    = sanitize_text_field( $request->get_param( 'date' ) );
		$user_id = (int) $request->get_param( 'user_id' );
		$rate    = $request->get_param( 'rate' );

		if ( ! self::valid_date( $date ) || ! $user_id ) {
			return new WP_Error( 'ehtt_invalid_params', 'Nieprawidłowe parametry.', array( 'status' => 400 ) );
		}

		$result = EHTT_Helpers::set_rate_override( $user_id, $date, $rate );
		return rest_ensure_response( array( 'user_id' => $user_id, 'date' => $date, 'rate' => $result ) );
	}

	public static function set_user_rate( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'user_id' );
		$rate    = (float) $request->get_param( 'rate' );
		if ( ! $user_id || $rate <= 0 ) {
			return new WP_Error( 'ehtt_invalid_params', 'Nieprawidłowe parametry.', array( 'status' => 400 ) );
		}
		update_user_meta( $user_id, 'ehtt_hourly_rate', $rate );
		return rest_ensure_response( array( 'user_id' => $user_id, 'rate' => $rate ) );
	}

	public static function set_manual_schedule( WP_REST_Request $request ) {
		$user_id    = (int) $request->get_param( 'user_id' );
		$date       = sanitize_text_field( $request->get_param( 'date' ) );
		$start_time = sanitize_text_field( $request->get_param( 'start_time' ) );
		$end_time   = sanitize_text_field( (string) $request->get_param( 'end_time' ) );

		if ( ! $user_id || ! self::valid_date( $date ) ) {
			return new WP_Error( 'ehtt_invalid_params', 'Nieprawidłowe parametry.', array( 'status' => 400 ) );
		}

		// Pusta godzina rozpoczęcia = usunięcie ręcznej podpowiedzi (wraca Grafik).
		EHTT_Integrations::set_manual_schedule( $user_id, $date, $start_time ? $start_time . ':00' : null, $end_time ? $end_time . ':00' : null );
		return rest_ensure_response( array( 'saved' => true ) );
	}

	public static function set_manual_tips( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'user_id' );
		$date    = sanitize_text_field( $request->get_param( 'date' ) );
		$raw     = $request->get_param( 'amount' );
		// Puste pole = usunięcie ręcznej kwoty (wracają dane z Napiwków).
		$amount  = ( null === $raw || '' === $raw ) ? null : (float) $raw;

		if ( ! $user_id || ! self::valid_date( $date ) ) {
			return new WP_Error( 'ehtt_invalid_params', 'Nieprawidłowe parametry.', array( 'status' => 400 ) );
		}

		EHTT_Integrations::set_manual_tips( $user_id, $date, $amount );
		return rest_ensure_response( array( 'saved' => true ) );
	}

	/* ------------------------------ Narzędzia ------------------------------ */

	private static function valid_date( $date ) {
		if ( empty( $date ) ) {
			return false;
		}
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Liczy podział ręcznie wpisanej kwoty brutto procentami z ustawień
	 * (zgodność wsteczna — rozliczenia liczy EHTT_Integrations::get_tips_breakdown()).
	 */
	public static function compute_tip_breakdown( $gross_amount, $settings ) {
		$gross = (float) $gross_amount;
		$kitchen_cut = round( $gross * ( (float) $settings['kitchen_deduction_pct'] / 100 ), 2 );
		$bar_cut     = round( $gross * ( (float) $settings['bar_deduction_pct'] / 100 ), 2 );
		$net = round( $gross - $kitchen_cut - $bar_cut, 2 );

		return array(
			'gross'       => round( $gross, 2 ),
			'kitchen_cut' => $kitchen_cut,
			'bar_cut'     => $bar_cut,
			'net'         => $net,
		);
	}
}
EHTT_REST::init();
