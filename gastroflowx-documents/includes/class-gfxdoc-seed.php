<?php
/**
 * Content seeder for the starter documents.
 *
 * Fresh install: inserts every document from gfxdoc_seed_documents().
 *
 * Upgrade (stored seed version lower than SEED_VERSION), per document:
 *  - 'since' newer than the stored version and no document with that title
 *    → the document is added,
 *  - 'updated' newer than the stored version and the document exists:
 *      · unedited copy (its text matches what an earlier seeder inserted —
 *        the stored fingerprint or one from seed-history.php) → the text is
 *        replaced; WordPress keeps the previous text as a revision,
 *      · edited by the admin → left untouched; the new text is added as
 *        a separate draft „… (nowa wersja)” to compare and merge by hand.
 * Documents the admin deleted on purpose are never re-inserted.
 *
 * @package GastroFlowx_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/seed-content.php';
require_once __DIR__ . '/seed-history.php';

class GFXDoc_Seed {

	const SEEDED_OPTION  = 'gfxdoc_documents_seeded';
	const VERSION_OPTION = 'gfxdoc_seed_version';
	const NOTICE_OPTION  = 'gfxdoc_seed_notice';
	const HASH_META      = '_gfxdoc_seed_hash';
	const SEED_VERSION   = 3;

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

		$history = gfxdoc_seed_history();
		$report  = array(
			'added'   => array(),
			'updated' => array(),
			'drafts'  => array(),
		);

		foreach ( gfxdoc_seed_documents() as $doc ) {
			$existing_id = self::find_by_title( $doc['title'] );
			$new_hash    = self::hash( $doc['content'] );

			if ( ! $existing_id ) {
				if ( ! $stored || (int) $doc['since'] > $stored ) {
					if ( self::insert( $doc['title'], $doc['content'], 'publish' ) ) {
						$report['added'][] = $doc['title'];
					}
				}
				continue;
			}

			if ( ! $stored || (int) $doc['updated'] <= $stored ) {
				continue;
			}

			$current_hash = self::hash( get_post_field( 'post_content', $existing_id, 'raw' ) );
			if ( $current_hash === $new_hash ) {
				update_post_meta( $existing_id, self::HASH_META, $new_hash );
				continue; // already up to date.
			}

			$known = isset( $history[ $doc['title'] ] ) ? $history[ $doc['title'] ] : array();
			$known[] = (string) get_post_meta( $existing_id, self::HASH_META, true );

			if ( in_array( $current_hash, $known, true ) ) {
				// Unedited copy — replace; the old text stays in revisions.
				$result = wp_update_post(
					array(
						'ID'           => $existing_id,
						'post_content' => wp_slash( $doc['content'] ),
					),
					true
				);
				if ( $result && ! is_wp_error( $result ) ) {
					update_post_meta( $existing_id, self::HASH_META, $new_hash );
					$report['updated'][] = $doc['title'];
				}
			} else {
				// Edited by the admin — never overwrite; offer the new text as a draft.
				$draft_title = $doc['title'] . ' (nowa wersja)';
				if ( ! self::find_by_title( $draft_title ) && self::insert( $draft_title, $doc['content'], 'draft' ) ) {
					$report['drafts'][] = $doc['title'];
				}
			}
		}

		update_option( self::SEEDED_OPTION, time() );
		update_option( self::VERSION_OPTION, self::SEED_VERSION );
		if ( $stored && ( $report['added'] || $report['updated'] || $report['drafts'] ) ) {
			update_option( self::NOTICE_OPTION, $report, false );
		}
	}

	private static function insert( $title, $content, $status ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'gfx_document',
				'post_title'   => $title,
				'post_content' => wp_slash( $content ),
				'post_status'  => $status,
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, self::HASH_META, self::hash( $content ) );
			return $id;
		}
		return 0;
	}

	/** Fingerprint of a document text, insensitive to line endings and outer whitespace. */
	private static function hash( $content ) {
		return md5( trim( str_replace( "\r\n", "\n", (string) $content ) ) );
	}

	private static function find_by_title( $title ) {
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
		return $existing->have_posts() ? (int) $existing->posts[0] : 0;
	}

	/**
	 * One-time notice after an upgrade, so the admin knows what changed.
	 */
	public static function render_notice() {
		$report = get_option( self::NOTICE_OPTION );
		if ( ! $report || ! current_user_can( GFXDOC_CAP ) ) {
			return;
		}
		delete_option( self::NOTICE_OPTION );
		if ( ! isset( $report['added'] ) ) {
			$report = array( 'added' => (array) $report, 'updated' => array(), 'drafts' => array() ); // format from 1.3.0
		}

		echo '<div class="notice notice-info is-dismissible"><p><strong>'
			. esc_html__( 'GastroFlowx Documents — aktualizacja dokumentów:', 'gastroflowx-documents' ) . '</strong></p><ul style="list-style:disc;padding-left:20px;">';
		if ( $report['updated'] ) {
			echo '<li>' . esc_html__( 'Zaktualizowano (poprzednia treść jest w rewizjach):', 'gastroflowx-documents' ) . ' ' . esc_html( implode( ', ', $report['updated'] ) ) . '</li>';
		}
		if ( $report['drafts'] ) {
			echo '<li>' . esc_html__( 'Edytowane ręcznie — pozostawiono bez zmian, a nowa treść czeka jako szkic „(nowa wersja)”:', 'gastroflowx-documents' ) . ' ' . esc_html( implode( ', ', $report['drafts'] ) ) . '</li>';
		}
		if ( $report['added'] ) {
			echo '<li>' . esc_html__( 'Dodano:', 'gastroflowx-documents' ) . ' ' . esc_html( implode( ', ', $report['added'] ) ) . '. '
				. esc_html__( 'Starsze dokumenty (np. „Polityka prywatności — monitorowanie aktywności”, „Wzór porozumienia z pracodawcą”) pozostały — usuń je, jeśli nie są potrzebne, i zaktualizuj link do polityki prywatności w GastroFlowx → Ustawienia ogólne.', 'gastroflowx-documents' ) . '</li>';
		}
		echo '</ul></div>';
	}
}
