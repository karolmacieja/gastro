<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shortcode [gastroflowx_pliki_app].
 *
 * KLUCZOWE: assety są ładowane WEWNĄTRZ callbacku shortcode'a (nie na
 * wp_enqueue_scripts przez has_shortcode()), dzięki czemu moduł działa
 * zarówno jako samodzielny shortcode na stronie, jak i osadzony dynamicznie
 * przez do_shortcode() wewnątrz [gastroflowx_app]. Skrypty są w stopce,
 * a style — jako „late styles” — też są wypisywane w stopce przez WP.
 */
class GFX_Pliki_Shortcode {

    const HANDLE = 'gfx-pliki-app';

    public static function init() {
        add_shortcode( GFX_PLIKI_SHORTCODE, array( __CLASS__, 'render' ) );
    }

    /** Uchwyty bibliotek wspólne z innymi modułami (Kolorowanki) — bez podwójnego ładowania. */
    public static function register_assets() {
        if ( ! wp_style_is( 'weranda-fontawesome', 'registered' ) ) {
            wp_register_style( 'weranda-fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css', array(), '6.0.0' );
        }
        if ( ! wp_script_is( 'vue3', 'registered' ) ) {
            wp_register_script( 'vue3', 'https://unpkg.com/vue@3.4.21/dist/vue.global.prod.js', array(), '3.4.21', true );
        }
        if ( ! wp_script_is( 'pdf-js', 'registered' ) ) {
            wp_register_script( 'pdf-js', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js', array(), '2.16.105', true );
        }
        if ( ! wp_style_is( 'gfx-pliki-style', 'registered' ) ) {
            wp_register_style( 'gfx-pliki-style', GFX_PLIKI_URL . 'assets/style.css', array( 'weranda-fontawesome' ), GFX_PLIKI_VERSION );
        }
        if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
            // Klasyczny skrypt (bez type="module") z jawnymi zależnościami — gwarantowana kolejność.
            wp_register_script( self::HANDLE, GFX_PLIKI_URL . 'assets/app.js', array( 'vue3', 'pdf-js' ), GFX_PLIKI_VERSION, true );
        }
    }

    private static function enqueue_assets() {
        static $done = false;
        if ( $done ) return;
        $done = true;

        self::register_assets();
        wp_enqueue_style( 'weranda-fontawesome' );
        wp_enqueue_style( 'gfx-pliki-style' );
        wp_enqueue_script( 'vue3' );
        wp_enqueue_script( 'pdf-js' );
        wp_enqueue_script( self::HANDLE );

        wp_localize_script( self::HANDLE, 'gfxPlikiConfig', array(
            'restUrl'   => esc_url_raw( rest_url( GFX_PLIKI_NS ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'pdfWorker' => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js',
            'version'   => GFX_PLIKI_VERSION,
        ) );
    }

    public static function render( $atts = array() ) {
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

        if ( ! is_user_logged_in() ) {
            $redirect = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );
            ob_start(); ?>
            <style>
                .gfx-pliki-login{max-width:400px;margin:60px auto;background:#fff;padding:40px 30px;border-radius:24px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid #E5E7EB;font-family:<?php echo $font; ?>;text-align:center;}
                .gfx-pliki-login h3{margin:0 0 10px;font-size:22px;color:#1F2937;font-weight:700;}
                .gfx-pliki-login p{font-size:14px;color:#6B7280;margin-bottom:25px;}
                .gfx-pliki-login p.login-username,.gfx-pliki-login p.login-password{text-align:left;margin-bottom:15px;}
                .gfx-pliki-login label{font-weight:600;display:block;margin-bottom:8px;font-size:14px;color:#1F2937;text-align:left;}
                .gfx-pliki-login input[type="text"],.gfx-pliki-login input[type="password"]{width:100%;padding:12px 14px;border:1px solid #E5E7EB;border-radius:8px;font-family:inherit;font-size:15px;box-sizing:border-box;background:#F9FAFB;}
                .gfx-pliki-login input[type="text"]:focus,.gfx-pliki-login input[type="password"]:focus{outline:none;border-color:#2563EB;background:#fff;}
                .gfx-pliki-login p.login-remember{text-align:left;font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:20px;}
                .gfx-pliki-login input[type="submit"]{background:#2563EB;color:#fff;border:none;padding:14px 20px;width:100%;font-family:inherit;font-size:16px;font-weight:700;border-radius:8px;cursor:pointer;}
                .gfx-pliki-login input[type="submit"]:hover{background:#1D4ED8;}
            </style>
            <div class="gfx-pliki-login">
                <h3><?php esc_html_e( 'Panel Pracownika — Pliki', 'gastroflowx-pliki' ); ?></h3>
                <p><?php esc_html_e( 'Zaloguj się, aby zobaczyć dokumenty do druku.', 'gastroflowx-pliki' ); ?></p>
                <?php
                wp_login_form( array(
                    'redirect'       => esc_url_raw( $redirect ),
                    'label_username' => __( 'Nazwa użytkownika', 'gastroflowx-pliki' ),
                    'label_password' => __( 'Hasło', 'gastroflowx-pliki' ),
                    'label_remember' => __( 'Zapamiętaj mnie', 'gastroflowx-pliki' ),
                    'label_log_in'   => __( 'Zaloguj się', 'gastroflowx-pliki' ),
                ) );
                ?>
            </div>
            <?php
            return ob_get_clean();
        }

        if ( ! GFX_Pliki_Settings::can_view() ) {
            return '<div style="padding:40px;text-align:center;border:1px solid #FECACA;background:#FEF2F2;color:#EF4444;border-radius:24px;font-family:' . esc_attr( $font ) . ';margin:40px auto;max-width:600px;">'
                . '<h3 style="font-weight:700;margin-top:0;">' . esc_html__( 'Brak dostępu', 'gastroflowx-pliki' ) . '</h3>'
                . '<p style="margin-bottom:0;">' . esc_html__( 'Twoje konto nie ma dostępu do modułu Pliki. Skontaktuj się z administratorem.', 'gastroflowx-pliki' ) . '</p>'
                . '</div>';
        }

        self::enqueue_assets();

        return '<div id="gfx-pliki-app" class="gfx-pliki-root"><div style="display:flex;align-items:center;justify-content:center;padding:60px 0;color:#9CA3AF;font-size:14px;font-family:' . esc_attr( $font ) . ';">' . esc_html__( 'Ładowanie panelu…', 'gastroflowx-pliki' ) . '</div></div>';
    }
}
