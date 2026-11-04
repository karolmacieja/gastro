<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function weranda_lunch_panel_shortcode() {

    if ( ! is_user_logged_in() ) {
        ob_start(); ?>
        <style>
            .weranda-login-wrapper { max-width: 400px; margin: 60px auto; background: #fff; padding: 40px 30px; border-radius: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.06); border: 1px solid #E5E7EB; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; text-align: center; }
            .weranda-login-wrapper h3 { margin-top: 0; font-size: 22px; color: #1F2937; margin-bottom: 10px; font-weight: 700; }
            .weranda-login-wrapper p { font-size: 14px; color: #6B7280; margin-bottom: 25px; }
            .weranda-login-wrapper p.login-username, .weranda-login-wrapper p.login-password { text-align: left; margin-bottom: 15px; }
            .weranda-login-wrapper label { font-weight: 600; display: block; margin-bottom: 8px; font-size: 14px; color: #1F2937; text-align: left;}
            .weranda-login-wrapper input[type="text"], .weranda-login-wrapper input[type="password"] { width: 100%; padding: 12px 14px; border: 1px solid #E5E7EB; border-radius: 8px; font-family: inherit; font-size: 15px; box-sizing: border-box; background: #F9FAFB; }
            .weranda-login-wrapper input[type="text"]:focus, .weranda-login-wrapper input[type="password"]:focus { outline: none; border-color: #2563EB; background: #fff; }
            .weranda-login-wrapper p.login-remember { text-align: left; font-size: 13px; display: flex; align-items: center; gap: 8px; margin-bottom: 20px; }
            .weranda-login-wrapper input[type="submit"] { background: #2563EB; color: #fff; border: none; padding: 14px 20px; width: 100%; font-family: inherit; font-size: 16px; font-weight: 700; border-radius: 8px; cursor: pointer; }
            .weranda-login-wrapper input[type="submit"]:hover { background: #1D4ED8; }
        </style>
        <div class="weranda-login-wrapper">
            <h3>Panel Pracownika — Lunch</h3>
            <p>Zaloguj się na swoje konto, aby uzyskać dostęp do generatora menu.</p>
            <?php
            wp_login_form( array(
                'redirect'       => ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
                'label_username' => __( 'Nazwa użytkownika' ),
                'label_password' => __( 'Hasło' ),
                'label_remember' => __( 'Zapamiętaj mnie' ),
                'label_log_in'   => __( 'Zaloguj się' ),
            ) );
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    $user = wp_get_current_user();
    $has_access = in_array( 'lunch', (array) $user->roles, true ) || in_array( 'administrator', (array) $user->roles, true );

    if ( ! $has_access ) {
        return '<div style="padding:40px; text-align:center; border: 1px solid #FECACA; background: #FEF2F2; color: #EF4444; border-radius:24px; font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif; margin: 40px auto; max-width: 600px;">
                    <h3 style="font-weight:700;">Brak dostępu</h3>
                    <p>Jesteś zalogowany, ale Twoje konto nie posiada uprawnień pracownika lunchu. Skontaktuj się z administratorem.</p>
                </div>';
    }

    // Cały widok renderuje Vue 3 (assets/app.js) — tu tylko punkt montowania SPA.
    return '<div id="weranda-lunch-app" class="weranda-master-container"><div style="display:flex;align-items:center;justify-content:center;padding:60px 0;color:#9CA3AF;font-size:14px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">Ładowanie panelu…</div></div>';
}
add_shortcode( 'weranda_lunch_panel', 'weranda_lunch_panel_shortcode' );
