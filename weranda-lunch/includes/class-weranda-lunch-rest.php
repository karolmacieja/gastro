<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Weranda_Lunch_Rest {

    const NS = 'weranda-lunch/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'safe_register_routes' ) );
    }

    /**
     * Rejestracja trasy owinięta w try/catch. WordPress wywołuje WSZYSTKIE
     * callbacki podpięte pod 'rest_api_init' (z każdej aktywnej wtyczki) przy
     * KAŻDYM żądaniu /wp-json/..., niezależnie od tego, do którego namespace'u
     * ono trafia. Nieprzechwycony fatal error w tej metodzie potrafi więc
     * zepsuć odpowiedź REST także dla zupełnie innej wtyczki (np. Kolorowanek).
     * Dlatego nigdy nie pozwalamy tu na niezłapany wyjątek.
     */
    public static function safe_register_routes() {
        try {
            self::register_routes();
        } catch ( \Throwable $e ) {
            error_log( 'Weranda Lunch: błąd rejestracji tras REST: ' . $e->getMessage() );
        }
    }

    /**
     * Owija dowolny callback tak, by KAŻDY nieprzewidziany błąd (nie tylko te,
     * które sami przewidzieliśmy) wracał jako czysty JSON ze statusem 500,
     * a nie jako strona HTML błędu krytycznego WordPressa.
     */
    private static function safe( $callback ) {
        return function ( WP_REST_Request $req ) use ( $callback ) {
            try {
                return call_user_func( $callback, $req );
            } catch ( \Throwable $e ) {
                error_log( 'Weranda Lunch REST error: ' . $e->getMessage() );
                return new WP_Error( 'weranda_lunch_exception', 'Błąd serwera: ' . $e->getMessage(), array( 'status' => 500 ) );
            }
        };
    }

    /**
     * Analogiczne zabezpieczenie dla permission_callback — jeśli sprawdzenie
     * uprawnień z jakiegoś powodu rzuci błąd, traktujemy to jako odmowę
     * dostępu (403) zamiast pozwolić na fatal error / stronę HTML.
     */
    private static function safe_permission( $callback ) {
        return function ( WP_REST_Request $req ) use ( $callback ) {
            try {
                return call_user_func( $callback, $req );
            } catch ( \Throwable $e ) {
                error_log( 'Weranda Lunch REST permission error: ' . $e->getMessage() );
                return new WP_Error( 'weranda_lunch_permission_exception', 'Błąd serwera przy sprawdzaniu uprawnień: ' . $e->getMessage(), array( 'status' => 500 ) );
            }
        };
    }

    public static function has_access() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        return in_array( 'lunch', (array) $user->roles, true ) || in_array( 'administrator', (array) $user->roles, true );
    }

    public static function is_admin() {
        return current_user_can( 'administrator' );
    }

    /**
     * Bezpieczne wykrywanie MIME. finfo_open() na części hostingów zwraca false
     * (rozszerzenie fileinfo wyłączone), a przekazanie false do finfo_file() rzuca
     * w PHP8 fatal error, przez co WordPress zwraca stronę HTML błędu krytycznego
     * zamiast JSON. Fallback: finfo -> wp_check_filetype_and_ext -> rozszerzenie.
     */
    private static function detect_mime( $tmp_path, $original_name ) {
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = @finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $mime = finfo_file( $finfo, $tmp_path );
                finfo_close( $finfo );
                if ( $mime ) return $mime;
            }
        }
        $checked = wp_check_filetype_and_ext( $tmp_path, $original_name );
        if ( ! empty( $checked['type'] ) ) return $checked['type'];
        $ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
        return $ext === 'pdf' ? 'application/pdf' : '';
    }

    public static function register_routes() {

        register_rest_route( self::NS, '/bootstrap', array(
            'methods'             => 'GET',
            'callback'            => self::safe( array( __CLASS__, 'get_bootstrap' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'has_access' ) ),
        ) );

        register_rest_route( self::NS, '/draft', array(
            'methods'             => 'POST',
            'callback'            => self::safe( array( __CLASS__, 'save_draft' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'has_access' ) ),
        ) );

        register_rest_route( self::NS, '/archive', array(
            'methods'             => 'POST',
            'callback'            => self::safe( array( __CLASS__, 'upload_pdf' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'has_access' ) ),
        ) );

        register_rest_route( self::NS, '/archive/(?P<id>[a-zA-Z0-9_]+)', array(
            'methods'             => 'DELETE',
            'callback'            => self::safe( array( __CLASS__, 'delete_pdf' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'has_access' ) ),
        ) );

        register_rest_route( self::NS, '/checklist', array(
            'methods'             => 'POST',
            'callback'            => self::safe( array( __CLASS__, 'add_checklist_item' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'has_access' ) ),
        ) );

        register_rest_route( self::NS, '/checklist', array(
            'methods'             => 'DELETE',
            'callback'            => self::safe( array( __CLASS__, 'delete_checklist_item' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'has_access' ) ),
        ) );

        // --- Ustawienia PDF (układ karty lunchowej, od 2.0.0) ---
        register_rest_route( self::NS, '/layout', array(
            'methods'             => 'POST',
            'callback'            => self::safe( array( __CLASS__, 'save_layout' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'is_admin' ) ),
        ) );

        // --- Szablony PDF (wersje < 2.0.0; trasy zostają dla zgodności) ---

        register_rest_route( self::NS, '/templates', array(
            'methods'             => 'POST',
            'callback'            => self::safe( array( __CLASS__, 'create_template' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'is_admin' ) ),
        ) );

        register_rest_route( self::NS, '/templates/(?P<id>[a-zA-Z0-9_]+)', array(
            'methods'             => 'POST',
            'callback'            => self::safe( array( __CLASS__, 'update_template' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'is_admin' ) ),
        ) );

        register_rest_route( self::NS, '/templates/(?P<id>[a-zA-Z0-9_]+)', array(
            'methods'             => 'DELETE',
            'callback'            => self::safe( array( __CLASS__, 'delete_template' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'is_admin' ) ),
        ) );

        register_rest_route( self::NS, '/templates/(?P<id>[a-zA-Z0-9_]+)/default', array(
            'methods'             => 'POST',
            'callback'            => self::safe( array( __CLASS__, 'set_default_template' ) ),
            'permission_callback' => self::safe_permission( array( __CLASS__, 'is_admin' ) ),
        ) );
    }

    public static function get_bootstrap() {
        return array(
            'templates'      => get_option( 'weranda_lunch_templates', array() ),
            'activeTemplate' => get_option( 'weranda_lunch_active_template', '' ),
            'draft'          => get_option( 'weranda_lunch_draft', array( 'soup' => '', 'weight' => '', 'lunch' => '', 'rec' => '', 'template_id' => '' ) ),
            'archive'        => get_option( 'weranda_lunch_archive', array() ),
            'checklist'      => get_option( 'weranda_lunch_checklist', array( 'Torcik marchewkowy', 'Torcik czekoladowy z wiśniami', 'Three Cents Aegean Tonic' ) ),
            'isAdmin'        => self::is_admin(),
            // Układ karty (null = domyślny z assets/lunch-layout.js, odwzorowany z Canvy).
            'layout'         => get_option( 'weranda_lunch_layout', null ),
        );
    }

    /* ------------------------------------------------------------------
     * Ustawienia PDF — walidacja: tylko znane klucze, liczby w rozsądnych
     * zakresach, czcionki z listy dołączonych, teksty bez HTML.
     * ---------------------------------------------------------------- */

    const FONTS = array(
        'Poppins-ExtraLight', 'Poppins-Light', 'Poppins-Regular', 'Poppins-Medium', 'Poppins-SemiBold',
        'Poppins-Bold', 'Poppins-ExtraBold', 'Poppins-Black',
        'BarlowCondensed-Light', 'BarlowCondensed-Regular', 'BarlowCondensed-Medium', 'BarlowCondensed-SemiBold', 'BarlowCondensed-Bold',
    );
    const BLOCKS = array( 'soup_title', 'soup_price', 'soup', 'lunch_title', 'lunch_price', 'weight', 'lunch', 'rec_title', 'rec' );

    private static function n( $v, $min, $max, $def ) {
        if ( ! is_numeric( $v ) ) return $def;
        return round( max( $min, min( $max, (float) $v ) ), 3 );
    }

    private static function color( $v, $def ) {
        return ( is_string( $v ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $v ) ) ? strtolower( $v ) : $def;
    }

    private static function font( $v, $def ) {
        return in_array( $v, self::FONTS, true ) ? $v : $def;
    }

    public static function sanitize_layout( $raw ) {
        $raw = is_array( $raw ) ? $raw : array();
        $p   = isset( $raw['page'] ) && is_array( $raw['page'] ) ? $raw['page'] : array();
        $l   = isset( $raw['logo'] ) && is_array( $raw['logo'] ) ? $raw['logo'] : array();
        $f   = isset( $raw['footer'] ) && is_array( $raw['footer'] ) ? $raw['footer'] : array();

        $out = array(
            'version' => 1,
            'page'    => array(
                'center_left'      => self::n( $p['center_left'] ?? null, 0, 595.5, 150.1 ),
                'center_right'     => self::n( $p['center_right'] ?? null, 0, 595.5, 446.9 ),
                'max_width'        => self::n( $p['max_width'] ?? null, 50, 595.5, 250 ),
                'content_top'      => self::n( $p['content_top'] ?? null, 0, 842.25, 179.04 ),
                'content_bottom'   => self::n( $p['content_bottom'] ?? null, 0, 842.25, 765 ),
                'fit_page'         => empty( $p['fit_page'] ) ? 0 : 1,
                'min_scale'        => self::n( $p['min_scale'] ?? null, 0.3, 1, 0.7 ),
                'color'            => self::color( $p['color'] ?? '', '#000000' ),
                'keep_short_words' => empty( $p['keep_short_words'] ) ? 0 : 1,
                'cut_line'         => empty( $p['cut_line'] ) ? 0 : 1,
                'cut_x'            => self::n( $p['cut_x'] ?? null, 0, 595.5, 297.1 ),
                'cut_width'        => self::n( $p['cut_width'] ?? null, 0.1, 5, 0.4 ),
                'cut_color'        => self::color( $p['cut_color'] ?? '', '#000000' ),
            ),
            'logo'    => array(
                'enabled' => empty( $l['enabled'] ) ? 0 : 1,
                'id'      => isset( $l['id'] ) ? absint( $l['id'] ) : 0,
                'url'     => isset( $l['url'] ) ? esc_url_raw( (string) $l['url'] ) : '',
                'top'     => self::n( $l['top'] ?? null, 0, 842.25, 59.16 ),
                'width'   => self::n( $l['width'] ?? null, 10, 595.5, 261.1 ),
                'left_x'  => self::n( $l['left_x'] ?? null, 0, 595.5, 19.7 ),
                'right_x' => self::n( $l['right_x'] ?? null, 0, 595.5, 313.6 ),
            ),
            'footer'  => array(
                'enabled' => empty( $f['enabled'] ) ? 0 : 1,
                'text'    => sanitize_textarea_field( (string) ( $f['text'] ?? '' ) ),
                'font'    => self::font( $f['font'] ?? '', 'Poppins-ExtraLight' ),
                'size'    => self::n( $f['size'] ?? null, 4, 72, 8 ),
                'lh'      => self::n( $f['lh'] ?? null, 0.8, 3, 1.4 ),
                'top'     => self::n( $f['top'] ?? null, 0, 842.25, 783.21 ),
            ),
            'blocks'  => array(),
        );

        $seen = array();
        foreach ( ( isset( $raw['blocks'] ) && is_array( $raw['blocks'] ) ) ? $raw['blocks'] : array() as $b ) {
            if ( ! is_array( $b ) || empty( $b['key'] ) || ! in_array( $b['key'], self::BLOCKS, true ) || isset( $seen[ $b['key'] ] ) ) {
                continue;
            }
            $seen[ $b['key'] ] = 1;
            $out['blocks'][]   = array(
                'key'     => $b['key'],
                'text'    => sanitize_textarea_field( (string) ( $b['text'] ?? '' ) ),
                'font'    => self::font( $b['font'] ?? '', 'Poppins-ExtraLight' ),
                'size'    => self::n( $b['size'] ?? null, 4, 120, 12 ),
                'lh'      => self::n( $b['lh'] ?? null, 0.8, 3, 1.4 ),
                'gap'     => self::n( $b['gap'] ?? null, -200, 400, 0 ),
                'enabled' => empty( $b['enabled'] ) ? 0 : 1,
            );
        }
        return $out;
    }

    public static function save_layout( WP_REST_Request $req ) {
        $json = $req->get_json_params();
        if ( isset( $json['reset'] ) && $json['reset'] ) {
            delete_option( 'weranda_lunch_layout' );
            return array( 'layout' => null );
        }
        $layout = self::sanitize_layout( isset( $json['layout'] ) ? $json['layout'] : array() );
        update_option( 'weranda_lunch_layout', $layout, false );
        return array( 'layout' => $layout );
    }

    public static function save_draft( WP_REST_Request $req ) {
        $draft = array(
            'soup'        => sanitize_textarea_field( (string) $req->get_param( 'soup' ) ),
            'weight'      => sanitize_text_field( (string) $req->get_param( 'weight' ) ),
            'lunch'       => sanitize_textarea_field( (string) $req->get_param( 'lunch' ) ),
            'rec'         => sanitize_textarea_field( (string) $req->get_param( 'rec' ) ),
            'template_id' => sanitize_text_field( (string) $req->get_param( 'template_id' ) ),
        );
        update_option( 'weranda_lunch_draft', $draft );
        if ( $draft['template_id'] ) {
            update_option( 'weranda_lunch_active_template', $draft['template_id'] );
        }
        return array( 'success' => true );
    }

    public static function upload_pdf( WP_REST_Request $req ) {
      try {
        $files = $req->get_file_params();
        if ( empty( $files['pdf_file'] ) || $files['pdf_file']['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'no_file', 'Nie otrzymano pliku PDF.', array( 'status' => 400 ) );
        }

        $tmp_name  = $files['pdf_file']['tmp_name'];
        $mime_type = self::detect_mime( $tmp_name, $files['pdf_file']['name'] );

        if ( $mime_type !== 'application/pdf' ) {
            return new WP_Error( 'bad_mime', 'Niedozwolony format pliku. Oczekiwano PDF.', array( 'status' => 400 ) );
        }

        $upload_dir  = wp_upload_dir();
        $unique_hash = wp_generate_password( 6, false );
        $filename    = 'Lunch_' . wp_date( 'd-m-Y_H-i-s' ) . '_' . $unique_hash . '.pdf';
        $file_path   = $upload_dir['path'] . '/' . $filename;
        $file_url    = $upload_dir['url'] . '/' . $filename;

        if ( ! move_uploaded_file( $tmp_name, $file_path ) ) {
            return new WP_Error( 'write_failed', 'Brak uprawnień serwera do zapisu w folderze wp-content/uploads.', array( 'status' => 500 ) );
        }

        $archive = get_option( 'weranda_lunch_archive', array() );
        if ( ! is_array( $archive ) ) $archive = array();
        $item_id = uniqid( 'pdf_' );
        array_unshift( $archive, array(
            'id'   => $item_id,
            'date' => wp_date( 'Y-m-d H:i:s' ),
            'url'  => $file_url,
            'name' => $filename,
        ) );
        $archive = array_slice( $archive, 0, 15 );
        update_option( 'weranda_lunch_archive', $archive );

        return array( 'success' => true, 'archive' => $archive );
      } catch ( \Throwable $e ) {
          return new WP_Error( 'upload_exception', 'Błąd serwera podczas wgrywania: ' . $e->getMessage(), array( 'status' => 500 ) );
      }
    }

    public static function delete_pdf( WP_REST_Request $req ) {
        $file_id     = sanitize_text_field( $req->get_param( 'id' ) );
        $archive     = get_option( 'weranda_lunch_archive', array() );
        $new_archive = array();
        $upload_dir  = wp_upload_dir();

        foreach ( $archive as $item ) {
            $current_id = isset( $item['id'] ) ? $item['id'] : md5( $item['url'] );
            if ( $current_id !== $file_id ) {
                $new_archive[] = $item;
            } else {
                $file_path = $upload_dir['path'] . '/' . $item['name'];
                if ( file_exists( $file_path ) ) unlink( $file_path );
            }
        }
        update_option( 'weranda_lunch_archive', $new_archive );
        return array( 'success' => true, 'archive' => $new_archive );
    }

    public static function add_checklist_item( WP_REST_Request $req ) {
        $new_item = sanitize_text_field( (string) $req->get_param( 'item' ) );
        $items    = get_option( 'weranda_lunch_checklist', array() );
        if ( $new_item && ! in_array( $new_item, $items, true ) ) {
            $items[] = $new_item;
            update_option( 'weranda_lunch_checklist', $items );
        }
        return array( 'success' => true, 'checklist' => $items );
    }

    public static function delete_checklist_item( WP_REST_Request $req ) {
        $del_item = sanitize_text_field( (string) $req->get_param( 'item' ) );
        $items    = get_option( 'weranda_lunch_checklist', array() );
        $items    = array_values( array_diff( $items, array( $del_item ) ) );
        update_option( 'weranda_lunch_checklist', $items );
        return array( 'success' => true, 'checklist' => $items );
    }

    // --- Szablony ---

    private static function sanitize_config( $raw ) {
        $sections = array( 'soup', 'weight', 'lunch', 'rec' );
        $clean    = array();
        foreach ( $sections as $s ) {
            $c = isset( $raw[ $s ] ) ? $raw[ $s ] : array();
            $clean[ $s ] = array(
                'y'        => isset( $c['y'] ) ? floatval( $c['y'] ) : 0,
                'size'     => isset( $c['size'] ) ? floatval( $c['size'] ) : 12,
                'maxWidth' => isset( $c['maxWidth'] ) ? floatval( $c['maxWidth'] ) : 260,
                'x1'       => isset( $c['x1'] ) ? floatval( $c['x1'] ) : 0,
                'x2'       => isset( $c['x2'] ) ? floatval( $c['x2'] ) : 0,
            );
        }
        return $clean;
    }

    public static function create_template( WP_REST_Request $req ) {
      try {
        $name = sanitize_text_field( (string) $req->get_param( 'name' ) );
        if ( ! $name ) $name = 'Nowy szablon';

        $config_raw = $req->get_param( 'config' );
        $config     = is_string( $config_raw ) ? json_decode( $config_raw, true ) : (array) $config_raw;

        $files = $req->get_file_params();
        $url   = '';

        if ( ! empty( $files['pdf_file'] ) && $files['pdf_file']['error'] === UPLOAD_ERR_OK ) {
            $mime_type = self::detect_mime( $files['pdf_file']['tmp_name'], $files['pdf_file']['name'] );
            if ( $mime_type !== 'application/pdf' ) {
                return new WP_Error( 'bad_mime', 'Plik szablonu musi być w formacie PDF.', array( 'status' => 400 ) );
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_id = media_handle_upload( 'pdf_file', 0 );
            if ( is_wp_error( $attach_id ) ) {
                return new WP_Error( 'upload_failed', $attach_id->get_error_message(), array( 'status' => 500 ) );
            }
            $url = wp_get_attachment_url( $attach_id );
        } else {
            $url = esc_url_raw( (string) $req->get_param( 'url' ) );
        }

        if ( ! $url ) {
            return new WP_Error( 'no_file', 'Wgraj plik PDF lub podaj adres URL szablonu.', array( 'status' => 400 ) );
        }

        $templates  = get_option( 'weranda_lunch_templates', array() );
        $new_id     = 'tpl_' . wp_generate_password( 8, false );
        $templates[] = array(
            'id'     => $new_id,
            'name'   => $name,
            'url'    => $url,
            'config' => self::sanitize_config( $config ),
        );
        update_option( 'weranda_lunch_templates', $templates );

        return array( 'success' => true, 'templates' => $templates, 'id' => $new_id );
      } catch ( \Throwable $e ) {
          return new WP_Error( 'template_exception', 'Błąd serwera podczas dodawania szablonu: ' . $e->getMessage(), array( 'status' => 500 ) );
      }
    }

    public static function update_template( WP_REST_Request $req ) {
      try {
        $id        = sanitize_text_field( $req->get_param( 'id' ) );
        $templates = get_option( 'weranda_lunch_templates', array() );
        $found     = false;

        $files = $req->get_file_params();
        $new_url = null;
        if ( ! empty( $files['pdf_file'] ) && $files['pdf_file']['error'] === UPLOAD_ERR_OK ) {
            $mime_type = self::detect_mime( $files['pdf_file']['tmp_name'], $files['pdf_file']['name'] );
            if ( $mime_type !== 'application/pdf' ) {
                return new WP_Error( 'bad_mime', 'Plik szablonu musi być w formacie PDF.', array( 'status' => 400 ) );
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_id = media_handle_upload( 'pdf_file', 0 );
            if ( is_wp_error( $attach_id ) ) {
                return new WP_Error( 'upload_failed', $attach_id->get_error_message(), array( 'status' => 500 ) );
            }
            $new_url = wp_get_attachment_url( $attach_id );
        }

        $name       = $req->get_param( 'name' );
        $config_raw = $req->get_param( 'config' );
        $config     = is_string( $config_raw ) ? json_decode( $config_raw, true ) : $config_raw;

        foreach ( $templates as &$t ) {
            if ( $t['id'] === $id ) {
                $found = true;
                if ( $name !== null && $name !== '' ) $t['name'] = sanitize_text_field( $name );
                if ( $new_url ) $t['url'] = $new_url;
                if ( is_array( $config ) ) $t['config'] = self::sanitize_config( $config );
            }
        }
        unset( $t );

        if ( ! $found ) {
            return new WP_Error( 'not_found', 'Nie znaleziono szablonu.', array( 'status' => 404 ) );
        }

        update_option( 'weranda_lunch_templates', $templates );
        return array( 'success' => true, 'templates' => $templates );
      } catch ( \Throwable $e ) {
          return new WP_Error( 'template_exception', 'Błąd serwera podczas zapisu szablonu: ' . $e->getMessage(), array( 'status' => 500 ) );
      }
    }

    public static function delete_template( WP_REST_Request $req ) {
        $id        = sanitize_text_field( $req->get_param( 'id' ) );
        $templates = get_option( 'weranda_lunch_templates', array() );
        $templates = array_values( array_filter( $templates, function ( $t ) use ( $id ) {
            return $t['id'] !== $id;
        } ) );
        update_option( 'weranda_lunch_templates', $templates );

        $active = get_option( 'weranda_lunch_active_template', '' );
        if ( $active === $id && ! empty( $templates ) ) {
            update_option( 'weranda_lunch_active_template', $templates[0]['id'] );
        }

        return array( 'success' => true, 'templates' => $templates );
    }

    public static function set_default_template( WP_REST_Request $req ) {
        $id = sanitize_text_field( $req->get_param( 'id' ) );
        update_option( 'weranda_lunch_active_template', $id );
        return array( 'success' => true, 'activeTemplate' => $id );
    }
}
Weranda_Lunch_Rest::init();
