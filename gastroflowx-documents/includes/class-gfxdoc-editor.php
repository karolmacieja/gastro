<?php
/**
 * Adds a "GastroFlowx" dropdown button to the Classic editor toolbar so
 * editors can insert a colored box, a badge, a fill-in line, a field table
 * or the signature table without typing shortcodes from memory.
 *
 * The gfx_document post type is deliberately kept on the Classic editor
 * (show_in_rest = false in the CPT registration) specifically so this
 * toolbar button is available — it has no equivalent in the block editor.
 *
 * @package GastroFlowx_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFXDoc_Editor {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'mce_external_plugins', array( $this, 'register_tinymce_plugin' ) );
		add_filter( 'mce_buttons', array( $this, 'register_tinymce_button' ) );
	}

	private function is_document_editor_screen() {
		global $post_type;
		return 'gfx_document' === $post_type;
	}

	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $this->is_document_editor_screen() ) {
			return;
		}
		// Quicktags button: works in the "Text" tab too, and as a fallback
		// if TinyMCE is disabled for a user.
		wp_enqueue_script( 'gfxdoc-quicktags', GFXDOC_URL . 'assets/js/gfxdoc-quicktags.js', array( 'quicktags' ), GFXDOC_VERSION, true );
	}

	public function register_tinymce_plugin( $plugins ) {
		if ( $this->is_document_editor_screen() && current_user_can( GFXDOC_CAP ) && get_user_option( 'rich_editing' ) === 'true' ) {
			$plugins['gfxdoc_shortcodes'] = GFXDOC_URL . 'assets/js/gfxdoc-tinymce.js';
		}
		return $plugins;
	}

	public function register_tinymce_button( $buttons ) {
		if ( $this->is_document_editor_screen() ) {
			$buttons[] = 'gfxdoc_insert';
		}
		return $buttons;
	}
}
