<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zbiera dane o urodzinach całego zespołu na podstawie meta `user_birth_date`
 * — tego samego pola, którego używa wtyczka Napiwków i zakładka „Moje konto”.
 * Żadnych osobnych, duplikowanych danych — jedno źródło prawdy.
 */
class GFX_Birthdays {

	public static function month_names() {
		return array(
			__( 'Styczeń', 'gastroflowx-hub' ),
			__( 'Luty', 'gastroflowx-hub' ),
			__( 'Marzec', 'gastroflowx-hub' ),
			__( 'Kwiecień', 'gastroflowx-hub' ),
			__( 'Maj', 'gastroflowx-hub' ),
			__( 'Czerwiec', 'gastroflowx-hub' ),
			__( 'Lipiec', 'gastroflowx-hub' ),
			__( 'Sierpień', 'gastroflowx-hub' ),
			__( 'Wrzesień', 'gastroflowx-hub' ),
			__( 'Październik', 'gastroflowx-hub' ),
			__( 'Listopad', 'gastroflowx-hub' ),
			__( 'Grudzień', 'gastroflowx-hub' ),
		);
	}

	public static function month_abbr() {
		return array(
			__( 'Sty', 'gastroflowx-hub' ),
			__( 'Lut', 'gastroflowx-hub' ),
			__( 'Mar', 'gastroflowx-hub' ),
			__( 'Kwi', 'gastroflowx-hub' ),
			__( 'Maj', 'gastroflowx-hub' ),
			__( 'Cze', 'gastroflowx-hub' ),
			__( 'Lip', 'gastroflowx-hub' ),
			__( 'Sie', 'gastroflowx-hub' ),
			__( 'Wrz', 'gastroflowx-hub' ),
			__( 'Paź', 'gastroflowx-hub' ),
			__( 'Lis', 'gastroflowx-hub' ),
			__( 'Gru', 'gastroflowx-hub' ),
		);
	}

	/**
	 * Wszystkie osoby z ustawioną datą urodzenia (dowolna rola — nie
	 * filtrujemy po roli, każdy widzi urodziny każdego).
	 *
	 * Domyślnie pomijamy pracowników oznaczonych jako nieaktywni — ta sama
	 * flaga (`rs_inactive`), której używa moduł Grafiku Pracy do ukrywania
	 * byłych pracowników w siatce grafiku i kalendarzu urlopów. Jedno
	 * miejsce oznaczenia „pracownik już nie pracuje” wystarcza dla całego
	 * systemu. Administrator może to zachowanie wyłączyć w
	 * GastroFlowx → Ustawienia ogólne (opcja `gfx_birthdays_hide_inactive`).
	 *
	 * @return array<int, array{user_id:int,name:string,role:string,month:int,day:int,year:int}>
	 */
	public static function collect() {
		$hide_inactive = '0' !== get_option( 'gfx_birthdays_hide_inactive', '1' );

		$users = get_users( array( 'fields' => array( 'ID' ) ) );
		$list  = array();

		foreach ( $users as $row ) {
			if ( $hide_inactive && get_user_meta( $row->ID, 'rs_inactive', true ) ) {
				continue;
			}

			$birthdate = get_user_meta( $row->ID, 'user_birth_date', true );
			if ( empty( $birthdate ) ) {
				continue;
			}
			// UWAGA: parsujemy jako czysty tekst (DateTime::createFromFormat),
			// NIGDY przez strtotime()+gmdate() - ta kombinacja psuje
			// dopasowanie o jeden dzień, gdy domyślna strefa czasowa PHP na
			// serwerze nie jest UTC (ten sam rodzaj błędu, co wcześniej przy
			// godzinach w Historii wysyłki - patrz GFX_Push_Log::format_local_datetime()).
			$dt = DateTime::createFromFormat( 'Y-m-d', substr( trim( $birthdate ), 0, 10 ) );
			if ( ! $dt ) {
				continue;
			}
			$user = get_userdata( $row->ID );
			if ( ! $user ) {
				continue;
			}
			$name = trim( $user->first_name . ' ' . $user->last_name );
			$list[] = array(
				'user_id' => $user->ID,
				'name'    => $name ? $name : $user->display_name,
				'role'    => GFX_Modules::primary_role_label( $user ),
				'month'   => (int) $dt->format( 'n' ),
				'day'     => (int) $dt->format( 'j' ),
				'year'    => (int) $dt->format( 'Y' ),
			);
		}
		return $list;
	}

	/**
	 * Najbliższe urodziny licząc od dziś (zawijając na kolejny rok), z
	 * obliczonym wiekiem, jaki dana osoba kończy.
	 *
	 * @return array
	 */
	public static function upcoming( $limit = 15 ) {
		$today_str = current_time( 'Y-m-d' );
		$today     = new DateTime( $today_str );
		$this_year = (int) $today->format( 'Y' );

		$list = self::collect();
		foreach ( $list as &$item ) {
			$mm = str_pad( $item['month'], 2, '0', STR_PAD_LEFT );
			$dd = str_pad( $item['day'], 2, '0', STR_PAD_LEFT );

			$next = DateTime::createFromFormat( 'Y-m-d', $this_year . '-' . $mm . '-' . $dd );
			if ( ! $next || $next < $today ) {
				$next = DateTime::createFromFormat( 'Y-m-d', ( $this_year + 1 ) . '-' . $mm . '-' . $dd );
			}
			if ( ! $next ) {
				$item['next_date'] = null;
				continue;
			}
			$item['next_date']   = $next->format( 'Y-m-d' );
			$item['days_until']  = (int) $today->diff( $next )->format( '%a' );
			$item['turning_age'] = $item['year'] ? ( (int) $next->format( 'Y' ) - $item['year'] ) : 0;
		}
		unset( $item );

		$list = array_filter( $list, function ( $i ) {
			return null !== $i['next_date'];
		} );

		usort(
			$list,
			function ( $a, $b ) {
				return $a['days_until'] <=> $b['days_until'];
			}
		);

		return array_slice( array_values( $list ), 0, $limit );
	}

	/** Osoby, których urodziny przypadają dokładnie dziś (do powiadomień). */
	public static function today_list() {
		$today = current_time( 'Y-m-d' ); // Poprawnie lokalna data (uwzględnia strefę czasową witryny).
		$dt    = DateTime::createFromFormat( 'Y-m-d', $today );
		$m     = (int) $dt->format( 'n' );
		$d     = (int) $dt->format( 'j' );

		return array_values(
			array_filter(
				self::collect(),
				function ( $item ) use ( $m, $d ) {
					return $item['month'] === $m && $item['day'] === $d;
				}
			)
		);
	}
}
