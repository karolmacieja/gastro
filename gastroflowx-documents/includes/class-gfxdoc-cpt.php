<?php
/**
 * Registers the gfx_document custom post type.
 *
 * Documents are admin-only by design (capability GFXDOC_CAP, granted to
 * Administrator on activation) — this mirrors how GastroFlowx Access Cards
 * and the activity log are handled: legal/business documents are edited in
 * wp-admin, not in the front-end employee panel.
 *
 * @package GastroFlowx_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFXDoc_CPT {

	const POST_TYPE = 'gfx_document';

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Registers the post type. Public method (not just a hook callback) so
	 * the activation routine can call it directly before the seeder runs,
	 * without waiting for the next 'init'.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Dokumenty', 'gastroflowx-documents' ),
			'singular_name'      => __( 'Dokument', 'gastroflowx-documents' ),
			'add_new'            => __( 'Dodaj dokument', 'gastroflowx-documents' ),
			'add_new_item'       => __( 'Dodaj nowy dokument', 'gastroflowx-documents' ),
			'edit_item'          => __( 'Edytuj dokument', 'gastroflowx-documents' ),
			'new_item'           => __( 'Nowy dokument', 'gastroflowx-documents' ),
			'view_item'          => __( 'Podgląd dokumentu', 'gastroflowx-documents' ),
			'search_items'       => __( 'Szukaj dokumentów', 'gastroflowx-documents' ),
			'not_found'          => __( 'Nie znaleziono dokumentów.', 'gastroflowx-documents' ),
			'not_found_in_trash' => __( 'Brak dokumentów w koszu.', 'gastroflowx-documents' ),
			'menu_name'          => __( 'Dokumenty GastroFlowx', 'gastroflowx-documents' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-media-document',
				'menu_position'       => 58,
				// Deliberately NOT shown in REST: this keeps WordPress on the
				// Classic (TinyMCE) editor for this post type, which is what
				// our custom "Wstaw" toolbar button (ramki, plakietki,
				// tabela na podpisy…) hooks into. The block editor would
				// ignore that button entirely.
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				// Singular base ("gfx_document") drives the meta capabilities
				// (edit_post → edit_gfx_document, checked against one post);
				// plural base ("gfx_documents") drives the primitive,
				// role-level capabilities (edit_posts → edit_gfx_documents).
				// Letting WordPress derive these — rather than pointing every
				// one of them at a single hand-picked string — is what keeps
				// meta-cap and primitive-cap checks from colliding; see
				// GFXDoc_CPT::all_capabilities() for the full generated list.
				'capability_type'     => array( 'gfx_document', 'gfx_documents' ),
				'map_meta_cap'        => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	/**
	 * Every distinct capability string WordPress generated for this post
	 * type (meta + primitive), so the activation routine can grant all of
	 * them to the Administrator role in one pass. Must be called after
	 * register_post_type() has run.
	 *
	 * @return string[]
	 */
	public static function all_capabilities() {
		$obj = get_post_type_object( self::POST_TYPE );
		if ( ! $obj ) {
			return array();
		}
		return array_unique( array_values( (array) $obj->cap ) );
	}
}
