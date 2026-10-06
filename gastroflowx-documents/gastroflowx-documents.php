<?php
/**
 * Plugin Name:       GastroFlowx Documents
 * Plugin URI:        https://gastroflowx.pl
 * Description:       Edytor dokumentów firmowych (regulaminy, polityki, porozumienia) z zachowaniem stylizacji GastroFlowx oraz eksportem do PDF jednym kliknięciem. Pozwala też wgrać gotowy PDF i edytować go bezpośrednio w panelu WordPress.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            GastroFlowx
 * Text Domain:       gastroflowx-documents
 *
 * @package GastroFlowx_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'GFXDOC_VERSION', '1.3.0' );
define( 'GFXDOC_FILE', __FILE__ );
define( 'GFXDOC_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFXDOC_URL', plugin_dir_url( __FILE__ ) );
// The primitive "can you work with this post type at all" capability —
// WordPress auto-generates this as edit_<plural base> once the post type is
// registered (see GFXDoc_CPT::register_post_type()). Every permission check
// in this plugin uses this single primitive capability; we don't need
// per-author meta-cap nuance (edit_gfx_document, delete_gfx_document, …)
// since only the Administrator role ever gets access at all.
define( 'GFXDOC_CAP', 'edit_gfx_documents' );

require_once GFXDOC_DIR . 'includes/class-gfxdoc-cpt.php';
require_once GFXDOC_DIR . 'includes/class-gfxdoc-shortcodes.php';
require_once GFXDOC_DIR . 'includes/class-gfxdoc-editor.php';
require_once GFXDOC_DIR . 'includes/class-gfxdoc-pdf.php';
require_once GFXDOC_DIR . 'includes/class-gfxdoc-pdf-storage.php';
require_once GFXDOC_DIR . 'includes/class-gfxdoc-pdf-upload.php';
require_once GFXDOC_DIR . 'includes/class-gfxdoc-admin.php';
require_once GFXDOC_DIR . 'includes/class-gfxdoc-seed.php';

/**
 * Boots every plugin component. Each class wires its own hooks in its
 * constructor, so instantiating them here is enough.
 */
function gfxdoc_bootstrap() {
	new GFXDoc_CPT();
	new GFXDoc_Shortcodes();
	new GFXDoc_Editor();
	new GFXDoc_PDF();
	new GFXDoc_PDF_Upload();
	new GFXDoc_Admin();
	GFXDoc_Seed::init();
}
add_action( 'plugins_loaded', 'gfxdoc_bootstrap' );

/**
 * On activation: register every auto-generated capability for this post
 * type on the Administrator role (edit_gfx_documents, delete_gfx_documents,
 * publish_gfx_documents, read_private_gfx_documents, …), then seed the six
 * starter documents (only the first time — never overwrites documents the
 * admin has already edited).
 */
function gfxdoc_activate() {
	// CPT must be registered before WordPress can tell us its generated
	// capability names, and before we can insert posts of that type.
	require_once GFXDOC_DIR . 'includes/class-gfxdoc-cpt.php';
	$cpt = new GFXDoc_CPT();
	$cpt->register_post_type();

	$admin_role = get_role( 'administrator' );
	if ( $admin_role ) {
		foreach ( GFXDoc_CPT::all_capabilities() as $cap ) {
			if ( ! $admin_role->has_cap( $cap ) ) {
				$admin_role->add_cap( $cap );
			}
		}
	}

	require_once GFXDOC_DIR . 'includes/class-gfxdoc-seed.php';
	GFXDoc_Seed::maybe_seed();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'gfxdoc_activate' );

function gfxdoc_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'gfxdoc_deactivate' );
