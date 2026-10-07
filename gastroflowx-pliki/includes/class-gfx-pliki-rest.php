<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST API: gastroflowx-pliki/v1
 *
 * Każdy endpoint sprawdza uprawnienie (rolę), nie tylko zalogowanie.
 * Każdy zapis dodatkowo jawnie weryfikuje nonce wp_rest (X-WP-Nonce).
 * Wrappery safe()/safe_permission() jak w pozostałych modułach — żaden błąd
 * nie kończy się stroną HTML zamiast JSON.
 */
class GFX_Pliki_Rest {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'safe_register_routes' ) );
    }

    public static function safe_register_routes() {
        try {
            self::register_routes();
        } catch ( \Throwable $e ) {
            error_log( 'GastroFlowX Pliki: błąd rejestracji tras REST: ' . $e->getMessage() );
        }
    }

    private static function safe( $callback ) {
        return function ( WP_REST_Request $req ) use ( $callback ) {
            try {
                return call_user_func( $callback, $req );
            } catch ( \Throwable $e ) {
                error_log( 'GastroFlowX Pliki REST error: ' . $e->getMessage() );
                return new WP_Error( 'gfx_pliki_exception', 'Błąd serwera: ' . $e->getMessage(), array( 'status' => 500 ) );
            }
        };
    }

    private static function safe_permission( $callback ) {
        return function ( WP_REST_Request $req ) use ( $callback ) {
            try {
                return call_user_func( $callback, $req );
            } catch ( \Throwable $e ) {
                error_log( 'GastroFlowX Pliki REST permission error: ' . $e->getMessage() );
                return new WP_Error( 'gfx_pliki_permission_exception', 'Błąd serwera przy sprawdzaniu uprawnień.', array( 'status' => 500 ) );
            }
        };
    }

    /* ======================= UPRAWNIENIA ======================= */

    private static function verify_nonce( WP_REST_Request $req ) {
        $nonce = $req->get_header( 'X-WP-Nonce' );
        if ( ! $nonce ) $nonce = $req->get_param( '_wpnonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'gfx_pliki_bad_nonce', __( 'Sesja wygasła lub token bezpieczeństwa jest nieprawidłowy. Odśwież stronę.', 'gastroflowx-pliki' ), array( 'status' => 403 ) );
        }
        return true;
    }

    private static function forbidden() {
        return new WP_Error( 'gfx_pliki_forbidden', __( 'Brak uprawnień do tej operacji.', 'gastroflowx-pliki' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
    }

    public static function perm_view( WP_REST_Request $req ) {
        return GFX_Pliki_Settings::can_view() ? true : self::forbidden();
    }

    /** Zapis wymagający konkretnego uprawnienia z ustawień. */
    private static function perm_action( $action ) {
        return function ( WP_REST_Request $req ) use ( $action ) {
            if ( ! GFX_Pliki_Settings::can( $action ) ) return self::forbidden();
            return self::verify_nonce( $req );
        };
    }

    /** Nowy dokument: z edytora → „create”, wgrany plik → „upload”. */
    public static function perm_create_document( WP_REST_Request $req ) {
        $action = $req->get_param( 'type' ) === 'file' ? 'upload' : 'create';
        if ( ! GFX_Pliki_Settings::can( $action ) ) return self::forbidden();
        return self::verify_nonce( $req );
    }

    /**
     * Zapis dotyczący istniejącego dokumentu: tu sprawdzamy dostęp do modułu i nonce,
     * a uprawnienie do konkretnego dokumentu (własny / wszystkie) sprawdza handler.
     */
    public static function perm_doc_write( WP_REST_Request $req ) {
        if ( ! GFX_Pliki_Settings::can_view() ) return self::forbidden();
        return self::verify_nonce( $req );
    }

    public static function perm_admin( WP_REST_Request $req ) {
        if ( ! GFX_Pliki_Settings::is_admin() ) return self::forbidden();
        return self::verify_nonce( $req );
    }

    /* ======================= TRASY ======================= */

    public static function register_routes() {
        $ns   = GFX_PLIKI_NS;
        $view = self::safe_permission( array( __CLASS__, 'perm_view' ) );
        $cats = self::safe_permission( self::perm_action( 'categories' ) );
        $new  = self::safe_permission( array( __CLASS__, 'perm_create_document' ) );
        $doc  = self::safe_permission( array( __CLASS__, 'perm_doc_write' ) );
        $adm  = self::safe_permission( array( __CLASS__, 'perm_admin' ) );

        register_rest_route( $ns, '/bootstrap', array(
            'methods' => 'GET', 'callback' => self::safe( array( __CLASS__, 'bootstrap' ) ), 'permission_callback' => $view,
        ) );

        register_rest_route( $ns, '/categories', array(
            'methods' => 'POST', 'callback' => self::safe( array( __CLASS__, 'create_category' ) ), 'permission_callback' => $cats,
        ) );
        register_rest_route( $ns, '/categories/(?P<id>[a-zA-Z0-9_]+)', array(
            array( 'methods' => 'POST',   'callback' => self::safe( array( __CLASS__, 'rename_category' ) ), 'permission_callback' => $cats ),
            array( 'methods' => 'DELETE', 'callback' => self::safe( array( __CLASS__, 'delete_category' ) ), 'permission_callback' => $cats ),
        ) );

        register_rest_route( $ns, '/documents', array(
            'methods' => 'POST', 'callback' => self::safe( array( __CLASS__, 'create_document' ) ), 'permission_callback' => $new,
        ) );
        register_rest_route( $ns, '/documents/(?P<id>\d+)', array(
            array( 'methods' => 'POST',   'callback' => self::safe( array( __CLASS__, 'update_document' ) ), 'permission_callback' => $doc ),
            array( 'methods' => 'DELETE', 'callback' => self::safe( array( __CLASS__, 'delete_document' ) ), 'permission_callback' => $doc ),
        ) );
        register_rest_route( $ns, '/documents/(?P<id>\d+)/duplicate', array(
            'methods' => 'POST', 'callback' => self::safe( array( __CLASS__, 'duplicate_document' ) ), 'permission_callback' => $doc,
        ) );
        register_rest_route( $ns, '/documents/(?P<id>\d+)/file', array(
            'methods' => 'GET', 'callback' => self::safe( array( __CLASS__, 'stream_file' ) ), 'permission_callback' => $view,
        ) );

        register_rest_route( $ns, '/settings', array(
            'methods' => 'POST', 'callback' => self::safe( array( __CLASS__, 'save_settings' ) ), 'permission_callback' => $adm,
        ) );
    }

    /* ======================= BOOTSTRAP ======================= */

    public static function bootstrap() {
        $is_admin = GFX_Pliki_Settings::is_admin();
        return array(
            'user'       => array(
                'name'    => wp_get_current_user()->display_name,
                'isAdmin' => $is_admin,
                'caps'    => GFX_Pliki_Settings::current_caps(),
            ),
            'categories' => GFX_Pliki_Documents::get_categories(),
            'documents'  => GFX_Pliki_Documents::list_for_current_user(),
            'roleLabels' => GFX_Pliki_Settings::available_roles(),
            'docRoles'   => GFX_Pliki_Settings::can_author() ? GFX_Pliki_Settings::document_roles() : new stdClass(),
            'settings'   => $is_admin ? GFX_Pliki_Settings::get() : null,
            'actions'    => $is_admin ? self::actions_for_front() : null,
            'maxUpload'  => (int) wp_max_upload_size(),
        );
    }

    /* ======================= KATEGORIE ======================= */

    public static function create_category( WP_REST_Request $req ) {
        $name = mb_substr( sanitize_text_field( (string) $req->get_param( 'name' ) ), 0, 60 );
        if ( $name === '' ) {
            return new WP_Error( 'gfx_pliki_no_name', __( 'Podaj nazwę kategorii.', 'gastroflowx-pliki' ), array( 'status' => 400 ) );
        }
        $cats   = GFX_Pliki_Documents::get_categories();
        $cats[] = array( 'id' => 'cat_' . strtolower( wp_generate_password( 8, false, false ) ), 'name' => $name );
        return array( 'success' => true, 'categories' => GFX_Pliki_Documents::save_categories( $cats ) );
    }

    public static function rename_category( WP_REST_Request $req ) {
        $id   = sanitize_key( $req->get_param( 'id' ) );
        $name = mb_substr( sanitize_text_field( (string) $req->get_param( 'name' ) ), 0, 60 );
        if ( $name === '' ) {
            return new WP_Error( 'gfx_pliki_no_name', __( 'Podaj nazwę kategorii.', 'gastroflowx-pliki' ), array( 'status' => 400 ) );
        }
        $cats  = GFX_Pliki_Documents::get_categories();
        $found = false;
        foreach ( $cats as &$c ) {
            if ( $c['id'] === $id ) { $c['name'] = $name; $found = true; }
        }
        unset( $c );
        if ( ! $found ) return new WP_Error( 'gfx_pliki_not_found', __( 'Nie znaleziono kategorii.', 'gastroflowx-pliki' ), array( 'status' => 404 ) );
        return array( 'success' => true, 'categories' => GFX_Pliki_Documents::save_categories( $cats ) );
    }

    public static function delete_category( WP_REST_Request $req ) {
        $id = sanitize_key( $req->get_param( 'id' ) );
        if ( $id === GFX_Pliki_Documents::DEFAULT_CAT ) {
            return new WP_Error( 'gfx_pliki_protected', __( 'Nie można usunąć kategorii domyślnej.', 'gastroflowx-pliki' ), array( 'status' => 400 ) );
        }
        $cats = array_values( array_filter( GFX_Pliki_Documents::get_categories(), function ( $c ) use ( $id ) {
            return $c['id'] !== $id;
        } ) );
        GFX_Pliki_Documents::reassign_category( $id );
        return array(
            'success'    => true,
            'categories' => GFX_Pliki_Documents::save_categories( $cats ),
            'documents'  => GFX_Pliki_Documents::list_for_current_user(),
        );
    }

    /* ======================= DOKUMENTY ======================= */

    /** Zbiera tylko przesłane pola (przy edycji brak pola = bez zmian). */
    private static function collect_fields( WP_REST_Request $req ) {
        $d = array();
        if ( $req->has_param( 'title' ) ) {
            $d['title'] = mb_substr( sanitize_text_field( (string) $req->get_param( 'title' ) ), 0, 200 );
        }
        if ( $req->has_param( 'note' ) ) {
            $d['note'] = mb_substr( sanitize_textarea_field( (string) $req->get_param( 'note' ) ), 0, 1000 );
        }
        if ( $req->has_param( 'category' ) ) {
            $cat = sanitize_key( (string) $req->get_param( 'category' ) );
            $d['category'] = GFX_Pliki_Documents::category_exists( $cat ) ? $cat : GFX_Pliki_Documents::DEFAULT_CAT;
        }
        if ( $req->has_param( 'roles' ) ) {
            $raw = $req->get_param( 'roles' );
            if ( is_string( $raw ) ) $raw = json_decode( $raw, true );
            $allowed = array_keys( GFX_Pliki_Settings::document_roles() );
            $d['roles'] = array_values( array_intersect( GFX_Pliki_Settings::clean_roles( is_array( $raw ) ? $raw : array() ), $allowed ) );
        }
        if ( $req->has_param( 'orientation' ) ) {
            $d['orientation'] = $req->get_param( 'orientation' ) === 'landscape' ? 'landscape' : 'portrait';
        }
        if ( $req->has_param( 'printTitle' ) ) {
            $d['printTitle'] = in_array( (string) $req->get_param( 'printTitle' ), array( '1', 'true' ), true );
        }
        if ( $req->has_param( 'content' ) ) {
            $html = GFX_Pliki_Documents::sanitize_html( (string) $req->get_param( 'content' ) );
            if ( is_wp_error( $html ) ) return $html;
            $d['content'] = $html;
        }
        return $d;
    }

    private static function uploaded_file( WP_REST_Request $req ) {
        $files = $req->get_file_params();
        return ( ! empty( $files['file'] ) && isset( $files['file']['error'] ) && (int) $files['file']['error'] !== UPLOAD_ERR_NO_FILE ) ? $files['file'] : null;
    }

    private static function doc_or_error( $id ) {
        $p = GFX_Pliki_Documents::get_doc( $id );
        // Dokument niewidoczny dla użytkownika traktujemy jak nieistniejący (nie zdradzamy, że jest).
        if ( $p && ! GFX_Pliki_Documents::user_can_see( $p ) ) $p = null;
        return $p ? $p : new WP_Error( 'gfx_pliki_not_found', __( 'Nie znaleziono dokumentu.', 'gastroflowx-pliki' ), array( 'status' => 404 ) );
    }

    public static function create_document( WP_REST_Request $req ) {
        $type = $req->get_param( 'type' ) === 'file' ? 'file' : 'html';
        $data = self::collect_fields( $req );
        if ( is_wp_error( $data ) ) return $data;

        if ( $type === 'file' ) {
            $f = self::uploaded_file( $req );
            if ( ! $f ) {
                return new WP_Error( 'gfx_pliki_no_file', __( 'Wybierz plik do wgrania.', 'gastroflowx-pliki' ), array( 'status' => 400 ) );
            }
            $stored = GFX_Pliki_Documents::store_upload( $f );
            if ( is_wp_error( $stored ) ) return $stored;
            $data['file'] = $stored;
            if ( empty( $data['title'] ) ) {
                $data['title'] = mb_substr( sanitize_text_field( pathinfo( wp_basename( $f['name'] ), PATHINFO_FILENAME ) ), 0, 200 );
            }
        } else {
            if ( ! isset( $data['content'] ) ) $data['content'] = '';
        }

        if ( empty( $data['title'] ) ) {
            return new WP_Error( 'gfx_pliki_no_title', __( 'Podaj nazwę dokumentu.', 'gastroflowx-pliki' ), array( 'status' => 400 ) );
        }

        $id = GFX_Pliki_Documents::create( $type, $data );
        if ( is_wp_error( $id ) ) return $id;
        return array( 'success' => true, 'document' => GFX_Pliki_Documents::to_array( get_post( $id ) ) );
    }

    public static function update_document( WP_REST_Request $req ) {
        $p = self::doc_or_error( $req->get_param( 'id' ) );
        if ( is_wp_error( $p ) ) return $p;
        if ( ! GFX_Pliki_Documents::user_can_edit( $p ) ) return self::forbidden();
        $type = get_post_meta( $p->ID, '_gfx_type', true ) === 'file' ? 'file' : 'html';

        $data = self::collect_fields( $req );
        if ( is_wp_error( $data ) ) return $data;
        if ( array_key_exists( 'title', $data ) && $data['title'] === '' ) {
            return new WP_Error( 'gfx_pliki_no_title', __( 'Podaj nazwę dokumentu.', 'gastroflowx-pliki' ), array( 'status' => 400 ) );
        }
        if ( $type === 'file' ) {
            unset( $data['content'] );
            $f = self::uploaded_file( $req );
            if ( $f ) {
                if ( ! GFX_Pliki_Settings::can( 'upload' ) ) return self::forbidden();
                $stored = GFX_Pliki_Documents::store_upload( $f );
                if ( is_wp_error( $stored ) ) return $stored;
                $data['file'] = $stored;
            }
        }

        $res = GFX_Pliki_Documents::update( $p->ID, $data );
        if ( is_wp_error( $res ) ) return $res;
        clean_post_cache( $p->ID );
        return array( 'success' => true, 'document' => GFX_Pliki_Documents::to_array( get_post( $p->ID ) ) );
    }

    public static function duplicate_document( WP_REST_Request $req ) {
        $p = self::doc_or_error( $req->get_param( 'id' ) );
        if ( is_wp_error( $p ) ) return $p;
        if ( ! GFX_Pliki_Documents::user_can_duplicate( $p ) ) return self::forbidden();
        $id = GFX_Pliki_Documents::duplicate( $p->ID );
        if ( is_wp_error( $id ) ) return $id;
        return array( 'success' => true, 'document' => GFX_Pliki_Documents::to_array( get_post( $id ) ) );
    }

    public static function delete_document( WP_REST_Request $req ) {
        $p = self::doc_or_error( $req->get_param( 'id' ) );
        if ( is_wp_error( $p ) ) return $p;
        if ( ! GFX_Pliki_Documents::user_can_delete( $p ) ) return self::forbidden();
        GFX_Pliki_Documents::delete( $p->ID );
        return array( 'success' => true, 'id' => (int) $p->ID );
    }

    /**
     * Serwuje plik po sprawdzeniu uprawnień (katalog jest zablokowany dla
     * bezpośredniego dostępu). Autoryzacja: cookie + nonce w ?_wpnonce=
     * (dla <img>) albo nagłówek X-WP-Nonce (dla fetch()).
     */
    public static function stream_file( WP_REST_Request $req ) {
        $p = self::doc_or_error( $req->get_param( 'id' ) );
        if ( is_wp_error( $p ) ) return $p;
        if ( $req->get_param( 'download' ) && ! GFX_Pliki_Settings::can( 'download' ) ) return self::forbidden();
        if ( get_post_meta( $p->ID, '_gfx_type', true ) !== 'file' ) {
            return new WP_Error( 'gfx_pliki_no_file', __( 'Ten dokument nie ma pliku.', 'gastroflowx-pliki' ), array( 'status' => 400 ) );
        }

        $path = GFX_Pliki_Documents::file_path( $p->ID );
        $real = $path ? realpath( $path ) : false;
        $dir  = realpath( GFX_Pliki_Documents::storage_dir() );
        if ( ! $real || ! $dir || strpos( $real, $dir ) !== 0 || ! is_readable( $real ) ) {
            return new WP_Error( 'gfx_pliki_missing_file', __( 'Plik nie istnieje na serwerze.', 'gastroflowx-pliki' ), array( 'status' => 404 ) );
        }

        $mime  = (string) get_post_meta( $p->ID, '_gfx_mime', true );
        $allow = GFX_Pliki_Documents::allowed_mimes();
        if ( ! isset( $allow[ $mime ] ) ) $mime = 'application/octet-stream';
        $ext   = (string) get_post_meta( $p->ID, '_gfx_ext', true );
        $base  = sanitize_file_name( remove_accents( $p->post_title ) );
        if ( $base === '' ) $base = 'dokument';
        $name_ascii = $base . ( $ext ? '.' . $ext : '' );
        $name_utf8  = $p->post_title . ( $ext ? '.' . $ext : '' );
        $disp  = $req->get_param( 'download' ) ? 'attachment' : 'inline';

        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        status_header( 200 );
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . filesize( $real ) );
        header( 'Content-Disposition: ' . $disp . '; filename="' . $name_ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name_utf8 ) );
        header( 'Cache-Control: private, max-age=86400' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $real );
        exit;
    }

    /* ======================= USTAWIENIA ======================= */

    public static function save_settings( WP_REST_Request $req ) {
        $raw = $req->get_param( 'perms' );
        if ( is_string( $raw ) ) $raw = json_decode( $raw, true );
        if ( ! is_array( $raw ) ) {
            return new WP_Error( 'gfx_pliki_bad_settings', __( 'Nieprawidłowe dane uprawnień.', 'gastroflowx-pliki' ), array( 'status' => 400 ) );
        }
        $data = array();
        foreach ( GFX_Pliki_Settings::actions() as $a => $_ ) {
            if ( array_key_exists( $a, $raw ) ) $data[ $a ] = is_array( $raw[ $a ] ) ? $raw[ $a ] : array();
        }
        $settings = GFX_Pliki_Settings::update( $data );
        return array(
            'success'   => true,
            'settings'  => $settings,
            'docRoles'  => GFX_Pliki_Settings::document_roles(),
            'documents' => GFX_Pliki_Documents::list_for_current_user(),
        );
    }

    /** Opis uprawnień dla ekranu ustawień na froncie. */
    private static function actions_for_front() {
        $out = array();
        foreach ( GFX_Pliki_Settings::actions() as $key => $info ) {
            $out[] = array( 'key' => $key, 'label' => $info[0], 'desc' => $info[1], 'group' => $info[2] );
        }
        return $out;
    }
}
