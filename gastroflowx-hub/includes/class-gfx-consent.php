<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zgoda na przetwarzanie danych osobowych (polityka prywatności).
 *
 * Zgoda jest przypisana do KONTA użytkownika (nie do urządzenia/przeglądarki)
 * — raz wyrażona, obowiązuje niezależnie od tego, z jakiego telefonu czy
 * komputera dana osoba się zaloguje. Każda zmiana statusu (wyrażenie,
 * wycofanie) zostaje zapisana jako nowy wiersz w historii — bieżący status
 * to zawsze NAJNOWSZY wiersz danego użytkownika. Takie podejście (log
 * zdarzeń, nie jedno nadpisywane pole) daje pełną rozliczalność wymaganą
 * przez RODO: administrator może w każdej chwili wykazać, kiedy i z jakiego
 * adresu IP dana osoba wyraziła lub wycofała zgodę.
 */
class GFX_Consent {

	const TABLE = 'gfx_consents';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Wywoływane z hooka aktywacji wtyczki (patrz gastroflowx-hub.php) oraz
	 * defensywnie przy każdym ładowaniu klasy — dbDelta() samo w sobie jest
	 * bezpieczne do wielokrotnego wywołania (nie duplikuje ani nie czyści
	 * istniejących danych).
	 */
	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			consent_action VARCHAR(20) NOT NULL,
			policy_version VARCHAR(50) NULL,
			ip_address VARCHAR(45) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public function register_routes() {
		register_rest_route(
			GFX_REST_NS,
			'/consent/agree',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_agree' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			GFX_REST_NS,
			'/consent/withdraw',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_withdraw' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
	}

	public function handle_agree( WP_REST_Request $req ) {
		self::record( get_current_user_id(), 'agreed' );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	public function handle_withdraw( WP_REST_Request $req ) {
		self::record( get_current_user_id(), 'withdrawn' );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Zapisuje nowe zdarzenie w historii zgód. $action to 'agreed' albo
	 * 'withdrawn'.
	 */
	public static function record( $user_id, $action ) {
		global $wpdb;
		$wpdb->insert(
			self::table_name(),
			array(
				'user_id'        => (int) $user_id,
				'consent_action' => $action,
				'policy_version' => get_option( 'gfx_privacy_policy_version', '1.0' ),
				'ip_address'     => self::get_client_ip(),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Najnowszy wiersz historii dla danego użytkownika (albo null, jeśli
	 * nigdy nic nie zarejestrowano — czyli jeszcze nie widział ekranu zgody).
	 */
	public static function latest( $user_id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table jest stałą nazwą tabeli, nie danymi wejściowymi.
				$user_id
			)
		);
		return $row;
	}

	/** Czy dany użytkownik aktualnie ma ważną zgodę (najnowszy wpis = 'agreed'). */
	public static function has_consented( $user_id ) {
		$row = self::latest( $user_id );
		return $row && 'agreed' === $row->consent_action;
	}

	/** Data (lokalna, wg strefy czasowej witryny) ostatniego wyrażenia zgody, albo ''. */
	public static function consented_at( $user_id ) {
		$row = self::latest( $user_id );
		if ( ! $row || 'agreed' !== $row->consent_action ) {
			return '';
		}
		return $row->created_at;
	}

	/**
	 * Bieżący status wszystkich użytkowników (jeden wiersz per użytkownik —
	 * jego najnowsze zdarzenie), do ekranu GastroFlowx → Lista zgód.
	 *
	 * @return array<int,object{user_id:int,consent_action:string,created_at:string,ip_address:string}>
	 */
	public static function all_current_statuses() {
		global $wpdb;
		$table = self::table_name();
		// Najnowszy wiersz per user_id: MAX(id) pogrupowane, potem dociągamy resztę kolumn.
		$rows = $wpdb->get_results(
			"SELECT c.* FROM {$table} c
			 INNER JOIN (
				 SELECT user_id, MAX(id) AS max_id FROM {$table} GROUP BY user_id
			 ) latest ON latest.user_id = c.user_id AND latest.max_id = c.id
			 ORDER BY c.created_at DESC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table jest stałą nazwą tabeli.
		);
		return $rows ? $rows : array();
	}

	private static function get_client_ip() {
		$keys = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip_list = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
				$ip      = trim( $ip_list[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}
}
