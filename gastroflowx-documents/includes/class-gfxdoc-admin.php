<?php
/**
 * wp-admin glue: the "Pobierz PDF" button on the edit screen and in the
 * documents list, a rendered preview meta box, and a couple of safety
 * filters that keep this CPT on the Classic editor even on setups that try
 * to force Gutenberg everywhere.
 *
 * @package GastroFlowx_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFXDoc_Admin {

	public function __construct() {
		add_filter( 'use_block_editor_for_post_type', array( $this, 'force_classic_editor' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_preview_meta_box' ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'add_pdf_button' ) );
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_head-edit.php', array( $this, 'list_table_help' ) );
	}

	public function force_classic_editor( $use_block_editor, $post_type ) {
		if ( 'gfx_document' === $post_type ) {
			return false;
		}
		return $use_block_editor;
	}

	/**
	 * Shows how the document currently looks, using the exact stylesheet
	 * the PDF export uses. Reflects the last *saved* version — update the
	 * post, then scroll down, to check a change before generating a PDF.
	 */
	public function add_preview_meta_box() {
		add_meta_box(
			'gfxdoc_preview',
			__( 'Podgląd wyglądu (jak w PDF)', 'gastroflowx-documents' ),
			array( $this, 'render_preview_meta_box' ),
			'gfx_document',
			'normal',
			'low'
		);
	}

	public function render_preview_meta_box( $post ) {
		if ( 'pdf' === GFXDoc_PDF_Storage::source( $post->ID ) && GFXDoc_PDF_Storage::effective_path( $post->ID ) ) {
			echo '<p class="description">' . esc_html__( 'Ten dokument korzysta z wgranego pliku PDF — poniżej jego aktualna wersja (z zapisanymi zmianami). Aby eksportować treść edytora, przełącz „Przycisk »Pobierz PDF« zwraca” w polu „Plik PDF”.', 'gastroflowx-documents' ) . '</p>';
			echo '<iframe title="' . esc_attr__( 'Podgląd PDF', 'gastroflowx-documents' ) . '" src="' . esc_url( GFXDoc_PDF_Upload::file_url( $post->ID, 'current' ) ) . '" style="width:100%;height:760px;border:1px solid #dcdcde;background:#f6f7f7;"></iframe>';
			return;
		}
		if ( empty( $post->post_content ) ) {
			echo '<p>' . esc_html__( 'Zapisz dokument, aby zobaczyć podgląd.', 'gastroflowx-documents' ) . '</p>';
			return;
		}
		wp_enqueue_style( 'gfxdoc-style', GFXDOC_URL . 'assets/css/gfxdoc-style.css', array(), GFXDOC_VERSION );
		echo '<div class="gfxdoc-content" style="border:1px solid #dcdcde;padding:20px;background:#fff;">';
		echo '<h1>' . esc_html( get_the_title( $post ) ) . '</h1>';
		echo do_shortcode( $post->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput -- trusted admin-authored content, mirrors the_content().
		echo '</div>';
		echo '<p class="description">' . esc_html__( '„Podział strony” pokazuje się tu jako przerywana linia — w PDF stanie się rzeczywistym podziałem strony.', 'gastroflowx-documents' ) . '</p>';
	}

	/**
	 * "Pobierz PDF" button next to Zapisz/Aktualizuj, only once the
	 * document has actually been saved (dompdf needs a real post to read).
	 */
	public function add_pdf_button( $post ) {
		if ( 'gfx_document' !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! current_user_can( GFXDOC_CAP ) ) {
			return;
		}
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=gfxdoc_export_pdf&post=' . $post->ID ),
			'gfxdoc_export_pdf_' . $post->ID
		);
		?>
		<div class="misc-pub-section" style="border-top:1px solid #dcdcde;">
			<a href="<?php echo esc_url( $url ); ?>" class="button button-secondary" style="width:100%;text-align:center;" target="_blank" rel="noopener">
				⬇ <?php esc_html_e( 'Pobierz PDF', 'gastroflowx-documents' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Adds the same download link as a row action in the documents list
	 * (Dokumenty GastroFlowx → lista), so a quick re-export doesn't require
	 * opening the editor first.
	 */
	public function add_row_action( $actions, $post ) {
		if ( 'gfx_document' !== $post->post_type || ! current_user_can( GFXDOC_CAP ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=gfxdoc_export_pdf&post=' . $post->ID ),
			'gfxdoc_export_pdf_' . $post->ID
		);
		$actions['gfxdoc_pdf'] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Pobierz PDF', 'gastroflowx-documents' ) . '</a>';
		return $actions;
	}

	public function list_table_help() {
		$screen = get_current_screen();
		if ( ! $screen || 'gfx_document' !== $screen->post_type ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'gfxdoc-help',
				'title'   => __( 'Jak edytować dokumenty', 'gastroflowx-documents' ),
				'content' => '<p>' . esc_html__( 'Użyj przycisku „GastroFlowx” w pasku edytora, aby wstawić kolorową ramkę, plakietkę, linię do wypełnienia albo tabelę na podpisy — bez pisania kodu. Po zapisaniu dokumentu przewiń w dół, aby zobaczyć podgląd, i użyj przycisku „Pobierz PDF” po prawej stronie.', 'gastroflowx-documents' ) . '</p><p>' . esc_html__( 'Masz gotowy plik PDF? Wgraj go w polu „Plik PDF” (prawa kolumna edytora). Następnie kliknij „Edytuj PDF”, aby zmienić tekst, zakryć fragmenty, dodać wyróżnienia lub podpis bezpośrednio w pliku — albo „Przenieś tekst do treści dokumentu”, aby dalej edytować go jak zwykły dokument GastroFlowx.', 'gastroflowx-documents' ) . '</p>',
			)
		);
	}
}
