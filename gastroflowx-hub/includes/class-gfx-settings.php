<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Panel administracyjny GastroFlowx:
 *  - Ustawienia ogólne: nazwa restauracji, logo.
 *  - Dostęp ról: macierz rola x kategoria (moduł) z możliwością dodawania,
 *    edycji i usuwania dostępu.
 */
class GFX_Settings {

	const CAP = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'GastroFlowx', 'gastroflowx-hub' ),
			__( 'GastroFlowx', 'gastroflowx-hub' ),
			self::CAP,
			'gastroflowx',
			array( $this, 'render_general' ),
			'dashicons-store',
			58
		);
		add_submenu_page( 'gastroflowx', __( 'Ustawienia ogólne', 'gastroflowx-hub' ), __( 'Ustawienia ogólne', 'gastroflowx-hub' ), self::CAP, 'gastroflowx', array( $this, 'render_general' ) );
		add_submenu_page( 'gastroflowx', __( 'Moduły', 'gastroflowx-hub' ), __( 'Moduły', 'gastroflowx-hub' ), self::CAP, 'gastroflowx-modules', array( $this, 'render_modules' ) );
		add_submenu_page( 'gastroflowx', __( 'Dostęp ról', 'gastroflowx-hub' ), __( 'Dostęp ról', 'gastroflowx-hub' ), self::CAP, 'gastroflowx-access', array( $this, 'render_access' ) );
		add_submenu_page( 'gastroflowx', __( 'Tytuły pracowników', 'gastroflowx-hub' ), __( 'Tytuły pracowników', 'gastroflowx-hub' ), self::CAP, 'gastroflowx-titles', array( $this, 'render_titles' ) );
		add_submenu_page( 'gastroflowx', __( 'Integracje', 'gastroflowx-hub' ), __( 'Integracje', 'gastroflowx-hub' ), self::CAP, 'gastroflowx-integrations', array( $this, 'render_integrations' ) );
	}

	public function enqueue( $hook ) {
		if ( strpos( $hook, 'gastroflowx' ) === false ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'gfx-admin', GFX_PLUGIN_URL . 'assets/css/admin.css', array(), GFX_VERSION );
		wp_enqueue_script( 'gfx-admin', GFX_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), GFX_VERSION, true );
	}

	public function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// --- Ustawienia ogólne ---
		if ( isset( $_POST['gfx_general_nonce'] ) && wp_verify_nonce( $_POST['gfx_general_nonce'], 'gfx_save_general' ) ) {
			$name = isset( $_POST['gfx_restaurant_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gfx_restaurant_name'] ) ) : '';
			update_option( 'gfx_restaurant_name', $name ? $name : 'GastroFlowx' );
			update_option( 'gfx_logo_id', isset( $_POST['gfx_logo_id'] ) ? absint( $_POST['gfx_logo_id'] ) : 0 );
			update_option( 'gfx_birthdays_hide_inactive', ! empty( $_POST['gfx_birthdays_hide_inactive'] ) ? '1' : '0' );
			if ( isset( $_POST['gfx_privacy_policy_url'] ) ) {
				update_option( 'gfx_privacy_policy_url', sanitize_url( wp_unslash( $_POST['gfx_privacy_policy_url'] ) ) );
			}
			if ( isset( $_POST['gfx_bottom_nav_submitted'] ) ) {
				$pinned = isset( $_POST['gfx_bottom_nav_pinned'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['gfx_bottom_nav_pinned'] ) ) : array();
				$pinned = array_values( array_intersect( array_keys( GFX_Modules::all() ), $pinned ) );
				update_option( 'gfx_bottom_nav_pinned', array_slice( $pinned, 0, GFX_Modules::BOTTOM_NAV_SLOTS ) );
			}
			add_action( 'admin_notices', array( $this, 'notice_saved' ) );
		}

		// --- Nowy moduł (GastroFlowx → Moduły) ---
		if ( isset( $_POST['gfx_module_nonce'] ) && wp_verify_nonce( $_POST['gfx_module_nonce'], 'gfx_save_module' ) ) {
			$label     = isset( $_POST['gfx_module_label'] ) ? sanitize_text_field( wp_unslash( $_POST['gfx_module_label'] ) ) : '';
			$icon      = isset( $_POST['gfx_module_icon'] ) ? sanitize_html_class( wp_unslash( $_POST['gfx_module_icon'] ) ) : '';
			$shortcode = isset( $_POST['gfx_module_shortcode'] ) ? sanitize_key( wp_unslash( $_POST['gfx_module_shortcode'] ) ) : '';

			if ( '' === $label || '' === $shortcode ) {
				add_action( 'admin_notices', array( $this, 'notice_module_missing_fields' ) );
			} else {
				$id       = sanitize_title( $label );
				$custom   = GFX_Modules::custom_modules();
				$reserved = GFX_Modules::reserved_ids();

				if ( '' === $id || in_array( $id, $reserved, true ) || isset( $custom[ $id ] ) ) {
					add_action( 'admin_notices', array( $this, 'notice_module_id_taken' ) );
				} else {
					$custom[ $id ] = array(
						'label'     => $label,
						'icon'      => $icon ? $icon : 'fa-puzzle-piece',
						'type'      => 'shortcode',
						'shortcode' => $shortcode,
						'always'    => false,
					);
					GFX_Modules::save_custom_modules( $custom );
					add_action( 'admin_notices', array( $this, 'notice_saved' ) );
				}
			}
		}

		// --- Usunięcie modułu (link GET, poza formularzem dodawania) ---
		if ( isset( $_GET['gfx_delete_module'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'gfx_delete_module' ) ) {
			$id     = sanitize_key( wp_unslash( $_GET['gfx_delete_module'] ) );
			$custom = GFX_Modules::custom_modules();
			if ( isset( $custom[ $id ] ) ) {
				unset( $custom[ $id ] );
				GFX_Modules::save_custom_modules( $custom );

				// Sprzątamy też ewentualne wpisy tego modułu w macierzy uprawnień ról,
				// żeby nie zostawić osieroconych kluczy w opcji gfx_permissions.
				$perms = GFX_Modules::get_permissions();
				foreach ( $perms as $role => $mods ) {
					unset( $perms[ $role ][ $id ] );
				}
				GFX_Modules::save_permissions( $perms );

				add_action( 'admin_notices', array( $this, 'notice_saved' ) );
			}
		}

		// --- Macierz uprawnień ról ---
		if ( isset( $_POST['gfx_access_nonce'] ) && wp_verify_nonce( $_POST['gfx_access_nonce'], 'gfx_save_access' ) ) {
			$raw     = isset( $_POST['gfx_perm'] ) ? (array) $_POST['gfx_perm'] : array();
			$roles   = array_keys( GFX_Modules::all_roles() );
			$modules = array_keys( GFX_Modules::permissionable() );
			$clean   = array();

			foreach ( $roles as $role ) {
				$clean[ $role ] = array();
				foreach ( $modules as $mod ) {
					if ( ! empty( $raw[ $role ][ $mod ] ) ) {
						$clean[ $role ][ $mod ] = 1;
					}
				}
			}
			GFX_Modules::save_permissions( $clean );
			add_action( 'admin_notices', array( $this, 'notice_saved' ) );
		}

		// --- Wyczyszczenie dostępu dla pojedynczej roli (link GET, poza formularzem macierzy) ---
		if ( isset( $_GET['gfx_clear_role'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'gfx_clear_role' ) ) {
			$role  = sanitize_key( wp_unslash( $_GET['gfx_clear_role'] ) );
			$perms = GFX_Modules::get_permissions();
			$perms[ $role ] = array();
			GFX_Modules::save_permissions( $perms );
			add_action( 'admin_notices', array( $this, 'notice_saved' ) );
		}

		// --- Integracje: Firebase Cloud Messaging ---
		if ( isset( $_POST['gfx_integrations_nonce'] ) && wp_verify_nonce( $_POST['gfx_integrations_nonce'], 'gfx_save_integrations' ) ) {
			update_option( 'gfx_fcm_enabled', ! empty( $_POST['gfx_fcm_enabled'] ) ? '1' : '0' );

			$text_fields = array(
				'gfx_fcm_web_api_key',
				'gfx_fcm_auth_domain',
				'gfx_fcm_project_id',
				'gfx_fcm_storage_bucket',
				'gfx_fcm_messaging_sender_id',
				'gfx_fcm_app_id',
				'gfx_fcm_vapid_key',
				'gfx_fcm_test_title',
				'gfx_fcm_welcome_title',
			);
			foreach ( $text_fields as $field ) {
				if ( isset( $_POST[ $field ] ) ) {
					update_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
				}
			}
			if ( isset( $_POST['gfx_fcm_test_message'] ) ) {
				update_option( 'gfx_fcm_test_message', sanitize_textarea_field( wp_unslash( $_POST['gfx_fcm_test_message'] ) ) );
			}
			if ( isset( $_POST['gfx_fcm_welcome_message'] ) ) {
				update_option( 'gfx_fcm_welcome_message', sanitize_textarea_field( wp_unslash( $_POST['gfx_fcm_welcome_message'] ) ) );
			}
			update_option( 'gfx_fcm_welcome_enabled', ! empty( $_POST['gfx_fcm_welcome_enabled'] ) ? '1' : '0' );

			// Konto serwisowe nadpisujemy tylko, jeśli wklejono nową
			// wartość (nie jest ponownie wyświetlane po zapisaniu).
			if ( isset( $_POST['gfx_fcm_service_account_json'] ) && '' !== trim( wp_unslash( $_POST['gfx_fcm_service_account_json'] ) ) ) {
				$raw = wp_unslash( $_POST['gfx_fcm_service_account_json'] );
				$raw = trim( $raw );
				// Częsty realny problem: plik zapisany/otwarty w Notatniku na
				// Windows dostaje na początku znacznik BOM (3 bajty), który
				// psuje parsowanie JSON, choć wizualnie nic nie widać.
				$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
				// Kolejny częsty przypadek: wklejenie z blokiem markdown ```json ... ```.
				$raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
				$raw = preg_replace( '/```\s*$/', '', $raw );
				$raw = trim( $raw );

				$decoded    = json_decode( $raw, true );
				$json_error = json_last_error();

				if ( JSON_ERROR_NONE !== $json_error ) {
					$msg = sprintf(
						/* translators: %s: szczegóły błędu json_decode */
						__( 'Wklejony JSON konta serwisowego FCM nie parsuje się poprawnie (%s) - nie zapisano. Sprawdź, czy skopiowałeś/aś CAŁĄ zawartość pliku .json pobranego z Firebase, bez dodatkowych znaków na początku/końcu.', 'gastroflowx-hub' ),
						json_last_error_msg()
					);
					add_action(
						'admin_notices',
						function () use ( $msg ) {
							echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
						}
					);
				} elseif ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
					$missing = array();
					if ( empty( $decoded['client_email'] ) ) {
						$missing[] = 'client_email';
					}
					if ( empty( $decoded['private_key'] ) ) {
						$missing[] = 'private_key';
					}
					$msg = sprintf(
						/* translators: %s: nazwy brakujących pól JSON */
						__( 'JSON sparsował się poprawnie, ale brakuje w nim pola/pól: %s - to nie jest plik konta serwisowego (service account), tylko np. inny typ klucza z Firebase. Pobierz właściwy plik z "Ustawienia projektu → Konta usługi → Wygeneruj nowy klucz prywatny".', 'gastroflowx-hub' ),
						implode( ', ', $missing )
					);
					add_action(
						'admin_notices',
						function () use ( $msg ) {
							echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
						}
					);
				} else {
					update_option( 'gfx_fcm_service_account_json', $raw );
					if ( empty( $_POST['gfx_fcm_project_id'] ) && ! empty( $decoded['project_id'] ) ) {
						update_option( 'gfx_fcm_project_id', sanitize_text_field( $decoded['project_id'] ) );
					}
				}
			}

			if ( class_exists( 'GFX_Push' ) ) {
				GFX_Push::generate_service_worker_file();
			}
			add_action( 'admin_notices', array( $this, 'notice_saved' ) );
		}
		if ( isset( $_POST['gfx_titles_nonce'] ) && wp_verify_nonce( $_POST['gfx_titles_nonce'], 'gfx_save_titles' ) ) {
			$titles = isset( $_POST['gfx_title'] ) ? (array) $_POST['gfx_title'] : array();
			foreach ( $titles as $user_id => $title ) {
				$user_id = absint( $user_id );
				$title   = sanitize_text_field( wp_unslash( $title ) );
				if ( ! $user_id ) {
					continue;
				}
				if ( '' === $title ) {
					delete_user_meta( $user_id, 'gfx_display_role' );
				} else {
					update_user_meta( $user_id, 'gfx_display_role', $title );
				}
			}
			add_action( 'admin_notices', array( $this, 'notice_saved' ) );
		}
	}

	public function notice_saved() {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Zapisano zmiany.', 'gastroflowx-hub' ) . '</p></div>';
	}

	public function notice_module_missing_fields() {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Podaj nazwę modułu i shortcode — oba pola są wymagane.', 'gastroflowx-hub' ) . '</p></div>';
	}

	public function notice_module_id_taken() {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Moduł o takim identyfikatorze już istnieje (albo to nazwa zarezerwowana przez moduł wbudowany) — wybierz inną nazwę.', 'gastroflowx-hub' ) . '</p></div>';
	}

	public function render_general() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$name    = get_option( 'gfx_restaurant_name', 'GastroFlowx' );
		$logo_id = (int) get_option( 'gfx_logo_id', 0 );
		$logo_src = $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
		$hide_inactive_birthdays = '0' !== get_option( 'gfx_birthdays_hide_inactive', '1' );
		$privacy_url = get_option( 'gfx_privacy_policy_url', '' );
		$pinned      = GFX_Modules::bottom_nav_pinned();
		$slots       = GFX_Modules::BOTTOM_NAV_SLOTS;
		?>
		<div class="wrap gfx-wrap">
			<h1><?php esc_html_e( 'GastroFlowx — Ustawienia ogólne', 'gastroflowx-hub' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Ustaw nazwę restauracji oraz logo widoczne w nagłówku panelu SPA i na ekranie logowania.', 'gastroflowx-hub' ); ?></p>

			<form method="post" enctype="multipart/form-data" class="gfx-card">
				<?php wp_nonce_field( 'gfx_save_general', 'gfx_general_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="gfx_restaurant_name"><?php esc_html_e( 'Nazwa restauracji', 'gastroflowx-hub' ); ?></label></th>
						<td><input type="text" id="gfx_restaurant_name" name="gfx_restaurant_name" value="<?php echo esc_attr( $name ); ?>" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Logo', 'gastroflowx-hub' ); ?></label></th>
						<td>
							<div class="gfx-logo-picker">
								<img id="gfx-logo-preview" src="<?php echo esc_url( $logo_src ); ?>" style="<?php echo $logo_src ? '' : 'display:none;'; ?>max-width:96px;max-height:96px;border-radius:12px;border:1px solid #e2e2e2;object-fit:cover;" />
								<input type="hidden" name="gfx_logo_id" id="gfx_logo_id" value="<?php echo esc_attr( $logo_id ); ?>" />
								<p>
									<button type="button" class="button" id="gfx-logo-select"><?php esc_html_e( 'Wybierz logo', 'gastroflowx-hub' ); ?></button>
									<button type="button" class="button" id="gfx-logo-remove" <?php echo $logo_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Usuń logo', 'gastroflowx-hub' ); ?></button>
								</p>
								<p class="description"><?php esc_html_e( 'Jeśli nie wybierzesz logo, w nagłówku pojawi się domyślna ikona sztućców.', 'gastroflowx-hub' ); ?></p>
							</div>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Urodziny', 'gastroflowx-hub' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="gfx_birthdays_hide_inactive" value="1" <?php checked( $hide_inactive_birthdays ); ?> />
								<?php esc_html_e( 'Nie pokazuj urodzin pracowników oznaczonych jako nieaktywni', 'gastroflowx-hub' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Dotyczy zarówno listy w module Urodziny, jak i codziennych powiadomień push. Status „nieaktywny” ustawia się w profilu pracownika — to ta sama flaga, której używa moduł Grafiku Pracy do ukrywania byłych pracowników w siatce grafiku.', 'gastroflowx-hub' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="gfx_privacy_policy_url"><?php esc_html_e( 'Polityka prywatności — link', 'gastroflowx-hub' ); ?></label></th>
						<td>
							<input type="url" id="gfx_privacy_policy_url" name="gfx_privacy_policy_url" class="regular-text" value="<?php echo esc_attr( $privacy_url ); ?>" placeholder="https://werandalunchwine.pl/polityka-prywatnosci.pdf" />
							<p class="description"><?php esc_html_e( 'Adres do dokumentu polityki prywatności (np. plik PDF wgrany do biblioteki mediów albo strona na Twojej witrynie). Ten link pojawi się na ekranie zgody na przetwarzanie danych, który pracownicy widzą po zalogowaniu.', 'gastroflowx-hub' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Dolny pasek na telefonie', 'gastroflowx-hub' ); ?></th>
						<td>
							<input type="hidden" name="gfx_bottom_nav_submitted" value="1" />
							<fieldset id="gfx-bottom-nav-pins">
								<?php foreach ( GFX_Modules::all() as $id => $m ) : ?>
									<label style="display:inline-block;min-width:180px;margin:0 12px 6px 0;">
										<input type="checkbox" name="gfx_bottom_nav_pinned[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $pinned, true ) ); ?> />
										<?php echo esc_html( $m['label'] ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">
								<?php
								printf(
									/* translators: %d: number of slots */
									esc_html__( 'Zaznacz do %d modułów widocznych zawsze na dolnym pasku telefonu. Gdy pracownik ma dostęp do więcej niż %d modułów, pozostałe są w przycisku „Więcej” (z wylogowaniem). Moduły niedostępne dla danej osoby są pomijane, a wolne miejsca uzupełniają się kolejnymi modułami z menu. Na komputerze menu boczne pokazuje wszystkie moduły.', 'gastroflowx-hub' ),
									(int) $slots,
									(int) $slots + 1
								);
								?>
							</p>
							<script>
							( function () {
								var box = document.getElementById( 'gfx-bottom-nav-pins' ), max = <?php echo (int) $slots; ?>;
								function sync() {
									var boxes = box.querySelectorAll( 'input[type=checkbox]' ), n = 0;
									boxes.forEach( function ( b ) { if ( b.checked ) n++; } );
									boxes.forEach( function ( b ) { b.disabled = ! b.checked && n >= max; } );
								}
								box.addEventListener( 'change', sync );
								sync();
							} )();
							</script>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Zapisz ustawienia', 'gastroflowx-hub' ) ); ?>
			</form>

			<div class="gfx-card">
				<h2><?php esc_html_e( 'Instalacja panelu', 'gastroflowx-hub' ); ?></h2>
				<p><?php esc_html_e( 'Wstaw poniższy shortcode na dowolnej stronie (np. "Panel Pracownika"), aby wyświetlić logowanie i cały panel SPA:', 'gastroflowx-hub' ); ?></p>
				<code>[gastroflowx_app]</code>
			</div>
		</div>
		<?php
	}

	/**
	 * GastroFlowx → Moduły: pozwala dodać nową kategorię w menu panelu bez
	 * edycji kodu — wystarczy, że docelowa wtyczka rejestruje własny
	 * shortcode i sama ładuje swoje assety wewnątrz jego callbacku.
	 */
	public function render_modules() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$custom = GFX_Modules::custom_modules();
		?>
		<div class="wrap gfx-wrap">
			<h1><?php esc_html_e( 'GastroFlowx — Moduły', 'gastroflowx-hub' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Dodaj nową kategorię do menu panelu pracownika, wskazując shortcode innej zainstalowanej wtyczki — bez edytowania kodu.', 'gastroflowx-hub' ); ?></p>

			<div class="gfx-card" style="background:#EFF6FF;border:1px solid #BFDBFE;">
				<p style="margin:0;">
					<strong><?php esc_html_e( 'Zanim dodasz moduł:', 'gastroflowx-hub' ); ?></strong>
					<?php esc_html_e( 'wtyczka docelowa musi ładować swój JS/CSS wewnątrz callbacku własnego shortcode\'a (nie na hooku wp_enqueue_scripts przez has_shortcode na treści strony) — inaczej po osadzeniu w panelu zobaczysz tylko nieskończone „Ładowanie panelu…”. Jeśli tak nie jest, poproś autora modułu o dostosowanie albo zgłoś to programiście systemu.', 'gastroflowx-hub' ); ?>
				</p>
			</div>

			<form method="post" class="gfx-card">
				<?php wp_nonce_field( 'gfx_save_module', 'gfx_module_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="gfx_module_label"><?php esc_html_e( 'Nazwa w menu', 'gastroflowx-hub' ); ?></label></th>
						<td><input type="text" id="gfx_module_label" name="gfx_module_label" class="regular-text" placeholder="<?php esc_attr_e( 'np. Zamówienia', 'gastroflowx-hub' ); ?>" required /></td>
					</tr>
					<tr>
						<th><label for="gfx_module_shortcode"><?php esc_html_e( 'Shortcode', 'gastroflowx-hub' ); ?></label></th>
						<td>
							<input type="text" id="gfx_module_shortcode" name="gfx_module_shortcode" class="regular-text" placeholder="<?php esc_attr_e( 'np. moj_modul_app', 'gastroflowx-hub' ); ?>" required />
							<p class="description"><?php esc_html_e( 'Dokładna nazwa shortcode\'a zarejestrowanego przez docelową wtyczkę (bez nawiasów kwadratowych).', 'gastroflowx-hub' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="gfx_module_icon"><?php esc_html_e( 'Ikona (Font Awesome)', 'gastroflowx-hub' ); ?></label></th>
						<td>
							<input type="text" id="gfx_module_icon" name="gfx_module_icon" class="regular-text" placeholder="fa-puzzle-piece" />
							<p class="description"><?php esc_html_e( 'Opcjonalne — nazwa klasy ikony, np. fa-box lub fa-receipt. Lista ikon: fontawesome.com/search.', 'gastroflowx-hub' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Dodaj moduł', 'gastroflowx-hub' ) ); ?>
			</form>

			<div class="gfx-card">
				<h2><?php esc_html_e( 'Dodane moduły', 'gastroflowx-hub' ); ?></h2>
				<?php if ( empty( $custom ) ) : ?>
					<p class="description"><?php esc_html_e( 'Nie dodano jeszcze żadnego własnego modułu.', 'gastroflowx-hub' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Nazwa', 'gastroflowx-hub' ); ?></th>
								<th><?php esc_html_e( 'Shortcode', 'gastroflowx-hub' ); ?></th>
								<th><?php esc_html_e( 'Ikona', 'gastroflowx-hub' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $custom as $id => $m ) : ?>
							<tr>
								<td><?php echo esc_html( $m['label'] ); ?></td>
								<td><code><?php echo esc_html( '[' . $m['shortcode'] . ']' ); ?></code></td>
								<td><code><?php echo esc_html( $m['icon'] ); ?></code></td>
								<td>
									<?php
									$delete_url = wp_nonce_url(
										add_query_arg( 'gfx_delete_module', $id, admin_url( 'admin.php?page=gastroflowx-modules' ) ),
										'gfx_delete_module'
									);
									?>
									<a href="<?php echo esc_url( $delete_url ); ?>" class="gfx-danger-link" onclick="return confirm('<?php echo esc_js( __( 'Usunąć ten moduł z menu panelu? Dostęp ról do niego również zostanie wyczyszczony.', 'gastroflowx-hub' ) ); ?>');">
										<?php esc_html_e( 'Usuń', 'gastroflowx-hub' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description" style="margin-top:10px;"><?php esc_html_e( 'Po dodaniu modułu przejdź do „Dostęp ról”, aby zaznaczyć, które role mają go widzieć.', 'gastroflowx-hub' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function render_integrations() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$enabled       = get_option( 'gfx_fcm_enabled', '0' );
		$web_api_key   = get_option( 'gfx_fcm_web_api_key', '' );
		$auth_domain   = get_option( 'gfx_fcm_auth_domain', '' );
		$project_id    = get_option( 'gfx_fcm_project_id', '' );
		$storage_bkt   = get_option( 'gfx_fcm_storage_bucket', '' );
		$sender_id     = get_option( 'gfx_fcm_messaging_sender_id', '' );
		$app_id        = get_option( 'gfx_fcm_app_id', '' );
		$vapid_key     = get_option( 'gfx_fcm_vapid_key', '' );
		$has_sa        = (bool) get_option( 'gfx_fcm_service_account_json', '' );
		?>
		<div class="wrap gfx-wrap">
			<h1><?php esc_html_e( 'GastroFlowx — Integracje', 'gastroflowx-hub' ); ?></h1>

			<?php if ( isset( $_GET['gfx_debug_toggled'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Konsola diagnostyczna została przełączona. Otwórz stronę na froncie (np. na iPhonie z ikony), żeby ją zobaczyć.', 'gastroflowx-hub' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['gfx_test_sent'] ) ) : ?>
				<?php
				$current_user_id = get_current_user_id();
				$test_result     = get_transient( 'gfx_fcm_test_result_' . $current_user_id );
				delete_transient( 'gfx_fcm_test_result_' . $current_user_id );
				?>
				<?php if ( is_array( $test_result ) ) : ?>
					<div class="notice <?php echo $test_result['ok'] ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $test_result['msg'] ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Wysłano żądanie testowego powiadomienia do Firebase. Sprawdź, czy dotarło na urządzenie zasubskrybowane w przeglądarce.', 'gastroflowx-hub' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="gfx-card">
				<h2><?php esc_html_e( 'Konfiguracja scentralizowana', 'gastroflowx-hub' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'To jest JEDYNE miejsce, w którym konfiguruje się Firebase Cloud Messaging (FCM). Gdy integracja jest tu włączona i skonfigurowana, ten panel automatycznie zarządza SDK Firebase (inicjalizacja + zgoda + token) na KAŻDEJ stronie frontendu, dla KAŻDEGO zalogowanego pracownika — również tych, którzy korzystają wyłącznie z Grafiku lub Napiwków.', 'gastroflowx-hub' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Uwaga: w odróżnieniu od wcześniejszej integracji z OneSignal, Grafik i Napiwki NIE mają już własnej, zapasowej konfiguracji push — jeśli ten panel jest nieaktywny, powiadomienia push są po prostu pomijane (pozostałe kanały, np. e-mail, działają nadal).', 'gastroflowx-hub' ); ?>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link do ekranu powiadomień push */
						esc_html__( 'Statusy zgód użytkowników i historię każdej wysyłki (ze wszystkich trzech wtyczek) znajdziesz w %s.', 'gastroflowx-hub' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=gastroflowx-push' ) ) . '">' . esc_html__( 'GastroFlowx → Powiadomienia push', 'gastroflowx-hub' ) . '</a>'
					);
					?>
				</p>
			</div>

			<div class="gfx-card">
				<h2><?php esc_html_e( 'Firebase Cloud Messaging — powiadomienia push', 'gastroflowx-hub' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Codziennie o 9:00 wtyczka sprawdza, czy ktoś z zespołu ma dziś urodziny i wysyła powiadomienie push przez FCM. Grafik i Napiwki wysyłają tą samą drogą przy własnych zdarzeniach (nowy grafik, rozliczenie napiwków, itd.).', 'gastroflowx-hub' ); ?></p>

				<form method="post">
					<?php wp_nonce_field( 'gfx_save_integrations', 'gfx_integrations_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Włącz powiadomienia', 'gastroflowx-hub' ); ?></th>
							<td><label><input type="checkbox" name="gfx_fcm_enabled" value="1" <?php checked( '1', $enabled ); ?> /> <?php esc_html_e( 'Wysyłaj powiadomienia push przez Firebase', 'gastroflowx-hub' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="gfx_fcm_project_id"><?php esc_html_e( 'Project ID', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="text" id="gfx_fcm_project_id" name="gfx_fcm_project_id" value="<?php echo esc_attr( $project_id ); ?>" class="regular-text" placeholder="np. gastroflowx-abc12" /></td>
						</tr>
						<tr>
							<th><label for="gfx_fcm_web_api_key"><?php esc_html_e( 'Web API Key', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="text" id="gfx_fcm_web_api_key" name="gfx_fcm_web_api_key" value="<?php echo esc_attr( $web_api_key ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="gfx_fcm_auth_domain"><?php esc_html_e( 'Auth Domain', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="text" id="gfx_fcm_auth_domain" name="gfx_fcm_auth_domain" value="<?php echo esc_attr( $auth_domain ); ?>" class="regular-text" placeholder="np. gastroflowx-abc12.firebaseapp.com" /></td>
						</tr>
						<tr>
							<th><label for="gfx_fcm_storage_bucket"><?php esc_html_e( 'Storage Bucket', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="text" id="gfx_fcm_storage_bucket" name="gfx_fcm_storage_bucket" value="<?php echo esc_attr( $storage_bkt ); ?>" class="regular-text" placeholder="np. gastroflowx-abc12.appspot.com" /></td>
						</tr>
						<tr>
							<th><label for="gfx_fcm_messaging_sender_id"><?php esc_html_e( 'Messaging Sender ID', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="text" id="gfx_fcm_messaging_sender_id" name="gfx_fcm_messaging_sender_id" value="<?php echo esc_attr( $sender_id ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="gfx_fcm_app_id"><?php esc_html_e( 'App ID (Web SDK)', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="text" id="gfx_fcm_app_id" name="gfx_fcm_app_id" value="<?php echo esc_attr( $app_id ); ?>" class="regular-text" placeholder="np. 1:12345:web:abcdef" /></td>
						</tr>
						<tr>
							<th><label for="gfx_fcm_vapid_key"><?php esc_html_e( 'VAPID Key', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="text" id="gfx_fcm_vapid_key" name="gfx_fcm_vapid_key" value="<?php echo esc_attr( $vapid_key ); ?>" class="regular-text" placeholder="Cloud Messaging → Web configuration → Web Push certificates" /></td>
						</tr>
						<tr>
							<th><label for="gfx_fcm_service_account_json"><?php esc_html_e( 'Konto serwisowe (JSON)', 'gastroflowx-hub' ); ?><?php if ( $has_sa ) : ?> <span class="gfx-tag"><?php esc_html_e( 'zapisane', 'gastroflowx-hub' ); ?></span><?php endif; ?></label></th>
							<td>
								<textarea id="gfx_fcm_service_account_json" name="gfx_fcm_service_account_json" rows="6" class="large-text code" placeholder="<?php echo $has_sa ? esc_attr__( 'Zapisane — wklej nową zawartość, żeby zmienić', 'gastroflowx-hub' ) : esc_attr__( 'Wklej pełną zawartość pliku JSON konta serwisowego (Ustawienia projektu → Konta usługi → Wygeneruj nowy klucz prywatny)', 'gastroflowx-hub' ); ?>"></textarea>
								<p class="description"><?php esc_html_e( 'Wymagane do wysyłki po stronie serwera (FCM HTTP v1). Nie jest ponownie wyświetlane po zapisaniu ze względów bezpieczeństwa — zostaw puste, żeby zachować obecne.', 'gastroflowx-hub' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Powitalne powiadomienie', 'gastroflowx-hub' ); ?></th>
							<td><label><input type="checkbox" name="gfx_fcm_welcome_enabled" value="1" <?php checked( '1', get_option( 'gfx_fcm_welcome_enabled', '1' ) ); ?> /> <?php esc_html_e( 'Wysyłaj automatycznie, gdy ktoś pierwszy raz zaakceptuje powiadomienia na danym urządzeniu', 'gastroflowx-hub' ); ?></label></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Treść i ikony powiadomień', 'gastroflowx-hub' ); ?></th>
							<td>
								<p class="description">
									<?php esc_html_e( 'Tytuły, treści i ikony wszystkich powiadomień (w tym testowego i powitalnego) edytujesz w jednym miejscu:', 'gastroflowx-hub' ); ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=gastroflowx-push&tab=templates' ) ); ?>"><?php esc_html_e( 'Powiadomienia push → Szablony i ikony', 'gastroflowx-hub' ); ?></a>
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Zapisz integrację', 'gastroflowx-hub' ) ); ?>
				</form>
			</div>

			<div class="gfx-card">
				<h2><?php esc_html_e( 'Test', 'gastroflowx-hub' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Wyślij testowe powiadomienie od razu, żeby sprawdzić poprawność konfiguracji (bez czekania na cron).', 'gastroflowx-hub' ); ?></p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gfx_fcm_test' ), 'gfx_fcm_test' ) ); ?>"><?php esc_html_e( 'Wyślij testowe powiadomienie', 'gastroflowx-hub' ); ?></a>

				<p class="description" style="margin-top:16px;"><?php esc_html_e( 'Sprawdź od razu, czy dopasowanie dzisiejszej daty do urodzin w zespole działa i (jeśli tak) uruchom wysyłkę - bez czekania na codzienny cron o 9:00.', 'gastroflowx-hub' ); ?></p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gfx_fcm_check_birthdays_now' ), 'gfx_fcm_check_birthdays_now' ) ); ?>"><?php esc_html_e( 'Sprawdź urodziny teraz', 'gastroflowx-hub' ); ?></a>

				<?php $debug_on = (bool) get_user_meta( get_current_user_id(), 'gfx_push_debug_enabled', true ); ?>
				<p class="description" style="margin-top:16px;"><?php esc_html_e( 'Pływająca konsola JS (Eruda) na Twoim koncie, widoczna wprost na ekranie telefonu - przydatna do diagnozowania problemów z powiadomieniami w zainstalowanej aplikacji na iOS, gdzie nie da się podłączyć zdalnego debugera bez Maca.', 'gastroflowx-hub' ); ?></p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gfx_push_toggle_debug' ), 'gfx_push_toggle_debug' ) ); ?>"><?php echo $debug_on ? esc_html__( 'Wyłącz konsolę diagnostyczną', 'gastroflowx-hub' ) : esc_html__( 'Włącz konsolę diagnostyczną', 'gastroflowx-hub' ); ?></a>
				<?php if ( $debug_on ) : ?>
					<span class="gfx-tag"><?php esc_html_e( 'aktywna na Twoim koncie', 'gastroflowx-hub' ); ?></span>
				<?php endif; ?>
			</div>

			<div class="gfx-card">
				<h2><?php esc_html_e( 'Konfiguracja jednorazowa', 'gastroflowx-hub' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Załóż projekt na console.firebase.google.com i dodaj do niego aplikację webową.', 'gastroflowx-hub' ); ?></li>
					<li><?php esc_html_e( 'W "Ustawienia projektu → Ogólne" skopiuj apiKey, authDomain, projectId, storageBucket, messagingSenderId oraz appId i wklej powyżej.', 'gastroflowx-hub' ); ?></li>
					<li><?php esc_html_e( 'W "Ustawienia projektu → Cloud Messaging → Web configuration" wygeneruj (albo skopiuj istniejący) "Web Push certificate" (klucz VAPID) i wklej powyżej.', 'gastroflowx-hub' ); ?></li>
					<li><?php esc_html_e( 'W "Ustawienia projektu → Konta usługi" wygeneruj nowy klucz prywatny (JSON) i wklej jego CAŁĄ zawartość powyżej — to jest potrzebne do wysyłki po stronie serwera.', 'gastroflowx-hub' ); ?></li>
					<li><?php esc_html_e( 'Po zapisaniu wtyczka próbuje sama utworzyć w katalogu głównym witryny plik firebase-messaging-sw.js, wymagany do obsługi powiadomień w tle. Jeśli katalog główny nie jest zapisywalny, admin może wgrać go ręcznie przez FTP (treść generowana jest automatycznie na podstawie ustawień powyżej).', 'gastroflowx-hub' ); ?></li>
					<li><?php esc_html_e( 'Po zapisaniu ustawień, zalogowani pracownicy zobaczą w przeglądarce prośbę o zgodę na powiadomienia push — po jej zaakceptowaniu będą otrzymywać alerty.', 'gastroflowx-hub' ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	public function render_titles() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$users = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$role_names = GFX_Modules::all_roles();
		?>
		<div class="wrap gfx-wrap">
			<h1><?php esc_html_e( 'GastroFlowx — Tytuły pracowników', 'gastroflowx-hub' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Tekst wpisany tutaj zastępuje w nagłówku panelu SPA (obok imienia i nazwiska) automatycznie odczytywaną nazwę roli WordPress — np. zamiast "Administrator" możesz ustawić "Kelner", "Szef kuchni" itp. Puste pole = pokazywana jest domyślna nazwa roli WordPress.', 'gastroflowx-hub' ); ?></p>

			<form method="post" class="gfx-card">
				<?php wp_nonce_field( 'gfx_save_titles', 'gfx_titles_nonce' ); ?>
				<table class="widefat gfx-matrix">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Użytkownik', 'gastroflowx-hub' ); ?></th>
							<th><?php esc_html_e( 'Rola WordPress', 'gastroflowx-hub' ); ?></th>
							<th><?php esc_html_e( 'Wyświetlany tytuł w panelu', 'gastroflowx-hub' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $users as $u ) :
						$wp_role  = ! empty( $u->roles ) ? ( $role_names[ $u->roles[0] ] ?? $u->roles[0] ) : '—';
						$current  = get_user_meta( $u->ID, 'gfx_display_role', true );
						?>
						<tr>
							<td><strong><?php echo esc_html( $u->display_name ); ?></strong><br /><span class="description"><?php echo esc_html( $u->user_login ); ?></span></td>
							<td><?php echo esc_html( $wp_role ); ?></td>
							<td>
								<input type="text" class="regular-text" name="gfx_title[<?php echo esc_attr( $u->ID ); ?>]" value="<?php echo esc_attr( $current ); ?>" placeholder="<?php echo esc_attr( $wp_role ); ?>" />
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Zapisz tytuły', 'gastroflowx-hub' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function render_access() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$roles   = GFX_Modules::all_roles();
		$modules = GFX_Modules::permissionable();
		$perms   = GFX_Modules::get_permissions();
		?>
		<div class="wrap gfx-wrap">
			<h1><?php esc_html_e( 'GastroFlowx — Dostęp ról do kategorii', 'gastroflowx-hub' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Zaznacz, do których kategorii (modułów) w panelu SPA ma dostęp dana rola. Administrator ma zawsze pełny dostęp. Osoby bez zaznaczonych kategorii zobaczą tylko stronę domową i swoje konto oraz komunikat o braku dostępu w pozostałych modułach.', 'gastroflowx-hub' ); ?></p>

			<form method="post" class="gfx-card">
				<?php wp_nonce_field( 'gfx_save_access', 'gfx_access_nonce' ); ?>
				<table class="widefat gfx-matrix">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Rola', 'gastroflowx-hub' ); ?></th>
							<?php foreach ( $modules as $id => $m ) : ?>
								<th><?php echo esc_html( $m['label'] ); ?></th>
							<?php endforeach; ?>
							<th><?php esc_html_e( 'Akcje', 'gastroflowx-hub' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $roles as $slug => $label ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong><br /><code><?php echo esc_html( $slug ); ?></code></td>
							<?php foreach ( $modules as $mod_id => $m ) : ?>
								<td style="text-align:center;">
									<?php if ( 'administrator' === $slug ) : ?>
										<input type="checkbox" checked disabled title="<?php esc_attr_e( 'Administrator ma zawsze pełny dostęp', 'gastroflowx-hub' ); ?>" />
									<?php else : ?>
										<input type="checkbox" name="gfx_perm[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $mod_id ); ?>]" value="1" <?php checked( ! empty( $perms[ $slug ][ $mod_id ] ) ); ?> />
									<?php endif; ?>
								</td>
							<?php endforeach; ?>
							<td>
								<?php if ( 'administrator' !== $slug ) : ?>
									<?php
									$clear_url = wp_nonce_url(
										add_query_arg( array( 'page' => 'gastroflowx-access', 'gfx_clear_role' => $slug ), admin_url( 'admin.php' ) ),
										'gfx_clear_role'
									);
									?>
									<a href="<?php echo esc_url( $clear_url ); ?>" class="button-link-delete gfx-clear-role" onclick="return confirm('<?php echo esc_js( __( 'Usunąć cały dostęp dla tej roli?', 'gastroflowx-hub' ) ); ?>');"><?php esc_html_e( 'Wyczyść', 'gastroflowx-hub' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Zapisz macierz dostępu', 'gastroflowx-hub' ) ); ?>
			</form>
		</div>
		<?php
	}
}
