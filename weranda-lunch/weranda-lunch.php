<?php
/*
Plugin Name: Weranda Lunch Menu Generator
Description: Generator PDF karty lunchowej (SPA Vue 3) — karta budowana w całości z danych: treść dań z panelu, a nagłówki, ceny, logo, czcionki i układ w Ustawieniach PDF (z frontu). Wymaga roli "lunch" lub "administrator". Shortcode: [weranda_lunch_panel]
Version: 2.0.0
Author: Weranda
Text Domain: weranda-lunch
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WERANDA_LUNCH_VERSION', '2.0.0' );
define( 'WERANDA_LUNCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'WERANDA_LUNCH_URL', plugin_dir_url( __FILE__ ) );

require_once WERANDA_LUNCH_DIR . 'includes/class-weranda-lunch-rest.php';
require_once WERANDA_LUNCH_DIR . 'includes/class-weranda-lunch-shortcode.php';

/**
 * Ostatnia linia obrony: gdyby doszło do fatal errora, który NIE da się złapać
 * przez try/catch (np. wyczerpana pamięć, błąd w zupełnie innej wtyczce
 * podpiętej pod ten sam request), WordPress domyślnie renderuje całą stronę
 * HTML "Wystąpił krytyczny błąd" ze statusem 500 — to właśnie ta strona psuje
 * parsowanie JSON we froncie. Ta funkcja, jeśli fatal wystąpił w trakcie
 * obsługi NASZEGO endpointu REST, nadpisuje wyjście czystym JSON-em zamiast
 * pozwolić WordPressowi wyrenderować HTML.
 */
function weranda_lunch_fatal_shutdown_handler() {
    $error = error_get_last();
    if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
        return;
    }
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    if ( strpos( $uri, 'weranda-lunch/v1' ) === false ) {
        return; // To nie nasz endpoint — nie ingerujemy.
    }
    if ( ! headers_sent() ) {
        header_remove();
        status_header( 500 );
        header( 'Content-Type: application/json; charset=UTF-8' );
    }
    // Usuń wszystko, co ewentualnie już trafiło do bufora wyjścia (np. początek strony HTML).
    while ( ob_get_level() > 0 ) { ob_end_clean(); }
    echo wp_json_encode( array(
        'code'    => 'weranda_lunch_fatal',
        'message' => 'Krytyczny błąd PHP: ' . $error['message'] . ' (' . basename( $error['file'] ) . ':' . $error['line'] . ')',
        'data'    => array( 'status' => 500 ),
    ) );
    exit;
}
register_shutdown_function( 'weranda_lunch_fatal_shutdown_handler' );

/**
 * Aktywacja: tworzymy domyślny szablon na bazie starych, zaszytych na sztywno
 * współrzędnych, żeby po migracji z poprzedniej wersji panel od razu działał.
 */
function weranda_lunch_activate() {
    $existing = get_option( 'weranda_lunch_templates', null );
    if ( $existing === null ) {
        $default_templates = array(
            array(
                'id'     => 'tpl_' . wp_generate_password( 8, false ),
                'name'   => 'Szablon Standardowy',
                'url'    => 'https://gastroflowx.pl/wp-content/uploads/2026/03/lunch.pdf',
                'config' => array(
                    'soup'   => array( 'y' => 565, 'size' => 19,   'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                    'weight' => array( 'y' => 395, 'size' => 12,   'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                    'lunch'  => array( 'y' => 370, 'size' => 16.5, 'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                    'rec'    => array( 'y' => 195, 'size' => 12,   'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                ),
            ),
            array(
                'id'     => 'tpl_' . wp_generate_password( 8, false ),
                'name'   => 'Szablon 2',
                'url'    => 'https://gastroflowx.pl/wp-content/uploads/2026/03/szablon-2.pdf',
                'config' => array(
                    'soup'   => array( 'y' => 565, 'size' => 19,   'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                    'weight' => array( 'y' => 410, 'size' => 12,   'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                    'lunch'  => array( 'y' => 385, 'size' => 16.5, 'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                    'rec'    => array( 'y' => 210, 'size' => 12,   'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                ),
            ),
            array(
                'id'     => 'tpl_' . wp_generate_password( 8, false ),
                'name'   => 'Szablon 3',
                'url'    => 'https://gastroflowx.pl/wp-content/uploads/2026/03/szablon-3.pdf',
                'config' => array(
                    'soup'   => array( 'y' => 565, 'size' => 19,   'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                    'weight' => array( 'y' => 440, 'size' => 12,   'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                    'lunch'  => array( 'y' => 415, 'size' => 16.5, 'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                    'rec'    => array( 'y' => 240, 'size' => 12,   'maxWidth' => 260, 'x1' => 148, 'x2' => 446 ),
                ),
            ),
        );
        update_option( 'weranda_lunch_templates', $default_templates );
        update_option( 'weranda_lunch_active_template', $default_templates[0]['id'] );
    }

    // Migracja starych opcji z wersji "2 w 1", jeśli istnieją.
    if ( false !== get_option( 'weranda_menu_soup', false ) ) {
        update_option( 'weranda_lunch_draft', array(
            'soup'   => get_option( 'weranda_menu_soup', '' ),
            'weight' => get_option( 'weranda_menu_weight', '' ),
            'lunch'  => get_option( 'weranda_menu_lunch', '' ),
            'rec'    => get_option( 'weranda_menu_rec', '' ),
        ) );
    }
    if ( false !== get_option( 'weranda_pdf_archive', false ) ) {
        update_option( 'weranda_lunch_archive', get_option( 'weranda_pdf_archive', array() ) );
    }
    if ( false !== get_option( 'weranda_checklist_items', false ) ) {
        update_option( 'weranda_lunch_checklist', get_option( 'weranda_checklist_items', array() ) );
    }
}
register_activation_hook( __FILE__, 'weranda_lunch_activate' );

/**
 * Sprawdza, czy bieżąca strona faktycznie zawiera shortcode panelu — ładujemy
 * skrypty tylko wtedy, żeby nie kolidować z innymi wtyczkami/skryptami na
 * pozostałych podstronach i nie obciążać niepotrzebnie każdej strony witryny.
 */
function weranda_lunch_page_has_shortcode() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'weranda_lunch_panel' ) ) {
        return true;
    }
    return false;
}

/**
 * Ładuje wszystkie zasoby panelu Lunch. Publiczna, bo wywołuje ją także panel
 * GastroFlowx (Hub), gdy Lunch jest osadzony w [gastroflowx_app] — dzięki temu
 * lista skryptów jest w jednym miejscu.
 *
 * Od 2.0.0 karta lunchowa jest generowana w całości z danych (assets/lunch-layout.js),
 * a pdf-lib, fontkit, czcionki i logo są dołączone lokalnie do wtyczki.
 */
function weranda_lunch_enqueue_assets_now() {
    // Font Awesome w tej samej wersji co wtyczka "System Napiwków" — spójne ikony w panelu.
    wp_enqueue_style( 'weranda-fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css', array(), '6.0.0' );

    // Vue 3 (build globalny) + pdf-lib/fontkit (lokalnie, wersje sprawdzone z silnikiem układu)
    // + pdf.js do podglądu. Wszystko w stopce, w gwarantowanej kolejności zależności.
    wp_enqueue_script( 'vue3', 'https://unpkg.com/vue@3.4.21/dist/vue.global.prod.js', array(), '3.4.21', true );
    wp_enqueue_script( 'weranda-lunch-pdf-lib', WERANDA_LUNCH_URL . 'assets/vendor/pdf-lib.min.js', array(), '1.17.1', true );
    wp_enqueue_script( 'weranda-lunch-fontkit', WERANDA_LUNCH_URL . 'assets/vendor/fontkit.umd.min.js', array(), '1.1.1', true );
    wp_enqueue_script( 'pdf-js', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js', array(), '2.16.105', true );

    // Biblioteka mediów WP — administrator wybiera w niej własne logo.
    if ( current_user_can( 'upload_files' ) ) {
        wp_enqueue_media();
    }

    wp_enqueue_style( 'weranda-lunch-style', WERANDA_LUNCH_URL . 'assets/style.css', array(), WERANDA_LUNCH_VERSION );
    wp_enqueue_script( 'weranda-lunch-layout', WERANDA_LUNCH_URL . 'assets/lunch-layout.js', array(), WERANDA_LUNCH_VERSION, true );
    // Świadomie BEZ type="module" — klasyczny skrypt z jawną listą zależności.
    wp_enqueue_script( 'weranda-lunch-app', WERANDA_LUNCH_URL . 'assets/app.js', array( 'vue3', 'weranda-lunch-pdf-lib', 'weranda-lunch-fontkit', 'pdf-js', 'weranda-lunch-layout' ), WERANDA_LUNCH_VERSION, true );

    wp_localize_script( 'weranda-lunch-app', 'werandaLunchConfig', array(
        'restUrl'     => esc_url_raw( rest_url( 'weranda-lunch/v1' ) ),
        'nonce'       => wp_create_nonce( 'wp_rest' ),
        'isAdmin'     => current_user_can( 'administrator' ),
        'fontsBase'   => WERANDA_LUNCH_URL . 'assets/fonts/',
        'logoDefault' => WERANDA_LUNCH_URL . 'assets/img/logo-weranda.png',
        'version'     => WERANDA_LUNCH_VERSION,
    ) );
}

function weranda_lunch_enqueue_assets() {
    if ( ! weranda_lunch_page_has_shortcode() ) return;
    weranda_lunch_enqueue_assets_now();
}
add_action( 'wp_enqueue_scripts', 'weranda_lunch_enqueue_assets' );
