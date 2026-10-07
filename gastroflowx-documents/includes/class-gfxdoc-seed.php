<?php
/**
 * Content seeder for the starter documents.
 *
 * On a fresh install and on every upgrade to a new SEED_VERSION, all documents
 * from gfxdoc_seed_documents() are added again as NEW, PUBLISHED documents —
 * whatever already exists (drafts, older versions, edited copies) is left
 * untouched, so nothing is ever overwritten or deleted. The only thing skipped
 * is an already published document with the same title and the same text,
 * so running the seeder twice never creates duplicates.
 *
 * The same reseed can be started by hand: Dokumenty firmowe → „Wgraj dokumenty
 * startowe ponownie” (admin only, nonce-protected).
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
	const HASH_META      = '_gfxdoc_seed_hash';
	const SEED_VERSION   = 4;
	const RESEED_ACTION  = 'gfxdoc_reseed';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_reseed_button' ) );
		add_action( 'admin_post_' . self::RESEED_ACTION, array( __CLASS__, 'handle_reseed' ) );
	}

	/** Runs once per SEED_VERSION (activation hook and admin_init). */
	public static function maybe_seed() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::SEED_VERSION ) {
			return;
		}
		$report = self::reseed();
		update_option( self::SEEDED_OPTION, time() );
		update_option( self::VERSION_OPTION, self::SEED_VERSION );
		update_option( self::NOTICE_OPTION, $report, false );
	}

	/**
	 * Adds every starter document as a new published document.
	 *
	 * @return array{added:string[],skipped:string[]}
	 */
	public static function reseed() {
		$report = array(
			'added'   => array(),
			'skipped' => array(),
		);
		foreach ( gfxdoc_seed_documents() as $doc ) {
			if ( self::published_copy_exists( $doc['title'], $doc['content'] ) ) {
				$report['skipped'][] = $doc['title'];
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'gfx_document',
					'post_title'   => $doc['title'],
					'post_content' => wp_slash( $doc['content'] ),
					'post_status'  => 'publish',
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, self::HASH_META, self::hash( $doc['content'] ) );
				$report['added'][] = $doc['title'];
			}
		}
		return $report;
	}

	/** Fingerprint of a document text, insensitive to line endings and outer whitespace. */
	private static function hash( $content ) {
		return md5( trim( str_replace( "\r\n", "\n", (string) $content ) ) );
	}

	/** Is there already a PUBLISHED document with this title and this exact text? */
	private static function published_copy_exists( $title, $content ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'gfx_document',
				'title'                  => $title,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$hash = self::hash( $content );
		foreach ( $query->posts as $id ) {
			if ( self::hash( get_post_field( 'post_content', $id, 'raw' ) ) === $hash ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------
	 * Manual reseed button (Dokumenty firmowe list screen)
	 * ------------------------------------------------------------- */

	public static function render_reseed_button() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-gfx_document' !== $screen->id || ! current_user_can( GFXDOC_CAP ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RESEED_ACTION ), self::RESEED_ACTION );
		echo '<div class="notice notice-info"><p>'
			. esc_html__( 'Dokumenty startowe GastroFlowx (opis systemu, instrukcje, PWA, polityka prywatności, regulamin napiwków, porozumienie) można wgrać ponownie jako nowe, opublikowane dokumenty. Istniejące dokumenty nie zostaną zmienione ani usunięte.', 'gastroflowx-documents' )
			. '</p><p><a class="button button-primary" href="' . esc_url( $url ) . '">'
			. esc_html__( 'Wgraj dokumenty startowe ponownie', 'gastroflowx-documents' )
			. '</a></p></div>';
	}

	public static function handle_reseed() {
		if ( ! current_user_can( GFXDOC_CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'gastroflowx-documents' ), 403 );
		}
		check_admin_referer( self::RESEED_ACTION );
		update_option( self::NOTICE_OPTION, self::reseed(), false );
		wp_safe_redirect( admin_url( 'edit.php?post_type=gfx_document' ) );
		exit;
	}

	/* ---------------------------------------------------------------
	 * One-time report notice
	 * ------------------------------------------------------------- */

	public static function render_notice() {
		$report = get_option( self::NOTICE_OPTION );
		if ( ! $report || ! current_user_can( GFXDOC_CAP ) ) {
			return;
		}
		delete_option( self::NOTICE_OPTION );
		$added   = isset( $report['added'] ) ? (array) $report['added'] : (array) $report;
		$skipped = isset( $report['skipped'] ) ? (array) $report['skipped'] : array();

		echo '<div class="notice notice-success is-dismissible"><p><strong>'
			. esc_html__( 'GastroFlowx Documents — dokumenty startowe:', 'gastroflowx-documents' ) . '</strong></p><ul style="list-style:disc;padding-left:20px;">';
		if ( $added ) {
			echo '<li>' . esc_html__( 'Dodano jako opublikowane:', 'gastroflowx-documents' ) . ' ' . esc_html( implode( ', ', $added ) ) . '.</li>';
		}
		if ( $skipped ) {
			echo '<li>' . esc_html__( 'Pominięto (identyczny opublikowany dokument już istnieje):', 'gastroflowx-documents' ) . ' ' . esc_html( implode( ', ', $skipped ) ) . '.</li>';
		}
		echo '<li>' . esc_html__( 'Starsze dokumenty i szkice pozostały bez zmian — usuń te, których nie potrzebujesz, i sprawdź link do polityki prywatności w GastroFlowx → Ustawienia ogólne.', 'gastroflowx-documents' ) . '</li>';
		echo '</ul></div>';
	}
}
