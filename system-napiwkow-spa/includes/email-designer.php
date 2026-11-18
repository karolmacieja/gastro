<?php
/**
 * Snspa_Email_Designer
 *
 * Edytor e-maili: KAŻDA wiadomość wysyłana przez wtyczkę (automatyczna
 * i ręczna) ma własny, kompletny kod HTML - nagłówek, style, treść i stopkę
 * ustawia się bezpośrednio w kodzie. W kodzie można używać tagów, np. {imie},
 * {zmiana_data}, {wyplata} - lista tagów jest pokazywana przy każdej wiadomości.
 *
 * Strona w panelu: edytor kodu z podświetlaniem składni, podgląd na żywo
 * (z przykładowymi danymi), wysyłka testowa i przywracanie kodu domyślnego.
 *
 * Moduł decyduje wyłącznie o wyglądzie i treści e-maili - logika wtyczki,
 * powiadomienia push itd. pozostają bez zmian.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Snspa_Email_Designer' ) ) :

class Snspa_Email_Designer {

	private static $cfg      = array();
	private static $registry = null;
	private static $alt_body = null;

	/* =========================================================
	 *  KONFIGURACJA
	 * ========================================================= */

	public static function init( array $cfg ) {
		self::$cfg = array_merge(
			array(
				'slug'       => 'plugin',
				'page_title' => 'E-maile',
				'menu_title' => 'E-maile',
				'parent'     => 'options-general.php',
				'capability' => 'manage_options',
				'registry'   => null, // callable => lista wiadomości
				'app_url'    => null, // callable => string
			),
			$cfg
		);

		$slug = self::$cfg['slug'];
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_post_' . $slug . '_email_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . $slug . '_email_reset', array( __CLASS__, 'handle_reset' ) );
		add_action( 'admin_post_' . $slug . '_email_test', array( __CLASS__, 'handle_test' ) );
	}

	private static function opt_messages() {
		return self::$cfg['slug'] . '_email_messages';
	}

	private static function page_slug() {
		return self::$cfg['slug'] . '-emails';
	}

	public static function app_url() {
		return is_callable( self::$cfg['app_url'] ) ? (string) call_user_func( self::$cfg['app_url'] ) : home_url();
	}

	/* =========================================================
	 *  REJESTR WIADOMOŚCI
	 * ========================================================= */

	public static function messages() {
		if ( null === self::$registry ) {
			$list           = is_callable( self::$cfg['registry'] ) ? (array) call_user_func( self::$cfg['registry'] ) : array();
			self::$registry = array();
			foreach ( $list as $item ) {
				$item = array_merge(
					array(
						'key'              => '',
						'label'            => '',
						'type'             => 'auto',  // auto | manual
						'recipient'        => '',
						'description'      => '',
						'subject'          => '',
						'html'             => '',      // domyślny kod HTML
						'vars'             => array(), // tag => opis
						'sample'           => array(), // tag => przykładowa wartość (podgląd/test)
						'tresc'            => false,   // czy dostępny tag {tresc} (treść wpisywana w aplikacji)
						'sample_tresc'     => null,    // callable => przykładowa {tresc} (tekst)
						'subject_editable' => true,
						'load_subject'     => null,    // callable => string (temat trzymany w aplikacji)
						'save_subject'     => null,    // callable( $subject )
						'default_subject'  => null,    // callable => string
					),
					$item
				);
				if ( $item['key'] ) {
					self::$registry[ $item['key'] ] = $item;
				}
			}
		}
		return self::$registry;
	}

	private static function flush_registry() {
		self::$registry = null;
	}

	private static function stored( $key ) {
		$all = get_option( self::opt_messages(), array() );
		return is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
	}

	/** Aktualny temat i kod HTML wiadomości. */
	public static function get_message( $key ) {
		$reg    = self::messages();
		$item   = $reg[ $key ] ?? null;
		$stored = self::stored( $key );

		$subject = $item ? $item['subject'] : '';
		if ( $item && is_callable( $item['load_subject'] ) ) {
			$subject = (string) call_user_func( $item['load_subject'] );
		} elseif ( isset( $stored['subject'] ) && '' !== $stored['subject'] ) {
			$subject = $stored['subject'];
		}

		if ( isset( $stored['html'] ) && '' !== trim( $stored['html'] ) ) {
			$html = $stored['html'];
		} elseif ( 'custom' === ( $stored['layout_mode'] ?? '' ) && '' !== trim( (string) ( $stored['custom_html'] ?? '' ) ) ) {
			$html = $stored['custom_html']; // zgodność z poprzednią wersją modułu
		} elseif ( $item && '' !== trim( $item['html'] ) ) {
			$html = $item['html'];
		} else {
			$html = self::starter_html( array( 'inner' => '{tresc}' ) );
		}

		return array(
			'subject'    => (string) $subject,
			'html'       => (string) $html,
			'customized' => isset( $stored['html'] ),
		);
	}

	/* =========================================================
	 *  RENDEROWANIE
	 * ========================================================= */

	/** Tagi w temacie - wartości wstawiane bez zmian (temat to zwykły tekst). */
	public static function render_text( $text, array $vars ) {
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{' . trim( $k, '{}' ) . '}' ] = (string) $v;
		}
		return strtr( (string) $text, $map );
	}

	/**
	 * Tekst wpisany w aplikacji -> HTML dla tagu {tresc} (akapity, linki,
	 * podstawione tagi). Wartości są escapowane.
	 */
	public static function render_body( $text, array $vars ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return '';
		}
		$html = preg_match( '/<\s*\/?\s*(p|div|br|table|img|a|strong|b|em|span|h[1-6]|ul|ol|li)\b/i', $text )
			? wpautop( wp_kses_post( $text ) )
			: wpautop( esc_html( $text ) );
		$map  = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{' . trim( $k, '{}' ) . '}' ] = nl2br( esc_html( (string) $v ) );
		}
		return make_clickable( strtr( $html, $map ) );
	}

	/**
	 * Składa gotowy e-mail z kodu HTML: podstawia tagi wiadomości (escapowane,
	 * wielolinijkowe wartości dostają <br>) oraz tagi systemowe.
	 */
	public static function render_html( $html, $subject, array $vars, $tresc_html = '' ) {
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{' . trim( $k, '{}' ) . '}' ] = nl2br( esc_html( (string) $v ) );
		}
		$map['{tresc}']   = (string) $tresc_html;
		$map['{temat}']   = esc_html( $subject );
		$map['{rok}']     = gmdate( 'Y' );
		$map['{app_url}'] = esc_url( self::app_url() );

		$out = strtr( (string) $html, $map );
		return apply_filters( self::$cfg['slug'] . '_email_html', $out, $subject, $vars );
	}

	/* =========================================================
	 *  WYSYŁKA
	 * ========================================================= */

	/**
	 * Wysyła wiadomość $key. $subject_override - temat już złożony
	 * (np. wpisany w aplikacji); $tresc_html - HTML dla tagu {tresc}.
	 */
	public static function send_message( $to, $key, array $vars, $tresc_html = '', $subject_override = null, $html_override = null ) {
		$msg     = self::get_message( $key );
		$subject = null !== $subject_override ? (string) $subject_override : self::render_text( $msg['subject'], $vars );
		$html    = self::render_html( null !== $html_override ? $html_override : $msg['html'], $subject, $vars, $tresc_html );
		return self::send_raw( $to, $subject, $html );
	}

	public static function send_raw( $to, $subject, $html ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$subject = wp_specialchars_decode( wp_strip_all_tags( (string) $subject ), ENT_QUOTES );

		// Wersja tekstowa (multipart/alternative) - tylko dla NASZEJ wysyłki.
		self::$alt_body = self::html_to_text( $html );
		add_action( 'phpmailer_init', array( __CLASS__, 'set_alt_body' ) );
		$ok = wp_mail( $to, $subject, $html, $headers );
		remove_action( 'phpmailer_init', array( __CLASS__, 'set_alt_body' ) );
		self::$alt_body = null;

		return $ok;
	}

	public static function set_alt_body( $phpmailer ) {
		if ( self::$alt_body ) {
			$phpmailer->AltBody = self::$alt_body;
		}
	}

	private static function html_to_text( $html ) {
		$html = preg_replace( '#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', (string) $html );
		$html = preg_replace( '#<div[^>]*display\s*:\s*none[^>]*>.*?</div>#is', '', $html );
		$html = preg_replace_callback(
			'#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			function ( $m ) {
				$label = trim( wp_strip_all_tags( $m[2] ) );
				return ( '' === $label || $label === $m[1] ) ? $m[1] : $label . ' (' . $m[1] . ')';
			},
			$html
		);
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(p|div|h[1-6]|li|tr|table)>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/* =========================================================
	 *  KOD STARTOWY (domyślne szablony wiadomości)
	 * ========================================================= */

	/**
	 * Buduje kompletny, responsywny kod HTML e-maila (tabele + style inline -
	 * działa w Gmailu, Outlooku, Apple Mail). Używane przez katalogi wtyczek
	 * do wygenerowania DOMYŚLNEGO kodu każdej wiadomości - po zapisaniu
	 * w panelu kod jest już w pełni własny i można go dowolnie zmieniać.
	 */
	public static function starter_html( array $o ) {
		$o = array_merge(
			array(
				'brand'       => get_bloginfo( 'name' ),
				'tagline'     => '',
				'inner'       => '',
				'button_text' => '',
				'button_url'  => '{app_url}',
				'footer'      => '',
				'accent'      => '#2563EB',
				'header_bg'   => '#2563EB',
			),
			$o
		);
		$font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

		$button = '' === $o['button_text'] ? '' : '
              <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0 4px;">
                <tr>
                  <td bgcolor="' . $o['accent'] . '" style="border-radius:8px;">
                    <a href="' . $o['button_url'] . '" target="_blank" style="display:inline-block;padding:13px 28px;font-family:' . $font . ';font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">' . $o['button_text'] . '</a>
                  </td>
                </tr>
              </table>';

		$tagline = '' === $o['tagline'] ? '' : '
              <div style="margin-top:6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#ffffff;opacity:.85;">' . $o['tagline'] . '</div>';

		return '<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{temat}</title>
  <style>
    p { margin: 0 0 14px; }
    a { color: ' . $o['accent'] . '; }
    @media only screen and (max-width: 620px) {
      .karta { width: 100% !important; border-radius: 0 !important; }
      .pad { padding-left: 22px !important; padding-right: 22px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#F3F4F6;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#F3F4F6">
    <tr>
      <td align="center" style="padding:32px 12px;">
        <table role="presentation" class="karta" width="600" cellspacing="0" cellpadding="0" border="0" bgcolor="#FFFFFF" style="width:600px;max-width:600px;border-radius:12px;overflow:hidden;">

          <!-- NAGŁÓWEK (logo: wstaw tu <img src="https://.../logo.png" width="160" alt="">) -->
          <tr>
            <td class="pad" align="center" bgcolor="' . $o['header_bg'] . '" style="padding:30px 36px;font-family:' . $font . ';color:#ffffff;">
              <div style="font-size:22px;font-weight:700;">' . $o['brand'] . '</div>' . $tagline . '
            </td>
          </tr>

          <!-- TREŚĆ -->
          <tr>
            <td class="pad" style="padding:34px 38px 30px;font-family:' . $font . ';font-size:15px;line-height:1.65;color:#1F2937;">
' . $o['inner'] . $button . '
            </td>
          </tr>

          <!-- STOPKA -->
          <tr>
            <td class="pad" align="center" style="padding:22px 38px 30px;border-top:1px solid #E5E7EB;font-family:' . $font . ';font-size:12px;line-height:1.6;color:#6B7280;">
              ' . $o['footer'] . '
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
	}

	/** Pomocnik dla katalogów: ramka z danymi (etykieta => tag). */
	public static function starter_box( array $rows, $accent = '#2563EB' ) {
		$out = '
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:6px 0 18px;background:#F9FAFB;border:1px solid #E5E7EB;border-left:4px solid ' . $accent . ';border-radius:8px;">';
		foreach ( $rows as $label => $value ) {
			$out .= '
                <tr>
                  <td style="padding:10px 16px;font-size:14px;color:#6B7280;">' . $label . '</td>
                  <td align="right" style="padding:10px 16px;font-size:14px;font-weight:600;color:#1F2937;">' . $value . '</td>
                </tr>';
		}
		return $out . '
              </table>';
	}

	/* =========================================================
	 *  PANEL ADMINISTRACYJNY
	 * ========================================================= */

	public static function admin_menu() {
		add_submenu_page(
			self::$cfg['parent'],
			self::$cfg['page_title'],
			self::$cfg['menu_title'],
			self::$cfg['capability'],
			self::page_slug(),
			array( __CLASS__, 'render_page' )
		);
	}

	public static function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::page_slug() ) || empty( $_GET['edit'] ) ) { // phpcs:ignore
			return;
		}
		$settings = wp_enqueue_code_editor(
			array(
				'type'       => 'text/html',
				'codemirror' => array( 'lineWrapping' => true ),
				'htmlhint'   => array( 'space-tab-mixed-disabled' => false ),
			)
		);
		wp_add_inline_script( 'code-editor', 'window.emCodeEditorSettings = ' . wp_json_encode( $settings ? $settings : false ) . ';' );
		wp_enqueue_media();
	}

	private static function page_url( array $args = array() ) {
		return admin_url( self::$cfg['parent'] . '?page=' . self::page_slug() . ( $args ? '&' . http_build_query( $args ) : '' ) );
	}

	private static function check_request() {
		if ( ! current_user_can( self::$cfg['capability'] ) ) {
			wp_die( 'Brak uprawnień.', 403 );
		}
		check_admin_referer( self::$cfg['slug'] . '_email_admin' );
	}

	private static function post( $name, $default = '' ) {
		return isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : $default; // phpcs:ignore WordPress.Security
	}

	/** Administrator (unfiltered_html) zapisuje kod bez zmian; inni - przefiltrowany. */
	private static function sanitize_html( $html ) {
		$html = (string) $html;
		return current_user_can( 'unfiltered_html' ) ? $html : wp_kses_post( $html );
	}

	private static function redirect( array $args ) {
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	public static function handle_save() {
		self::check_request();
		$key  = sanitize_key( self::post( 'key' ) );
		$reg  = self::messages();
		$item = $reg[ $key ] ?? null;
		if ( ! $item ) {
			self::redirect( array( 'msg' => 'unknown' ) );
		}

		$all   = get_option( self::opt_messages(), array() );
		$all   = is_array( $all ) ? $all : array();
		$entry = array( 'html' => self::sanitize_html( self::post( 'html' ) ) );

		if ( $item['subject_editable'] ) {
			$subject = sanitize_text_field( self::post( 'subject' ) );
			if ( is_callable( $item['save_subject'] ) ) {
				call_user_func( $item['save_subject'], $subject );
			} else {
				$entry['subject'] = $subject;
			}
		}

		$all[ $key ] = $entry;
		update_option( self::opt_messages(), $all );
		self::flush_registry();

		self::redirect( array( 'edit' => $key, 'msg' => 'saved' ) );
	}

	public static function handle_reset() {
		self::check_request();
		$key  = sanitize_key( self::post( 'key' ) );
		$reg  = self::messages();
		$item = $reg[ $key ] ?? null;
		if ( ! $item ) {
			self::redirect( array( 'msg' => 'unknown' ) );
		}
		if ( is_callable( $item['save_subject'] ) && is_callable( $item['default_subject'] ) ) {
			call_user_func( $item['save_subject'], (string) call_user_func( $item['default_subject'] ) );
		}
		$all = get_option( self::opt_messages(), array() );
		if ( is_array( $all ) && isset( $all[ $key ] ) ) {
			unset( $all[ $key ] );
			update_option( self::opt_messages(), $all );
		}
		self::flush_registry();
		self::redirect( array( 'edit' => $key, 'msg' => 'reset' ) );
	}

	private static function sample_tresc_html( $item ) {
		if ( ! $item['tresc'] ) {
			return '';
		}
		$text = is_callable( $item['sample_tresc'] ) ? (string) call_user_func( $item['sample_tresc'] ) : '';
		if ( '' === trim( $text ) ) {
			$text = "Przykładowa treść wiadomości.\nTu pojawi się tekst wpisany w aplikacji przy wysyłce.";
		}
		return self::render_body( $text, (array) $item['sample'] );
	}

	public static function handle_test() {
		self::check_request();
		$key  = sanitize_key( self::post( 'key' ) );
		$reg  = self::messages();
		$item = $reg[ $key ] ?? null;
		if ( ! $item ) {
			self::redirect( array( 'msg' => 'unknown' ) );
		}
		$vars    = (array) $item['sample'];
		$subject = self::render_text( $item['subject_editable'] ? sanitize_text_field( self::post( 'subject' ) ) : 'Przykładowy temat', $vars );
		$html    = self::render_html( self::sanitize_html( self::post( 'html' ) ), $subject, $vars, self::sample_tresc_html( $item ) );
		$user    = wp_get_current_user();
		$ok      = $user->user_email ? self::send_raw( $user->user_email, '[TEST] ' . $subject, $html ) : false;
		self::redirect( array( 'edit' => $key, 'msg' => $ok ? 'test_ok' : 'test_fail' ) );
	}

	/* ---------- widoki ---------- */

	public static function render_page() {
		if ( ! current_user_can( self::$cfg['capability'] ) ) {
			return;
		}
		$edit = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : ''; // phpcs:ignore
		$msg  = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '';   // phpcs:ignore

		$notices = array(
			'saved'     => array( 'success', 'Zapisano wiadomość.' ),
			'reset'     => array( 'success', 'Przywrócono domyślny kod i temat wiadomości.' ),
			'test_ok'   => array( 'success', 'Wysłano wiadomość testową (z przykładowymi danymi) na: ' . wp_get_current_user()->user_email ),
			'test_fail' => array( 'error', 'Nie udało się wysłać wiadomości testowej - sprawdź konfigurację poczty / wtyczkę SMTP.' ),
			'unknown'   => array( 'error', 'Nieznana wiadomość.' ),
		);

		echo '<div class="wrap em-designer"><h1>' . esc_html( self::$cfg['page_title'] ) . '</h1>';
		if ( isset( $notices[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notices[ $msg ][0] ), esc_html( $notices[ $msg ][1] ) );
		}
		self::admin_styles();
		if ( $edit ) {
			self::render_edit( $edit );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function admin_styles() {
		?>
		<style>
			.em-designer .em-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px 22px;margin:18px 0}
			.em-designer .em-box h2{margin-top:0}
			.em-designer .em-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;vertical-align:middle}
			.em-designer .em-auto{background:#e0f2fe;color:#075985}
			.em-designer .em-manual{background:#fef3c7;color:#92400e}
			.em-designer .em-custom{background:#dcfce7;color:#166534}
			.em-designer .em-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start}
			@media (max-width:1300px){.em-designer .em-grid{grid-template-columns:1fr}}
			.em-designer .CodeMirror{height:640px;border:1px solid #dcdcde;border-radius:4px;font-size:12px}
			.em-designer #em_html{width:100%;height:640px;font-family:Menlo,Consolas,monospace;font-size:12px}
			.em-designer .em-preview-wrap{background:#f0f0f1;border:1px solid #dcdcde;border-radius:4px;padding:10px;text-align:center}
			.em-designer #em_preview{width:100%;height:640px;border:0;background:#fff;transition:width .2s}
			.em-designer .em-tags code{cursor:pointer;display:inline-block;margin:0 4px 6px 0}
			.em-designer .em-tags code:hover{background:#2271b1;color:#fff}
			.em-designer .em-tags td{padding:2px 12px 2px 0;vertical-align:top}
			.em-designer .em-actions .button{margin-right:6px}
			.em-designer .em-toolbar{margin-bottom:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
		</style>
		<?php
	}

	private static function render_list() {
		$groups = array(
			'auto'   => 'Wysyłane automatycznie (po zdarzeniu w systemie)',
			'manual' => 'Wysyłane ręcznie (przyciskiem w aplikacji)',
		);
		echo '<p>Każda wiadomość ma własny kod HTML - nagłówek, style, treść i stopkę ustawiasz bezpośrednio w kodzie, używając tagów (np. <code>{imie}</code>).</p>';
		foreach ( $groups as $type => $title ) {
			$items = array_filter( self::messages(), fn( $m ) => $m['type'] === $type );
			if ( ! $items ) {
				continue;
			}
			echo '<div class="em-box"><h2>' . esc_html( $title ) . '</h2><table class="widefat striped"><thead><tr><th>Wiadomość</th><th>Odbiorca</th><th>Temat</th><th>Kod</th><th></th></tr></thead><tbody>';
			foreach ( $items as $key => $m ) {
				$cur = self::get_message( $key );
				printf(
					'<tr><td><strong>%s</strong><br><span class="description">%s</span></td><td>%s</td><td>%s</td><td>%s</td><td><a class="button button-primary" href="%s">Edytuj kod</a></td></tr>',
					esc_html( $m['label'] ),
					esc_html( $m['description'] ),
					esc_html( $m['recipient'] ),
					esc_html( $m['subject_editable'] ? ( $cur['subject'] ?: '—' ) : 'wpisywany w aplikacji' ),
					$cur['customized'] ? '<span class="em-badge em-custom">własny</span>' : '<span class="description">domyślny</span>',
					esc_url( self::page_url( array( 'edit' => $key ) ) )
				);
			}
			echo '</tbody></table></div>';
		}
	}

	private static function render_edit( $key ) {
		$reg = self::messages();
		if ( ! isset( $reg[ $key ] ) ) {
			echo '<div class="notice notice-error"><p>Nieznana wiadomość.</p></div>';
			return;
		}
		$item = $reg[ $key ];
		$msg  = self::get_message( $key );
		$slug = self::$cfg['slug'];

		$tags = $item['vars'];
		if ( $item['tresc'] ) {
			$tags = array( 'tresc' => 'treść wpisana / zapisana w aplikacji (z akapitami i podstawionymi tagami)' ) + $tags;
		}
		$tags += array(
			'temat'   => 'temat wiadomości',
			'app_url' => 'adres aplikacji (' . self::app_url() . ')',
			'rok'     => 'bieżący rok',
		);

		// Dane do podglądu na żywo (podstawiane w przeglądarce jak na serwerze).
		$preview = array(
			'vars'  => array_map( 'strval', (array) $item['sample'] ),
			'tresc' => self::sample_tresc_html( $item ),
			'sys'   => array(
				'{rok}'     => gmdate( 'Y' ),
				'{app_url}' => esc_url( self::app_url() ),
			),
			'fixedSubject' => $item['subject_editable'] ? null : 'Przykładowy temat (wpisywany w aplikacji)',
		);
		?>
		<p><a href="<?php echo esc_url( self::page_url() ); ?>">&larr; Wszystkie wiadomości</a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="em_form">
			<?php wp_nonce_field( $slug . '_email_admin' ); ?>
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">

			<div class="em-box">
				<h2><?php echo esc_html( $item['label'] ); ?>
					<span class="em-badge <?php echo 'auto' === $item['type'] ? 'em-auto' : 'em-manual'; ?>"><?php echo 'auto' === $item['type'] ? 'automatyczna' : 'ręczna'; ?></span>
					<?php if ( $msg['customized'] ) : ?><span class="em-badge em-custom">własny kod</span><?php endif; ?>
				</h2>
				<p><?php echo esc_html( $item['description'] ); ?><?php echo $item['recipient'] ? ' <strong>Odbiorca:</strong> ' . esc_html( $item['recipient'] ) . '.' : ''; ?></p>
				<?php if ( $item['subject_editable'] ) : ?>
					<p><label for="em_subject"><strong>Temat</strong></label><br>
					<input type="text" id="em_subject" name="subject" class="large-text" value="<?php echo esc_attr( $msg['subject'] ); ?>"></p>
				<?php else : ?>
					<p><strong>Temat:</strong> wpisywany w aplikacji przy każdej wysyłce.</p>
				<?php endif; ?>
				<?php if ( $item['tresc'] ) : ?>
					<p class="description">Tag <code>{tresc}</code> wstawia tekst tej wiadomości edytowany w aplikacji. Możesz go zostawić (wtedy edycja w aplikacji nadal działa) albo usunąć i napisać całą treść bezpośrednio w kodzie.</p>
				<?php endif; ?>
			</div>

			<div class="em-box">
				<h2>Tagi <span class="description" style="font-weight:normal;">- kliknij, aby wstawić w miejscu kursora</span></h2>
				<table class="em-tags">
					<?php foreach ( $tags as $tag => $desc ) : ?>
						<tr><td><code class="em-insert" data-tag="<?php echo esc_attr( '{' . $tag . '}' ); ?>">{<?php echo esc_html( $tag ); ?>}</code></td><td><?php echo esc_html( $desc ); ?></td></tr>
					<?php endforeach; ?>
				</table>
				<p class="description">Wartości tagów są automatycznie zabezpieczane (znaki HTML w np. imieniu nie zepsują maila). Tagi działają także w atrybutach, np. <code>&lt;a href="{app_url}"&gt;</code>.</p>
			</div>

			<div class="em-grid">
				<div class="em-box" style="margin-top:0;">
					<div class="em-toolbar">
						<strong style="margin-right:auto;">Kod HTML</strong>
						<button type="button" class="button" id="em_add_img">Wstaw obrazek z biblioteki</button>
					</div>
					<textarea name="html" id="em_html" spellcheck="false"><?php echo esc_textarea( $msg['html'] ); ?></textarea>
					<p class="description">Wskazówki: układ oprzyj na tabelach i stylach inline (<code>style="..."</code>) - tak działają Gmail i Outlook. Obrazki muszą mieć pełny adres https (np. z biblioteki mediów), logo zapisz jako PNG/JPG, nie SVG.</p>
				</div>
				<div class="em-box" style="margin-top:0;">
					<div class="em-toolbar">
						<strong style="margin-right:auto;">Podgląd na żywo <span class="description" style="font-weight:normal;">(przykładowe dane)</span></strong>
						<button type="button" class="button em-w" data-w="100%">Komputer</button>
						<button type="button" class="button em-w" data-w="375px">Telefon</button>
					</div>
					<div class="em-preview-wrap"><iframe id="em_preview" title="Podgląd"></iframe></div>
				</div>
			</div>

			<div class="em-box em-actions">
				<button type="submit" class="button button-primary" name="action" value="<?php echo esc_attr( $slug . '_email_save' ); ?>">Zapisz wiadomość</button>
				<button type="submit" class="button" name="action" value="<?php echo esc_attr( $slug . '_email_test' ); ?>">Wyślij test na mój e-mail</button>
				<button type="submit" class="button button-link-delete" name="action" value="<?php echo esc_attr( $slug . '_email_reset' ); ?>" onclick="return confirm('Przywrócić domyślny kod i temat tej wiadomości? Twój kod zostanie usunięty.');">Przywróć domyślny kod</button>
			</div>
		</form>
		<script type="application/json" id="em_preview_data"><?php echo wp_json_encode( $preview ); ?></script>
		<?php self::admin_script(); ?>
		<?php
	}

	private static function admin_script() {
		?>
		<script>
		jQuery(function($){
			var data = JSON.parse(document.getElementById('em_preview_data').textContent);
			var ta = document.getElementById('em_html');
			var cm = null;
			if (window.wp && wp.codeEditor && window.emCodeEditorSettings) {
				cm = wp.codeEditor.initialize(ta, window.emCodeEditorSettings).codemirror;
			}
			function getCode(){ return cm ? cm.getValue() : ta.value; }
			function insert(text){
				if (cm) { cm.replaceSelection(text); cm.focus(); }
				else { var p = ta.selectionStart || ta.value.length; ta.value = ta.value.slice(0,p) + text + ta.value.slice(p); }
				refresh();
			}
			function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
			function nl2br(s){ return s.replace(/\r?\n/g,'<br />\n'); }
			function replaceAll(s, a, b){ return s.split(a).join(b); }
			function subject(){
				if (data.fixedSubject) return data.fixedSubject;
				var s = ($('#em_subject').val() || '');
				$.each(data.vars, function(k,v){ s = replaceAll(s, '{'+k+'}', v); });
				return s;
			}
			function render(){
				var html = getCode();
				var map = {};
				$.each(data.vars, function(k,v){ map['{'+k+'}'] = nl2br(esc(v)); });
				map['{tresc}'] = data.tresc;
				map['{temat}'] = esc(subject());
				$.each(data.sys, function(k,v){ map[k] = v; });
				// podstawienie jednym przebiegiem (jak strtr w PHP)
				var keys = Object.keys(map).sort(function(a,b){ return b.length - a.length; })
					.map(function(k){ return k.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); });
				if (!keys.length) return html;
				return html.replace(new RegExp(keys.join('|'),'g'), function(m){ return map[m]; });
			}
			var t = null;
			function refresh(){
				clearTimeout(t);
				t = setTimeout(function(){ document.getElementById('em_preview').srcdoc = render(); }, 250);
			}
			if (cm) { cm.on('change', refresh); } else { $(ta).on('input', refresh); }
			$('#em_subject').on('input', refresh);
			refresh();

			$('.em-insert').on('click', function(){ insert($(this).data('tag')); });
			$('.em-w').on('click', function(){ $('#em_preview').css('width', $(this).data('w')); });
			$('#em_add_img').on('click', function(e){
				e.preventDefault();
				var frame = wp.media({ title: 'Wybierz obraz', library: { type: 'image' }, multiple: false, button: { text: 'Wstaw' } });
				frame.on('select', function(){
					var a = frame.state().get('selection').first().toJSON();
					var w = Math.min(a.width || 600, 600);
					insert('<img src="' + a.url + '" width="' + w + '" alt="' + esc(a.alt || '') + '" style="display:block;max-width:100%;height:auto;border:0;">');
				});
				frame.open();
			});
			$('#em_form').on('submit', function(){ if (cm) { cm.save(); } });
		});
		</script>
		<?php
	}
}

endif;
