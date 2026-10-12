<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blokada logowania dla wybranych kont.
 *
 * Zablokowane konto zostaje w systemie bez zmian (grafik, napiwki, godziny,
 * historia) — użytkownik po prostu nie może się zalogować i widzi komunikat
 * ustawiony w GastroFlowx → Ustawienia ogólne (lub indywidualny komunikat
 * z profilu użytkownika).
 *
 * Blokada działa na każdej drodze logowania:
 *  - formularz panelu i /wp-login.php (filtr `authenticate`),
 *  - kod QR z karty dostępu (?gfc_auth=…, przechwycony przed wtyczką kart),
 *  - już zalogowane sesje i ciasteczka „Zapamiętaj mnie” (sesje są
 *    kasowane przy blokadzie, a `determine_current_user` odrzuca konto).
 */
class GFX_Account_Block {

	const META         = 'gfx_login_blocked';
	const META_MESSAGE = 'gfx_login_blocked_message';
	const META_AT      = 'gfx_login_blocked_at';
	const OPTION_MSG   = 'gfx_blocked_message';

	/** ID zablokowanego konta, które próbowało wejść w tym żądaniu (do komunikatu na ekranie logowania). */
	protected static $rejected_user = 0;

	public function __construct() {
		add_filter( 'authenticate', array( $this, 'block_authenticate' ), 100 );
		add_filter( 'determine_current_user', array( $this, 'block_current_user' ), 999 );
		add_action( 'init', array( $this, 'block_qr_login' ), 1 );

		// Profil użytkownika (wp-admin).
		add_action( 'show_user_profile', array( $this, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );

		// Lista użytkowników: kolumna + akcje zbiorcze.
		add_filter( 'manage_users_columns', array( $this, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'users_column_value' ), 10, 3 );
		add_filter( 'bulk_actions-users', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	/* ---------------------------------------------------------------
	 * API
	 * ------------------------------------------------------------- */

	public static function is_blocked( $user_id ) {
		return $user_id && (bool) get_user_meta( (int) $user_id, self::META, true );
	}

	public static function default_message() {
		return __( 'Twoje konto zostało zablokowane. Skontaktuj się z administratorem systemu.', 'gastroflowx-hub' );
	}

	public static function message( $user_id = 0 ) {
		$own = $user_id ? trim( (string) get_user_meta( (int) $user_id, self::META_MESSAGE, true ) ) : '';
		if ( '' !== $own ) {
			return $own;
		}
		$global = trim( (string) get_option( self::OPTION_MSG, '' ) );
		return '' !== $global ? $global : self::default_message();
	}

	/** Komunikat do pokazania na ekranie logowania (gdy w tym żądaniu odrzucono zablokowane konto). */
	public static function login_screen_message() {
		if ( self::$rejected_user ) {
			return self::message( self::$rejected_user );
		}
		if ( isset( $_GET['gfx_blocked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko wyświetlenie komunikatu.
			return self::message( 0 );
		}
		return '';
	}

	public static function set_blocked( $user_id, $blocked ) {
		$user_id = (int) $user_id;
		if ( $blocked ) {
			if ( ! self::is_blocked( $user_id ) ) {
				update_user_meta( $user_id, self::META_AT, current_time( 'mysql' ) );
			}
			update_user_meta( $user_id, self::META, 1 );
			// Natychmiastowe wylogowanie ze wszystkich urządzeń.
			if ( class_exists( 'WP_Session_Tokens' ) ) {
				WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			}
		} else {
			delete_user_meta( $user_id, self::META );
			delete_user_meta( $user_id, self::META_AT );
		}
	}

	/* ---------------------------------------------------------------
	 * Egzekwowanie blokady
	 * ------------------------------------------------------------- */

	public function block_authenticate( $user ) {
		if ( $user instanceof WP_User && self::is_blocked( $user->ID ) ) {
			self::$rejected_user = $user->ID;
			return new WP_Error( 'gfx_account_blocked', self::message( $user->ID ) );
		}
		return $user;
	}

	public function block_current_user( $user_id ) {
		if ( $user_id && self::is_blocked( $user_id ) ) {
			self::$rejected_user = (int) $user_id;
			return false;
		}
		return $user_id;
	}

	/**
	 * Logowanie kodem QR (wtyczka GastroFlowx Access Cards, ?gfc_auth=…) omija
	 * `authenticate` — sprawdzamy je wcześniej (priorytet 1 przed jej 10).
	 */
	public function block_qr_login() {
		if ( empty( $_GET['gfc_auth'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['gfc_auth'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$users = get_users(
			array(
				'meta_key'   => '_gastro_qr_token', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( $users && self::is_blocked( (int) $users[0] ) ) {
			wp_safe_redirect( add_query_arg( 'gfx_blocked', '1', home_url( '/' ) ) );
			exit;
		}
	}

	/* ---------------------------------------------------------------
	 * Profil użytkownika
	 * ------------------------------------------------------------- */

	public function profile_fields( $user ) {
		$can_manage = current_user_can( 'edit_users' );
		$blocked    = self::is_blocked( $user->ID );
		$is_self    = (int) $user->ID === get_current_user_id();
		$consent    = class_exists( 'GFX_Birthdays' ) && GFX_Birthdays::has_consent( $user->ID );
		?>
		<h2><?php esc_html_e( 'GastroFlowx', 'gastroflowx-hub' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php if ( $can_manage ) : ?>
				<tr>
					<th><?php esc_html_e( 'Blokada logowania', 'gastroflowx-hub' ); ?></th>
					<td>
						<?php wp_nonce_field( 'gfx_block_' . $user->ID, 'gfx_block_nonce' ); ?>
						<label>
							<input type="checkbox" name="gfx_login_blocked" value="1" <?php checked( $blocked ); ?> <?php disabled( $is_self ); ?> />
							<?php esc_html_e( 'Zablokuj logowanie na to konto', 'gastroflowx-hub' ); ?>
						</label>
						<?php if ( $is_self ) : ?>
							<p class="description"><?php esc_html_e( 'Nie możesz zablokować własnego konta.', 'gastroflowx-hub' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Konto i wszystkie jego dane (grafik, napiwki, godziny) zostają w systemie — użytkownik nie może się tylko zalogować (hasłem, kodem QR ani przez zapamiętaną sesję). Zablokowanie od razu wylogowuje go ze wszystkich urządzeń.', 'gastroflowx-hub' ); ?></p>
						<?php endif; ?>
						<?php if ( $blocked && get_user_meta( $user->ID, self::META_AT, true ) ) : ?>
							<p class="description"><?php printf( esc_html__( 'Zablokowane od: %s', 'gastroflowx-hub' ), esc_html( date_i18n( 'd.m.Y H:i', strtotime( get_user_meta( $user->ID, self::META_AT, true ) ) ) ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="gfx_login_blocked_message"><?php esc_html_e( 'Komunikat dla tego konta', 'gastroflowx-hub' ); ?></label></th>
					<td>
						<textarea id="gfx_login_blocked_message" name="gfx_login_blocked_message" rows="2" class="large-text" placeholder="<?php echo esc_attr( self::message( 0 ) ); ?>"><?php echo esc_textarea( get_user_meta( $user->ID, self::META_MESSAGE, true ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Opcjonalnie. Puste = komunikat ogólny z GastroFlowx → Ustawienia ogólne.', 'gastroflowx-hub' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Zgoda — urodziny', 'gastroflowx-hub' ); ?></th>
				<td>
					<?php echo $consent ? esc_html__( 'Wyrażona — urodziny widoczne w module Urodziny i objęte powiadomieniami.', 'gastroflowx-hub' ) : esc_html__( 'Brak — urodziny ukryte w module Urodziny i bez powiadomień.', 'gastroflowx-hub' ); ?>
					<p class="description"><?php esc_html_e( 'Zgodę zaznacza sam pracownik w „Moje konto”. Nie wpływa na naliczanie ulgi podatkowej w Napiwkach.', 'gastroflowx-hub' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) || ! isset( $_POST['gfx_block_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gfx_block_nonce'] ) ), 'gfx_block_' . $user_id ) ) {
			return;
		}
		if ( (int) $user_id !== get_current_user_id() ) {
			self::set_blocked( $user_id, ! empty( $_POST['gfx_login_blocked'] ) );
		}
		$msg = isset( $_POST['gfx_login_blocked_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['gfx_login_blocked_message'] ) ) : '';
		if ( '' === $msg ) {
			delete_user_meta( $user_id, self::META_MESSAGE );
		} else {
			update_user_meta( $user_id, self::META_MESSAGE, $msg );
		}
	}

	/* ---------------------------------------------------------------
	 * Lista użytkowników
	 * ------------------------------------------------------------- */

	public function users_column( $columns ) {
		$columns['gfx_login'] = __( 'Logowanie', 'gastroflowx-hub' );
		return $columns;
	}

	public function users_column_value( $value, $column, $user_id ) {
		if ( 'gfx_login' !== $column ) {
			return $value;
		}
		return self::is_blocked( $user_id )
			? '<span style="color:#B91C1C;font-weight:600;">' . esc_html__( 'Zablokowane', 'gastroflowx-hub' ) . '</span>'
			: '<span style="color:#15803D;">' . esc_html__( 'Aktywne', 'gastroflowx-hub' ) . '</span>';
	}

	public function bulk_actions( $actions ) {
		if ( current_user_can( 'edit_users' ) ) {
			$actions['gfx_block']   = __( 'Zablokuj logowanie', 'gastroflowx-hub' );
			$actions['gfx_unblock'] = __( 'Odblokuj logowanie', 'gastroflowx-hub' );
		}
		return $actions;
	}

	public function handle_bulk_actions( $redirect, $action, $user_ids ) {
		if ( ! in_array( $action, array( 'gfx_block', 'gfx_unblock' ), true ) || ! current_user_can( 'edit_users' ) ) {
			return $redirect;
		}
		$count = 0;
		foreach ( (array) $user_ids as $id ) {
			$id = (int) $id;
			if ( $id === get_current_user_id() ) {
				continue; // nigdy nie blokujemy samego siebie
			}
			self::set_blocked( $id, 'gfx_block' === $action );
			$count++;
		}
		return add_query_arg( array( 'gfx_block_done' => $action, 'gfx_block_count' => $count ), $redirect );
	}

	public function bulk_notice() {
		if ( empty( $_GET['gfx_block_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$count = isset( $_GET['gfx_block_count'] ) ? (int) $_GET['gfx_block_count'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$text  = 'gfx_block' === $_GET['gfx_block_done'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sprintf( __( 'Zablokowano logowanie kont: %d. Twoje konto nigdy nie jest blokowane.', 'gastroflowx-hub' ), $count )
			: sprintf( __( 'Odblokowano logowanie kont: %d.', 'gastroflowx-hub' ), $count );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
