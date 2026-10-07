<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [gastroflowx_app] — cała aplikacja SPA:
 * ekran logowania (niezalogowany) lub panel z sidebarem / dolną nawigacją
 * i treścią modułów renderowaną wg uprawnień roli.
 */
class GFX_Shell {

	public function __construct() {
		add_shortcode( 'gastroflowx_app', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 100 );
		// Musi wystrzelić PRZED priorytetem domyślnym (10), na którym te wtyczki
		// próbują same się załadować (patrz metoda niżej) — u nas i tak liczy się
		// tylko to, że wp_enqueue_script/style zostanie wywołane w ogóle na czas.
		add_action( 'wp_enqueue_scripts', array( $this, 'ensure_child_module_assets' ), 5 );
	}

	/**
	 * Wtyczki modułowe (Grafik, Lunch, Kolorowanki) ładują swoje skrypty
	 * (Vue itd.) tylko wtedy, gdy `has_shortcode( $post->post_content, '...' )`
	 * wykryje ICH shortcode dosłownie w treści strony. Ponieważ my osadzamy
	 * te shortcode'y dynamicznie przez do_shortcode() wewnątrz [gastroflowx_app],
	 * ten dosłowny tekst nigdy tam nie występuje i moduły zostają bez skryptów
	 * (efekt: "Ładowanie panelu…" w nieskończoność albo pusty ekran).
	 *
	 * WAŻNE: wcześniejsza wersja tej metody doklejała te shortcode'y do
	 * $post->post_content w pamięci, żeby oszukać has_shortcode(). To
	 * DZIAŁAŁO dla wykrywania, ale miało efekt uboczny: standardowy
	 * renderowania treści WordPressa (the_content -> do_shortcode) też
	 * przetwarza CAŁY post_content, więc doklejone shortcode'y renderowały
	 * się PONOWNIE, osobno, pod głównym panelem (stąd zduplikowane "Ładowanie
	 * panelu…"). Poprawka: zamiast ingerować w treść posta, wywołujemy tu
	 * bezpośrednio te same wp_enqueue_script/style, które te wtyczki
	 * wywołałyby same, gdyby ich shortcode był dosłownie w treści strony.
	 * Zero duplikacji renderowania, zero ingerencji w cudzy kod.
	 */
	public function ensure_child_module_assets() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'gastroflowx_app' ) ) {
			return;
		}

		if ( GFX_Modules::user_can_access( 'lunch' ) ) {
			$this->enqueue_weranda_lunch_assets();
		}
		if ( GFX_Modules::user_can_access( 'colors' ) ) {
			$this->enqueue_weranda_kolor_assets();
		}
		if ( GFX_Modules::user_can_access( 'schedule' ) ) {
			$this->enqueue_scheduler_assets();
		}
		// Napiwki (system-napiwkow-spa) i Godziny (employee-timesheet) ładują
		// swoje zasoby inaczej (bez bramki has_shortcode na post_content),
		// więc nie wymagają tu żadnej dodatkowej obsługi.
	}

	protected function enqueue_weranda_lunch_assets() {
		if ( ! defined( 'WERANDA_LUNCH_URL' ) ) {
			return; // Wtyczka nieaktywna.
		}
		wp_enqueue_script( 'vue3', 'https://unpkg.com/vue@3.4.21/dist/vue.global.prod.js', array(), '3.4.21', true );
		wp_enqueue_script( 'pdf-lib', 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js', array(), '1.17.1', true );
		wp_enqueue_script( 'fontkit', 'https://unpkg.com/@pdf-lib/fontkit@0.0.4/dist/fontkit.umd.min.js', array(), '0.0.4', true );
		wp_enqueue_script( 'pdf-js', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js', array(), '2.16.105', true );
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'weranda-lunch-style', WERANDA_LUNCH_URL . 'assets/style.css', array(), defined( 'WERANDA_LUNCH_VERSION' ) ? WERANDA_LUNCH_VERSION : GFX_VERSION );
		wp_enqueue_script( 'weranda-lunch-app', WERANDA_LUNCH_URL . 'assets/app.js', array( 'vue3', 'pdf-lib', 'fontkit', 'pdf-js' ), defined( 'WERANDA_LUNCH_VERSION' ) ? WERANDA_LUNCH_VERSION : GFX_VERSION, true );
		wp_localize_script(
			'weranda-lunch-app',
			'werandaLunchConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'weranda-lunch/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'isAdmin' => current_user_can( 'administrator' ),
				'fonts'   => array(
					'poppins' => 'https://cdn.jsdelivr.net/gh/google/fonts@main/ofl/poppins/Poppins-ExtraLight.ttf',
					'barlow'  => 'https://cdn.jsdelivr.net/gh/google/fonts@main/ofl/barlowcondensed/BarlowCondensed-Light.ttf',
				),
			)
		);
	}

	protected function enqueue_weranda_kolor_assets() {
		if ( ! defined( 'WERANDA_KOLOR_URL' ) ) {
			return; // Wtyczka nieaktywna.
		}
		wp_enqueue_script( 'vue3', 'https://unpkg.com/vue@3.4.21/dist/vue.global.prod.js', array(), '3.4.21', true );
		wp_enqueue_script( 'pdf-lib', 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js', array(), '1.17.1', true );
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'weranda-kolor-style', WERANDA_KOLOR_URL . 'assets/style.css', array(), defined( 'WERANDA_KOLOR_VERSION' ) ? WERANDA_KOLOR_VERSION : GFX_VERSION );
		wp_enqueue_script( 'weranda-kolor-app', WERANDA_KOLOR_URL . 'assets/app.js', array( 'vue3', 'pdf-lib' ), defined( 'WERANDA_KOLOR_VERSION' ) ? WERANDA_KOLOR_VERSION : GFX_VERSION, true );
		wp_localize_script(
			'weranda-kolor-app',
			'werandaKolorConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'weranda-kolor/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'isAdmin' => current_user_can( 'administrator' ),
			)
		);
	}

	protected function enqueue_scheduler_assets() {
		if ( ! defined( 'RS_PLUGIN_DIR' ) || ! class_exists( 'RS_Scope' ) ) {
			return; // Wtyczka nieaktywna.
		}
		$manifest_path = RS_PLUGIN_DIR . 'assets/dist/.vite/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			return; // Build developerski jeszcze nie wykonany.
		}
		$manifest = json_decode( file_get_contents( $manifest_path ), true );
		$entry    = $manifest['src/main.js'] ?? null;
		if ( ! $entry ) {
			return;
		}

		wp_enqueue_style( 'restaurant-scheduler-app', RS_PLUGIN_URL . 'assets/dist/' . ( $entry['css'][0] ?? '' ), array(), RS_VERSION );
		wp_enqueue_script( 'restaurant-scheduler-app', RS_PLUGIN_URL . 'assets/dist/' . $entry['file'], array(), RS_VERSION, true );
		wp_script_add_data( 'restaurant-scheduler-app', 'type', 'module' );

		$current_user = wp_get_current_user();
		wp_localize_script(
			'restaurant-scheduler-app',
			'RS_CONFIG',
			array(
				'apiUrl'        => esc_url_raw( rest_url( 'restaurant-scheduler/v1' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'logoUrl'       => esc_url_raw( RS_PLUGIN_URL . 'assets/public/logo.svg' ),
				'brandName'     => get_option( 'gfx_restaurant_name', 'GastroFlowx' ),
				'currentUser'   => array(
					'id'              => $current_user->ID,
					'displayName'     => $current_user->display_name,
					'isManager'       => user_can( $current_user, 'rs_manage_schedule' ),
					'canViewCombined' => user_can( $current_user, 'rs_view_combined_schedule' ),
					'isClosing'       => (bool) get_user_meta( $current_user->ID, 'rs_is_closing', true ),
					'managerScope'    => RS_Scope::manager_scope( $current_user->ID ),
					'employeeSection' => RS_Scope::employee_section( $current_user->ID ),
					'roleLabel'       => RS_Scope::role_label_for_user( $current_user->ID ),
					'roles'           => RS_Scope::role_slugs_for_user( $current_user->ID ),
				),
				'pushPublicKey' => get_option( 'rs_vapid_public_key', '' ),
				'icalUrl'       => class_exists( 'RS_ICal' ) ? RS_ICal::feed_url( $current_user->ID ) : '',
				'restNonceLife' => DAY_IN_SECONDS,
			)
		);
	}

	public function maybe_enqueue() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'gastroflowx_app' ) ) {
			return;
		}
		wp_enqueue_style( 'font-awesome-gfx', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1' );
		wp_enqueue_style( 'gfx-shell', GFX_PLUGIN_URL . 'assets/css/shell.css', array(), GFX_VERSION );
		wp_enqueue_style( 'gfx-brand-override', GFX_PLUGIN_URL . 'assets/css/brand-override.css', array(), GFX_VERSION );

		wp_enqueue_script( 'gfx-shell', GFX_PLUGIN_URL . 'assets/js/shell.js', array(), GFX_VERSION, true );
		wp_localize_script(
			'gfx-shell',
			'GFX_DATA',
			array(
				'restUrl' => esc_url_raw( rest_url( GFX_REST_NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'homeUrl' => home_url( add_query_arg( array(), $GLOBALS['wp']->request ) ),
				'i18n'    => array(
					'loginError' => __( 'Nieprawidłowy login lub hasło.', 'gastroflowx-hub' ),
					'saved'      => __( 'Zapisano zmiany.', 'gastroflowx-hub' ),
				),
			)
		);

		if ( is_user_logged_in() && GFX_Modules::user_can_access( 'birthdays' ) && class_exists( 'GFX_Birthdays' ) ) {
			wp_enqueue_style( 'gfx-birthdays', GFX_PLUGIN_URL . 'assets/css/birthdays.css', array( 'gfx-shell' ), GFX_VERSION );
			wp_enqueue_script( 'gfx-birthdays', GFX_PLUGIN_URL . 'assets/js/birthdays.js', array( 'gfx-shell' ), GFX_VERSION, true );

			$all = array();
			foreach ( GFX_Birthdays::collect() as $item ) {
				$all[] = array(
					'month' => $item['month'],
					'day'   => $item['day'],
					'name'  => $item['name'],
				);
			}
			wp_localize_script(
				'gfx-birthdays',
				'GFX_BIRTHDAYS',
				array(
					'all'         => $all,
					'monthNames'  => array_map( 'strtoupper', GFX_Birthdays::month_names() ),
					'i18n'        => array(
						'today' => __( 'DZIŚ', 'gastroflowx-hub' ),
						'saved' => __( 'Zapisano!', 'gastroflowx-hub' ),
						'error' => __( 'Błąd zapisu.', 'gastroflowx-hub' ),
					),
				)
			);
		}
	}

	public function render() {
		if ( ! is_user_logged_in() ) {
			return $this->render_login();
		}
		if ( class_exists( 'GFX_Consent' ) && ! GFX_Consent::has_consented( get_current_user_id() ) ) {
			return $this->render_consent_gate();
		}
		return $this->render_app();
	}

	/* ---------------------------------------------------------------
	 * ZGODA NA PRZETWARZANIE DANYCH (polityka prywatności)
	 * ------------------------------------------------------------- */
	protected function render_consent_gate() {
		$policy_url = get_option( 'gfx_privacy_policy_url', '' );
		$name       = get_option( 'gfx_restaurant_name', 'GastroFlowx' );

		ob_start();
		?>
		<div class="gfx-root">
			<div class="gfx-login-wrap">
				<div class="gfx-login-card fade-in gfx-consent-card">
					<div class="gfx-login-icon">
						<i class="fa-solid fa-shield-halved"></i>
					</div>
					<h3 class="gfx-login-title"><?php esc_html_e( 'Zgoda na przetwarzanie danych', 'gastroflowx-hub' ); ?></h3>
					<p class="gfx-login-sub">
						<?php
						printf(
							/* translators: %s: nazwa restauracji */
							esc_html__( 'Aby korzystać z panelu pracownika %s, musisz wyrazić zgodę na przetwarzanie Twoich danych osobowych zgodnie z naszą polityką prywatności.', 'gastroflowx-hub' ),
							esc_html( $name )
						);
						?>
					</p>

					<?php if ( $policy_url ) : ?>
						<p style="text-align:center;margin-bottom:16px;">
							<a href="<?php echo esc_url( $policy_url ); ?>" target="_blank" rel="noopener" class="gfx-consent-policy-link">
								<i class="fa-solid fa-file-lines"></i> <?php esc_html_e( 'Przeczytaj politykę prywatności', 'gastroflowx-hub' ); ?>
							</a>
						</p>
					<?php endif; ?>

					<p class="gfx-consent-error" id="gfx-consent-declined" style="display:none;">
						<?php esc_html_e( 'Zgoda jest niezbędna, aby korzystać z systemu. Jeśli zmienisz zdanie, możesz wyrazić zgodę poniżej.', 'gastroflowx-hub' ); ?>
					</p>

					<button type="button" class="gfx-btn gfx-btn-primary gfx-btn-block" id="gfx-consent-agree">
						<?php esc_html_e( 'Wyrażam zgodę i chcę korzystać z systemu', 'gastroflowx-hub' ); ?>
					</button>
					<button type="button" class="gfx-btn gfx-btn-link gfx-btn-block" id="gfx-consent-decline">
						<?php esc_html_e( 'Nie wyrażam zgody', 'gastroflowx-hub' ); ?>
					</button>

					<div class="gfx-account-logout" style="margin-top:18px;">
						<button type="button" class="gfx-btn gfx-btn-danger gfx-btn-block gfx-logout">
							<i class="fa-solid fa-right-from-bracket"></i> <?php esc_html_e( 'Wyloguj się', 'gastroflowx-hub' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------
	 * EKRAN LOGOWANIA
	 * ------------------------------------------------------------- */
	protected function render_login() {
		$name    = get_option( 'gfx_restaurant_name', 'GastroFlowx' );
		$logo_id = (int) get_option( 'gfx_logo_id', 0 );
		$logo    = $logo_id ? wp_get_attachment_image( $logo_id, 'thumbnail', false, array( 'class' => 'gfx-login-logo-img' ) ) : '';
		$icon_class = $logo_id ? 'gfx-login-icon has-logo' : 'gfx-login-icon';

		ob_start();
		?>
		<div class="gfx-root">
			<div class="gfx-login-wrap">
				<div class="gfx-login-card fade-in">
					<div class="<?php echo esc_attr( $icon_class ); ?>">
						<?php if ( $logo ) : ?>
							<?php echo $logo; ?>
						<?php else : ?>
							<i class="fa-solid fa-lock"></i>
						<?php endif; ?>
					</div>
					<h3 class="gfx-login-title"><?php esc_html_e( 'Panel Pracownika', 'gastroflowx-hub' ); ?></h3>
					<p class="gfx-login-sub"><?php esc_html_e( 'Zaloguj się, aby uzyskać dostęp do modułów.', 'gastroflowx-hub' ); ?></p>

					<form id="gfx-login-form" class="gfx-login-form">
						<div class="gfx-field">
							<label for="gfx-login-username"><?php esc_html_e( 'Login pracownika', 'gastroflowx-hub' ); ?></label>
							<div class="gfx-input-wrap">
								<i class="fa-solid fa-user"></i>
								<input type="text" id="gfx-login-username" name="login" autocomplete="username" required placeholder="<?php esc_attr_e( ' ', 'gastroflowx-hub' ); ?>" />
		
							</div>
						</div>

						<div class="gfx-field">
							<label for="gfx-login-password"><?php esc_html_e( 'Hasło', 'gastroflowx-hub' ); ?></label>
							<div class="gfx-input-wrap">
								<i class="fa-solid fa-key"></i>
								<input type="password" id="gfx-login-password" name="password" autocomplete="current-password" required placeholder="<?php esc_attr_e( ' ', 'gastroflowx-hub' ); ?>" />
							</div>
						</div>

						<label class="gfx-remember">
							<input type="checkbox" name="remember" id="gfx-login-remember" checked />
							<span><?php esc_html_e( 'Zapamiętaj mnie', 'gastroflowx-hub' ); ?></span>
						</label>

						<p class="gfx-login-error" id="gfx-login-error" style="display:none;"></p>

						<button type="submit" class="gfx-btn gfx-btn-primary gfx-btn-block" id="gfx-login-submit">
							<?php esc_html_e( 'Zaloguj się', 'gastroflowx-hub' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------
	 * APLIKACJA (po zalogowaniu)
	 * ------------------------------------------------------------- */
	protected function render_app() {
		$user        = wp_get_current_user();
		$name        = get_option( 'gfx_restaurant_name', 'GastroFlowx' );
		$logo_id     = (int) get_option( 'gfx_logo_id', 0 );
		$logo        = $logo_id ? wp_get_attachment_image( $logo_id, 'thumbnail', false, array( 'class' => 'gfx-brand-logo-img' ) ) : '';
		$brand_icon_class = $logo_id ? 'gfx-brand-icon has-logo' : 'gfx-brand-icon';
		$role_label  = GFX_Modules::primary_role_label( $user );
		$all_modules = GFX_Modules::all();
		$accessible  = GFX_Modules::accessible_module_ids( $user );
		$default_tab = in_array( 'home', $accessible, true ) ? 'home' : ( $accessible ? $accessible[0] : 'home' );

		ob_start();
		?>
		<div class="gfx-root" id="gfx-app" data-default-tab="<?php echo esc_attr( $default_tab ); ?>">
			<aside class="gfx-sidebar" id="gfx-sidebar">
				<nav class="gfx-nav">
					<?php foreach ( $all_modules as $id => $m ) :
						if ( ! in_array( $id, $accessible, true ) ) {
							continue;
						}
						?>
						<button type="button" class="gfx-nav-btn" data-tab="<?php echo esc_attr( $id ); ?>">
							<i class="fa-solid <?php echo esc_attr( $m['icon'] ); ?>"></i>
							<span><?php echo esc_html( $m['label'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</nav>

				<div class="gfx-sidebar-footer">
					<button type="button" class="gfx-sidebar-logout gfx-logout">
						<i class="fa-solid fa-right-from-bracket"></i>
						<span><?php esc_html_e( 'Wyloguj się', 'gastroflowx-hub' ); ?></span>
					</button>
				</div>
			</aside>

			<div class="gfx-main">
				<header class="gfx-header">
					<div class="gfx-brand">
						<div class="<?php echo esc_attr( $brand_icon_class ); ?>">
							<?php if ( $logo ) : ?>
								<?php echo $logo; ?>
							<?php else : ?>
								<i class="fa-solid fa-utensils"></i>
							<?php endif; ?>
						</div>
						<div class="gfx-brand-text">
							<h1><?php echo esc_html( $name ); ?></h1>
							<h2 id="gfx-current-title"><?php echo esc_html( $all_modules[ $default_tab ]['label'] ); ?></h2>
						</div>
					</div>
					<div class="gfx-user">
						<p class="gfx-user-name"><?php echo esc_html( trim( $user->first_name . ' ' . $user->last_name ) ? trim( $user->first_name . ' ' . $user->last_name ) : $user->display_name ); ?></p>
						<p class="gfx-user-role"><?php echo esc_html( $role_label ); ?></p>
					</div>
				</header>

				<main class="gfx-content" id="gfx-content">
					<?php foreach ( $all_modules as $id => $m ) : ?>
						<?php $allowed = in_array( $id, $accessible, true ); ?>
						<div class="gfx-view <?php echo $id === $default_tab ? 'is-active' : ''; ?>" id="gfx-view-<?php echo esc_attr( $id ); ?>" data-tab="<?php echo esc_attr( $id ); ?>">
							<?php if ( ! $allowed ) : ?>
								<?php echo $this->render_denied( $m ); ?>
							<?php elseif ( 'native' === $m['type'] ) : ?>
								<?php echo $this->render_native_view( $id, $user, $accessible ); ?>
							<?php else : ?>
								<div class="gfx-module-frame">
									<?php echo do_shortcode( '[' . $m['shortcode'] . ']' ); ?>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</main>
			</div>

			<?php $bottom = GFX_Modules::bottom_nav_split( $accessible ); ?>
			<nav class="gfx-bottom-nav" id="gfx-bottom-nav" aria-label="<?php esc_attr_e( 'Menu', 'gastroflowx-hub' ); ?>">
				<?php foreach ( $bottom['bar'] as $id ) :
					$m = $all_modules[ $id ];
					?>
					<button type="button" class="gfx-bottom-btn" data-tab="<?php echo esc_attr( $id ); ?>">
						<i class="fa-solid <?php echo esc_attr( $m['icon'] ); ?>"></i>
						<span><?php echo esc_html( $this->short_label( $id, $m ) ); ?></span>
					</button>
				<?php endforeach; ?>
				<?php if ( $bottom['more'] ) : ?>
					<button type="button" class="gfx-bottom-btn gfx-more-btn" id="gfx-more-btn" aria-haspopup="true" aria-expanded="false" aria-controls="gfx-more-sheet"
						data-more-tabs="<?php echo esc_attr( implode( ',', $bottom['more'] ) ); ?>">
						<i class="fa-solid fa-grip"></i>
						<span><?php esc_html_e( 'Więcej', 'gastroflowx-hub' ); ?></span>
					</button>
				<?php endif; ?>
			</nav>

			<?php if ( $bottom['more'] ) : ?>
				<div class="gfx-more-backdrop" id="gfx-more-backdrop" hidden></div>
				<div class="gfx-more-sheet" id="gfx-more-sheet" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Więcej modułów', 'gastroflowx-hub' ); ?>" hidden>
					<div class="gfx-more-handle" aria-hidden="true"></div>
					<div class="gfx-more-head">
						<span><?php esc_html_e( 'Wszystkie moduły', 'gastroflowx-hub' ); ?></span>
						<button type="button" class="gfx-more-close" id="gfx-more-close" aria-label="<?php esc_attr_e( 'Zamknij', 'gastroflowx-hub' ); ?>"><i class="fa-solid fa-xmark"></i></button>
					</div>
					<div class="gfx-more-grid">
						<?php foreach ( $bottom['more'] as $id ) :
							$m = $all_modules[ $id ];
							?>
							<button type="button" class="gfx-more-item" data-tab="<?php echo esc_attr( $id ); ?>">
								<span class="gfx-more-icon"><i class="fa-solid <?php echo esc_attr( $m['icon'] ); ?>"></i></span>
								<span class="gfx-more-label"><?php echo esc_html( $m['label'] ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
					<button type="button" class="gfx-more-logout gfx-logout">
						<i class="fa-solid fa-right-from-bracket"></i> <?php esc_html_e( 'Wyloguj się', 'gastroflowx-hub' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Krótsza etykieta na dolny pasek (długie nazwy nie mieszczą się na telefonie). */
	protected function short_label( $id, $m ) {
		$short = array(
			'home'    => __( 'Start', 'gastroflowx-hub' ),
			'account' => __( 'Konto', 'gastroflowx-hub' ),
			'colors'  => __( 'Kolory', 'gastroflowx-hub' ),
		);
		return isset( $short[ $id ] ) ? $short[ $id ] : $m['label'];
	}

	/** Rozdziela renderowanie natywnych (nie-shortcode'owych) widoków huba. */
	protected function render_native_view( $id, $user, $accessible ) {
		switch ( $id ) {
			case 'home':
				return $this->render_home( $user, $accessible );
			case 'birthdays':
				return $this->render_birthdays( $user );
			case 'account':
			default:
				return $this->render_account( $user );
		}
	}

	protected function render_denied( $module ) {
		ob_start();
		?>
		<div class="gfx-denied">
			<i class="fa-solid fa-ban"></i>
			<h3><?php esc_html_e( 'Brak dostępu', 'gastroflowx-hub' ); ?></h3>
			<p><?php printf( esc_html__( 'Twoja rola nie ma uprawnień do modułu „%s”. Skontaktuj się z menadżerem, jeśli uważasz, że to pomyłka.', 'gastroflowx-hub' ), esc_html( $module['label'] ) ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Natywny widok "Strona domowa": najbliższy dyżur (z tabeli wp_rs_shifts,
	 * jeśli wtyczka grafiku jest aktywna) oraz suma napiwków w bieżącym
	 * tygodniu (z tabeli wp_sn_waiter_tips, jeśli wtyczka napiwków jest
	 * aktywna). Odporne na brak tych wtyczek.
	 */
	protected function render_home( $user, $accessible ) {
		global $wpdb;
		$shift = null;
		$tips  = null;

		if ( in_array( 'schedule', $accessible, true ) ) {
			$table = $wpdb->prefix . 'rs_shifts';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$shift = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE user_id = %d AND shift_date >= %s AND status != 'cancelled' ORDER BY shift_date ASC, start_time ASC LIMIT 1",
						$user->ID,
						current_time( 'Y-m-d' )
					)
				);
			}
		}

		if ( in_array( 'tips', $accessible, true ) ) {
			$table = $wpdb->prefix . 'sn_waiter_tips';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$week_start = date( 'Y-m-d', strtotime( 'monday this week', current_time( 'timestamp' ) ) );
				$week_end   = date( 'Y-m-d', strtotime( 'sunday this week', current_time( 'timestamp' ) ) );
				$prev_start = date( 'Y-m-d', strtotime( $week_start . ' -7 days' ) );
				$prev_end   = date( 'Y-m-d', strtotime( $week_end . ' -7 days' ) );

				$current = (float) $wpdb->get_var(
					$wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE waiter_id = %d AND date BETWEEN %s AND %s", $user->ID, $week_start, $week_end )
				);
				$previous = (float) $wpdb->get_var(
					$wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE waiter_id = %d AND date BETWEEN %s AND %s", $user->ID, $prev_start, $prev_end )
				);
				$tips = array(
					'current' => $current,
					'delta'   => $current - $previous,
				);
			}
		}

		ob_start();
		?>
		<div class="gfx-home">
			<h2 class="gfx-home-title"><?php esc_html_e( 'Twoje podsumowanie:', 'gastroflowx-hub' ); ?></h2>
			<div class="gfx-home-cards">
				<?php if ( null !== $shift ) : ?>
					<div class="gfx-card-tile">
						<div class="gfx-card-tile-head">
							<span><?php esc_html_e( 'Najbliższa zmiana', 'gastroflowx-hub' ); ?></span>
							<i class="fa-solid fa-calendar-check"></i>
						</div>
						<div class="gfx-shift-box">
							<span class="gfx-shift-day"><?php echo esc_html( $this->format_relative_day( $shift->shift_date ) ); ?></span>
							<span class="gfx-shift-time"><?php echo esc_html( substr( $shift->start_time, 0, 5 ) . ' - ' . substr( $shift->end_time, 0, 5 ) ); ?></span>
							<?php if ( ! empty( $shift->role ) ) : ?>
								<span class="gfx-shift-loc"><i class="fa-solid fa-location-dot"></i> <?php echo esc_html( $shift->role ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif ( in_array( 'schedule', $accessible, true ) ) : ?>
					<div class="gfx-card-tile">
						<div class="gfx-card-tile-head"><span><?php esc_html_e( 'Najbliższa zmiana', 'gastroflowx-hub' ); ?></span><i class="fa-solid fa-calendar-check"></i></div>
						<p class="gfx-empty"><?php esc_html_e( 'Brak zaplanowanych zmian.', 'gastroflowx-hub' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( null !== $tips ) : ?>
					<div class="gfx-card-tile">
						<div class="gfx-card-tile-head">
							<span><?php esc_html_e( 'Napiwki (Tydzień)', 'gastroflowx-hub' ); ?></span>
							<i class="fa-solid fa-hand-holding-dollar"></i>
						</div>
						<div class="gfx-tips-amount"><?php echo esc_html( number_format_i18n( $tips['current'], 2 ) ); ?> <span>PLN</span></div>
						<?php if ( 0 != $tips['delta'] ) : ?>
							<div class="gfx-tips-delta <?php echo $tips['delta'] >= 0 ? 'up' : 'down'; ?>">
								<i class="fa-solid fa-arrow-<?php echo $tips['delta'] >= 0 ? 'up' : 'down'; ?>"></i>
								<?php echo ( $tips['delta'] >= 0 ? '+' : '' ) . esc_html( number_format_i18n( $tips['delta'], 2 ) ); ?> PLN
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( null === $shift && null === $tips ) : ?>
					<div class="gfx-card-tile gfx-welcome">
						<i class="fa-solid fa-mug-hot"></i>
						<p><?php esc_html_e( 'Witaj! Skorzystaj z menu, aby przejść do dostępnych modułów.', 'gastroflowx-hub' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function format_relative_day( $date ) {
		$today    = current_time( 'Y-m-d' );
		$tomorrow = date( 'Y-m-d', strtotime( $today . ' +1 day' ) );
		if ( $date === $today ) {
			return __( 'DZIŚ', 'gastroflowx-hub' );
		}
		if ( $date === $tomorrow ) {
			return __( 'JUTRO', 'gastroflowx-hub' );
		}
		return date_i18n( 'd.m.Y', strtotime( $date ) );
	}

	/**
	 * Natywny widok "Moje konto".
	 */
	protected function render_account( $user ) {
		// Wspólne pole z wtyczką Napiwków (user_birth_date) — ta sama data
		// urodzenia jest używana tam do naliczania ulgi podatkowej < 26 lat.
		$birthdate  = get_user_meta( $user->ID, 'user_birth_date', true );
		$avatar_id  = (int) get_user_meta( $user->ID, 'gfx_avatar_id', true );
		$avatar_img = $avatar_id ? wp_get_attachment_image( $avatar_id, 'thumbnail', false, array( 'class' => 'gfx-avatar-img', 'id' => 'gfx-avatar-preview' ) ) : '';
		ob_start();
		?>
		<div class="gfx-account">
			<div class="gfx-account-card">
				<div class="gfx-account-head">
					<div class="gfx-avatar" id="gfx-avatar-wrap">
						<?php if ( $avatar_img ) : ?>
							<?php echo $avatar_img; ?>
						<?php else : ?>
							<i class="fa-solid fa-user" id="gfx-avatar-placeholder"></i>
						<?php endif; ?>
						<button type="button" class="gfx-avatar-edit" id="gfx-avatar-edit" title="<?php esc_attr_e( 'Zmień zdjęcie profilowe', 'gastroflowx-hub' ); ?>">
							<i class="fa-solid fa-camera"></i>
						</button>
						<input type="file" id="gfx-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden />
					</div>
					<div>
						<h3><?php esc_html_e( 'Twoje dane', 'gastroflowx-hub' ); ?></h3>
						<p><?php esc_html_e( 'Zarządzaj ustawieniami profilu', 'gastroflowx-hub' ); ?></p>
						<button type="button" class="gfx-avatar-change-link" id="gfx-avatar-change-link">
							<i class="fa-solid fa-camera"></i> <?php esc_html_e( 'Zmień zdjęcie profilowe', 'gastroflowx-hub' ); ?>
						</button>
						<p class="gfx-avatar-msg" id="gfx-avatar-msg" style="display:none;"></p>
					</div>
				</div>
				<form id="gfx-account-form">
					<div class="gfx-form-row">
						<div class="gfx-form-col">
							<label><?php esc_html_e( 'Imię', 'gastroflowx-hub' ); ?></label>
							<input type="text" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>" placeholder="<?php esc_attr_e( 'Twoje imię', 'gastroflowx-hub' ); ?>" />
						</div>
						<div class="gfx-form-col">
							<label><?php esc_html_e( 'Nazwisko', 'gastroflowx-hub' ); ?></label>
							<input type="text" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>" placeholder="<?php esc_attr_e( 'Twoje nazwisko', 'gastroflowx-hub' ); ?>" />
						</div>
					</div>
					<div class="gfx-field">
						<label><?php esc_html_e( 'Email', 'gastroflowx-hub' ); ?></label>
						<input type="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>" placeholder="<?php esc_attr_e( 'Twój adres e-mail', 'gastroflowx-hub' ); ?>" />
					</div>
					<div class="gfx-field">
						<label><?php esc_html_e( 'Data urodzenia', 'gastroflowx-hub' ); ?></label>
						<input type="date" name="birthdate" value="<?php echo esc_attr( $birthdate ); ?>" />
						<p class="gfx-field-hint"><?php esc_html_e( 'Ta sama data jest używana przy naliczaniu napiwków (ulga podatkowa < 26 lat).', 'gastroflowx-hub' ); ?></p>
					</div>

					<p class="gfx-account-msg" id="gfx-account-msg" style="display:none;"></p>

					<div class="gfx-account-actions">
						<button type="submit" class="gfx-btn gfx-btn-primary"><?php esc_html_e( 'Zapisz zmiany', 'gastroflowx-hub' ); ?></button>
					</div>
				</form>

				<div class="gfx-account-consent">
					<h4><i class="fa-solid fa-shield-halved"></i> <?php esc_html_e( 'Zgoda na przetwarzanie danych', 'gastroflowx-hub' ); ?></h4>
					<?php
					$consented_at = class_exists( 'GFX_Consent' ) ? GFX_Consent::consented_at( $user->ID ) : '';
					$policy_url   = get_option( 'gfx_privacy_policy_url', '' );
					?>
					<p class="gfx-consent-status">
						<i class="fa-solid fa-circle-check" style="color:#16A34A;"></i>
						<?php
						if ( $consented_at ) {
							printf(
								/* translators: %s: sformatowana data i godzina */
								esc_html__( 'Zgodę wyrażono: %s', 'gastroflowx-hub' ),
								esc_html( date_i18n( 'd.m.Y H:i', strtotime( $consented_at ) ) )
							);
						} else {
							esc_html_e( 'Zgoda wyrażona.', 'gastroflowx-hub' );
						}
						?>
					</p>
					<?php if ( $policy_url ) : ?>
						<p><a href="<?php echo esc_url( $policy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Zobacz politykę prywatności', 'gastroflowx-hub' ); ?></a></p>
					<?php endif; ?>
					<p class="gfx-consent-status" id="gfx-consent-withdraw-msg" style="display:none;"></p>
					<button type="button" class="gfx-btn gfx-btn-outline" id="gfx-consent-withdraw-btn">
						<?php esc_html_e( 'Wycofaj zgodę', 'gastroflowx-hub' ); ?>
					</button>
					<p class="gfx-field-hint"><?php esc_html_e( 'Wycofanie zgody oznacza, że nie będziesz mógł/mogła korzystać z panelu, dopóki nie wyrazisz jej ponownie.', 'gastroflowx-hub' ); ?></p>
				</div>

				<div class="gfx-account-logout">
					<button type="button" class="gfx-btn gfx-btn-danger gfx-btn-block gfx-logout">
						<i class="fa-solid fa-right-from-bracket"></i> <?php esc_html_e( 'Wyloguj się z systemu', 'gastroflowx-hub' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Natywny widok "Urodziny": siatka kalendarza (nawigowana klientowo w JS
	 * na podstawie danych z GFX_Birthdays) + lista nadchodzących urodzin
	 * pogrupowana wg miesięcy. Dane pochodzą wyłącznie z pola `user_birth_date`
	 * (to samo, którego używa "Moje konto" i wtyczka Napiwków) — brak
	 * jakiegokolwiek ograniczania widoczności czyichkolwiek urodzin.
	 */
	protected function render_birthdays( $user ) {
		if ( ! class_exists( 'GFX_Birthdays' ) ) {
			return '';
		}
		$upcoming     = GFX_Birthdays::upcoming( 15 );
		$month_names  = GFX_Birthdays::month_names();
		$month_abbr   = GFX_Birthdays::month_abbr();
		$my_birthdate = get_user_meta( $user->ID, 'user_birth_date', true );

		// Grupowanie chronologiczne po miesiącu wystąpienia (kolejność już
		// posortowana wg dni do najbliższych urodzin, więc grupy naturalnie
		// nie przeplatają się).
		$groups = array();
		foreach ( $upcoming as $i => $item ) {
			$m_index = ( (int) gmdate( 'n', strtotime( $item['next_date'] ) ) ) - 1;
			$label   = $month_names[ $m_index ];
			if ( ! isset( $groups[ $label ] ) ) {
				$groups[ $label ] = array();
			}
			$item['is_next']  = ( 0 === $i );
			$item['month_ab'] = $month_abbr[ $m_index ];
			$item['day_num']  = gmdate( 'd', strtotime( $item['next_date'] ) );
			$groups[ $label ][] = $item;
		}

		ob_start();
		?>
		<div class="gfx-bday">
			<div class="gfx-bday-head">
				<div>
					<h2><?php esc_html_e( 'Urodziny w zespole', 'gastroflowx-hub' ); ?></h2>
					<p><?php esc_html_e( 'Sprawdź, kto z załogi niedługo świętuje, i przygotuj się na życzenia!', 'gastroflowx-hub' ); ?></p>
				</div>
				<button type="button" class="gfx-btn gfx-btn-primary" id="gfx-bday-add-btn">
					<i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Dodaj datę', 'gastroflowx-hub' ); ?>
				</button>
			</div>

			<div class="gfx-bday-layout">
				<div class="gfx-bday-calendar-card">
					<div class="gfx-bday-cal-nav">
						<button type="button" class="gfx-bday-nav-btn" id="gfx-bday-prev" aria-label="<?php esc_attr_e( 'Poprzedni miesiąc', 'gastroflowx-hub' ); ?>"><i class="fa-solid fa-chevron-left"></i></button>
						<h3 id="gfx-bday-month-title">&nbsp;</h3>
						<button type="button" class="gfx-bday-nav-btn" id="gfx-bday-next" aria-label="<?php esc_attr_e( 'Następny miesiąc', 'gastroflowx-hub' ); ?>"><i class="fa-solid fa-chevron-right"></i></button>
					</div>
					<div class="gfx-bday-weekdays">
						<span><?php esc_html_e( 'Pon', 'gastroflowx-hub' ); ?></span>
						<span><?php esc_html_e( 'Wt', 'gastroflowx-hub' ); ?></span>
						<span><?php esc_html_e( 'Śr', 'gastroflowx-hub' ); ?></span>
						<span><?php esc_html_e( 'Czw', 'gastroflowx-hub' ); ?></span>
						<span><?php esc_html_e( 'Pt', 'gastroflowx-hub' ); ?></span>
						<span><?php esc_html_e( 'Sob', 'gastroflowx-hub' ); ?></span>
						<span class="is-sun"><?php esc_html_e( 'Ndz', 'gastroflowx-hub' ); ?></span>
					</div>
					<div class="gfx-bday-grid" id="gfx-bday-grid"></div>
				</div>

				<div class="gfx-bday-list-card">
					<div class="gfx-bday-list-head">
						<h3><i class="fa-regular fa-calendar"></i> <?php esc_html_e( 'Nadchodzące święta', 'gastroflowx-hub' ); ?></h3>
					</div>
					<div class="gfx-bday-list-body">
						<?php if ( empty( $groups ) ) : ?>
							<p class="gfx-empty"><?php esc_html_e( 'Nikt w zespole nie ma jeszcze ustawionej daty urodzin.', 'gastroflowx-hub' ); ?></p>
						<?php else : ?>
							<?php foreach ( $groups as $label => $items ) : ?>
								<div class="gfx-bday-month-group">
									<h4><?php echo esc_html( $label ); ?><span></span></h4>
									<div class="gfx-bday-month-items">
										<?php foreach ( $items as $item ) : ?>
											<div class="gfx-bday-item <?php echo $item['is_next'] ? 'is-next' : ''; ?>">
												<div class="gfx-bday-badge">
													<span class="gfx-bday-badge-month"><?php echo esc_html( $item['month_ab'] ); ?></span>
													<span class="gfx-bday-badge-day"><?php echo esc_html( $item['day_num'] ); ?></span>
												</div>
												<div>
													<p class="gfx-bday-item-name"><?php echo esc_html( $item['name'] ); ?></p>
													<p class="gfx-bday-item-sub">
														<?php if ( $item['is_next'] && $item['turning_age'] > 0 ) : ?>
															<?php printf( esc_html__( 'Kończy %d lat!', 'gastroflowx-hub' ), (int) $item['turning_age'] ); ?>
														<?php else : ?>
															<?php echo esc_html( $item['role'] ); ?>
														<?php endif; ?>
													</p>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		<div class="gfx-bday-modal" id="gfx-bday-modal">
			<div class="gfx-bday-modal-box">
				<button type="button" class="gfx-bday-modal-close" id="gfx-bday-modal-close" aria-label="<?php esc_attr_e( 'Zamknij', 'gastroflowx-hub' ); ?>">&times;</button>
				<h3><?php esc_html_e( 'Twoja data urodzenia', 'gastroflowx-hub' ); ?></h3>
				<p class="gfx-bday-modal-hint"><?php esc_html_e( 'Ta sama data jest widoczna w „Moje konto” i używana przy naliczaniu napiwków.', 'gastroflowx-hub' ); ?></p>
				<form id="gfx-bday-modal-form">
					<input type="date" id="gfx-bday-modal-date" required value="<?php echo esc_attr( $my_birthdate ); ?>" />
					<p class="gfx-bday-modal-msg" id="gfx-bday-modal-msg" style="display:none;"></p>
					<button type="submit" class="gfx-btn gfx-btn-primary gfx-btn-block"><?php esc_html_e( 'Zapisz', 'gastroflowx-hub' ); ?></button>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
