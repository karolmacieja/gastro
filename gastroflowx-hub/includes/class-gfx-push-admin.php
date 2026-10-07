<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Podekran "Powiadomienia push" w GastroFlowx: zakładki "Zgody użytkowników"
 * i "Historia wysyłki" (Zadanie 4).
 */
class GFX_Push_Admin {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_gfx_push_clear_history', array( $this, 'handle_clear_history' ) );
		add_action( 'admin_post_gfx_push_clear_user_devices', array( $this, 'handle_clear_user_devices' ) );
		add_action( 'admin_post_gfx_push_clear_all_devices', array( $this, 'handle_clear_all_devices' ) );
		add_action( 'admin_post_gfx_push_save_templates', array( $this, 'handle_save_templates' ) );
		add_action( 'admin_post_gfx_push_test_template', array( $this, 'handle_test_template' ) );
		add_action( 'admin_post_gfx_push_forget_type', array( $this, 'handle_forget_type' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'gastroflowx',
			__( 'Powiadomienia push', 'gastroflowx-hub' ),
			__( 'Powiadomienia push', 'gastroflowx-hub' ),
			self::CAP,
			'gastroflowx-push',
			array( $this, 'render' )
		);
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'consents';
		if ( ! in_array( $tab, array( 'consents', 'history', 'templates' ), true ) ) {
			$tab = 'consents';
		}
		?>
		<div class="wrap gfx-wrap">
			<h1><?php esc_html_e( 'GastroFlowx — Powiadomienia push', 'gastroflowx-hub' ); ?></h1>

			<?php if ( isset( $_GET['gfx_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Historia wysyłki została wyczyszczona.', 'gastroflowx-hub' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['gfx_devices_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Zapisane urządzenia tego użytkownika zostały wyczyszczone. Status zgody wróci do "Nieznany", dopóki znowu nie odwiedzi strony z aktywną integracją push.', 'gastroflowx-hub' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['gfx_all_devices_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Zapisane urządzenia WSZYSTKICH użytkowników zostały wyczyszczone.', 'gastroflowx-hub' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['gfx_tpl_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Szablony i ikony powiadomień zostały zapisane.', 'gastroflowx-hub' ); ?></p></div>
			<?php endif; ?>

			<?php
			$tpl_test = get_transient( 'gfx_push_tpl_test_' . get_current_user_id() );
			if ( $tpl_test ) :
				delete_transient( 'gfx_push_tpl_test_' . get_current_user_id() );
				?>
				<div class="notice <?php echo $tpl_test['ok'] ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $tpl_test['msg'] ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gastroflowx-push&tab=consents' ) ); ?>" class="nav-tab <?php echo 'consents' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Zgody użytkowników', 'gastroflowx-hub' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gastroflowx-push&tab=history' ) ); ?>" class="nav-tab <?php echo 'history' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Historia wysyłki', 'gastroflowx-hub' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gastroflowx-push&tab=templates' ) ); ?>" class="nav-tab <?php echo 'templates' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Szablony i ikony', 'gastroflowx-hub' ); ?></a>
			</h2>

			<div class="gfx-card" style="margin-top:16px;">
				<?php
				if ( 'history' === $tab ) {
					$this->render_history();
				} elseif ( 'templates' === $tab ) {
					$this->render_templates();
				} else {
					$this->render_consents();
				}
				?>
			</div>
		</div>
		<?php
	}

	protected function render_consents() {
		if ( ! class_exists( 'GFX_Push_Consent' ) ) {
			return;
		}
		$users        = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$clear_all_url = wp_nonce_url( admin_url( 'admin-post.php?action=gfx_push_clear_all_devices' ), 'gfx_push_clear_all_devices' );
		?>
		<p class="description"><?php esc_html_e( 'Status ustalany automatycznie na podstawie tego, co REALNIE zgłasza przeglądarka (nie da się go ustawić ręcznie z tego ekranu) - "Wyczyść" resetuje tylko zapisaną historię, żeby dana osoba zaczęła od zera.', 'gastroflowx-hub' ); ?></p>
		<p class="description"><?php esc_html_e( 'Uwaga: jeśli w Historii wysyłki widzisz błąd "invalid_aliases" tuż po tym, jak dana osoba dopiero zaakceptowała powiadomienia w przeglądarce, spróbuj wysłać ponownie po chwili — nowa subskrypcja bywa widoczna dla wysyłki z niewielkim opóźnieniem.', 'gastroflowx-hub' ); ?></p>
		<p style="margin-bottom:16px;">
			<a href="<?php echo esc_url( $clear_all_url ); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Wyczyścić zapisane urządzenia WSZYSTKICH użytkowników? Każda osoba będzie musiała ponownie zaakceptować zgodę na powiadomienia, żeby znowu dostawać push.', 'gastroflowx-hub' ) ); ?>');"><?php esc_html_e( 'Wyczyść urządzenia wszystkich użytkowników', 'gastroflowx-hub' ); ?></a>
		</p>
		<table class="widefat striped gfx-matrix">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Użytkownik', 'gastroflowx-hub' ); ?></th>
					<th><?php esc_html_e( 'Zgoda na powiadomienia push', 'gastroflowx-hub' ); ?></th>
					<th><?php esc_html_e( 'Urządzenia z aktywną zgodą / łącznie znanych', 'gastroflowx-hub' ); ?></th>
					<th><?php esc_html_e( 'Ostatnia aktywność', 'gastroflowx-hub' ); ?></th>
					<th><?php esc_html_e( 'Akcje', 'gastroflowx-hub' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $users as $u ) :
					$devices   = GFX_Push_Consent::devices_for_user( $u->ID );
					$status    = GFX_Push_Consent::user_consent_status( $u->ID );
					$active    = GFX_Push_Consent::active_device_count( $u->ID );
					$last_seen = GFX_Push_Consent::last_seen( $u->ID );

					if ( null === $status ) {
						$label = __( 'Nieznany', 'gastroflowx-hub' );
					} elseif ( $status ) {
						$label = __( 'Tak', 'gastroflowx-hub' );
					} else {
						$label = __( 'Nie', 'gastroflowx-hub' );
					}

					$clear_user_url = wp_nonce_url(
						add_query_arg( array( 'gfx_user_id' => $u->ID ), admin_url( 'admin-post.php?action=gfx_push_clear_user_devices' ) ),
						'gfx_push_clear_user_devices_' . $u->ID
					);
					?>
					<tr>
						<td><strong><?php echo esc_html( $u->display_name ); ?></strong><br /><span class="description"><?php echo esc_html( $u->user_login ); ?></span></td>
						<td><?php echo esc_html( $label ); ?></td>
						<td><?php echo esc_html( $active . ' / ' . count( $devices ) ); ?></td>
						<td><?php echo esc_html( $last_seen ? GFX_Push_Log::format_local_datetime( $last_seen, 'Y-m-d H:i' ) : '—' ); ?></td>
						<td>
							<?php if ( ! empty( $devices ) ) : ?>
								<a href="<?php echo esc_url( $clear_user_url ); ?>" class="button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Wyczyścić zapisane urządzenia tej osoby?', 'gastroflowx-hub' ) ); ?>');"><?php esc_html_e( 'Wyczyść', 'gastroflowx-hub' ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	protected function render_history() {
		if ( ! class_exists( 'GFX_Push_Log' ) ) {
			return;
		}
		$category = isset( $_GET['gfx_cat'] ) ? sanitize_text_field( wp_unslash( $_GET['gfx_cat'] ) ) : '';
		$status   = isset( $_GET['gfx_status'] ) ? sanitize_text_field( wp_unslash( $_GET['gfx_status'] ) ) : '';
		$page     = isset( $_GET['gfx_page'] ) ? max( 1, (int) $_GET['gfx_page'] ) : 1;

		$result    = GFX_Push_Log::query( array( 'category' => $category, 'status' => $status, 'page' => $page, 'per_page' => 25 ) );
		$clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=gfx_push_clear_history' ), 'gfx_push_clear_history' );
		?>
		<form method="get" style="margin-bottom:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="page" value="gastroflowx-push" />
			<input type="hidden" name="tab" value="history" />
			<select name="gfx_cat">
				<option value=""><?php esc_html_e( 'Wszystkie kategorie', 'gastroflowx-hub' ); ?></option>
				<?php foreach ( array( __( 'Urodziny', 'gastroflowx-hub' ), __( 'Napiwki', 'gastroflowx-hub' ), __( 'Grafik', 'gastroflowx-hub' ), __( 'Powitalne', 'gastroflowx-hub' ), __( 'Test', 'gastroflowx-hub' ) ) as $c ) : ?>
					<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $category, $c ); ?>><?php echo esc_html( $c ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="gfx_status">
				<option value=""><?php esc_html_e( 'Wszystkie statusy', 'gastroflowx-hub' ); ?></option>
				<?php foreach ( array( __( 'Wysłano', 'gastroflowx-hub' ), __( 'Błąd', 'gastroflowx-hub' ), __( 'Pominięto', 'gastroflowx-hub' ) ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filtruj', 'gastroflowx-hub' ); ?></button>
			<a href="<?php echo esc_url( $clear_url ); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Wyczyścić całą historię wysyłki?', 'gastroflowx-hub' ) ); ?>');"><?php esc_html_e( 'Wyczyść historię', 'gastroflowx-hub' ); ?></a>
		</form>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Data', 'gastroflowx-hub' ); ?></th>
					<th><?php esc_html_e( 'Kategoria', 'gastroflowx-hub' ); ?></th>
					<th><?php esc_html_e( 'Odbiorca', 'gastroflowx-hub' ); ?></th>
					<th><?php esc_html_e( 'Tytuł', 'gastroflowx-hub' ); ?></th>
					<th><?php esc_html_e( 'Status', 'gastroflowx-hub' ); ?></th>
					<th><?php esc_html_e( 'Szczegóły błędu', 'gastroflowx-hub' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $result['rows'] ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Brak wpisów.', 'gastroflowx-hub' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $result['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( GFX_Push_Log::format_local_datetime( $row->created_at ) ); ?></td>
						<td><?php echo esc_html( $row->category . ( $row->subcategory ? ( ' — ' . $row->subcategory ) : '' ) ); ?></td>
						<td><?php echo esc_html( $row->recipient_name ); ?></td>
						<td><?php echo esc_html( $row->title ); ?></td>
						<td><?php echo esc_html( $row->status ); ?></td>
						<td><?php echo esc_html( $row->error_detail ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$total_pages = max( 1, (int) ceil( $result['total'] / $result['per_page'] ) );
		if ( $total_pages > 1 ) {
			echo '<p>';
			for ( $p = 1; $p <= $total_pages; $p++ ) {
				$url = add_query_arg(
					array(
						'page'       => 'gastroflowx-push',
						'tab'        => 'history',
						'gfx_cat'    => $category,
						'gfx_status' => $status,
						'gfx_page'   => $p,
					),
					admin_url( 'admin.php' )
				);
				if ( $p === $result['page'] ) {
					echo '<strong>' . esc_html( (string) $p ) . '</strong> ';
				} else {
					echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $p ) . '</a> ';
				}
			}
			echo '</p>';
		}
	}

	public function handle_clear_history() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-hub' ) );
		}
		check_admin_referer( 'gfx_push_clear_history' );
		if ( class_exists( 'GFX_Push_Log' ) ) {
			GFX_Push_Log::clear();
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'gastroflowx-push', 'tab' => 'history', 'gfx_cleared' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_clear_user_devices() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-hub' ) );
		}
		$user_id = isset( $_GET['gfx_user_id'] ) ? absint( $_GET['gfx_user_id'] ) : 0;
		check_admin_referer( 'gfx_push_clear_user_devices_' . $user_id );
		if ( $user_id && class_exists( 'GFX_Push_Consent' ) ) {
			GFX_Push_Consent::clear_devices_for_user( $user_id );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'gastroflowx-push', 'tab' => 'consents', 'gfx_devices_cleared' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_clear_all_devices() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-hub' ) );
		}
		check_admin_referer( 'gfx_push_clear_all_devices' );
		if ( class_exists( 'GFX_Push_Consent' ) ) {
			GFX_Push_Consent::clear_all_devices();
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'gastroflowx-push', 'tab' => 'consents', 'gfx_all_devices_cleared' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* =========================================================
	 *  SZABLONY TREŚCI I IKONY
	 * ========================================================= */

	/**
	 * Wybór ikony z biblioteki mediów (obsługa w assets/js/admin.js).
	 */
	protected function icon_picker( $input_name, $input_id, $attachment_id, $fallback_url = '' ) {
		$url = $attachment_id ? GFX_Push_Templates::attachment_url( $attachment_id ) : '';
		?>
		<div class="gfx-icon-picker" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
			<span class="gfx-icon-preview-wrap" style="width:48px;height:48px;border:1px solid #dcdcde;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;background:#f6f7f7;">
				<img class="gfx-icon-preview" src="<?php echo esc_url( $url ? $url : $fallback_url ); ?>" alt="" style="max-width:100%;max-height:100%;<?php echo ( $url || $fallback_url ) ? '' : 'display:none;'; ?><?php echo ( ! $url && $fallback_url ) ? 'opacity:.45;' : ''; ?>" data-fallback="<?php echo esc_url( $fallback_url ); ?>" />
			</span>
			<input type="hidden" class="gfx-icon-id" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( (string) absint( $attachment_id ) ); ?>" />
			<button type="button" class="button gfx-icon-select"><?php esc_html_e( 'Wybierz ikonę', 'gastroflowx-hub' ); ?></button>
			<button type="button" class="button-link-delete gfx-icon-remove" style="<?php echo $attachment_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Usuń (użyj domyślnej)', 'gastroflowx-hub' ); ?></button>
		</div>
		<?php
	}

	protected function render_templates() {
		if ( ! class_exists( 'GFX_Push_Templates' ) ) {
			return;
		}
		$types           = GFX_Push_Templates::types();
		$default_icon_id = absint( get_option( GFX_Push_Templates::OPTION_DEFAULT_ICON, 0 ) );

		// Podgląd "co zostanie użyte, jeśli nic nie wybierzesz" - bez własnej
		// domyślnej ikony to ikona aplikacji (PWA) albo logo.
		$system_fallback   = GFX_Push_Templates::system_fallback_icon_url();
		$effective_default = GFX_Push_Templates::default_icon_url();
		$custom_default    = GFX_Push_Templates::custom_default_icon_url();

		$common = GFX_Push_Templates::common_vars();

		// Grupowanie po kategorii dla czytelności.
		$grouped = array();
		foreach ( $types as $key => $type ) {
			$grouped[ $type['category'] ][ $key ] = $type;
		}
		?>
		<p class="description"><?php esc_html_e( 'Tu możesz zmienić tytuł i treść KAŻDEGO powiadomienia push wysyłanego przez GastroFlowx oraz ustawić ikonę, która pojawi się obok powiadomienia. Typy z innych modułów (np. Grafik) pojawiają się tu automatycznie po pierwszej wysyłce.', 'gastroflowx-hub' ); ?></p>
		<p class="description"><?php esc_html_e( 'Ikona: zalecany kwadratowy PNG, min. 192×192 px (SVG nie jest obsługiwany przez większość przeglądarek w powiadomieniach). Na iPhone/iPad system zawsze pokazuje ikonę zainstalowanej aplikacji — ustawiona tu ikona będzie widoczna na Androidzie, Windows, macOS i ChromeOS.', 'gastroflowx-hub' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gfx_push_save_templates" />
			<?php wp_nonce_field( 'gfx_push_save_templates' ); ?>

			<h2><?php esc_html_e( 'Domyślna ikona powiadomień', 'gastroflowx-hub' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Ikona domyślna', 'gastroflowx-hub' ); ?></th>
					<td>
						<?php $this->icon_picker( 'gfx_default_icon_id', 'gfx_default_icon_id', $default_icon_id, $system_fallback ); ?>
						<p class="description"><?php esc_html_e( 'Używana dla każdego powiadomienia, które nie ma własnej ikony typu. Jeśli nie wybierzesz żadnej, moduł może podać własną ikonę (np. ogólna ikona z ustawień Grafiku), a w ostateczności użyta zostanie ikona aplikacji (PWA) lub logo restauracji.', 'gastroflowx-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Ikonka paska stanu (Android)', 'gastroflowx-hub' ); ?></th>
					<td>
						<?php $this->icon_picker( 'gfx_default_badge_id', 'gfx_default_badge_id', absint( get_option( GFX_Push_Templates::OPTION_DEFAULT_BADGE, 0 ) ), '' ); ?>
						<p class="description"><?php esc_html_e( 'Mała ikonka na pasku stanu Androida. System pokazuje ją jako jednokolorową sylwetkę — użyj białego/czarnego znaku na PRZEZROCZYSTYM tle (PNG), ok. 96×96 px. Pełnokolorowe logo będzie wyglądać jak białe kółko. Ma pierwszeństwo przed ikonką ustawioną w module.', 'gastroflowx-hub' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Powiadomienia', 'gastroflowx-hub' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Pola dostępne we wszystkich szablonach:', 'gastroflowx-hub' ); ?>
				<?php
				$parts = array();
				foreach ( $common as $ph => $desc ) {
					$parts[] = '<code>' . esc_html( $ph ) . '</code> — ' . esc_html( $desc );
				}
				echo wp_kses( implode( '; ', $parts ), array( 'code' => array() ) );
				?>
			</p>

			<?php foreach ( $grouped as $category => $items ) : ?>
				<h3 style="margin-top:28px;"><?php echo esc_html( $category ); ?></h3>
				<?php foreach ( $items as $key => $type ) :
					$tpl      = GFX_Push_Templates::get( $key );
					$managed  = ! empty( $type['content_managed_by'] );
					$field    = 'gfx_tpl[' . $key . ']';
					$test_url = wp_nonce_url( add_query_arg( array( 'action' => 'gfx_push_test_template', 'gfx_type' => $key ), admin_url( 'admin-post.php' ) ), 'gfx_push_test_template_' . $key );
					?>
					<div style="border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:12px 0;background:#fff;">
						<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
							<strong style="font-size:14px;"><?php echo esc_html( $type['label'] ); ?></strong>
							<span>
								<?php if ( $tpl['custom'] && ! $managed ) : ?>
									<span class="description" style="margin-right:10px;"><?php esc_html_e( 'zmieniony', 'gastroflowx-hub' ); ?></span>
								<?php endif; ?>
								<a href="<?php echo esc_url( $test_url ); ?>" class="button button-small"><?php esc_html_e( 'Wyślij test do mnie', 'gastroflowx-hub' ); ?></a>
								<?php if ( ! empty( $type['discovered'] ) ) :
									$forget_url = wp_nonce_url( add_query_arg( array( 'action' => 'gfx_push_forget_type', 'gfx_type' => $key ), admin_url( 'admin-post.php' ) ), 'gfx_push_forget_type_' . $key );
									?>
									<a href="<?php echo esc_url( $forget_url ); ?>" class="button-link-delete" style="margin-left:8px;" onclick="return confirm('<?php echo esc_js( __( 'Usunąć ten typ z listy razem z jego szablonem? Pojawi się ponownie przy następnej wysyłce (z oryginalną treścią).', 'gastroflowx-hub' ) ); ?>');"><?php esc_html_e( 'Usuń z listy', 'gastroflowx-hub' ); ?></a>
								<?php endif; ?>
							</span>
						</div>
						<?php if ( ! empty( $type['discovered'] ) ) : ?>
							<p class="description" style="margin:6px 0 0;"><?php esc_html_e( 'Wykryte automatycznie. Domyślnie wysyłana jest oryginalna treść z modułu ({tytul} / {tresc}) — możesz ją obudować własnym tekstem lub całkowicie zastąpić.', 'gastroflowx-hub' ); ?></p>
						<?php endif; ?>

						<table class="form-table" role="presentation" style="margin-top:4px;">
							<?php if ( $managed ) : ?>
								<tr>
									<th><?php esc_html_e( 'Treść', 'gastroflowx-hub' ); ?></th>
									<td><p class="description">
										<?php
										/* translators: %s: miejsce, w którym edytuje się treść */
										echo esc_html( sprintf( __( 'Tytuł i treść tego powiadomienia edytuje się w: %s (zawiera kwoty liczone per osoba).', 'gastroflowx-hub' ), $type['content_managed_by'] ) );
										?>
									</p></td>
								</tr>
							<?php else : ?>
								<tr>
									<th><label for="gfx_tpl_title_<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Tytuł', 'gastroflowx-hub' ); ?></label></th>
									<td><input type="text" id="gfx_tpl_title_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $field ); ?>[title]" value="<?php echo esc_attr( $tpl['title'] ); ?>" class="large-text" /></td>
								</tr>
								<tr>
									<th><label for="gfx_tpl_msg_<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Treść', 'gastroflowx-hub' ); ?></label></th>
									<td>
										<textarea id="gfx_tpl_msg_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $field ); ?>[message]" rows="3" class="large-text"><?php echo esc_textarea( $tpl['message'] ); ?></textarea>
										<?php if ( ! empty( $type['vars'] ) ) : ?>
											<p class="description">
												<?php esc_html_e( 'Pola tego powiadomienia:', 'gastroflowx-hub' ); ?>
												<?php
												$vparts = array();
												foreach ( $type['vars'] as $ph => $desc ) {
													$vparts[] = '<code>' . esc_html( $ph ) . '</code> — ' . esc_html( $desc );
												}
												echo wp_kses( implode( '; ', $vparts ), array( 'code' => array() ) );
												?>
											</p>
										<?php endif; ?>
										<?php if ( $tpl['custom'] ) : ?>
											<label style="display:inline-block;margin-top:6px;"><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[reset]" value="1" /> <?php esc_html_e( 'Przywróć domyślny tytuł i treść', 'gastroflowx-hub' ); ?></label>
										<?php endif; ?>
									</td>
								</tr>
							<?php endif; ?>
							<tr>
								<th><?php esc_html_e( 'Ikona', 'gastroflowx-hub' ); ?></th>
								<td>
									<?php
									// Podgląd "co pójdzie bez ikony typu" - z uwzględnieniem
									// ikony zastępczej modułu (np. ogólna ikona Grafiku).
									$type_fallback = $custom_default ? $custom_default : ( ! empty( $type['fallback_icon'] ) ? $type['fallback_icon'] : $effective_default );
									$this->icon_picker( $field . '[icon_id]', 'gfx_tpl_icon_' . $key, $tpl['icon_id'], $type_fallback );
									?>
									<?php if ( $managed ) : ?>
										<p class="description"><?php esc_html_e( 'Jeśli w module źródłowym ustawiono własną ikonę, ma ona pierwszeństwo przed tą.', 'gastroflowx-hub' ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						</table>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<?php submit_button( __( 'Zapisz szablony i ikony', 'gastroflowx-hub' ) ); ?>
		</form>
		<?php
	}

	public function handle_save_templates() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-hub' ) );
		}
		check_admin_referer( 'gfx_push_save_templates' );

		update_option( GFX_Push_Templates::OPTION_DEFAULT_ICON, isset( $_POST['gfx_default_icon_id'] ) ? absint( $_POST['gfx_default_icon_id'] ) : 0 );
		update_option( GFX_Push_Templates::OPTION_DEFAULT_BADGE, isset( $_POST['gfx_default_badge_id'] ) ? absint( $_POST['gfx_default_badge_id'] ) : 0 );

		$types  = GFX_Push_Templates::types();
		$posted = isset( $_POST['gfx_tpl'] ) && is_array( $_POST['gfx_tpl'] ) ? wp_unslash( $_POST['gfx_tpl'] ) : array(); // phpcs:ignore -- sanityzacja w GFX_Push_Templates::save().

		foreach ( $types as $key => $type ) {
			if ( ! isset( $posted[ $key ] ) || ! is_array( $posted[ $key ] ) ) {
				continue;
			}
			$row     = $posted[ $key ];
			$managed = ! empty( $type['content_managed_by'] );
			$current = GFX_Push_Templates::get( $key );
			GFX_Push_Templates::save(
				$key,
				$managed ? $current['title'] : (string) ( $row['title'] ?? '' ),
				$managed ? $current['message'] : (string) ( $row['message'] ?? '' ),
				absint( $row['icon_id'] ?? 0 ),
				$managed || ! empty( $row['reset'] )
			);
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'gastroflowx-push', 'tab' => 'templates', 'gfx_tpl_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Wysyła JEDNO powiadomienie danego typu do bieżącego admina, z
	 * przykładowymi danymi - żeby zobaczyć tekst i ikonę na własnym
	 * urządzeniu przed prawdziwą wysyłką. Pomija filtrowanie po zgodzie
	 * (jak przycisk testu w Integracjach) i NIE trafia do Historii wysyłki
	 * pod prawdziwą kategorią, tylko jako "Test".
	 */
	public function handle_test_template() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-hub' ) );
		}
		$key = isset( $_GET['gfx_type'] ) ? sanitize_key( wp_unslash( $_GET['gfx_type'] ) ) : '';
		check_admin_referer( 'gfx_push_test_template_' . $key );

		$user  = wp_get_current_user();
		$types = GFX_Push_Templates::types();
		$push  = GFX_Push::instance();

		if ( ! isset( $types[ $key ] ) ) {
			$result = array( 'ok' => false, 'msg' => __( 'Nieznany typ powiadomienia.', 'gastroflowx-hub' ) );
		} elseif ( ! $push || ! $push->is_active() ) {
			$result = array( 'ok' => false, 'msg' => __( 'FCM nie jest skonfigurowany/włączony (GastroFlowx → Integracje).', 'gastroflowx-hub' ) );
		} else {
			$type   = $types[ $key ];
			$sample = array_merge(
				array( 'solenizant' => $user->display_name ),
				(array) $type['sample_vars']
			);
			$rendered = GFX_Push_Templates::render(
				$key,
				__( '[Przykładowy tytuł z modułu]', 'gastroflowx-hub' ),
				__( '[Przykładowa treść z modułu]', 'gastroflowx-hub' ),
				$sample,
				$user->ID
			);
			$send = $push->send_to_user(
				$user->ID,
				$rendered['title'],
				$rendered['message'],
				$type['test_url'] ?? null,
				GFX_Push_Templates::icon_url( $key, '', $type['fallback_icon'] ?? '' ),
				GFX_Push_Templates::badge_url( $type['fallback_badge'] ?? '' )
			);
			if ( class_exists( 'GFX_Push_Log' ) ) {
				GFX_Push_Log::add( __( 'Test', 'gastroflowx-hub' ), $type['label'], $user->ID, $user->display_name, $rendered['title'], is_wp_error( $send ) ? __( 'Błąd', 'gastroflowx-hub' ) : __( 'Wysłano', 'gastroflowx-hub' ), is_wp_error( $send ) ? $send->get_error_message() : '' );
			}
			$result = is_wp_error( $send )
				? array( 'ok' => false, 'msg' => $send->get_error_message() )
				: array(
					'ok'  => true,
					/* translators: %s: nazwa typu powiadomienia */
					'msg' => sprintf( __( 'Wysłano test „%s” na Twoje urządzenia z aktywną zgodą. Niezapisane zmiany w formularzu nie są uwzględniane — najpierw zapisz.', 'gastroflowx-hub' ), $type['label'] ),
				);
		}

		set_transient( 'gfx_push_tpl_test_' . $user->ID, $result, MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => 'gastroflowx-push', 'tab' => 'templates' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_forget_type() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-hub' ) );
		}
		$key = isset( $_GET['gfx_type'] ) ? sanitize_key( wp_unslash( $_GET['gfx_type'] ) ) : '';
		check_admin_referer( 'gfx_push_forget_type_' . $key );
		GFX_Push_Templates::forget( $key );
		wp_safe_redirect( add_query_arg( array( 'page' => 'gastroflowx-push', 'tab' => 'templates', 'gfx_tpl_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
