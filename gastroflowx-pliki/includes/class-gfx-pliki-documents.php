<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Model danych: dokumenty jako niepubliczny custom post type „gfx_plik”,
 * kategorie jako opcja (jak w Kolorowankach), pliki w chronionym katalogu
 * uploads/gastroflowx-pliki/ (serwowane wyłącznie przez REST po sprawdzeniu uprawnień).
 *
 * Dwa typy dokumentów:
 *  - html — dokument tworzony w edytorze na froncie (treść w post_content),
 *  - file — wgrany PDF / obraz.
 */
class GFX_Pliki_Documents {

    const POST_TYPE   = 'gfx_plik';
    const CATS_OPTION = 'gfx_pliki_categories';
    const DEFAULT_CAT = 'cat_default';
    const DIR_NAME    = 'gastroflowx-pliki';
    const MAX_HTML    = 600000; // ~600 KB treści HTML na dokument

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete' ) );
    }

    public static function register_post_type() {
        if ( post_type_exists( self::POST_TYPE ) ) return;
        register_post_type( self::POST_TYPE, array(
            'labels'              => array(
                'name'          => __( 'Pliki GastroFlowX', 'gastroflowx-pliki' ),
                'singular_name' => __( 'Dokument', 'gastroflowx-pliki' ),
            ),
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'show_in_nav_menus'   => false,
            'rewrite'             => false,
            'query_var'           => false,
            'can_export'          => true,
            'supports'            => array( 'title', 'editor', 'excerpt', 'author' ),
        ) );
    }

    /* ======================= KATEGORIE ======================= */

    public static function default_categories() {
        return array(
            array( 'id' => self::DEFAULT_CAT,  'name' => __( 'Ogólne', 'gastroflowx-pliki' ) ),
            array( 'id' => 'cat_raporty',      'name' => __( 'Raporty', 'gastroflowx-pliki' ) ),
            array( 'id' => 'cat_obowiazki',    'name' => __( 'Listy obowiązków', 'gastroflowx-pliki' ) ),
            array( 'id' => 'cat_checklisty',   'name' => __( 'Checklisty / HACCP', 'gastroflowx-pliki' ) ),
        );
    }

    public static function get_categories() {
        $cats = get_option( self::CATS_OPTION, null );
        if ( ! is_array( $cats ) || ! $cats ) $cats = self::default_categories();
        $clean = array();
        $has_default = false;
        foreach ( $cats as $c ) {
            if ( empty( $c['id'] ) || ! isset( $c['name'] ) ) continue;
            if ( $c['id'] === self::DEFAULT_CAT ) $has_default = true;
            $clean[] = array( 'id' => (string) $c['id'], 'name' => (string) $c['name'] );
        }
        if ( ! $has_default ) {
            array_unshift( $clean, array( 'id' => self::DEFAULT_CAT, 'name' => __( 'Ogólne', 'gastroflowx-pliki' ) ) );
        }
        return $clean;
    }

    public static function save_categories( array $cats ) {
        update_option( self::CATS_OPTION, array_values( $cats ), false );
        return self::get_categories();
    }

    public static function category_exists( $id ) {
        foreach ( self::get_categories() as $c ) {
            if ( $c['id'] === $id ) return true;
        }
        return false;
    }

    /* ======================= KATALOG PLIKÓW ======================= */

    public static function storage_dir() {
        $u   = wp_upload_dir( null, false );
        $dir = trailingslashit( $u['basedir'] ) . self::DIR_NAME;
        if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
        self::protect_dir( $dir );
        return $dir;
    }

    private static function protect_dir( $dir ) {
        $ht = $dir . '/.htaccess';
        if ( ! file_exists( $ht ) ) {
            @file_put_contents( $ht, "# GastroFlowX Pliki: pliki serwowane wyłącznie przez REST API po sprawdzeniu uprawnień.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n" );
        }
        $idx = $dir . '/index.php';
        if ( ! file_exists( $idx ) ) {
            @file_put_contents( $idx, "<?php\n// Silence is golden.\n" );
        }
    }

    public static function file_path( $post_id ) {
        $p = get_post_meta( $post_id, '_gfx_path', true );
        if ( ! $p ) return '';
        return self::storage_dir() . '/' . basename( $p );
    }

    public static function allowed_mimes() {
        return array(
            'application/pdf' => array( 'ext' => 'pdf',  'kind' => 'pdf' ),
            'image/jpeg'      => array( 'ext' => 'jpg',  'kind' => 'image' ),
            'image/png'       => array( 'ext' => 'png',  'kind' => 'image' ),
            'image/webp'      => array( 'ext' => 'webp', 'kind' => 'image' ),
        );
    }

    /**
     * Bezpieczne wykrywanie MIME z fallbackami (finfo bywa wyłączone na hostingach —
     * ten sam wzorzec co w Kolorowankach).
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

        $map = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
        $ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
        return isset( $map[ $ext ] ) ? $map[ $ext ] : '';
    }

    /**
     * Zapisuje wgrany plik w chronionym katalogu pod losową nazwą.
     * Zwraca tablicę metadanych albo WP_Error.
     */
    public static function store_upload( $f ) {
        if ( ! is_array( $f ) || ! isset( $f['error'] ) ) {
            return new WP_Error( 'gfx_pliki_no_file', __( 'Nie otrzymano pliku.', 'gastroflowx-pliki' ), array( 'status' => 400 ) );
        }
        // Gdyby przyszła tablica wielu plików — bierzemy pierwszy (front wysyła po jednym).
        if ( is_array( $f['name'] ) ) {
            $f = array(
                'name'     => $f['name'][0],
                'type'     => $f['type'][0],
                'tmp_name' => $f['tmp_name'][0],
                'error'    => $f['error'][0],
                'size'     => $f['size'][0],
            );
        }
        $orig = wp_basename( (string) $f['name'] );

        if ( (int) $f['error'] !== UPLOAD_ERR_OK ) {
            if ( in_array( (int) $f['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
                /* translators: %s: nazwa pliku */
                return new WP_Error( 'gfx_pliki_too_big', sprintf( __( 'Plik jest za duży (limit serwera): %s', 'gastroflowx-pliki' ), $orig ), array( 'status' => 413 ) );
            }
            /* translators: %s: nazwa pliku */
            return new WP_Error( 'gfx_pliki_upload_error', sprintf( __( 'Błąd przesyłania pliku: %s', 'gastroflowx-pliki' ), $orig ), array( 'status' => 400 ) );
        }
        if ( empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
            return new WP_Error( 'gfx_pliki_upload_error', __( 'Nieprawidłowy plik tymczasowy.', 'gastroflowx-pliki' ), array( 'status' => 400 ) );
        }
        $size = (int) $f['size'];
        if ( $size > wp_max_upload_size() ) {
            /* translators: %s: nazwa pliku */
            return new WP_Error( 'gfx_pliki_too_big', sprintf( __( 'Plik jest za duży: %s', 'gastroflowx-pliki' ), $orig ), array( 'status' => 413 ) );
        }

        $mime    = self::detect_mime( $f['tmp_name'], $orig );
        $allowed = self::allowed_mimes();
        if ( ! isset( $allowed[ $mime ] ) ) {
            /* translators: 1: nazwa pliku, 2: wykryty typ */
            return new WP_Error( 'gfx_pliki_bad_type', sprintf( __( 'Niedozwolony format pliku: %1$s (wykryto: %2$s). Dozwolone: PDF, JPG, PNG, WEBP.', 'gastroflowx-pliki' ), $orig, $mime ? $mime : 'nieznany' ), array( 'status' => 415 ) );
        }

        $dir = self::storage_dir();
        if ( ! wp_is_writable( $dir ) ) {
            return new WP_Error( 'gfx_pliki_not_writable', __( 'Katalog na pliki nie jest zapisywalny (wp-content/uploads/gastroflowx-pliki).', 'gastroflowx-pliki' ), array( 'status' => 500 ) );
        }

        $ext    = $allowed[ $mime ]['ext'];
        $stored = strtolower( wp_generate_password( 24, false, false ) ) . '.' . $ext;
        if ( ! @move_uploaded_file( $f['tmp_name'], $dir . '/' . $stored ) ) {
            /* translators: %s: nazwa pliku */
            return new WP_Error( 'gfx_pliki_write_error', sprintf( __( 'Nie udało się zapisać pliku: %s', 'gastroflowx-pliki' ), $orig ), array( 'status' => 500 ) );
        }
        @chmod( $dir . '/' . $stored, 0644 );

        return array(
            'path'     => $stored,
            'mime'     => $mime,
            'kind'     => $allowed[ $mime ]['kind'],
            'ext'      => $ext,
            'size'     => $size,
            'original' => sanitize_file_name( $orig ),
        );
    }

    /** Usuwa plik z dysku niezależnie od tego, jak skasowano wpis (aplikacja, wp-cli, inna wtyczka). */
    public static function on_before_delete( $post_id ) {
        if ( get_post_type( $post_id ) === self::POST_TYPE ) self::delete_physical( $post_id );
    }

    private static function delete_physical( $post_id ) {
        $path = self::file_path( $post_id );
        if ( $path && file_exists( $path ) ) {
            $real = realpath( $path );
            $dir  = realpath( self::storage_dir() );
            if ( $real && $dir && strpos( $real, $dir ) === 0 ) @unlink( $real );
        }
    }

    /* ======================= SANITYZACJA ======================= */

    public static function sanitize_html( $html ) {
        $html = (string) $html;
        if ( strlen( $html ) > self::MAX_HTML ) {
            return new WP_Error( 'gfx_pliki_too_long', __( 'Dokument jest zbyt długi.', 'gastroflowx-pliki' ), array( 'status' => 413 ) );
        }
        return wp_kses_post( $html );
    }

    /* ======================= ODCZYT ======================= */

    public static function get_doc( $id ) {
        $p = get_post( (int) $id );
        if ( ! $p || $p->post_type !== self::POST_TYPE || $p->post_status !== 'publish' ) return null;
        return $p;
    }

    public static function doc_roles( $post_id ) {
        $r = get_post_meta( $post_id, '_gfx_roles', true );
        return is_array( $r ) ? array_values( $r ) : array();
    }

    /**
     * Dokument może mieć zawężoną widoczność do wybranych ról.
     * Widzą go zawsze: administrator, autor oraz role z uprawnieniem „Widzi dokumenty innych ról”.
     */
    public static function user_can_see( WP_Post $p ) {
        if ( ! GFX_Pliki_Settings::can_view() ) return false;
        if ( GFX_Pliki_Settings::can( 'view_hidden' ) || self::is_owner( $p ) ) return true;
        $roles = self::doc_roles( $p->ID );
        if ( ! $roles ) return true;
        return (bool) array_intersect( GFX_Pliki_Settings::current_roles(), $roles );
    }

    public static function is_owner( WP_Post $p ) {
        return is_user_logged_in() && (int) $p->post_author === get_current_user_id();
    }

    private static function own_or_all( WP_Post $p, $prefix ) {
        if ( ! self::user_can_see( $p ) ) return false;
        if ( GFX_Pliki_Settings::can( $prefix . '_all' ) ) return true;
        return self::is_owner( $p ) && GFX_Pliki_Settings::can( $prefix . '_own' );
    }

    public static function user_can_edit( WP_Post $p ) {
        return self::own_or_all( $p, 'edit' );
    }

    public static function user_can_delete( WP_Post $p ) {
        return self::own_or_all( $p, 'delete' );
    }

    /** Duplikat to nowy dokument: z edytora wymaga „create”, wgrany plik wymaga „upload”. */
    public static function user_can_duplicate( WP_Post $p ) {
        if ( ! self::user_can_see( $p ) ) return false;
        $type = get_post_meta( $p->ID, '_gfx_type', true ) === 'file' ? 'file' : 'html';
        return GFX_Pliki_Settings::can( $type === 'file' ? 'upload' : 'create' );
    }

    public static function to_array( WP_Post $p ) {
        $type = get_post_meta( $p->ID, '_gfx_type', true ) === 'file' ? 'file' : 'html';
        $cat  = get_post_meta( $p->ID, '_gfx_category', true );
        if ( ! $cat || ! self::category_exists( $cat ) ) $cat = self::DEFAULT_CAT;
        $pt = get_post_meta( $p->ID, '_gfx_print_title', true );

        $file = null;
        if ( $type === 'file' ) {
            $file = array(
                'kind'     => get_post_meta( $p->ID, '_gfx_kind', true ) === 'pdf' ? 'pdf' : 'image',
                'mime'     => (string) get_post_meta( $p->ID, '_gfx_mime', true ),
                'ext'      => (string) get_post_meta( $p->ID, '_gfx_ext', true ),
                'size'     => (int) get_post_meta( $p->ID, '_gfx_size', true ),
                'original' => (string) get_post_meta( $p->ID, '_gfx_original', true ),
            );
        }
        $author = get_userdata( (int) $p->post_author );

        return array(
            'id'          => (int) $p->ID,
            'title'       => $p->post_title,
            'type'        => $type,
            'category'    => $cat,
            'roles'       => self::doc_roles( $p->ID ),
            'note'        => $p->post_excerpt,
            'content'     => $type === 'html' ? $p->post_content : '',
            'orientation' => get_post_meta( $p->ID, '_gfx_orientation', true ) === 'landscape' ? 'landscape' : 'portrait',
            'printTitle'  => $pt === '' ? true : ( $pt === '1' ),
            'file'        => $file,
            'updated'     => mysql2date( 'Y-m-d H:i', $p->post_modified ),
            'version'     => (string) strtotime( $p->post_modified_gmt ),
            'author'      => $author ? $author->display_name : '',
            'can'         => array(
                'edit'      => self::user_can_edit( $p ),
                'delete'    => self::user_can_delete( $p ),
                'duplicate' => self::user_can_duplicate( $p ),
                'download'  => $type === 'file' && GFX_Pliki_Settings::can( 'download' ),
            ),
        );
    }

    public static function list_for_current_user() {
        $posts = get_posts( array(
            'post_type'        => self::POST_TYPE,
            'post_status'      => 'publish',
            'numberposts'      => -1,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ) );
        $out = array();
        foreach ( $posts as $p ) {
            if ( self::user_can_see( $p ) ) $out[] = self::to_array( $p );
        }
        return $out;
    }

    /* ======================= ZAPIS ======================= */

    private static function apply_meta( $id, array $data ) {
        if ( array_key_exists( 'category', $data ) )    update_post_meta( $id, '_gfx_category', $data['category'] );
        if ( array_key_exists( 'roles', $data ) )       update_post_meta( $id, '_gfx_roles', array_values( (array) $data['roles'] ) );
        if ( array_key_exists( 'orientation', $data ) ) update_post_meta( $id, '_gfx_orientation', $data['orientation'] );
        if ( array_key_exists( 'printTitle', $data ) )  update_post_meta( $id, '_gfx_print_title', $data['printTitle'] ? '1' : '0' );
        if ( ! empty( $data['file'] ) && is_array( $data['file'] ) ) {
            $f = $data['file'];
            update_post_meta( $id, '_gfx_path', $f['path'] );
            update_post_meta( $id, '_gfx_mime', $f['mime'] );
            update_post_meta( $id, '_gfx_kind', $f['kind'] );
            update_post_meta( $id, '_gfx_ext', $f['ext'] );
            update_post_meta( $id, '_gfx_size', (int) $f['size'] );
            update_post_meta( $id, '_gfx_original', $f['original'] );
        }
    }

    public static function create( $type, array $data ) {
        $postarr = array(
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => isset( $data['title'] ) ? $data['title'] : '',
            'post_excerpt' => isset( $data['note'] ) ? $data['note'] : '',
            'post_content' => ( $type === 'html' && isset( $data['content'] ) ) ? $data['content'] : '',
            'post_author'  => get_current_user_id(),
        );
        $id = wp_insert_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $id ) ) return $id;
        update_post_meta( $id, '_gfx_type', $type === 'file' ? 'file' : 'html' );
        $data = wp_parse_args( $data, array( 'category' => self::DEFAULT_CAT, 'roles' => array(), 'orientation' => 'portrait', 'printTitle' => true ) );
        self::apply_meta( $id, $data );
        return $id;
    }

    public static function update( $id, array $data ) {
        $postarr = array( 'ID' => (int) $id );
        if ( array_key_exists( 'title', $data ) )   $postarr['post_title']   = $data['title'];
        if ( array_key_exists( 'note', $data ) )    $postarr['post_excerpt'] = $data['note'];
        if ( array_key_exists( 'content', $data ) ) $postarr['post_content'] = $data['content'];

        $old_path = '';
        if ( ! empty( $data['file'] ) ) $old_path = self::file_path( $id );

        // Zawsze aktualizujemy wpis, żeby podbić post_modified (wersja pliku w URL = brak starego cache).
        $res = wp_update_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $res ) ) return $res;
        self::apply_meta( $id, $data );

        if ( $old_path && file_exists( $old_path ) && basename( $old_path ) !== $data['file']['path'] ) {
            $real = realpath( $old_path );
            $dir  = realpath( self::storage_dir() );
            if ( $real && $dir && strpos( $real, $dir ) === 0 ) @unlink( $real );
        }
        return (int) $id;
    }

    public static function duplicate( $id ) {
        $p = self::get_doc( $id );
        if ( ! $p ) return new WP_Error( 'gfx_pliki_not_found', __( 'Nie znaleziono dokumentu.', 'gastroflowx-pliki' ), array( 'status' => 404 ) );
        $src = self::to_array( $p );

        $data = array(
            /* translators: %s: nazwa dokumentu */
            'title'       => sprintf( __( '%s (kopia)', 'gastroflowx-pliki' ), $src['title'] ),
            'note'        => $src['note'],
            'content'     => $src['content'],
            'category'    => $src['category'],
            'roles'       => $src['roles'],
            'orientation' => $src['orientation'],
            'printTitle'  => $src['printTitle'],
        );

        if ( $src['type'] === 'file' ) {
            $from = self::file_path( $p->ID );
            if ( ! $from || ! file_exists( $from ) ) {
                return new WP_Error( 'gfx_pliki_missing_file', __( 'Brak pliku źródłowego na serwerze.', 'gastroflowx-pliki' ), array( 'status' => 404 ) );
            }
            $stored = strtolower( wp_generate_password( 24, false, false ) ) . '.' . $src['file']['ext'];
            if ( ! @copy( $from, self::storage_dir() . '/' . $stored ) ) {
                return new WP_Error( 'gfx_pliki_write_error', __( 'Nie udało się skopiować pliku.', 'gastroflowx-pliki' ), array( 'status' => 500 ) );
            }
            $data['file'] = array(
                'path'     => $stored,
                'mime'     => $src['file']['mime'],
                'kind'     => $src['file']['kind'],
                'ext'      => $src['file']['ext'],
                'size'     => $src['file']['size'],
                'original' => $src['file']['original'],
            );
        }
        return self::create( $src['type'], $data );
    }

    public static function delete( $id ) {
        self::delete_physical( $id );
        return (bool) wp_delete_post( (int) $id, true );
    }

    /** Dokumenty z usuniętej kategorii wracają do „Ogólne”. */
    public static function reassign_category( $from ) {
        $ids = get_posts( array(
            'post_type'        => self::POST_TYPE,
            'post_status'      => 'any',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'meta_key'         => '_gfx_category',
            'meta_value'       => $from,
            'suppress_filters' => true,
        ) );
        foreach ( $ids as $id ) update_post_meta( $id, '_gfx_category', self::DEFAULT_CAT );
    }

    /* ======================= PRZYKŁADY ======================= */

    public static function seed_examples() {
        if ( get_option( 'gfx_pliki_seeded' ) ) return;
        update_option( 'gfx_pliki_seeded', 1, false );

        $sign = '<table class="gp-table gp-noborder gp-sign"><tbody><tr><td>Podpis osoby rozliczającej<br><span class="gp-blank"> </span></td><td>Podpis managera<br><span class="gp-blank"> </span></td></tr></tbody></table>';

        $row = function ( $label, $cols ) {
            return '<tr><td>' . $label . '</td>' . str_repeat( '<td></td>', $cols ) . '</tr>';
        };

        // 1. Raport utargu dziennego
        $pay = '';
        foreach ( array( 'Gotówka', 'Karta płatnicza', 'BLIK', 'Vouchery / bony', 'Przelew / faktura', 'Inne' ) as $l ) $pay .= $row( $l, 3 );
        $pay .= '<tr><td><strong>RAZEM</strong></td><td></td><td></td><td></td></tr>';
        $cash = '';
        foreach ( array( 'Pogotowie kasowe na początek zmiany', 'Wpłaty do kasy (KP)', 'Wypłaty z kasy (KW)', 'Gotówka ze sprzedaży', 'Stan kasy wyliczony', 'Stan kasy faktyczny (przeliczony)', 'Różnica (nadwyżka / niedobór)', 'Odprowadzono do sejfu / banku' ) as $l ) $cash .= $row( $l, 1 );
        $other = '';
        foreach ( array(
            array( 'Liczba paragonów', 'Zwroty (kwota)' ),
            array( 'Anulowane pozycje', 'Napiwki kartą' ),
            array( 'Liczba gości', 'Nr raportu dobowego' ),
        ) as $pair ) $other .= '<tr><td>' . $pair[0] . '</td><td></td><td>' . $pair[1] . '</td><td></td></tr>';

        $raport = '<table class="gp-table gp-noborder"><tbody>'
            . '<tr><td>Data: <span class="gp-blank"> </span></td><td>Zmiana: ☐ poranna ☐ popołudniowa ☐ cały dzień</td></tr>'
            . '<tr><td>Osoba rozliczająca: <span class="gp-blank"> </span></td><td>Kasa / stanowisko: <span class="gp-blank"> </span></td></tr>'
            . '</tbody></table>'
            . '<h3>Sprzedaż według formy płatności</h3>'
            . '<table class="gp-table"><thead><tr><th>Forma płatności</th><th>Wg systemu (zł)</th><th>Faktycznie (zł)</th><th>Różnica (zł)</th></tr></thead><tbody>' . $pay . '</tbody></table>'
            . '<h3>Rozliczenie gotówki</h3>'
            . '<table class="gp-table"><thead><tr><th>Pozycja</th><th class="gp-w30">Kwota (zł)</th></tr></thead><tbody>' . $cash . '</tbody></table>'
            . '<h3>Pozostałe</h3>'
            . '<table class="gp-table"><thead><tr><th>Pozycja</th><th class="gp-w20">Wartość</th><th>Pozycja</th><th class="gp-w20">Wartość</th></tr></thead><tbody>' . $other . '</tbody></table>'
            . '<h3>Uwagi</h3><p><span class="gp-blank gp-blank-full"> </span></p>'
            . $sign;

        // 2. Otwarcie sali
        $otwarcie = '<table class="gp-table gp-noborder"><tbody><tr><td>Data: <span class="gp-blank"> </span></td><td>Osoba otwierająca: <span class="gp-blank"> </span></td></tr></tbody></table>'
            . '<h3>Sala</h3><ul class="gp-check"><li>Wyłączony alarm, zapalone światło, włączona muzyka w tle</li><li>Stoliki i krzesła ustawione wg planu sali, blaty przetarte</li><li>Zastawa, sztućce i serwetki uzupełnione na stolikach</li><li>Przyprawniki i cukiernice pełne i czyste</li><li>Karty menu czyste, wkładka z daniem dnia aktualna</li><li>Rezerwacje na dziś sprawdzone, stoliki oznaczone</li></ul>'
            . '<h3>Bar</h3><ul class="gp-check"><li>Ekspres włączony, próbne espresso zrobione</li><li>Lód, cytryny i dodatki przygotowane</li><li>Lodówki uzupełnione, temperatury wpisane do rejestru</li><li>Kasa uruchomiona, pogotowie kasowe przeliczone</li></ul>'
            . '<h3>Toalety i wejście</h3><ul class="gp-check"><li>Mydło, ręczniki i papier uzupełnione</li><li>Wejście i witryna czyste, tablica z menu wystawiona</li></ul>'
            . '<h3>Uwagi</h3><p><span class="gp-blank gp-blank-full"> </span></p>'
            . '<p>Podpis: <span class="gp-blank"> </span></p>';

        // 3. Zamknięcie zmiany
        $zamkniecie = '<table class="gp-table gp-noborder"><tbody><tr><td>Data: <span class="gp-blank"> </span></td><td>Osoba zamykająca: <span class="gp-blank"> </span></td></tr></tbody></table>'
            . '<h3>Sala i bar</h3><ul class="gp-check"><li>Stoliki uprzątnięte i przetarte, krzesła odstawione</li><li>Podłoga zamieciona i umyta</li><li>Zmywarka opróżniona i wyczyszczona</li><li>Ekspres wyczyszczony (płukanie grupy, dysza pary)</li><li>Otwarte produkty opisane datą i schowane do lodówek</li><li>Utarg rozliczony — raport utargu wypełniony i podpisany</li></ul>'
            . '<h3>Kuchnia</h3><ul class="gp-check"><li>Urządzenia grzewcze wyłączone</li><li>Blaty i deski zdezynfekowane</li><li>Odpady posegregowane i wyniesione</li><li>Temperatury urządzeń chłodniczych wpisane do rejestru</li></ul>'
            . '<h3>Na koniec</h3><ul class="gp-check"><li>Okna i drzwi zamknięte, światło zgaszone</li><li>Alarm uzbrojony</li></ul>'
            . '<p>Podpis: <span class="gp-blank"> </span></p>';

        // 4. Rejestr temperatur (HACCP) — poziomo
        $head = '<tr><th>Dzień</th>';
        for ( $i = 1; $i <= 6; $i++ ) $head .= '<th>Urządzenie ' . $i . ' (°C)</th>';
        $head .= '<th>Godzina</th><th>Podpis</th></tr>';
        $rows = '';
        for ( $d = 1; $d <= 16; $d++ ) $rows .= '<tr><td>' . $d . '</td>' . str_repeat( '<td></td>', 8 ) . '</tr>';
        $temp = '<p>Miesiąc / rok: <span class="gp-blank"> </span>   Norma: lodówki 0–4 °C, zamrażarki ≤ −18 °C</p>'
            . '<table class="gp-table"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table>'
            . '<p>Przy przekroczeniu normy: poinformuj managera i zapisz działanie korygujące w uwagach.</p>';

        $examples = array(
            array( 'Raport utargu dziennego', 'cat_raporty', 'portrait', $raport, 'Wypełnij na koniec zmiany i oddaj managerowi razem z raportem dobowym z kasy.' ),
            array( 'Lista obowiązków — otwarcie', 'cat_obowiazki', 'portrait', $otwarcie, '' ),
            array( 'Lista obowiązków — zamknięcie zmiany', 'cat_obowiazki', 'portrait', $zamkniecie, '' ),
            array( 'Rejestr temperatur urządzeń chłodniczych', 'cat_checklisty', 'landscape', $temp, 'Dni 1–16 na pierwszej kartce, dni 17–31 na drugiej — drukuj 2 kopie.' ),
        );
        foreach ( $examples as $e ) {
            self::create( 'html', array(
                'title'       => $e[0],
                'category'    => $e[1],
                'orientation' => $e[2],
                'content'     => wp_kses_post( $e[3] ),
                'note'        => $e[4],
                'roles'       => array(),
                'printTitle'  => true,
            ) );
        }
    }
}
