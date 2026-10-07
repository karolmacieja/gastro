<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ustawienia modułu + wszystkie reguły uprawnień w jednym miejscu.
 *
 * Każde uprawnienie (akcja) ma własną listę ról — patrz actions().
 * Administrator ma zawsze wszystkie uprawnienia i jako jedyny zmienia ustawienia.
 * Każde uprawnienie wymaga dostępu do modułu (view) — zapis ustawień pilnuje tego sam.
 */
class GFX_Pliki_Settings {

    const OPTION    = 'gfx_pliki_settings';
    const PAGE_SLUG = 'gastroflowx-pliki';

    private static $cache = null;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 99 );
        add_action( 'init', array( __CLASS__, 'migrate_manager_role' ), 20 );
    }

    /**
     * Jednorazowa migracja: rola „manager” została zastąpiona rolą „rs_restaurant_manager”.
     * Przenosi zapisane uprawnienia oraz ograniczenia ról w dokumentach.
     */
    public static function migrate_manager_role() {
        if ( (int) get_option( 'gfx_pliki_roles_migrated', 0 ) >= 1 ) return;
        $swap = function ( $roles ) {
            if ( ! is_array( $roles ) ) return $roles;
            $roles = array_map( function ( $r ) { return $r === 'manager' ? 'rs_restaurant_manager' : $r; }, $roles );
            return array_values( array_unique( $roles ) );
        };
        $o = get_option( self::OPTION, false );
        if ( is_array( $o ) ) {
            foreach ( array( 'view_roles', 'manage_roles' ) as $k ) {
                if ( isset( $o[ $k ] ) ) $o[ $k ] = $swap( $o[ $k ] );
            }
            if ( isset( $o['perms'] ) && is_array( $o['perms'] ) ) {
                foreach ( $o['perms'] as $a => $roles ) $o['perms'][ $a ] = $swap( $roles );
            }
            update_option( self::OPTION, $o, false );
            self::$cache = null;
        }
        $ids = get_posts( array( 'post_type' => GFX_Pliki_Documents::POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
        foreach ( $ids as $id ) {
            $r = get_post_meta( $id, '_gfx_roles', true );
            if ( is_array( $r ) && in_array( 'manager', $r, true ) ) update_post_meta( $id, '_gfx_roles', $swap( $r ) );
        }
        update_option( 'gfx_pliki_roles_migrated', 1, false );
    }

    /** Role istniejące w ekosystemie GastroFlowX (z polskimi etykietami). */
    public static function known_roles() {
        return array(
            'rs_restaurant_manager' => __( 'Manager restauracji', 'gastroflowx-pliki' ),
            'admin_bar'     => __( 'Admin baru', 'gastroflowx-pliki' ),
            'admin_kuchnia' => __( 'Admin kuchni', 'gastroflowx-pliki' ),
            'kelner'        => __( 'Kelner', 'gastroflowx-pliki' ),
            'barman'        => __( 'Barman', 'gastroflowx-pliki' ),
            'kucharz'       => __( 'Kucharz', 'gastroflowx-pliki' ),
            'lunch'         => __( 'Lunch', 'gastroflowx-pliki' ),
        );
    }

    /**
     * Lista uprawnień w kolejności wyświetlania: klucz => [etykieta, opis, grupa].
     */
    public static function actions() {
        return array(
            'view'        => array( __( 'Widzi moduł i drukuje', 'gastroflowx-pliki' ), __( 'Otwiera Pliki, przegląda i drukuje dokumenty dostępne dla swojej roli.', 'gastroflowx-pliki' ), __( 'Dostęp', 'gastroflowx-pliki' ) ),
            'view_hidden' => array( __( 'Widzi dokumenty innych ról', 'gastroflowx-pliki' ), __( 'Widzi także dokumenty ograniczone do innych ról (np. kuchni).', 'gastroflowx-pliki' ), __( 'Dostęp', 'gastroflowx-pliki' ) ),
            'download'    => array( __( 'Pobiera oryginalne pliki', 'gastroflowx-pliki' ), __( 'Przycisk „Pobierz plik” przy wgranych PDF-ach i zdjęciach.', 'gastroflowx-pliki' ), __( 'Dostęp', 'gastroflowx-pliki' ) ),
            'create'      => array( __( 'Tworzy dokumenty w edytorze', 'gastroflowx-pliki' ), __( 'Nowe dokumenty w edytorze oraz duplikowanie dokumentów z edytora.', 'gastroflowx-pliki' ), __( 'Dodawanie', 'gastroflowx-pliki' ) ),
            'upload'      => array( __( 'Wgrywa pliki', 'gastroflowx-pliki' ), __( 'Wgrywanie PDF i zdjęć, podmiana pliku, duplikowanie wgranych plików.', 'gastroflowx-pliki' ), __( 'Dodawanie', 'gastroflowx-pliki' ) ),
            'edit_own'    => array( __( 'Edytuje własne dokumenty', 'gastroflowx-pliki' ), __( 'Tylko dokumenty, które sam dodał.', 'gastroflowx-pliki' ), __( 'Edycja', 'gastroflowx-pliki' ) ),
            'edit_all'    => array( __( 'Edytuje wszystkie dokumenty', 'gastroflowx-pliki' ), __( 'Także dokumenty dodane przez innych.', 'gastroflowx-pliki' ), __( 'Edycja', 'gastroflowx-pliki' ) ),
            'delete_own'  => array( __( 'Usuwa własne dokumenty', 'gastroflowx-pliki' ), __( 'Tylko dokumenty, które sam dodał.', 'gastroflowx-pliki' ), __( 'Usuwanie', 'gastroflowx-pliki' ) ),
            'delete_all'  => array( __( 'Usuwa wszystkie dokumenty', 'gastroflowx-pliki' ), __( 'Także dokumenty dodane przez innych.', 'gastroflowx-pliki' ), __( 'Usuwanie', 'gastroflowx-pliki' ) ),
            'categories'  => array( __( 'Zarządza kategoriami', 'gastroflowx-pliki' ), __( 'Dodaje, zmienia nazwy i usuwa kategorie.', 'gastroflowx-pliki' ), __( 'Porządek', 'gastroflowx-pliki' ) ),
        );
    }

    /** Uprawnienie „wszystkie” obejmuje „własne”. */
    private static function implied_by() {
        return array( 'edit_own' => 'edit_all', 'delete_own' => 'delete_all' );
    }

    public static function defaults() {
        $all = array_keys( self::known_roles() );
        return array(
            'view'        => $all,
            'view_hidden' => array( 'rs_restaurant_manager' ),
            'download'    => $all,
            'create'      => array( 'rs_restaurant_manager' ),
            'upload'      => array( 'rs_restaurant_manager' ),
            'edit_own'    => array( 'rs_restaurant_manager' ),
            'edit_all'    => array( 'rs_restaurant_manager' ),
            'delete_own'  => array( 'rs_restaurant_manager' ),
            'delete_all'  => array( 'rs_restaurant_manager' ),
            'categories'  => array( 'rs_restaurant_manager' ),
        );
    }

    public static function install_defaults() {
        if ( false === get_option( self::OPTION, false ) ) {
            add_option( self::OPTION, array( 'perms' => self::defaults() ), '', false );
        }
    }

    public static function clean_roles( $roles ) {
        if ( ! is_array( $roles ) ) return array();
        $roles = array_map( 'sanitize_key', $roles );
        $roles = array_filter( $roles, function ( $r ) {
            return $r !== '' && $r !== 'administrator';
        } );
        return array_values( array_unique( $roles ) );
    }

    /** Uprawnienia: akcja => role. Obsługuje stary format z wersji 1.0.0 (view_roles / manage_roles). */
    public static function get() {
        if ( self::$cache !== null ) return self::$cache;
        $o = get_option( self::OPTION, array() );
        if ( ! is_array( $o ) ) $o = array();
        if ( ! isset( $o['perms'] ) || ! is_array( $o['perms'] ) ) {
            $defaults = self::defaults();
            $view     = isset( $o['view_roles'] ) ? (array) $o['view_roles'] : $defaults['view'];
            $manage   = isset( $o['manage_roles'] ) ? (array) $o['manage_roles'] : $defaults['create'];
            $perms    = array();
            foreach ( array_keys( self::actions() ) as $a ) {
                $perms[ $a ] = in_array( $a, array( 'view', 'download' ), true ) ? array_merge( $view, $manage ) : $manage;
            }
            $o['perms'] = $perms;
        }
        $out = array();
        foreach ( self::actions() as $a => $_ ) {
            $out[ $a ] = isset( $o['perms'][ $a ] ) ? self::clean_roles( $o['perms'][ $a ] ) : array();
        }
        self::$cache = self::normalize( $out );
        return self::$cache;
    }

    /** Pilnuje zależności: każda akcja wymaga „view”, „wszystkie” wymaga „własne”. */
    private static function normalize( array $perms ) {
        foreach ( self::implied_by() as $own => $all ) {
            $perms[ $own ] = array_values( array_unique( array_merge( $perms[ $own ], $perms[ $all ] ) ) );
        }
        $need_view = array();
        foreach ( $perms as $a => $roles ) {
            if ( $a !== 'view' ) $need_view = array_merge( $need_view, $roles );
        }
        $perms['view'] = array_values( array_unique( array_merge( $perms['view'], $need_view ) ) );
        return $perms;
    }

    /** $data: akcja => role. Brak klucza = bez zmian. */
    public static function update( array $data ) {
        $cur       = self::get();
        $available = array_keys( self::available_roles() );
        foreach ( self::actions() as $a => $_ ) {
            if ( array_key_exists( $a, $data ) ) {
                $cur[ $a ] = array_values( array_intersect( self::clean_roles( (array) $data[ $a ] ), $available ) );
            }
        }
        update_option( self::OPTION, array( 'perms' => self::normalize( $cur ) ), false );
        self::$cache = null;
        return self::get();
    }

    /** Wszystkie role WP poza administratorem: slug => etykieta. */
    public static function available_roles() {
        $out   = array();
        $known = self::known_roles();
        foreach ( wp_roles()->get_names() as $slug => $name ) {
            if ( $slug === 'administrator' ) continue;
            $out[ $slug ] = isset( $known[ $slug ] ) ? $known[ $slug ] : translate_user_role( $name );
        }
        return $out;
    }

    /** Role, którym można zawęzić widoczność pojedynczego dokumentu (tylko te z dostępem do modułu). */
    public static function document_roles() {
        $all = self::available_roles();
        $out = array();
        foreach ( self::get()['view'] as $slug ) {
            if ( isset( $all[ $slug ] ) ) $out[ $slug ] = $all[ $slug ];
        }
        return $out;
    }

    public static function current_roles() {
        if ( ! is_user_logged_in() ) return array();
        return (array) wp_get_current_user()->roles;
    }

    public static function is_admin() {
        return is_user_logged_in() && current_user_can( 'administrator' );
    }

    /**
     * Czy bieżący użytkownik może wykonać akcję. Administrator — zawsze.
     * Filtr: gfx_pliki_can( bool $ok, string $action, WP_User $user ).
     */
    public static function can( $action ) {
        if ( ! is_user_logged_in() ) return false;
        if ( self::is_admin() ) {
            $ok = true;
        } else {
            $perms = self::get();
            $mine  = self::current_roles();
            $ok    = isset( $perms[ $action ] ) && array_intersect( $mine, $perms[ $action ] ) && array_intersect( $mine, $perms['view'] );
        }
        return (bool) apply_filters( 'gfx_pliki_can', (bool) $ok, $action, wp_get_current_user() );
    }

    public static function can_view() {
        return self::can( 'view' );
    }

    /** Czy użytkownik może dodawać lub edytować cokolwiek (potrzebne np. do listy ról przy dokumencie). */
    public static function can_author() {
        foreach ( array( 'create', 'upload', 'edit_own', 'edit_all' ) as $a ) {
            if ( self::can( $a ) ) return true;
        }
        return false;
    }

    /** Uprawnienia bieżącego użytkownika dla frontu: akcja => bool. */
    public static function current_caps() {
        $out = array();
        foreach ( self::actions() as $a => $_ ) $out[ $a ] = self::can( $a );
        return $out;
    }

    /* ------------------------------------------------------------------
     * wp-admin: prosta strona ustawień (tylko administrator).
     * Te same ustawienia są dostępne na froncie (ikona koła zębatego).
     * ------------------------------------------------------------------ */

    private static $parent = 'options-general.php';

    public static function admin_menu() {
        // Jeśli Hub ma własne menu „GastroFlowX”, podpinamy się pod nie.
        global $menu;
        if ( is_array( $menu ) ) {
            foreach ( $menu as $item ) {
                if ( ! empty( $item[2] ) && stripos( $item[2], 'gastroflowx' ) !== false && $item[2] !== self::PAGE_SLUG ) {
                    self::$parent = $item[2];
                    break;
                }
            }
        }
        add_submenu_page(
            self::$parent,
            __( 'GastroFlowX Pliki', 'gastroflowx-pliki' ),
            __( 'Pliki (dokumenty)', 'gastroflowx-pliki' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function page_url() {
        $parent = self::$parent === 'options-general.php' ? 'options-general.php' : 'admin.php';
        return admin_url( $parent . '?page=' . self::PAGE_SLUG );
    }

    public static function render_page() {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-pliki' ) );
        }

        $saved = false;
        if ( isset( $_POST['gfx_pliki_save'] ) ) {
            check_admin_referer( 'gfx_pliki_settings' );
            $posted = isset( $_POST['perms'] ) && is_array( $_POST['perms'] ) ? wp_unslash( $_POST['perms'] ) : array();
            $data   = array();
            foreach ( self::actions() as $a => $_ ) {
                $data[ $a ] = isset( $posted[ $a ] ) ? (array) $posted[ $a ] : array();
            }
            self::update( $data );
            $saved = true;
        }

        $s     = self::get();
        $roles = self::available_roles();
        $count = wp_count_posts( GFX_Pliki_Documents::POST_TYPE );
        $count = isset( $count->publish ) ? (int) $count->publish : 0;
        $group = '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'GastroFlowX Pliki', 'gastroflowx-pliki' ); ?></h1>
            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Zapisano uprawnienia.', 'gastroflowx-pliki' ); ?></p></div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:18px 22px;max-width:980px;margin:16px 0;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Rejestracja w panelu (GastroFlowx → Moduły)', 'gastroflowx-pliki' ); ?></h2>
                <p><?php esc_html_e( 'Shortcode do wklejenia:', 'gastroflowx-pliki' ); ?></p>
                <p><input type="text" readonly value="[<?php echo esc_attr( GFX_PLIKI_SHORTCODE ); ?>]" onclick="this.select();" style="font-family:monospace;font-size:15px;width:320px;"></p>
                <p style="color:#6B7280;"><?php
                    /* translators: %d: liczba dokumentów */
                    printf( esc_html__( 'Dokumentów w bibliotece: %d. Całe zarządzanie odbywa się na froncie — tutaj ustawiasz tylko uprawnienia.', 'gastroflowx-pliki' ), $count );
                ?></p>
            </div>

            <h2><?php esc_html_e( 'Uprawnienia', 'gastroflowx-pliki' ); ?></h2>
            <p style="max-width:980px;color:#4B5563;"><?php esc_html_e( 'Zaznacz, które role mogą wykonywać daną czynność. Administrator ma zawsze wszystkie uprawnienia i jako jedyny zmienia te ustawienia. Każde uprawnienie wymaga dostępu do modułu, a „wszystkie dokumenty” obejmuje też „własne” — te pola zaznaczą się same przy zapisie.', 'gastroflowx-pliki' ); ?></p>

            <form method="post" style="max-width:100%;overflow-x:auto;">
                <?php wp_nonce_field( 'gfx_pliki_settings' ); ?>
                <table class="widefat striped" style="min-width:760px;">
                    <thead>
                        <tr>
                            <th style="width:280px;"><?php esc_html_e( 'Uprawnienie', 'gastroflowx-pliki' ); ?></th>
                            <th style="text-align:center;"><?php esc_html_e( 'Administrator', 'gastroflowx-pliki' ); ?></th>
                            <?php foreach ( $roles as $slug => $label ) : ?>
                                <th style="text-align:center;"><?php echo esc_html( $label ); ?><br><code style="font-size:11px;"><?php echo esc_html( $slug ); ?></code></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( self::actions() as $a => $info ) : ?>
                            <?php if ( $info[2] !== $group ) : $group = $info[2]; ?>
                                <tr><td colspan="<?php echo (int) ( count( $roles ) + 2 ); ?>" style="background:#F3F4F6;font-weight:700;"><?php echo esc_html( $group ); ?></td></tr>
                            <?php endif; ?>
                            <tr>
                                <td><strong><?php echo esc_html( $info[0] ); ?></strong><br><span style="color:#6B7280;font-size:12px;"><?php echo esc_html( $info[1] ); ?></span></td>
                                <td style="text-align:center;vertical-align:middle;" title="<?php esc_attr_e( 'Administrator ma zawsze pełny dostęp', 'gastroflowx-pliki' ); ?>"><input type="checkbox" checked disabled></td>
                                <?php foreach ( $roles as $slug => $label ) : ?>
                                    <td style="text-align:center;vertical-align:middle;"><input type="checkbox" name="perms[<?php echo esc_attr( $a ); ?>][]" value="<?php echo esc_attr( $slug ); ?>" aria-label="<?php echo esc_attr( $label . ': ' . $info[0] ); ?>" <?php checked( in_array( $slug, $s[ $a ], true ) ); ?>></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <tr><td colspan="<?php echo (int) ( count( $roles ) + 2 ); ?>" style="background:#F3F4F6;font-weight:700;"><?php esc_html_e( 'Ustawienia', 'gastroflowx-pliki' ); ?></td></tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Zmienia uprawnienia', 'gastroflowx-pliki' ); ?></strong><br><span style="color:#6B7280;font-size:12px;"><?php esc_html_e( 'Tylko administrator.', 'gastroflowx-pliki' ); ?></span></td>
                            <td style="text-align:center;vertical-align:middle;"><input type="checkbox" checked disabled></td>
                            <?php foreach ( $roles as $slug => $label ) : ?>
                                <td style="text-align:center;vertical-align:middle;color:#9CA3AF;">—</td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
                <script>
                ( function () {
                    var implies = { edit_all: 'edit_own', delete_all: 'delete_own' };
                    var impliedBy = { edit_own: 'edit_all', delete_own: 'delete_all' };
                    function box( action, role ) {
                        return document.querySelector( 'input[name="perms[' + action + '][]"][value="' + role + '"]' );
                    }
                    document.querySelectorAll( 'input[name^="perms["]' ).forEach( function ( el ) {
                        el.addEventListener( 'change', function () {
                            var action = el.name.slice( 6, el.name.indexOf( ']' ) ), role = el.value, b;
                            if ( el.checked ) {
                                if ( action !== 'view' && ( b = box( 'view', role ) ) ) b.checked = true;
                                if ( implies[ action ] && ( b = box( implies[ action ], role ) ) ) b.checked = true;
                            } else {
                                if ( action === 'view' ) document.querySelectorAll( 'input[name^="perms["][value="' + role + '"]' ).forEach( function ( x ) { x.checked = false; } );
                                if ( impliedBy[ action ] && ( b = box( impliedBy[ action ], role ) ) ) b.checked = false;
                            }
                        } );
                    } );
                } )();
                </script>
                <p><button type="submit" name="gfx_pliki_save" value="1" class="button button-primary"><?php esc_html_e( 'Zapisz uprawnienia', 'gastroflowx-pliki' ); ?></button></p>
            </form>
        </div>
        <?php
    }
}
