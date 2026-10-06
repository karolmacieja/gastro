<?php
/**
 * Renders a gfx_document post to PDF using the bundled dompdf library
 * (no Composer / server shell access required — see vendor/autoload.php).
 *
 * @package GastroFlowx_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFXDoc_PDF {

	public function __construct() {
		add_action( 'admin_post_gfxdoc_export_pdf', array( $this, 'handle_export' ) );
	}

	/**
	 * Handles the "Pobierz PDF" button/link:
	 * admin-post.php?action=gfxdoc_export_pdf&post=123&_wpnonce=...
	 */
	public function handle_export() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		if ( ! $post_id || ! current_user_can( GFXDOC_CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień do wygenerowania tego dokumentu.', 'gastroflowx-documents' ), 403 );
		}
		check_admin_referer( 'gfxdoc_export_pdf_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post || 'gfx_document' !== $post->post_type ) {
			wp_die( esc_html__( 'Nie znaleziono dokumentu.', 'gastroflowx-documents' ), 404 );
		}

		// Document built from an uploaded PDF (see GFXDoc_PDF_Upload): hand
		// out that file — with the admin's edits applied — instead of
		// rendering the editor content through dompdf.
		if ( 'pdf' === GFXDoc_PDF_Storage::source( $post->ID ) ) {
			$path = GFXDoc_PDF_Storage::effective_path( $post->ID );
			if ( $path ) {
				$name = sanitize_title( get_the_title( $post ) );
				GFXDoc_PDF_Storage::stream( $path, ( $name ? $name : 'dokument' ) . '.pdf' );
				exit;
			}
		}

		$this->stream_pdf( $post );
		exit;
	}

	/**
	 * Builds the printable HTML for a document and streams it as a PDF
	 * download. Kept public + given a $post object (rather than only an ID)
	 * so other parts of the plugin — or a future REST endpoint — can reuse
	 * it without another database round-trip.
	 */
	public function stream_pdf( WP_Post $post ) {
		require_once GFXDOC_DIR . 'vendor/autoload.php';

		if ( ! defined( 'GFXDOC_RENDERING_PDF' ) ) {
			define( 'GFXDOC_RENDERING_PDF', true ); // lets [gfxpagebreak] switch behaviour
		}

		$body = do_shortcode( $post->post_content );
		$css  = file_get_contents( GFXDOC_DIR . 'assets/css/gfxdoc-style.css' );

		$html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
			. '<style>@page { margin: 18mm 16mm; } body{margin:0;} ' . $css . '</style>'
			. '</head><body><div class="gfxdoc-content">'
			. '<h1>' . esc_html( get_the_title( $post ) ) . '</h1>'
			. $body
			. '</div></body></html>';

		$options = new \Dompdf\Options();
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'isRemoteEnabled', false ); // no external assets — keeps this safe & fast
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'chroot', GFXDOC_DIR ); // dompdf refuses local paths outside this on principle

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		$filename = sanitize_title( get_the_title( $post ) ) . '.pdf';

		// Clean any stray output before headers (a common cause of "corrupt
		// PDF" reports when a theme/plugin has already echoed a notice).
		if ( ob_get_length() ) {
			ob_clean();
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $dompdf->output(); // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF stream.
	}
}
