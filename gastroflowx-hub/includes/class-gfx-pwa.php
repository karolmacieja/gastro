<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generuje dynamiczny manifest.webmanifest (PWA - "Dodaj do ekranu
 * początkowego") na podstawie ustawień z panelu (nazwa, ikona, kolory) i
 * wypisuje wymagane znaczniki <meta>/<link> w <head>.
 *
 * WAŻNE: ten manifest NIE rejestruje własnego service workera - to robi
 * już `push-manager.js` (rejestruje `/firebase-messaging-sw.js` na zasięgu
 * "/"). Manifest odpowiada wyłącznie za metadane instalacji (nazwa, ikona,
 * tryb wyświetlania), więc jest w pełni zgodny z istniejącą integracją
 * push - nic tu nie koliduje ani nie duplikuje service workera.
 */
class GFX_PWA {

	const OPTION_PREFIX = 'gfx_pwa_';

	public function __construct() {
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve_manifest' ) );
		add_action( 'wp_head', array( $this, 'print_meta_tags' ), 2 );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
	}

	public function register_rewrite() {
		add_rewrite_rule( '^manifest\.webmanifest$', 'index.php?gfx_manifest=1', 'top' );
		add_rewrite_tag( '%gfx_manifest%', '([^&]+)' );
	}

	public static function flush_rewrite_rules_once() {
		flush_rewrite_rules( false );
	}

	public function maybe_serve_manifest( $wp ) {
		if ( empty( $wp->query_vars['gfx_manifest'] ) ) {
			return;
		}
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( self::build_manifest() ); // phpcs:ignore WordPress.Security.EscapeOutput -- czysty JSON, budowany z bezpiecznych, zescapowanych wartości.
		exit;
	}

	public static function build_manifest() {
		$icon_id = (int) get_option( self::OPTION_PREFIX . 'icon_id', 0 );
		$icons   = array();

		if ( $icon_id ) {
			// Priorytet: automatycznie wygenerowany PNG (gdy oryginał był
			// w nieobsługiwanym formacie, np. JPG - patrz generate_png_icon_if_needed()).
			$png_url  = get_option( self::OPTION_PREFIX . 'icon_png_url', '' );
			$png_size = get_option( self::OPTION_PREFIX . 'icon_png_size', '' );
			if ( $png_url && $png_size ) {
				$icons[] = array(
					'src'     => $png_url,
					'sizes'   => $png_size,
					'type'    => 'image/png',
					'purpose' => 'any',
				);
			} else {
				$src = wp_get_attachment_image_src( $icon_id, 'full' );
				if ( $src ) {
					$mime    = get_post_mime_type( $icon_id );
					$icons[] = array(
						'src'     => $src[0],
						'sizes'   => $src[1] . 'x' . $src[2],
						'type'    => $mime ? $mime : 'image/png',
						// UWAGA: NIE deklarujemy 'maskable' tutaj - Chrome
						// wprost odradza łączenie 'any' i 'maskable' na TYM
						// SAMYM pliku (może wyglądać źle - za dużo/za mało
						// marginesu), a bez osobnej, specjalnie przygotowanej
						// wersji maskowalnej (z bezpiecznym marginesem) samo
						// 'any' jest bezpieczniejszym wyborem.
						'purpose' => 'any',
					);
				}
			}
		}

		$manifest = array(
			'name'             => get_option( self::OPTION_PREFIX . 'name', get_bloginfo( 'name' ) ),
			'short_name'       => get_option( self::OPTION_PREFIX . 'short_name', substr( get_bloginfo( 'name' ), 0, 12 ) ),
			'start_url'        => get_option( self::OPTION_PREFIX . 'start_url', home_url( '/' ) ),
			'scope'            => home_url( '/' ),
			'display'          => get_option( self::OPTION_PREFIX . 'display', 'standalone' ),
			'background_color' => get_option( self::OPTION_PREFIX . 'background_color', '#ffffff' ),
			'theme_color'      => get_option( self::OPTION_PREFIX . 'theme_color', '#1a73e8' ),
			'lang'             => 'pl',
		);
		if ( ! empty( $icons ) ) {
			$manifest['icons'] = $icons;
		}
		return $manifest;
	}

	/**
	 * Znaczniki wymagane, żeby "Dodaj do ekranu początkowego" pokazywało
	 * poprawną nazwę/ikonę. Apple/Safari (w tym iOS) nie w pełni wspiera
	 * manifest.webmanifest i wymaga DODATKOWO własnych meta tagów - stąd
	 * oba warianty jednocześnie.
	 */
	public function print_meta_tags() {
		if ( is_admin() ) {
			return;
		}
		$icon_id    = (int) get_option( self::OPTION_PREFIX . 'icon_id', 0 );
		$name       = get_option( self::OPTION_PREFIX . 'name', get_bloginfo( 'name' ) );
		$short_name = get_option( self::OPTION_PREFIX . 'short_name', $name );
		$theme      = get_option( self::OPTION_PREFIX . 'theme_color', '#1a73e8' );

		echo '<link rel="manifest" href="' . esc_url( home_url( '/manifest.webmanifest' ) ) . '">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( $theme ) . '">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $short_name ) . '">' . "\n";

		if ( $icon_id ) {
			$icon_url = get_option( self::OPTION_PREFIX . 'icon_png_url', '' );
			if ( ! $icon_url ) {
				$src      = wp_get_attachment_image_src( $icon_id, 'full' );
				$icon_url = $src ? $src[0] : '';
			}
			if ( $icon_url ) {
				echo '<link rel="apple-touch-icon" href="' . esc_url( $icon_url ) . '">' . "\n";
				echo '<link rel="icon" href="' . esc_url( $icon_url ) . '">' . "\n";
			}
		}
	}

	/* =========================================================
	 *  ADMIN
	 * ========================================================= */

	public function register_menu() {
		add_submenu_page(
			'gastroflowx',
			__( 'Aplikacja (PWA)', 'gastroflowx-hub' ),
			__( 'Aplikacja (PWA)', 'gastroflowx-hub' ),
			'manage_options',
			'gastroflowx-pwa',
			array( $this, 'render' )
		);
	}

	public function maybe_save() {
		if ( ! isset( $_POST['gfx_pwa_nonce'] ) || ! wp_verify_nonce( $_POST['gfx_pwa_nonce'], 'gfx_save_pwa' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_option( self::OPTION_PREFIX . 'name', sanitize_text_field( wp_unslash( $_POST['gfx_pwa_name'] ?? '' ) ) );
		update_option( self::OPTION_PREFIX . 'short_name', sanitize_text_field( wp_unslash( $_POST['gfx_pwa_short_name'] ?? '' ) ) );
		update_option( self::OPTION_PREFIX . 'start_url', esc_url_raw( wp_unslash( $_POST['gfx_pwa_start_url'] ?? home_url( '/' ) ) ) );
		update_option( self::OPTION_PREFIX . 'display', in_array( $_POST['gfx_pwa_display'] ?? '', array( 'standalone', 'fullscreen', 'minimal-ui', 'browser' ), true ) ? sanitize_text_field( wp_unslash( $_POST['gfx_pwa_display'] ) ) : 'standalone' );
		update_option( self::OPTION_PREFIX . 'theme_color', sanitize_hex_color( wp_unslash( $_POST['gfx_pwa_theme_color'] ?? '' ) ) ?: '#1a73e8' );
		update_option( self::OPTION_PREFIX . 'background_color', sanitize_hex_color( wp_unslash( $_POST['gfx_pwa_background_color'] ?? '' ) ) ?: '#ffffff' );
		if ( ! empty( $_POST['gfx_pwa_icon_id'] ) ) {
			$new_icon_id = absint( $_POST['gfx_pwa_icon_id'] );
			update_option( self::OPTION_PREFIX . 'icon_id', $new_icon_id );
			$this->generate_png_icon_if_needed( $new_icon_id );
		} elseif ( isset( $_POST['gfx_pwa_icon_id'] ) ) {
			update_option( self::OPTION_PREFIX . 'icon_id', 0 );
			update_option( self::OPTION_PREFIX . 'icon_png_url', '' );
		}

		add_action( 'admin_notices', array( $this, 'notice_saved' ) );
	}

	/**
	 * Chrome/Edge akceptują jako ikonę PWA WYŁĄCZNIE PNG, SVG lub WebP - JPG
	 * (bardzo częsty format logo firmowego) jest odrzucany, mimo poprawnego
	 * rozmiaru. Zamiast wymagać od admina ręcznego przygotowania osobnego
	 * pliku, generujemy PNG automatycznie wbudowanym edytorem obrazów
	 * WordPressa i zapamiętujemy jego URL - manifest korzysta z tej
	 * wygenerowanej wersji zamiast oryginału, gdy oryginał jest w
	 * nieobsługiwanym formacie (np. JPG).
	 */
	protected function generate_png_icon_if_needed( $icon_id ) {
		$mime = get_post_mime_type( $icon_id );
		if ( in_array( $mime, array( 'image/png', 'image/svg+xml', 'image/webp' ), true ) ) {
			// Już we właściwym formacie - manifest użyje oryginału wprost.
			update_option( self::OPTION_PREFIX . 'icon_png_url', '' );
			return;
		}

		$file = get_attached_file( $icon_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return; // Brak GD/Imagick na serwerze - admin zobaczy w panelu, że warto wgrać PNG ręcznie.
		}

		$upload_dir = wp_upload_dir();
		$dest       = trailingslashit( $upload_dir['basedir'] ) . 'gfx-pwa-icon-' . $icon_id . '.png';
		$saved      = $editor->save( $dest, 'image/png' );
		if ( is_wp_error( $saved ) ) {
			return;
		}

		update_option( self::OPTION_PREFIX . 'icon_png_url', trailingslashit( $upload_dir['baseurl'] ) . basename( $saved['path'] ) );
		update_option( self::OPTION_PREFIX . 'icon_png_size', $saved['width'] . 'x' . $saved['height'] );
	}

	public function notice_saved() {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Zapisano ustawienia aplikacji PWA.', 'gastroflowx-hub' ) . '</p></div>';
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_media();

		$name         = get_option( self::OPTION_PREFIX . 'name', get_bloginfo( 'name' ) );
		$short_name   = get_option( self::OPTION_PREFIX . 'short_name', substr( get_bloginfo( 'name' ), 0, 12 ) );
		$start_url    = get_option( self::OPTION_PREFIX . 'start_url', home_url( '/' ) );
		$display      = get_option( self::OPTION_PREFIX . 'display', 'standalone' );
		$theme_color  = get_option( self::OPTION_PREFIX . 'theme_color', '#1a73e8' );
		$bg_color     = get_option( self::OPTION_PREFIX . 'background_color', '#ffffff' );
		$icon_id      = (int) get_option( self::OPTION_PREFIX . 'icon_id', 0 );
		$icon_preview = $icon_id ? wp_get_attachment_image_url( $icon_id, 'thumbnail' ) : '';
		$icon_warning = '';
		if ( $icon_id ) {
			$icon_src = wp_get_attachment_image_src( $icon_id, 'full' );
			if ( $icon_src && ( $icon_src[1] < 512 || $icon_src[2] < 512 ) ) {
				$icon_warning = sprintf(
					/* translators: 1: szerokość, 2: wysokość */
					__( 'Uwaga: wybrany obrazek ma tylko %1$d×%2$dpx. Chrome/Edge wymagają min. 144×144px, ale do poprawnej instalacji na wszystkich platformach (w tym ikony wysokiej rozdzielczości) zalecane jest co najmniej 512×512px - wybierz większy obraz.', 'gastroflowx-hub' ),
					$icon_src[1],
					$icon_src[2]
				);
			}
		}
		?>
		<div class="wrap gfx-wrap">
			<h1><?php esc_html_e( 'GastroFlowx — Aplikacja (PWA)', 'gastroflowx-hub' ); ?></h1>
			<div class="gfx-card">
				<p class="description">
					<?php esc_html_e( 'Ustawienia poniżej decydują, jak strona wygląda po dodaniu do ekranu początkowego (nazwa, ikona, kolory) - zarówno na Androidzie/Chrome (baner "Zainstaluj aplikację"), jak i na iOS/Safari (Udostępnij → Dodaj do ekranu początkowego). Zmiany są zgodne z istniejącą integracją powiadomień push - nie trzeba nic więcej konfigurować.', 'gastroflowx-hub' ); ?>
				</p>
				<form method="post">
					<?php wp_nonce_field( 'gfx_save_pwa', 'gfx_pwa_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="gfx_pwa_name"><?php esc_html_e( 'Pełna nazwa aplikacji', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="text" id="gfx_pwa_name" name="gfx_pwa_name" value="<?php echo esc_attr( $name ); ?>" class="regular-text" placeholder="np. Weranda Lunch and Wine" /></td>
						</tr>
						<tr>
							<th><label for="gfx_pwa_short_name"><?php esc_html_e( 'Krótka nazwa (pod ikoną)', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="text" id="gfx_pwa_short_name" name="gfx_pwa_short_name" value="<?php echo esc_attr( $short_name ); ?>" class="regular-text" maxlength="20" placeholder="np. Weranda" />
								<p class="description"><?php esc_html_e( 'Max ok. 12-20 znaków - dłuższe bywają przycinane pod ikoną.', 'gastroflowx-hub' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Ikona aplikacji', 'gastroflowx-hub' ); ?></th>
							<td>
								<div id="gfx-pwa-icon-preview" style="margin-bottom:8px;">
									<?php if ( $icon_preview ) : ?>
										<img src="<?php echo esc_url( $icon_preview ); ?>" style="width:80px;height:80px;object-fit:cover;border-radius:12px;border:1px solid #ddd;" />
									<?php endif; ?>
								</div>
								<input type="hidden" id="gfx_pwa_icon_id" name="gfx_pwa_icon_id" value="<?php echo esc_attr( $icon_id ); ?>" />
								<button type="button" class="button" id="gfx-pwa-pick-icon"><?php esc_html_e( 'Wybierz z biblioteki mediów', 'gastroflowx-hub' ); ?></button>
								<button type="button" class="button" id="gfx-pwa-remove-icon" <?php echo $icon_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Usuń', 'gastroflowx-hub' ); ?></button>
								<p class="description"><?php esc_html_e( 'Najlepiej kwadratowy obraz, min. 512×512px. JPG/inne formaty są automatycznie konwertowane na PNG (wymagany przez Chrome/Edge do instalacji) - nie musisz robić tego ręcznie.', 'gastroflowx-hub' ); ?></p>
								<?php if ( $icon_warning ) : ?>
									<p class="description" style="color:#b32d2e;"><strong><?php echo esc_html( $icon_warning ); ?></strong></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><label for="gfx_pwa_theme_color"><?php esc_html_e( 'Kolor motywu (pasek systemowy)', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="color" id="gfx_pwa_theme_color" name="gfx_pwa_theme_color" value="<?php echo esc_attr( $theme_color ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="gfx_pwa_background_color"><?php esc_html_e( 'Kolor tła (ekran powitalny)', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="color" id="gfx_pwa_background_color" name="gfx_pwa_background_color" value="<?php echo esc_attr( $bg_color ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="gfx_pwa_display"><?php esc_html_e( 'Tryb wyświetlania', 'gastroflowx-hub' ); ?></label></th>
							<td>
								<select id="gfx_pwa_display" name="gfx_pwa_display">
									<option value="standalone" <?php selected( $display, 'standalone' ); ?>><?php esc_html_e( 'Standalone (zalecane — bez paska przeglądarki)', 'gastroflowx-hub' ); ?></option>
									<option value="fullscreen" <?php selected( $display, 'fullscreen' ); ?>><?php esc_html_e( 'Fullscreen (pełny ekran, bez paska stanu)', 'gastroflowx-hub' ); ?></option>
									<option value="minimal-ui" <?php selected( $display, 'minimal-ui' ); ?>><?php esc_html_e( 'Minimal UI (minimalne kontrolki przeglądarki)', 'gastroflowx-hub' ); ?></option>
									<option value="browser" <?php selected( $display, 'browser' ); ?>><?php esc_html_e( 'Browser (zwykła karta przeglądarki)', 'gastroflowx-hub' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="gfx_pwa_start_url"><?php esc_html_e( 'Adres startowy', 'gastroflowx-hub' ); ?></label></th>
							<td><input type="text" id="gfx_pwa_start_url" name="gfx_pwa_start_url" value="<?php echo esc_attr( $start_url ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Strona, która otworzy się po dotknięciu ikony (domyślnie strona główna).', 'gastroflowx-hub' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Zapisz', 'gastroflowx-hub' ) ); ?>
				</form>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link do manifestu */
						esc_html__( 'Podgląd wygenerowanego manifestu: %s', 'gastroflowx-hub' ),
						'<a href="' . esc_url( home_url( '/manifest.webmanifest' ) ) . '" target="_blank">' . esc_html( home_url( '/manifest.webmanifest' ) ) . '</a>'
					);
					?>
					<?php esc_html_e( '(jeśli po zapisaniu wyświetla błąd 404, wejdź w Ustawienia → Bezpośrednie odnośniki i kliknij Zapisz, żeby odświeżyć reguły przekierowań).', 'gastroflowx-hub' ); ?>
				</p>
			</div>
		</div>
		<script>
		( function( $ ) {
			$( '#gfx-pwa-pick-icon' ).on( 'click', function( e ) {
				e.preventDefault();
				var frame = wp.media( { title: '<?php echo esc_js( __( 'Wybierz ikonę aplikacji', 'gastroflowx-hub' ) ); ?>', multiple: false, library: { type: 'image' } } );
				frame.on( 'select', function() {
					var att = frame.state().get( 'selection' ).first().toJSON();
					$( '#gfx_pwa_icon_id' ).val( att.id );
					$( '#gfx-pwa-icon-preview' ).html( '<img src="' + att.url + '" style="width:80px;height:80px;object-fit:cover;border-radius:12px;border:1px solid #ddd;" />' );
					$( '#gfx-pwa-remove-icon' ).show();
				} );
				frame.open();
			} );
			$( '#gfx-pwa-remove-icon' ).on( 'click', function( e ) {
				e.preventDefault();
				$( '#gfx_pwa_icon_id' ).val( '' );
				$( '#gfx-pwa-icon-preview' ).html( '' );
				$( this ).hide();
			} );
		} )( jQuery );
		</script>
		<?php
	}
}
