<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [gastroflowx_menu_app].
 *
 * KLUCZOWE: wszystkie assety są kolejkowane WEWNĄTRZ callbacku shortcode'a,
 * a nie na hooku wp_enqueue_scripts z has_shortcode(). Dzięki temu moduł
 * działa zarówno jako samodzielny shortcode na stronie, jak i osadzony
 * dynamicznie przez do_shortcode() wewnątrz [gastroflowx_app] (Hub).
 * Skrypty idą do stopki ($in_footer = true), która drukuje się po treści,
 * więc późne wp_enqueue_* działa poprawnie; style kolejkowane w trakcie
 * treści WP wypisuje jako „late styles” w stopce.
 */
class GFXM_Shortcode {

	private static $enqueued = false;

	public static function init() {
		add_shortcode( GFXM_SHORTCODE, array( __CLASS__, 'render' ) );
	}

	public static function enqueue_assets() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		// Te same uchwyty i wersje co Hub/Lunch — WP nie załaduje ich podwójnie.
		wp_enqueue_style( 'font-awesome-gfx', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1' );
		wp_enqueue_script( 'vue3', 'https://unpkg.com/vue@3.4.21/dist/vue.global.prod.js', array(), '3.4.21', true );

		// Natywny Media Uploader WP (logo nagłówka i stopki w Ustawieniach).
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_style( 'gastroflowx-menu', GFXM_URL . 'assets/style.css', array(), GFXM_VERSION );
		wp_enqueue_script( 'gastroflowx-menu', GFXM_URL . 'assets/app.js', array( 'vue3' ), GFXM_VERSION, true );

		wp_localize_script(
			'gastroflowx-menu',
			'gfxmConfig',
			array(
				'restUrl'   => esc_url_raw( rest_url( GFXM_REST_NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'isAdmin'   => current_user_can( 'administrator' ),
				'canUpload' => current_user_can( 'upload_files' ),
				'fonts'     => GFXM_Data::font_urls(),
				'weights'   => GFXM_PDF::heading_weights(),
				'nameWeights' => GFXM_PDF::name_weights(),
				'templates' => GFXM_PDF::templates(),
				// Wartości fabryczne — źródło dla przycisków „Przywróć domyślne”.
				'defaults'  => array(
					'settings'   => GFXM_Install::default_settings(),
					'categories' => GFXM_Install::default_categories(),
				),
				'pageW'     => GFXM_PDF::PAGE_W,
				'pageH'     => GFXM_PDF::PAGE_H,
			)
		);
	}

	public static function render() {
		if ( ! is_user_logged_in() ) {
			// W Hubie niezalogowany nie zobaczy modułu (Hub ma własne logowanie);
			// to zabezpieczenie dla samodzielnego użycia shortcode'a.
			ob_start();
			echo '<div class="gfxm-login"><h3>' . esc_html__( 'Menu GastroFlowX', 'gastroflowx-menu' ) . '</h3><p>' . esc_html__( 'Zaloguj się, aby zarządzać menu.', 'gastroflowx-menu' ) . '</p>';
			wp_login_form(
				array(
					'label_username' => __( 'Nazwa użytkownika', 'gastroflowx-menu' ),
					'label_password' => __( 'Hasło', 'gastroflowx-menu' ),
					'label_remember' => __( 'Zapamiętaj mnie', 'gastroflowx-menu' ),
					'label_log_in'   => __( 'Zaloguj się', 'gastroflowx-menu' ),
				)
			);
			echo '</div>';
			wp_enqueue_style( 'gastroflowx-menu', GFXM_URL . 'assets/style.css', array(), GFXM_VERSION );
			return ob_get_clean();
		}

		if ( ! GFXM_Access::user_has_access() ) {
			wp_enqueue_style( 'gastroflowx-menu', GFXM_URL . 'assets/style.css', array(), GFXM_VERSION );
			return '<div class="gfxm-denied"><h3>' . esc_html__( 'Brak dostępu', 'gastroflowx-menu' ) . '</h3><p>' . esc_html__( 'Twoje konto nie ma uprawnień do modułu Menu. Skontaktuj się z administratorem.', 'gastroflowx-menu' ) . '</p></div>';
		}

		self::enqueue_assets();

		return '<div id="gfxm-app" class="gfxm-root"><div class="gfxm-boot">' . esc_html__( 'Ładowanie modułu Menu…', 'gastroflowx-menu' ) . '</div></div>';
	}
}
