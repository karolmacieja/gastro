<?php
/*
Plugin Name: GastroFlowX Pliki
Plugin URI: https://gastroflowx.pl
Description: Moduł GastroFlowX — biblioteka dokumentów do druku (wzory raportów utargu, listy obowiązków, checklisty). Dodawanie, edycja, podgląd i drukowanie w całości z poziomu frontu (SPA Vue 3). Shortcode: [gastroflowx_pliki_app]
Version: 1.1.4
Requires at least: 6.0
Requires PHP: 7.4
Author: GastroFlowX
Text Domain: gastroflowx-pliki
Domain Path: /languages
License: GPLv2 or later
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GFX_PLIKI_VERSION', '1.1.4' );
define( 'GFX_PLIKI_FILE', __FILE__ );
define( 'GFX_PLIKI_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFX_PLIKI_URL', plugin_dir_url( __FILE__ ) );
define( 'GFX_PLIKI_NS', 'gastroflowx-pliki/v1' );
define( 'GFX_PLIKI_SHORTCODE', 'gastroflowx_pliki_app' );

require_once GFX_PLIKI_DIR . 'includes/class-gfx-pliki-settings.php';
require_once GFX_PLIKI_DIR . 'includes/class-gfx-pliki-documents.php';
require_once GFX_PLIKI_DIR . 'includes/class-gfx-pliki-rest.php';
require_once GFX_PLIKI_DIR . 'includes/class-gfx-pliki-shortcode.php';

GFX_Pliki_Settings::init();
GFX_Pliki_Documents::init();
GFX_Pliki_Rest::init();
GFX_Pliki_Shortcode::init();

add_action( 'init', function () {
    load_plugin_textdomain( 'gastroflowx-pliki', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Ostatnia linia obrony (jak w pozostałych modułach): jeśli podczas obsługi
 * NASZEGO endpointu REST wystąpi fatal error, którego nie da się złapać
 * try/catch, zwracamy czysty JSON zamiast strony HTML „Wystąpił krytyczny błąd”,
 * która psułaby parsowanie odpowiedzi we froncie.
 */
function gfx_pliki_fatal_shutdown_handler() {
    $error = error_get_last();
    if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
        return;
    }
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( strpos( $uri, 'gastroflowx-pliki/v1' ) === false && strpos( urldecode( $uri ), 'gastroflowx-pliki/v1' ) === false ) {
        return;
    }
    if ( ! headers_sent() ) {
        header_remove();
        status_header( 500 );
        header( 'Content-Type: application/json; charset=UTF-8' );
    }
    while ( ob_get_level() > 0 ) { ob_end_clean(); }
    echo wp_json_encode( array(
        'code'    => 'gfx_pliki_fatal',
        'message' => 'Krytyczny błąd PHP: ' . $error['message'] . ' (' . basename( $error['file'] ) . ':' . $error['line'] . ')',
        'data'    => array( 'status' => 500 ),
    ) );
    exit;
}
register_shutdown_function( 'gfx_pliki_fatal_shutdown_handler' );

/**
 * Aktywacja: typ wpisu, chroniony katalog na pliki, domyślne ustawienia,
 * kategorie i przykładowe dokumenty (tylko przy pierwszej aktywacji).
 */
function gfx_pliki_activate() {
    GFX_Pliki_Documents::register_post_type();
    GFX_Pliki_Documents::storage_dir();
    GFX_Pliki_Settings::install_defaults();
    if ( get_option( GFX_Pliki_Documents::CATS_OPTION, null ) === null ) {
        update_option( GFX_Pliki_Documents::CATS_OPTION, GFX_Pliki_Documents::default_categories(), false );
    }
    GFX_Pliki_Documents::seed_examples();
}
register_activation_hook( __FILE__, 'gfx_pliki_activate' );

/**
 * Blokada wp-admin dla wszystkich poza administratorem — tak jak w reszcie
 * systemu. Jeśli Hub już to robi, dublowanie jest nieszkodliwe; można też
 * wyłączyć tę część filtrem: add_filter( 'gfx_pliki_block_wp_admin', '__return_false' );
 */
function gfx_pliki_block_wp_admin() {
    if ( ! apply_filters( 'gfx_pliki_block_wp_admin', true ) ) return;
    if ( ! is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) return;
    if ( ! is_user_logged_in() || current_user_can( 'administrator' ) ) return;
    global $pagenow;
    if ( in_array( $pagenow, array( 'admin-post.php', 'async-upload.php' ), true ) ) return;
    wp_safe_redirect( apply_filters( 'gfx_pliki_admin_redirect_url', home_url( '/' ) ) );
    exit;
}
add_action( 'admin_init', 'gfx_pliki_block_wp_admin', 1 );

add_filter( 'show_admin_bar', function ( $show ) {
    if ( ! apply_filters( 'gfx_pliki_block_wp_admin', true ) ) return $show;
    return current_user_can( 'administrator' ) ? $show : false;
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    array_unshift( $links, '<a href="' . esc_url( GFX_Pliki_Settings::page_url() ) . '">' . esc_html__( 'Ustawienia', 'gastroflowx-pliki' ) . '</a>' );
    return $links;
} );
