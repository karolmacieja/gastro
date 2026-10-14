<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [gastroflowx_dokumenty_app].
 *
 * WAŻNE (konwencja GastroFlowx): wszystkie wp_enqueue_script/style są
 * wywoływane WEWNĄTRZ tego callbacku, a nie na hooku wp_enqueue_scripts z
 * bramką has_shortcode(). Dzięki temu moduł działa zarówno wklejony wprost
 * na stronę, jak i osadzony dynamicznie przez do_shortcode() wewnątrz
 * [gastroflowx_app] — bez żadnych zmian w kodzie Huba. Skrypty są
 * zarejestrowane z $in_footer = true, więc WordPress wydrukuje je w stopce,
 * a style dodane w trakcie renderowania treści również trafią do stopki.
 */
class GFXDOK_Shortcode {

	protected static $rendered = false;

	public static function init() {
		add_shortcode( GFXDOK_SHORTCODE, array( __CLASS__, 'render' ) );
	}

	protected static function box( $title, $text, $danger = false ) {
		$border = $danger ? '#FECACA' : '#E5E7EB';
		$bg     = $danger ? '#FEF2F2' : '#FFFFFF';
		$color  = $danger ? '#B91C1C' : '#1F2937';
		return '<div style="padding:32px 24px;text-align:center;border:1px solid ' . $border . ';background:' . $bg . ';color:' . $color . ';border-radius:24px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;margin:24px auto;max-width:560px;">'
			. '<h3 style="font-weight:700;margin:0 0 8px;font-size:18px;">' . esc_html( $title ) . '</h3>'
			. '<p style="margin:0;font-size:14px;line-height:1.5;color:' . ( $danger ? '#B91C1C' : '#6B7280' ) . ';">' . esc_html( $text ) . '</p></div>';
	}

	public static function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return self::box(
				__( 'Dokumenty', 'gastroflowx-dokumenty' ),
				__( 'Zaloguj się, aby tworzyć raporty dobowe.', 'gastroflowx-dokumenty' )
			);
		}

		if ( ! GFXDOK_Settings::user_can_access() ) {
			return self::box(
				__( 'Brak dostępu', 'gastroflowx-dokumenty' ),
				__( 'Twoje konto nie ma uprawnień do modułu Dokumenty. Skontaktuj się z administratorem.', 'gastroflowx-dokumenty' ),
				true
			);
		}

		// Jedna instancja SPA na stronę (jeden punkt montowania, jedna konfiguracja).
		if ( self::$rendered ) {
			return '';
		}
		self::$rendered = true;

		if ( ! wp_script_is( 'gfxdok-app', 'registered' ) ) {
			gfxdok_register_assets();
		}

		// Hub ładuje już Font Awesome 6.5.1 — nie dublujemy, jeśli jest.
		if ( ! wp_style_is( 'font-awesome-gfx', 'enqueued' ) && ! wp_style_is( 'font-awesome-gfx', 'done' ) ) {
			wp_enqueue_style( 'gfxdok-fontawesome' );
		}
		wp_enqueue_style( 'gfxdok-style' );
		wp_enqueue_script( 'vue3' );
		wp_enqueue_script( 'gfxdok-jspdf' );
		wp_enqueue_script( 'gfxdok-app' );

		$user = wp_get_current_user();
		wp_localize_script( 'gfxdok-app', 'gfxDokConfig', array(
			'restUrl'   => esc_url_raw( rest_url( GFXDOK_REST_NS ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'canManage' => GFXDOK_Settings::user_can_manage( $user ),
			'today'     => current_time( 'Y-m-d' ),
			'maxUpload' => (int) wp_max_upload_size(),
			'maxImages' => GFXDOK_REST::MAX_IMAGES_DAY,
			'settings'  => GFXDOK_Settings::get(),
			'version'   => GFXDOK_VERSION,
			// Konwerter HEIC → JPEG (zdjęcia z iPhone'a w Chrome/Edge/Firefox) — ładowany tylko w razie potrzeby.
			'heicLib'   => GFXDOK_PLUGIN_URL . 'assets/vendor/heic-to/heic-to.js?ver=1.6.6',
		) );

		return '<div id="gfxdok-app" class="gfxdok-root"><div class="gfxdok-boot">' . esc_html__( 'Ładowanie panelu…', 'gastroflowx-dokumenty' ) . '</div></div>';
	}
}
GFXDOK_Shortcode::init();
