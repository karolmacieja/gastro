<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejestr modułów (kategorii) SPA oraz logika uprawnień ról.
 *
 * Każdy moduł biznesowy odpowiada shortcode'owi z jednej z podłączonych
 * wtyczek. Moduły "home" i "account" są natywne dla huba i zawsze
 * widoczne dla zalogowanego użytkownika.
 */
class GFX_Modules {

	/**
	 * Zwraca pełny rejestr modułów.
	 * type: 'native' (renderowane przez hub) | 'shortcode' (delegowane do innej wtyczki)
	 * always: true = dostępne dla każdego zalogowanego użytkownika, pomijane w macierzy uprawnień.
	 *
	 * Moduły "core" (Lunch, Kolorowanki, Napiwki, Grafik, Godziny…) są
	 * zaszyte w kodzie na stałe. Moduły dodane samodzielnie przez
	 * administratora w GastroFlowx → Moduły (opcja `gfx_custom_modules`)
	 * są domieszane tuż przed "Moje konto", żeby to ostatnie zawsze
	 * zostawało na końcu menu — patrz custom_modules().
	 */
	public static function all() {
		$core = array(
			'home'     => array(
				'label'  => __( 'Strona domowa', 'gastroflowx-hub' ),
				'icon'   => 'fa-house',
				'type'   => 'native',
				'always' => true,
			),
			'lunch'    => array(
				'label'     => __( 'Lunch', 'gastroflowx-hub' ),
				'icon'      => 'fa-burger',
				'type'      => 'shortcode',
				'shortcode' => 'weranda_lunch_panel',
				'always'    => false,
			),
			'colors'   => array(
				'label'     => __( 'Kolorowanki', 'gastroflowx-hub' ),
				'icon'      => 'fa-palette',
				'type'      => 'shortcode',
				'shortcode' => 'weranda_kolorowanki_panel',
				'always'    => false,
			),
			'tips'     => array(
				'label'     => __( 'Napiwki', 'gastroflowx-hub' ),
				'icon'      => 'fa-hand-holding-dollar',
				'type'      => 'shortcode',
				'shortcode' => 'napiwki_app',
				'always'    => false,
			),
			'schedule' => array(
				'label'     => __( 'Grafik', 'gastroflowx-hub' ),
				'icon'      => 'fa-calendar-days',
				'type'      => 'shortcode',
				'shortcode' => 'restaurant_scheduler_app',
				'always'    => false,
			),
			'hours'    => array(
				'label'     => __( 'Godziny', 'gastroflowx-hub' ),
				'icon'      => 'fa-clock',
				'type'      => 'shortcode',
				'shortcode' => 'ehtt_timesheet',
				'always'    => false,
			),
			'birthdays' => array(
				'label'  => __( 'Urodziny', 'gastroflowx-hub' ),
				'icon'   => 'fa-cake-candles',
				'type'   => 'native',
				'always' => false,
			),
		);

		$modules = array_merge( $core, self::custom_modules() );

		$modules['account'] = array(
			'label'  => __( 'Moje konto', 'gastroflowx-hub' ),
			'icon'   => 'fa-circle-user',
			'type'   => 'native',
			'always' => true,
		);

		return $modules;
	}

	/**
	 * Moduły dodane przez administratora w GastroFlowx → Moduły, bez
	 * edycji kodu. Każdy wpis to shortcode innej wtyczki — sama ta wtyczka
	 * musi rejestrować swój shortcode i ładować własne assety (JS/CSS)
	 * wewnątrz callbacku shortcode'a (nie na hooku wp_enqueue_scripts przez
	 * has_shortcode() na post_content — to zawodzi, gdy shortcode jest
	 * osadzany dynamicznie przez [gastroflowx_app], patrz komentarz w
	 * GFX_Shell::ensure_child_module_assets()).
	 */
	public static function custom_modules() {
		$raw = get_option( 'gfx_custom_modules', array() );
		return is_array( $raw ) ? $raw : array();
	}

	public static function save_custom_modules( $modules ) {
		update_option( 'gfx_custom_modules', $modules );
	}

	/** ID-y zarezerwowane przez moduły core — nowy moduł nie może się tak nazywać. */
	public static function reserved_ids() {
		return array( 'home', 'lunch', 'colors', 'tips', 'schedule', 'hours', 'birthdays', 'account' );
	}

	/** Moduły, którymi zarządza macierz uprawnień ról (bez "always"). */
	public static function permissionable() {
		return array_filter(
			self::all(),
			function ( $m ) {
				return empty( $m['always'] );
			}
		);
	}

	/** Zwraca listę ról systemu (slug => nazwa czytelna dla człowieka). */
	public static function all_roles() {
		if ( ! function_exists( 'wp_roles' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}
		$wp_roles = wp_roles();
		return $wp_roles->get_names();
	}

	/** Aktualna macierz uprawnień: [ role_slug => [ module_id => true ] ]. */
	public static function get_permissions() {
		$perms = get_option( 'gfx_permissions', array() );
		return is_array( $perms ) ? $perms : array();
	}

	public static function save_permissions( $perms ) {
		update_option( 'gfx_permissions', $perms );
	}

	/**
	 * Czy dany (lub aktualny) użytkownik ma dostęp do modułu.
	 */
	public static function user_can_access( $module_id, $user = null ) {
		$modules = self::all();
		if ( ! isset( $modules[ $module_id ] ) ) {
			return false;
		}
		if ( ! empty( $modules[ $module_id ]['always'] ) ) {
			return is_user_logged_in();
		}

		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true; // Administrator ma zawsze pełny dostęp.
		}

		$perms = self::get_permissions();
		foreach ( (array) $user->roles as $role ) {
			if ( ! empty( $perms[ $role ][ $module_id ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Lista ID modułów dostępnych dla aktualnego użytkownika (z "always" na początku/końcu wg kolejności rejestru). */
	public static function accessible_module_ids( $user = null ) {
		$ids = array();
		foreach ( self::all() as $id => $m ) {
			if ( self::user_can_access( $id, $user ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** Maks. liczba modułów na dolnym pasku, gdy obok jest przycisk „Więcej”. */
	const BOTTOM_NAV_SLOTS = 4;

	/** Moduły przypięte do dolnego paska (GastroFlowx → Ustawienia ogólne). */
	public static function bottom_nav_pinned() {
		$pinned = get_option( 'gfx_bottom_nav_pinned', array( 'home', 'schedule', 'tips', 'hours' ) );
		return is_array( $pinned ) ? array_values( array_map( 'sanitize_key', $pinned ) ) : array();
	}

	/**
	 * Dzieli dostępne moduły na dolny pasek i panel „Więcej”.
	 * Do BOTTOM_NAV_SLOTS + 1 modułów — wszystkie na pasku, bez „Więcej”.
	 * Więcej modułów — na pasku przypięte (w kolejności menu), wolne miejsca
	 * uzupełniane kolejnymi modułami z menu; reszta trafia do „Więcej”.
	 *
	 * @param string[] $accessible ID modułów dostępnych dla użytkownika (kolejność menu).
	 * @return array{bar:string[],more:string[]}
	 */
	public static function bottom_nav_split( array $accessible ) {
		if ( count( $accessible ) <= self::BOTTOM_NAV_SLOTS + 1 ) {
			return array( 'bar' => $accessible, 'more' => array() );
		}
		$pinned = self::bottom_nav_pinned();
		$bar    = array_values( array_intersect( $accessible, $pinned ) );
		foreach ( $accessible as $id ) {
			if ( count( $bar ) >= self::BOTTOM_NAV_SLOTS ) {
				break;
			}
			if ( ! in_array( $id, $bar, true ) ) {
				$bar[] = $id;
			}
		}
		$bar = array_slice( $bar, 0, self::BOTTOM_NAV_SLOTS );
		// Pasek w kolejności menu, nie kolejności zaznaczenia.
		$bar  = array_values( array_intersect( $accessible, $bar ) );
		$more = array_values( array_diff( $accessible, $bar ) );
		return array( 'bar' => $bar, 'more' => $more );
	}

	/**
	 * Czytelna "rola" wyświetlana w nagłówku panelu. Priorytet:
	 * 1) ręcznie ustawiony tytuł przez admina (meta `gfx_display_role`
	 *    zarządzany w "GastroFlowx → Tytuły pracowników") — niezależny od
	 *    faktycznej roli WordPress,
	 * 2) w braku tytułu — nazwa faktycznej roli WP (jak dotychczas).
	 */
	public static function primary_role_label( $user ) {
		if ( ! $user || ! $user->exists() ) {
			return __( 'Brak roli', 'gastroflowx-hub' );
		}

		$custom = get_user_meta( $user->ID, 'gfx_display_role', true );
		if ( ! empty( $custom ) ) {
			return $custom;
		}

		if ( empty( $user->roles ) ) {
			return __( 'Brak roli', 'gastroflowx-hub' );
		}
		$names = self::all_roles();
		$role  = $user->roles[0];
		return isset( $names[ $role ] ) ? $names[ $role ] : $role;
	}
}
