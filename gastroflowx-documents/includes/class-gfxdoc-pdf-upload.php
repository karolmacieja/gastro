<?php
/**
 * "Plik PDF" feature: upload an existing PDF to a document, then either
 *
 *   A) edit the PDF itself in a browser-based editor (add / replace text,
 *      cover fragments, highlight, insert a signature or stamp image) —
 *      edits are kept as JSON so they can be changed again later, and are
 *      flattened onto the untouched original with pdf-lib, or
 *   B) import its text into the regular TinyMCE editor, so it becomes a
 *      normal GastroFlowx-styled document exported by dompdf.
 *
 * Which file the existing "Pobierz PDF" button returns is controlled per
 * document by the "Źródło PDF" switch in the meta box.
 *
 * All heavy PDF work happens in the browser (bundled pdf.js + pdf-lib),
 * so — just like the dompdf export — nothing needs to be installed on the
 * server.
 *
 * @package GastroFlowx_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFXDoc_PDF_Upload {

	const EDITOR_SLUG = 'gfxdoc-pdf-editor';
	const MAX_EDITS   = 2000;

	/** @var string Hook suffix of the hidden editor page. */
	private $editor_hook = '';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_gfx_document', array( $this, 'save_source' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_edit_screen' ) );
		add_action( 'admin_menu', array( $this, 'register_editor_page' ) );
		add_action( 'admin_head', array( $this, 'hide_editor_menu_item' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 11, 2 );

		add_action( 'wp_ajax_gfxdoc_pdf_upload', array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_gfxdoc_pdf_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_gfxdoc_pdf_save', array( $this, 'ajax_save' ) );
		add_action( 'admin_post_gfxdoc_pdf_file', array( $this, 'serve_file' ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	public static function nonce_action( $post_id ) {
		return 'gfxdoc_pdf_' . (int) $post_id;
	}

	public static function file_url( $post_id, $version = 'current', $download = false ) {
		$args = array(
			'action' => 'gfxdoc_pdf_file',
			'post'   => (int) $post_id,
			'v'      => $version,
		);
		if ( $download ) {
			$args['dl'] = 1;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::nonce_action( $post_id ) );
	}

	public static function editor_url( $post_id ) {
		return admin_url( 'edit.php?post_type=gfx_document&page=' . self::EDITOR_SLUG . '&post=' . (int) $post_id );
	}

	/**
	 * Resolves + authorises the document for an AJAX / admin-post request.
	 * Dies with an error on any failure, so callers can rely on the result.
	 */
	private function require_document( $post_id, $ajax = true ) {
		$post = $post_id ? get_post( $post_id ) : null;
		$fail = function ( $msg, $code ) use ( $ajax ) {
			if ( $ajax ) {
				wp_send_json_error( array( 'message' => $msg ), $code );
			}
			wp_die( esc_html( $msg ), '', array( 'response' => $code ) );
		};

		if ( ! $post || GFXDoc_CPT::POST_TYPE !== $post->post_type ) {
			$fail( __( 'Nie znaleziono dokumentu.', 'gastroflowx-documents' ), 404 );
		}
		if ( ! current_user_can( GFXDOC_CAP ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			$fail( __( 'Brak uprawnień do tego dokumentu.', 'gastroflowx-documents' ), 403 );
		}
		return $post;
	}

	private static function human_upload_error( $code ) {
		switch ( (int) $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				/* translators: %s: max upload size */
				return sprintf( __( 'Plik jest za duży. Maksymalny rozmiar na tym serwerze: %s.', 'gastroflowx-documents' ), size_format( wp_max_upload_size() ) );
			case UPLOAD_ERR_PARTIAL:
				return __( 'Plik został przesłany tylko częściowo — spróbuj ponownie.', 'gastroflowx-documents' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Nie wybrano pliku.', 'gastroflowx-documents' );
			default:
				return __( 'Błąd serwera podczas przesyłania pliku.', 'gastroflowx-documents' );
		}
	}

	/**
	 * Validates one entry of $_FILES as a PDF.
	 *
	 * @return true|WP_Error
	 */
	private function validate_pdf_upload( $file, $check_extension = true ) {
		if ( empty( $file ) || ! is_array( $file ) || ! isset( $file['error'] ) ) {
			return new WP_Error( 'gfxdoc_nofile', self::human_upload_error( UPLOAD_ERR_NO_FILE ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'gfxdoc_upload', self::human_upload_error( $file['error'] ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'gfxdoc_upload', self::human_upload_error( -1 ) );
		}
		if ( $file['size'] > wp_max_upload_size() ) {
			return new WP_Error( 'gfxdoc_size', self::human_upload_error( UPLOAD_ERR_INI_SIZE ) );
		}
		if ( $check_extension && 'pdf' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'gfxdoc_ext', __( 'Dozwolone są tylko pliki .pdf.', 'gastroflowx-documents' ) );
		}
		if ( ! GFXDoc_PDF_Storage::looks_like_pdf( $file['tmp_name'] ) ) {
			return new WP_Error( 'gfxdoc_type', __( 'To nie jest prawidłowy plik PDF.', 'gastroflowx-documents' ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Meta box on the document edit screen
	 * ---------------------------------------------------------------- */

	public function add_meta_box() {
		add_meta_box(
			'gfxdoc_pdf_file',
			__( 'Plik PDF', 'gastroflowx-documents' ),
			array( $this, 'render_meta_box' ),
			GFXDoc_CPT::POST_TYPE,
			'side',
			'high'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'gfxdoc_source_' . $post->ID, 'gfxdoc_source_nonce' );
		echo '<div id="gfxdoc-pdf-box" data-post="' . esc_attr( $post->ID ) . '">';
		echo $this->meta_box_inner( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo '</div>';
	}

	/**
	 * Inner HTML of the meta box — also returned by AJAX after an upload /
	 * delete, so the box refreshes without reloading the (possibly unsaved)
	 * edit screen.
	 */
	private function meta_box_inner( $post_id ) {
		$info   = GFXDoc_PDF_Storage::info( $post_id );
		$source = GFXDoc_PDF_Storage::source( $post_id );
		ob_start();

		if ( ! $info ) :
			?>
			<div class="gfxdoc-pdf-drop" tabindex="0">
				<span class="dashicons dashicons-pdf" aria-hidden="true"></span>
				<p><strong><?php esc_html_e( 'Wgraj istniejący plik PDF', 'gastroflowx-documents' ); ?></strong><br>
				<?php esc_html_e( 'Przeciągnij plik tutaj lub', 'gastroflowx-documents' ); ?></p>
				<label class="button button-primary">
					<?php esc_html_e( 'Wybierz plik…', 'gastroflowx-documents' ); ?>
					<input type="file" accept="application/pdf,.pdf" class="gfxdoc-pdf-input screen-reader-text">
				</label>
				<p class="description">
					<?php
					/* translators: %s: max upload size */
					printf( esc_html__( 'Maks. %s. Po wgraniu możesz edytować PDF w edytorze albo przenieść jego tekst do treści dokumentu.', 'gastroflowx-documents' ), esc_html( size_format( wp_max_upload_size() ) ) );
					?>
				</p>
			</div>
			<div class="gfxdoc-pdf-progress" hidden><span class="spinner is-active"></span> <?php esc_html_e( 'Przesyłanie…', 'gastroflowx-documents' ); ?></div>
			<?php
		else :
			?>
			<div class="gfxdoc-pdf-file">
				<span class="dashicons dashicons-pdf" aria-hidden="true"></span>
				<div>
					<strong class="gfxdoc-pdf-file__name"><?php echo esc_html( $info['name'] ); ?></strong><br>
					<span class="description">
						<?php echo esc_html( size_format( $info['size'] ) ); ?> ·
						<?php echo esc_html( wp_date( 'j.m.Y H:i', $info['uploaded'] ) ); ?>
					</span><br>
					<?php if ( $info['has_edits'] ) : ?>
						<span class="gfxdoc-pdf-status gfxdoc-pdf-status--edited">
							<?php
							/* translators: 1: number of changes, 2: date */
							printf( esc_html__( 'Zmiany: %1$d · zapisano %2$s', 'gastroflowx-documents' ), (int) $info['edit_count'], esc_html( wp_date( 'j.m.Y H:i', $info['saved'] ) ) );
							?>
						</span>
					<?php else : ?>
						<span class="gfxdoc-pdf-status"><?php esc_html_e( 'Oryginał, bez zmian', 'gastroflowx-documents' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<p>
				<a href="<?php echo esc_url( self::editor_url( $post_id ) ); ?>" class="button button-primary gfxdoc-pdf-wide">
					✎ <?php esc_html_e( 'Edytuj PDF', 'gastroflowx-documents' ); ?>
				</a>
			</p>
			<p class="gfxdoc-pdf-row">
				<a href="<?php echo esc_url( self::file_url( $post_id, 'current' ) ); ?>" class="button" target="_blank" rel="noopener"><?php esc_html_e( 'Podgląd', 'gastroflowx-documents' ); ?></a>
				<a href="<?php echo esc_url( self::file_url( $post_id, 'current', true ) ); ?>" class="button"><?php esc_html_e( 'Pobierz', 'gastroflowx-documents' ); ?></a>
				<?php if ( $info['has_edits'] ) : ?>
					<a href="<?php echo esc_url( self::file_url( $post_id, 'original', true ) ); ?>" class="button" title="<?php esc_attr_e( 'Plik w wersji wgranej, bez zmian', 'gastroflowx-documents' ); ?>"><?php esc_html_e( 'Oryginał', 'gastroflowx-documents' ); ?></a>
				<?php endif; ?>
			</p>

			<p>
				<button type="button" class="button gfxdoc-pdf-wide gfxdoc-pdf-import"
					data-url="<?php echo esc_url( self::file_url( $post_id, 'current' ) ); ?>">
					⇩ <?php esc_html_e( 'Przenieś tekst do treści dokumentu', 'gastroflowx-documents' ); ?>
				</button>
			</p>
			<p class="description"><?php esc_html_e( 'Wyciąga tekst z aktualnej wersji PDF (nagłówki, akapity, listy, tabele) i wstawia go do edytora powyżej — dokument dostaje wtedy wygląd GastroFlowx i eksport „z edytora”.', 'gastroflowx-documents' ); ?></p>

			<fieldset class="gfxdoc-pdf-source">
				<legend><strong><?php esc_html_e( 'Przycisk „Pobierz PDF” zwraca:', 'gastroflowx-documents' ); ?></strong></legend>
				<label><input type="radio" name="gfxdoc_source" value="pdf" <?php checked( $source, 'pdf' ); ?>> <?php esc_html_e( 'wgrany plik PDF (z Twoimi zmianami)', 'gastroflowx-documents' ); ?></label><br>
				<label><input type="radio" name="gfxdoc_source" value="editor" <?php checked( $source, 'editor' ); ?>> <?php esc_html_e( 'dokument wygenerowany z treści edytora', 'gastroflowx-documents' ); ?></label>
				<p class="description"><?php esc_html_e( 'Zmiana zostanie zapisana po kliknięciu „Aktualizuj”.', 'gastroflowx-documents' ); ?></p>
			</fieldset>

			<p class="gfxdoc-pdf-row gfxdoc-pdf-row--danger">
				<label class="button-link">
					<?php esc_html_e( 'Zamień plik…', 'gastroflowx-documents' ); ?>
					<input type="file" accept="application/pdf,.pdf" class="gfxdoc-pdf-input gfxdoc-pdf-input--replace screen-reader-text">
				</label>
				<button type="button" class="button-link button-link-delete gfxdoc-pdf-delete"><?php esc_html_e( 'Usuń PDF', 'gastroflowx-documents' ); ?></button>
			</p>
			<div class="gfxdoc-pdf-progress" hidden><span class="spinner is-active"></span> <?php esc_html_e( 'Przesyłanie…', 'gastroflowx-documents' ); ?></div>
			<?php
		endif;

		return ob_get_clean();
	}

	public function save_source( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['gfxdoc_source_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gfxdoc_source_nonce'] ) ), 'gfxdoc_source_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['gfxdoc_source'] ) ) {
			return;
		}
		$source = 'pdf' === $_POST['gfxdoc_source'] ? 'pdf' : 'editor';
		update_post_meta( $post_id, GFXDoc_PDF_Storage::META_SOURCE, $source );
	}

	public function on_delete_post( $post_id ) {
		if ( GFXDoc_CPT::POST_TYPE === get_post_type( $post_id ) ) {
			GFXDoc_PDF_Storage::purge( $post_id );
		}
	}

	/**
	 * Scripts for the meta box (upload / delete) and the "import text into
	 * TinyMCE" feature, which needs pdf.js.
	 */
	public function enqueue_edit_screen( $hook ) {
		global $post_type;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || GFXDoc_CPT::POST_TYPE !== $post_type ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		wp_enqueue_style( 'gfxdoc-pdf-admin', GFXDOC_URL . 'assets/css/gfxdoc-pdf-admin.css', array(), GFXDOC_VERSION );
		wp_register_script( 'gfxdoc-pdfjs', GFXDOC_URL . 'assets/lib/pdfjs/pdf.min.js', array(), '3.11.174', true );
		wp_enqueue_script( 'gfxdoc-pdf-metabox', GFXDOC_URL . 'assets/js/gfxdoc-pdf-metabox.js', array( 'jquery', 'gfxdoc-pdfjs' ), GFXDOC_VERSION, true );
		wp_localize_script(
			'gfxdoc-pdf-metabox',
			'gfxdocPdfBox',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'postId'    => $post->ID,
				'nonce'     => wp_create_nonce( self::nonce_action( $post->ID ) ),
				'maxSize'   => wp_max_upload_size(),
				'workerSrc' => GFXDOC_URL . 'assets/lib/pdfjs/pdf.worker.min.js',
				'i18n'      => array(
					'notPdf'        => __( 'Wybierz plik PDF.', 'gastroflowx-documents' ),
					/* translators: %s: max upload size */
					'tooBig'        => sprintf( __( 'Plik jest za duży (maks. %s).', 'gastroflowx-documents' ), size_format( wp_max_upload_size() ) ),
					'confirmDelete' => __( 'Usunąć wgrany PDF razem ze wszystkimi zmianami? Tej operacji nie można cofnąć.', 'gastroflowx-documents' ),
					'confirmReplace'=> __( 'Zamienić plik? Obecny PDF i wszystkie wprowadzone w nim zmiany zostaną usunięte.', 'gastroflowx-documents' ),
					'error'         => __( 'Wystąpił błąd. Spróbuj ponownie.', 'gastroflowx-documents' ),
					'importing'     => __( 'Odczytywanie tekstu z PDF…', 'gastroflowx-documents' ),
					'noText'        => __( 'Ten PDF nie zawiera tekstu do odczytania (prawdopodobnie to skan lub obraz). Użyj „Edytuj PDF”, aby nanieść zmiany bezpośrednio na plik.', 'gastroflowx-documents' ),
					'importChoice'  => __( "Edytor zawiera już treść.\n\nOK — zastąp obecną treść tekstem z PDF\nAnuluj — dopisz tekst z PDF na końcu", 'gastroflowx-documents' ),
					'imported'      => __( 'Tekst został wstawiony do edytora. Sprawdź go i kliknij „Aktualizuj”, aby zapisać.', 'gastroflowx-documents' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * AJAX: upload / delete / save edits
	 * ---------------------------------------------------------------- */

	public function ajax_upload() {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( self::nonce_action( $post_id ) );
		$post = $this->require_document( $post_id );

		$file  = isset( $_FILES['pdf'] ) ? $_FILES['pdf'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
		$valid = $this->validate_pdf_upload( $file );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ), 400 );
		}

		$stored = GFXDoc_PDF_Storage::store( $file['tmp_name'] );
		if ( is_wp_error( $stored ) ) {
			wp_send_json_error( array( 'message' => $stored->get_error_message() ), 500 );
		}

		// Replacing: drop the old files + edits (they belong to the old PDF).
		GFXDoc_PDF_Storage::purge( $post->ID );

		$name = sanitize_file_name( wp_unslash( $file['name'] ) );
		update_post_meta( $post->ID, GFXDoc_PDF_Storage::META_ORIGINAL, $stored );
		update_post_meta( $post->ID, GFXDoc_PDF_Storage::META_NAME, $name );
		update_post_meta( $post->ID, GFXDoc_PDF_Storage::META_UPLOADED, time() );
		update_post_meta( $post->ID, GFXDoc_PDF_Storage::META_SOURCE, 'pdf' );

		$title = '';
		// A brand-new, never-saved document is an auto-draft, which WordPress
		// deletes after a week. Turn it into a real draft so the file stays.
		if ( 'auto-draft' === $post->post_status ) {
			$title = $post->post_title ? $post->post_title : preg_replace( '/[-_]+/', ' ', pathinfo( $name, PATHINFO_FILENAME ) );
			wp_update_post(
				array(
					'ID'          => $post->ID,
					'post_status' => 'draft',
					'post_title'  => $title,
				)
			);
		}

		wp_send_json_success(
			array(
				'html'  => $this->meta_box_inner( $post->ID ),
				'title' => $title,
			)
		);
	}

	public function ajax_delete() {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( self::nonce_action( $post_id ) );
		$post = $this->require_document( $post_id );

		GFXDoc_PDF_Storage::purge( $post->ID );
		wp_send_json_success( array( 'html' => $this->meta_box_inner( $post->ID ) ) );
	}

	/**
	 * Saves the editor state: the JSON list of objects plus the flattened
	 * PDF the browser produced from (original + objects). An empty list
	 * means "back to the original".
	 */
	public function ajax_save() {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( self::nonce_action( $post_id ) );
		$post = $this->require_document( $post_id );

		if ( ! GFXDoc_PDF_Storage::path( get_post_meta( $post->ID, GFXDoc_PDF_Storage::META_ORIGINAL, true ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Ten dokument nie ma już wgranego PDF.', 'gastroflowx-documents' ) ), 409 );
		}

		$raw  = isset( $_POST['edits'] ) ? wp_unslash( $_POST['edits'] ) : '{}'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, validated below.
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Nieprawidłowe dane edytora.', 'gastroflowx-documents' ) ), 400 );
		}
		$objects = isset( $data['objects'] ) && is_array( $data['objects'] ) ? $data['objects'] : array();
		if ( count( $objects ) > self::MAX_EDITS ) {
			wp_send_json_error( array( 'message' => __( 'Za dużo zmian w jednym dokumencie.', 'gastroflowx-documents' ) ), 400 );
		}
		$objects = array_values( array_filter( array_map( array( $this, 'sanitize_edit' ), $objects ) ) );
		$pages   = $this->sanitize_pages( isset( $data['pages'] ) ? $data['pages'] : null );
		if ( false === $pages ) {
			wp_send_json_error( array( 'message' => __( 'Nieprawidłowa lista stron.', 'gastroflowx-documents' ) ), 400 );
		}
		$edits = array(
			'version' => 2,
			'objects' => $objects,
			'pages'   => $pages,
		);
		$count = count( $objects ) + ( null === $pages ? 0 : 1 );

		$old_current = get_post_meta( $post->ID, GFXDoc_PDF_Storage::META_CURRENT, true );

		if ( ! $count ) {
			GFXDoc_PDF_Storage::delete_file( $old_current );
			delete_post_meta( $post->ID, GFXDoc_PDF_Storage::META_CURRENT );
			delete_post_meta( $post->ID, GFXDoc_PDF_Storage::META_EDITS );
			update_post_meta( $post->ID, GFXDoc_PDF_Storage::META_SAVED, time() );
			wp_send_json_success( array( 'message' => __( 'Przywrócono oryginał — brak zmian.', 'gastroflowx-documents' ) ) );
		}

		$file  = isset( $_FILES['pdf'] ) ? $_FILES['pdf'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
		$valid = $this->validate_pdf_upload( $file, false );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ), 400 );
		}
		$stored = GFXDoc_PDF_Storage::store( $file['tmp_name'] );
		if ( is_wp_error( $stored ) ) {
			wp_send_json_error( array( 'message' => $stored->get_error_message() ), 500 );
		}

		update_post_meta( $post->ID, GFXDoc_PDF_Storage::META_CURRENT, $stored );
		update_post_meta( $post->ID, GFXDoc_PDF_Storage::META_EDITS, wp_slash( wp_json_encode( $edits ) ) );
		update_post_meta( $post->ID, GFXDoc_PDF_Storage::META_SAVED, time() );
		GFXDoc_PDF_Storage::delete_file( $old_current );

		wp_send_json_success(
			array(
				/* translators: %d: number of changes */
				'message' => sprintf( _n( 'Zapisano PDF (%d zmiana).', 'Zapisano PDF (zmian: %d).', $count, 'gastroflowx-documents' ), $count ),
			)
		);
	}

	/**
	 * Validates the page list: [{src, rot}] with unique source pages.
	 *
	 * @return array|null|false Null = pages untouched, false = invalid.
	 */
	private function sanitize_pages( $pages ) {
		if ( null === $pages ) {
			return null;
		}
		if ( ! is_array( $pages ) || ! $pages || count( $pages ) > 5000 ) {
			return false;
		}
		$seen  = array();
		$clean = array();
		foreach ( $pages as $p ) {
			if ( ! is_array( $p ) || ! isset( $p['src'] ) ) {
				return false;
			}
			$src = (int) $p['src'];
			$rot = isset( $p['rot'] ) ? ( ( (int) $p['rot'] % 360 ) + 360 ) % 360 : 0;
			if ( $src < 0 || isset( $seen[ $src ] ) || $rot % 90 ) {
				return false;
			}
			$seen[ $src ] = true;
			$clean[]      = array(
				'src' => $src,
				'rot' => $rot,
			);
		}
		return $clean;
	}

	/**
	 * Whitelists the fields of one editor object. Anything unknown is
	 * dropped, so the stored JSON is always in a shape the editor expects.
	 */
	public function sanitize_edit( $o ) {
		if ( ! is_array( $o ) || empty( $o['type'] ) || ! in_array( $o['type'], array( 'text', 'rect', 'image' ), true ) ) {
			return null;
		}
		$num   = function ( $v, $min, $max ) {
			return max( $min, min( $max, round( (float) $v, 2 ) ) );
		};
		$color = function ( $v, $default ) {
			return ( is_string( $v ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $v ) ) ? strtolower( $v ) : $default;
		};

		$clean = array(
			'type' => $o['type'],
			'page' => (int) $num( isset( $o['page'] ) ? $o['page'] : 0, 0, 9999 ),
			'x'    => $num( isset( $o['x'] ) ? $o['x'] : 0, -5000, 20000 ),
			'y'    => $num( isset( $o['y'] ) ? $o['y'] : 0, -5000, 20000 ),
			'w'    => $num( isset( $o['w'] ) ? $o['w'] : 10, 1, 20000 ),
			'h'    => $num( isset( $o['h'] ) ? $o['h'] : 10, 1, 20000 ),
		);
		$rot = isset( $o['rot'] ) ? ( ( (int) $o['rot'] % 360 ) + 360 ) % 360 : 0;
		if ( $rot && 0 === $rot % 90 ) {
			$clean['rot'] = $rot;
		}

		switch ( $o['type'] ) {
			case 'text':
				$text = isset( $o['text'] ) ? (string) $o['text'] : '';
				$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
				if ( '' === trim( $text ) ) {
					return null;
				}
				$clean['text']  = mb_substr( wp_check_invalid_utf8( $text ), 0, 20000 );
				$clean['size']  = $num( isset( $o['size'] ) ? $o['size'] : 11, 4, 144 );
				$clean['color'] = $color( isset( $o['color'] ) ? $o['color'] : '', '#000000' );
				$clean['bold']  = ! empty( $o['bold'] );
				break;

			case 'rect':
				$clean['fill']    = $color( isset( $o['fill'] ) ? $o['fill'] : '', '#ffffff' );
				$clean['opacity'] = $num( isset( $o['opacity'] ) ? $o['opacity'] : 1, 0.05, 1 );
				$clean['kind']    = ( isset( $o['kind'] ) && in_array( $o['kind'], array( 'cover', 'highlight', 'box' ), true ) ) ? $o['kind'] : 'cover';
				if ( 'cover' === $clean['kind'] ) {
					// true redaction (text removed from the file) unless switched off
					$clean['redact'] = ! ( isset( $o['redact'] ) && false === $o['redact'] );
				}
				break;

			case 'image':
				$src = isset( $o['src'] ) ? (string) $o['src'] : '';
				if ( ! preg_match( '#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $src ) || strlen( $src ) > 8 * MB_IN_BYTES ) {
					return null;
				}
				$clean['src'] = $src;
				break;
		}
		return $clean;
	}

	/* ------------------------------------------------------------------
	 * Serving files
	 * ---------------------------------------------------------------- */

	public function serve_file() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( self::nonce_action( $post_id ) );
		$post = $this->require_document( $post_id, false );

		$version = ( isset( $_GET['v'] ) && 'original' === $_GET['v'] ) ? 'original' : 'current';
		$path    = 'original' === $version
			? GFXDoc_PDF_Storage::path( get_post_meta( $post->ID, GFXDoc_PDF_Storage::META_ORIGINAL, true ) )
			: GFXDoc_PDF_Storage::effective_path( $post->ID );

		if ( ! $path ) {
			wp_die( esc_html__( 'Ten dokument nie ma wgranego pliku PDF.', 'gastroflowx-documents' ), '', array( 'response' => 404 ) );
		}

		$base = sanitize_title( get_the_title( $post ) );
		$base = $base ? $base : 'dokument';
		if ( 'original' === $version ) {
			$base .= '-oryginal';
		}
		GFXDoc_PDF_Storage::stream( $path, $base . '.pdf', empty( $_GET['dl'] ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Documents list
	 * ---------------------------------------------------------------- */

	public function row_action( $actions, $post ) {
		if ( GFXDoc_CPT::POST_TYPE !== $post->post_type || ! current_user_can( GFXDOC_CAP ) ) {
			return $actions;
		}
		if ( GFXDoc_PDF_Storage::info( $post->ID ) ) {
			$actions['gfxdoc_pdf_edit'] = '<a href="' . esc_url( self::editor_url( $post->ID ) ) . '">' . esc_html__( 'Edytor PDF', 'gastroflowx-documents' ) . '</a>';
		}
		return $actions;
	}

	/* ------------------------------------------------------------------
	 * The PDF editor page
	 * ---------------------------------------------------------------- */

	public function register_editor_page() {
		$this->editor_hook = add_submenu_page(
			'edit.php?post_type=' . GFXDoc_CPT::POST_TYPE,
			__( 'Edytor PDF', 'gastroflowx-documents' ),
			__( 'Edytor PDF', 'gastroflowx-documents' ),
			GFXDOC_CAP,
			self::EDITOR_SLUG,
			array( $this, 'render_editor_page' )
		);
		add_action( 'load-' . $this->editor_hook, array( $this, 'load_editor_page' ) );
	}

	/**
	 * The page is reachable only from a document, so hide it from the menu.
	 * Done in admin_head (menu not rendered yet, page title already set) so
	 * WordPress still treats the page as registered.
	 */
	public function hide_editor_menu_item() {
		remove_submenu_page( 'edit.php?post_type=' . GFXDoc_CPT::POST_TYPE, self::EDITOR_SLUG );
	}

	public function load_editor_page() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$post    = $this->require_document( $post_id, false );

		if ( ! GFXDoc_PDF_Storage::info( $post->ID ) ) {
			wp_safe_redirect( get_edit_post_link( $post->ID, 'url' ) );
			exit;
		}

		add_filter(
			'admin_body_class',
			function ( $classes ) {
				return $classes . ' gfxdoc-pdf-editor-page folded';
			}
		);
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor' ) );
	}

	public function enqueue_editor() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$post    = get_post( $post_id );

		wp_enqueue_style( 'gfxdoc-pdf-editor', GFXDOC_URL . 'assets/css/gfxdoc-pdf-editor.css', array(), GFXDOC_VERSION );

		wp_register_script( 'gfxdoc-pdfjs', GFXDOC_URL . 'assets/lib/pdfjs/pdf.min.js', array(), '3.11.174', true );
		wp_register_script( 'gfxdoc-pdf-lib', GFXDOC_URL . 'assets/lib/pdf-lib/pdf-lib.min.js', array(), '1.17.1', true );
		wp_register_script( 'gfxdoc-fontkit', GFXDOC_URL . 'assets/lib/pdf-lib/fontkit.umd.min.js', array(), '1.1.1', true );
		wp_register_script( 'gfxdoc-pdf-redact', GFXDOC_URL . 'assets/js/gfxdoc-pdf-redact.js', array(), GFXDOC_VERSION, true );
		wp_register_script( 'gfxdoc-pdf-flatten', GFXDOC_URL . 'assets/js/gfxdoc-pdf-flatten.js', array( 'gfxdoc-pdf-redact' ), GFXDOC_VERSION, true );
		wp_enqueue_script( 'gfxdoc-pdf-editor', GFXDOC_URL . 'assets/js/gfxdoc-pdf-editor.js', array( 'gfxdoc-pdfjs', 'gfxdoc-pdf-lib', 'gfxdoc-fontkit', 'gfxdoc-pdf-flatten' ), GFXDOC_VERSION, true );

		$fonts = GFXDOC_URL . 'vendor/dompdf/lib/fonts/';
		wp_localize_script(
			'gfxdoc-pdf-editor',
			'gfxdocPdfEditor',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'postId'      => $post->ID,
				'nonce'       => wp_create_nonce( self::nonce_action( $post->ID ) ),
				'originalUrl' => self::file_url( $post->ID, 'original' ),
				'downloadUrl' => self::file_url( $post->ID, 'current', true ),
				'editUrl'     => get_edit_post_link( $post->ID, 'url' ),
				'workerSrc'   => GFXDOC_URL . 'assets/lib/pdfjs/pdf.worker.min.js',
				'fontRegular' => $fonts . 'DejaVuSans.ttf',
				'fontBold'    => $fonts . 'DejaVuSans-Bold.ttf',
				'maxSize'     => wp_max_upload_size(),
			)
		);
		// Saved objects go in as raw JSON rather than through
		// wp_localize_script(), which would HTML-decode the text people typed.
		wp_add_inline_script(
			'gfxdoc-pdf-editor',
			'window.gfxdocPdfEdits = ' . wp_json_encode( GFXDoc_PDF_Storage::edits( $post->ID ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);
	}

	public function render_editor_page() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$post    = get_post( $post_id );
		$info    = GFXDoc_PDF_Storage::info( $post_id );
		?>
		<div class="wrap gfxdoc-pe">
			<div class="gfxdoc-pe__head">
				<a href="<?php echo esc_url( get_edit_post_link( $post->ID, 'url' ) ); ?>" class="gfxdoc-pe__back">← <?php esc_html_e( 'Wróć do dokumentu', 'gastroflowx-documents' ); ?></a>
				<h1><?php echo esc_html( get_the_title( $post ) ? get_the_title( $post ) : __( '(bez tytułu)', 'gastroflowx-documents' ) ); ?> <span class="gfxdoc-pe__file"><?php echo esc_html( $info['name'] ); ?></span></h1>
			</div>

			<div class="gfxdoc-pe__toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Narzędzia edytora PDF', 'gastroflowx-documents' ); ?>">
				<div class="gfxdoc-pe__group" data-group="tools">
					<button type="button" class="gfxdoc-pe__tool is-active" data-tool="select" title="<?php esc_attr_e( 'Zaznacz / przesuń (V)', 'gastroflowx-documents' ); ?>">⬚ <?php esc_html_e( 'Zaznacz', 'gastroflowx-documents' ); ?></button>
					<button type="button" class="gfxdoc-pe__tool" data-tool="edittext" title="<?php esc_attr_e( 'Kliknij istniejący tekst w PDF, aby go zmienić (E)', 'gastroflowx-documents' ); ?>">✎ <?php esc_html_e( 'Zmień tekst', 'gastroflowx-documents' ); ?></button>
					<button type="button" class="gfxdoc-pe__tool" data-tool="text" title="<?php esc_attr_e( 'Kliknij na stronie, aby dodać nowy tekst (T)', 'gastroflowx-documents' ); ?>">T <?php esc_html_e( 'Dodaj tekst', 'gastroflowx-documents' ); ?></button>
					<button type="button" class="gfxdoc-pe__tool" data-tool="cover" title="<?php esc_attr_e( 'Przeciągnij, aby zakryć fragment (W). Tekst pod zakryciem zostanie trwale usunięty z pliku przy zapisie.', 'gastroflowx-documents' ); ?>">▭ <?php esc_html_e( 'Zakryj', 'gastroflowx-documents' ); ?></button>
					<button type="button" class="gfxdoc-pe__tool" data-tool="highlight" title="<?php esc_attr_e( 'Przeciągnij, aby zaznaczyć fragment markerem (H)', 'gastroflowx-documents' ); ?>">▮ <?php esc_html_e( 'Wyróżnij', 'gastroflowx-documents' ); ?></button>
					<label class="gfxdoc-pe__tool" title="<?php esc_attr_e( 'Wstaw obraz PNG/JPG — np. skan podpisu, pieczątkę, logo', 'gastroflowx-documents' ); ?>">
						🖼 <?php esc_html_e( 'Obraz / podpis', 'gastroflowx-documents' ); ?>
						<input type="file" accept="image/png,image/jpeg" class="gfxdoc-pe__image-input screen-reader-text">
					</label>
				</div>

				<div class="gfxdoc-pe__group gfxdoc-pe__group--right">
					<button type="button" class="button" data-action="undo" title="<?php esc_attr_e( 'Cofnij (Ctrl+Z)', 'gastroflowx-documents' ); ?>" disabled>↶</button>
					<button type="button" class="button" data-action="redo" title="<?php esc_attr_e( 'Ponów (Ctrl+Y)', 'gastroflowx-documents' ); ?>" disabled>↷</button>
					<select data-action="zoom" aria-label="<?php esc_attr_e( 'Powiększenie', 'gastroflowx-documents' ); ?>">
						<option value="0.75">75%</option>
						<option value="1">100%</option>
						<option value="1.25" selected>125%</option>
						<option value="1.5">150%</option>
						<option value="2">200%</option>
					</select>
					<button type="button" class="button" data-action="reset" title="<?php esc_attr_e( 'Usuń wszystkie zmiany i wróć do oryginału', 'gastroflowx-documents' ); ?>"><?php esc_html_e( 'Oryginał', 'gastroflowx-documents' ); ?></button>
					<button type="button" class="button button-primary" data-action="save">💾 <?php esc_html_e( 'Zapisz PDF', 'gastroflowx-documents' ); ?></button>
					<a class="button" data-action="download" href="<?php echo esc_url( self::file_url( $post->ID, 'current', true ) ); ?>">⬇ <?php esc_html_e( 'Pobierz', 'gastroflowx-documents' ); ?></a>
				</div>
				<div class="gfxdoc-pe__propsrow">
					<span class="gfxdoc-pe__propshint"><?php esc_html_e( 'Zaznacz element, aby zmienić jego wygląd.', 'gastroflowx-documents' ); ?></span>
					<div class="gfxdoc-pe__group gfxdoc-pe__props" data-props="text" hidden>
						<label><?php esc_html_e( 'Rozmiar', 'gastroflowx-documents' ); ?> <input type="number" min="4" max="144" step="0.5" data-prop="size"></label>
						<label><?php esc_html_e( 'Kolor', 'gastroflowx-documents' ); ?> <input type="color" data-prop="color"></label>
						<button type="button" class="gfxdoc-pe__toggle" data-prop="bold" aria-pressed="false"><strong>B</strong></button>
					</div>
					<div class="gfxdoc-pe__group gfxdoc-pe__props" data-props="rect" hidden>
						<label><?php esc_html_e( 'Kolor', 'gastroflowx-documents' ); ?> <input type="color" data-prop="fill"></label>
						<label><?php esc_html_e( 'Krycie', 'gastroflowx-documents' ); ?> <input type="range" min="0.05" max="1" step="0.05" data-prop="opacity"></label>
						<label class="gfxdoc-pe__redact" title="<?php esc_attr_e( 'Włączone: tekst pod zakryciem jest wycinany z pliku (nie da się go skopiować ani odzyskać). Wyłączone: tylko zasłonięcie.', 'gastroflowx-documents' ); ?>"><input type="checkbox" data-prop="redact"> <?php esc_html_e( 'Usuń tekst spod spodu', 'gastroflowx-documents' ); ?></label>
					</div>
					<div class="gfxdoc-pe__group gfxdoc-pe__props" data-props="any" hidden>
						<button type="button" class="button-link button-link-delete" data-action="delete" title="<?php esc_attr_e( 'Usuń zaznaczony element (Delete)', 'gastroflowx-documents' ); ?>">🗑 <?php esc_html_e( 'Usuń', 'gastroflowx-documents' ); ?></button>
					</div>
				</div>
			</div>

			<div class="gfxdoc-pe__status" aria-live="polite"></div>

			<div class="gfxdoc-pe__viewport">
				<div class="gfxdoc-pe__pages">
					<p class="gfxdoc-pe__loading"><span class="spinner is-active"></span> <?php esc_html_e( 'Wczytywanie PDF…', 'gastroflowx-documents' ); ?></p>
				</div>
			</div>

			<p class="description gfxdoc-pe__help">
				<?php esc_html_e( 'Wskazówki: „Zmień tekst” zakrywa kliknięty fragment i wstawia w jego miejsce edytowalną kopię. Dwuklik na tekście — edycja, przeciąganie — przesuwanie, róg — zmiana rozmiaru. Przyciski nad każdą stroną: przesuń wyżej/niżej, obróć, usuń stronę. Zmiany nakładane są zawsze na nienaruszony oryginał, więc możesz do nich wracać i poprawiać je w dowolnym momencie. Przy zapisie tekst pod zakryciami (także przy „Zmień tekst”) jest trwale wycinany z pliku; jeśli na jakiejś stronie nie da się tego zrobić bezpiecznie, strona zostaje zapisana jako obraz. Grafika i obrazy pod zakryciem są tylko zasłaniane.', 'gastroflowx-documents' ); ?>
			</p>
		</div>
		<?php
	}
}
