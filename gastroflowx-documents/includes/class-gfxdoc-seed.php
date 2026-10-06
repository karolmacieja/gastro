<?php
/**
 * Content seeder for the starter documents.
 *
 * Fresh install: inserts every document from gfxdoc_seed_documents().
 * Upgrade (stored seed version lower than SEED_VERSION): inserts only the
 * documents introduced after the stored version ('since' key), so documents
 * the admin edited or deliberately deleted are never touched or re-inserted.
 * A document whose exact title already exists is always skipped.
 *
 * @package GastroFlowx_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/seed-content.php';

class GFXDoc_Seed {

	const SEEDED_OPTION  = 'gfxdoc_documents_seeded';
	const VERSION_OPTION = 'gfxdoc_seed_version';
	const NOTICE_OPTION  = 'gfxdoc_seed_notice';
	const SEED_VERSION   = 2;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	public static function maybe_seed() {
		$stored = (int) get_option( self::VERSION_OPTION, 0 );
		if ( ! $stored && get_option( self::SEEDED_OPTION ) ) {
			$stored = 1; // seeded by plugin 1.2.x, which had no version option.
		}
		if ( $stored >= self::SEED_VERSION ) {
			return;
		}

		$added = array();
		foreach ( gfxdoc_seed_documents() as $doc ) {
			if ( $stored && (int) $doc['since'] <= $stored ) {
				continue;
			}
			if ( self::title_exists( $doc['title'] ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'gfx_document',
					'post_title'   => $doc['title'],
					'post_content' => $doc['content'],
					'post_status'  => 'publish',
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				$added[] = $doc['title'];
			}
		}

		update_option( self::SEEDED_OPTION, time() );
		update_option( self::VERSION_OPTION, self::SEED_VERSION );
		if ( $stored && $added ) {
			update_option( self::NOTICE_OPTION, $added, false );
		}
	}

	private static function title_exists( $title ) {
		$existing = new WP_Query(
			array(
				'post_type'              => 'gfx_document',
				'title'                  => $title,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return $existing->have_posts();
	}

	/**
	 * One-time notice after an upgrade that added documents, so the admin
	 * knows the older versions are still there and can be removed.
	 */
	public static function render_notice() {
		$added = get_option( self::NOTICE_OPTION );
		if ( ! $added || ! current_user_can( GFXDOC_CAP ) ) {
			return;
		}
		delete_option( self::NOTICE_OPTION );
		echo '<div class="notice notice-info is-dismissible"><p><strong>'
			. esc_html__( 'GastroFlowx Documents: dodano nowe wersje dokumentów:', 'gastroflowx-documents' )
			. '</strong> ' . esc_html( implode( ', ', $added ) ) . '.</p><p>'
			. esc_html__( 'Poprzednie dokumenty (np. „Opis funkcji systemu GastroFlowx”, „Instrukcje dla pracowników — podział na role”, „Polityka prywatności — monitorowanie aktywności”, „Wzór porozumienia z pracodawcą”) pozostały bez zmian — usuń je, jeśli nie są już potrzebne, i zaktualizuj link do polityki prywatności w GastroFlowx → Ustawienia ogólne.', 'gastroflowx-documents' )
			. '</p></div>';
	}
}
