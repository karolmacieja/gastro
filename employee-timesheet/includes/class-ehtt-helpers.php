<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Funkcje pomocnicze: zaokrąglanie czasu do 15 minut, ustalanie obowiązującej
 * stawki godzinowej wg hierarchii (nadpisanie na dzień > stawka użytkownika > stawka globalna),
 * formatowanie liczby godzin jako "7,5 h".
 */
class EHTT_Helpers {

	/**
	 * Zwraca ustawienia wtyczki (scalone z domyślnymi).
	 */
	public static function get_settings() {
		$defaults = array(
			'global_hourly_rate'    => 30.00,
			'round_minutes'         => 15,
			'kitchen_deduction_pct' => 3.0,
			'bar_deduction_pct'     => 3.0,
			'currency'              => 'zł',
			'tips_payment_method'   => 'cash',
		);
		$saved = get_option( 'ehtt_settings', array() );
		return wp_parse_args( $saved, $defaults );
	}

	public static function update_settings( $new_values ) {
		$current = self::get_settings();
		$merged  = wp_parse_args( $new_values, $current );
		update_option( 'ehtt_settings', $merged );
		return $merged;
	}

	/**
	 * Zaokrągla pojedynczy znacznik czasu ("HH:MM" lub "HH:MM:SS") do najbliższej
	 * wielokrotności $step_minutes (domyślnie 15), np. 19:12 -> 19:15, 7:38 -> 7:30.
	 * Zapewnia, że w bazie nigdy nie zostanie zapisana godzina spoza siatki 15-minutowej,
	 * niezależnie od tego, co użytkownik wpisał ręcznie w polu godziny.
	 */
	public static function round_time_to_step( $time_string, $step_minutes = 15 ) {
		if ( empty( $time_string ) ) {
			return $time_string;
		}
		try {
			$dt = new DateTime( '1970-01-01 ' . $time_string );
		} catch ( Exception $e ) {
			return $time_string;
		}

		$step_minutes = max( 1, (int) $step_minutes );
		$minutes_since_midnight = ( (int) $dt->format( 'H' ) ) * 60 + (int) $dt->format( 'i' );
		$rounded = round( $minutes_since_midnight / $step_minutes ) * $step_minutes;
		$rounded = ( (int) $rounded ) % ( 24 * 60 ); // zawijanie w obrębie doby

		$hours   = intdiv( $rounded, 60 );
		$minutes = $rounded % 60;

		return sprintf( '%02d:%02d', $hours, $minutes );
	}

	/**
	 * Liczy różnicę godzin (jako liczba dziesiętna) pomiędzy dwoma znacznikami
	 * czasu HH:MM, zaokrągloną do najbliższych $round_minutes minut (domyślnie 15).
	 * Wynik zaokrąglany jest na poziomie łącznego czasu trwania (nie każdej granicy z osobna),
	 * co odpowiada praktyce "licznik zaokrąglony do 15 minut".
	 */
	public static function calculate_hours( $start_time, $end_time, $round_minutes = 15 ) {
		if ( empty( $start_time ) || empty( $end_time ) ) {
			return 0.0;
		}

		try {
			$start = new DateTime( '1970-01-01 ' . $start_time );
			$end   = new DateTime( '1970-01-01 ' . $end_time );
		} catch ( Exception $e ) {
			return 0.0;
		}

		// Praca przez północ (np. 22:00 - 02:00).
		if ( $end <= $start ) {
			$end->modify( '+1 day' );
		}

		$diff_minutes = ( $end->getTimestamp() - $start->getTimestamp() ) / 60;

		$round_minutes = max( 1, (int) $round_minutes );
		$rounded_minutes = round( $diff_minutes / $round_minutes ) * $round_minutes;

		return round( $rounded_minutes / 60, 2 );
	}

	/**
	 * Formatuje liczbę godzin w stylu polskim, np. 7.5 -> "7,5 h".
	 */
	public static function format_hours( $hours ) {
		$hours = (float) $hours;
		// Usuń zbędne zera (7.50 -> 7,5 ; 8.00 -> 8).
		$formatted = rtrim( rtrim( number_format( $hours, 2, '.', '' ), '0' ), '.' );
		if ( $formatted === '' || $formatted === '-' ) {
			$formatted = '0';
		}
		$formatted = str_replace( '.', ',', $formatted );
		return $formatted . ' h';
	}

	public static function format_money( $amount ) {
		$settings = self::get_settings();
		$formatted = number_format( (float) $amount, 2, ',', ' ' );
		return $formatted . ' ' . $settings['currency'];
	}

	/**
	 * Ustala obowiązującą stawkę godzinową dla danego pracownika i daty, zgodnie
	 * z hierarchią: 1) nadpisanie na konkretny dzień, 2) indywidualna stawka
	 * pracownika (user_meta), 3) stawka globalna.
	 */
	public static function resolve_hourly_rate( $user_id, $date ) {
		global $wpdb;

		$table = EHTT_DB::table_rate_overrides();
		$override = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT hourly_rate FROM {$table} WHERE user_id = %d AND override_date = %s",
				$user_id,
				$date
			)
		);
		if ( null !== $override ) {
			return (float) $override;
		}

		$user_rate = get_user_meta( $user_id, 'ehtt_hourly_rate', true );
		if ( $user_rate !== '' && $user_rate !== false && (float) $user_rate > 0 ) {
			return (float) $user_rate;
		}

		$settings = self::get_settings();
		return (float) $settings['global_hourly_rate'];
	}

	/**
	 * Zapisuje (lub usuwa, jeśli $rate = null) nadpisanie stawki na konkretny dzień.
	 */
	public static function set_rate_override( $user_id, $date, $rate ) {
		global $wpdb;
		$table = EHTT_DB::table_rate_overrides();

		if ( null === $rate || '' === $rate ) {
			$wpdb->delete( $table, array( 'user_id' => $user_id, 'override_date' => $date ) );
			return null;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND override_date = %s", $user_id, $date )
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				array( 'hourly_rate' => $rate ),
				array( 'id' => $existing )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'user_id'       => $user_id,
					'override_date' => $date,
					'hourly_rate'   => $rate,
					'created_at'    => current_time( 'mysql' ),
				)
			);
		}

		return (float) $rate;
	}

	/**
	 * Sprawdza czy użytkownik może zarządzać danymi (własnymi) rozliczeniami
	 * (administrator / kierownik).
	 */
	public static function current_user_can_manage() {
		return current_user_can( EHTT_MANAGE_CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Lista pracowników (wszyscy użytkownicy, opcjonalnie można ograniczyć do roli).
	 */
	public static function get_employees() {
		$users = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$list = array();
		foreach ( $users as $u ) {
			$list[] = array(
				'id'   => $u->ID,
				'name' => $u->display_name,
			);
		}
		return $list;
	}
}
