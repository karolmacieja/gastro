<?php
/**
 * Shortcodes that reproduce the GastroFlowx document design (colored info
 * boxes, small pill badges, dotted fill-in lines, bordered field tables and
 * a ready-made two-column signature table) so editors never have to write
 * raw HTML/CSS to get the same look as the original PDF templates.
 *
 * These shortcodes are expanded identically for the on-screen preview and
 * for the PDF export (see class-gfxdoc-pdf.php), so what you see in the
 * preview is what ends up in the PDF.
 *
 * @package GastroFlowx_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFXDoc_Shortcodes {

	public function __construct() {
		add_shortcode( 'gfxbox', array( $this, 'box' ) );
		add_shortcode( 'gfxbadge', array( $this, 'badge' ) );
		add_shortcode( 'gfxfillin', array( $this, 'fillin' ) );
		add_shortcode( 'gfxnote', array( $this, 'note' ) );
		add_shortcode( 'gfxsigtable', array( $this, 'sigtable' ) );
		add_shortcode( 'gfxfieldtable', array( $this, 'fieldtable' ) );
		add_shortcode( 'gfxfield', array( $this, 'field_row' ) ); // used only inside gfxfieldtable
		add_shortcode( 'gfxpagebreak', array( $this, 'pagebreak' ) );

		// Front-end/admin preview needs the shared stylesheet; PDF export
		// inlines the same file itself (see class-gfxdoc-pdf.php).
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_preview_style' ) );
	}

	public function enqueue_preview_style( $hook ) {
		global $post_type;
		if ( 'gfx_document' !== $post_type ) {
			return;
		}
		wp_enqueue_style( 'gfxdoc-style', GFXDOC_URL . 'assets/css/gfxdoc-style.css', array(), GFXDOC_VERSION );
	}

	/**
	 * [gfxbox color="blue|yellow|green|red|fill|muted|party"]...[/gfxbox]
	 */
	public function box( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'color' => 'blue' ), $atts, 'gfxbox' );
		$color = sanitize_html_class( $atts['color'] );
		$content = do_shortcode( $content );
		return '<div class="gfxdoc-box gfxdoc-box--' . esc_attr( $color ) . '">' . wpautop( trim( $content ) ) . '</div>';
	}

	/**
	 * [gfxbadge]tekst[/gfxbadge]
	 */
	public function badge( $atts, $content = '' ) {
		return '<span class="gfxdoc-badge">' . esc_html( trim( wp_strip_all_tags( $content ) ) ) . '</span>';
	}

	/**
	 * [gfxfillin] — an inline dotted line for hand-filled data, e.g. names,
	 * addresses, dates. Renders inline so it can sit inside a sentence.
	 */
	public function fillin( $atts ) {
		$atts = shortcode_atts( array( 'width' => '220' ), $atts );
		return '<span class="gfxdoc-fillin" style="display:inline-block;min-width:' . intval( $atts['width'] ) . 'px;">&nbsp;</span>';
	}

	/**
	 * [gfxnote]...[/gfxnote] — small muted footer-style note.
	 */
	public function note( $atts, $content = '' ) {
		return '<p class="gfxdoc-note">' . wp_kses_post( trim( $content ) ) . '</p>';
	}

	/**
	 * [gfxsigtable label1="Podpis Pracodawcy" label2="Podpis Administratora"]
	 * Ready-made two-column signature table with a blank row to sign in.
	 */
	public function sigtable( $atts ) {
		$atts = shortcode_atts(
			array(
				'label1' => __( 'Podpis', 'gastroflowx-documents' ),
				'label2' => __( 'Podpis', 'gastroflowx-documents' ),
			),
			$atts,
			'gfxsigtable'
		);
		ob_start();
		?>
		<table class="gfxdoc-table gfxdoc-sigtable">
			<tr>
				<td style="height:60px;">&nbsp;</td>
				<td style="height:60px;">&nbsp;</td>
			</tr>
			<tr>
				<td><?php echo esc_html( $atts['label1'] ); ?></td>
				<td><?php echo esc_html( $atts['label2'] ); ?></td>
			</tr>
		</table>
		<?php
		return ob_get_clean();
	}

	/**
	 * [gfxfieldtable]
	 *   [gfxfield label="Imię i nazwisko"][/gfxfield]
	 *   [gfxfield label="Adres"][/gfxfield]
	 * [/gfxfieldtable]
	 * A bordered, two-column "label | dotted fill-in line" table — used for
	 * the "dane do wypełnienia" blocks (parties, addresses, dates…).
	 */
	public function fieldtable( $atts, $content = '' ) {
		$rows = do_shortcode( $content );
		return '<table class="gfxdoc-table gfxdoc-fieldtable">' . $rows . '</table>';
	}

	public function field_row( $atts ) {
		$atts = shortcode_atts( array( 'label' => '' ), $atts, 'gfxfield' );
		return '<tr><td class="gfxdoc-fieldtable__label">' . esc_html( $atts['label'] ) . '</td><td class="gfxdoc-fieldtable__value">' . $this->fillin( array( 'width' => '260' ) ) . '</td></tr>';
	}

	/**
	 * [gfxpagebreak] — forces a new page when exported to PDF. Rendered as
	 * an unobtrusive divider in the on-screen preview (there are no "pages"
	 * on screen), so editors can still see where it is.
	 */
	public function pagebreak( $atts ) {
		if ( defined( 'GFXDOC_RENDERING_PDF' ) && GFXDOC_RENDERING_PDF ) {
			return '<div style="page-break-before:always;"></div>';
		}
		return '<hr class="gfxdoc-pagebreak-marker" />';
	}
}
