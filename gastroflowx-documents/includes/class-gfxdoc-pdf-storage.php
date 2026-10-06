<?php
/**
 * Private storage for PDF files uploaded to a gfx_document.
 *
 * Files are NOT put into the Media Library: these are internal legal /
 * business documents, so they live in a dedicated folder
 * (wp-content/uploads/gfxdoc-private/) protected by .htaccess + index.php
 * and random, unguessable file names. They are only ever served through
 * admin-post.php after a capability + nonce check (see GFXDoc_PDF_Upload).
 *
 * Per document we keep:
 *   - the ORIGINAL file (never modified — edits are always re-applied to it),
 *   - the CURRENT file (original + the admin's edits, flattened by the
 *     browser editor), or nothing if there are no edits yet,
 *   - the edits themselves as JSON, so they stay editable later.
 *
 * @package GastroFlowx_Documents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFXDoc_PDF_Storage {

	const DIR_NAME = 'gfxdoc-private';

	const META_ORIGINAL = '_gfxdoc_pdf_original';
	const META_CURRENT  = '_gfxdoc_pdf_current';
	const META_NAME     = '_gfxdoc_pdf_name';
	const META_EDITS    = '_gfxdoc_pdf_edits';
	const META_UPLOADED = '_gfxdoc_pdf_uploaded';
	const META_SAVED    = '_gfxdoc_pdf_saved';
	const META_SOURCE   = '_gfxdoc_source'; // 'editor' (dompdf) | 'pdf' (uploaded file)

	/**
	 * Absolute path of the private folder (created + protected on demand).
	 *
	 * @return string|false
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Block direct HTTP access (Apache 2.2 + 2.4). On nginx this file is
		// ignored — the random 32-char file names are the second line of defence.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$htaccess,
				"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
			);
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/**
	 * Full path for a stored file name, or '' when it is missing / invalid.
	 * File names are validated so a tampered meta value can never point
	 * outside the private folder.
	 */
	public static function path( $file ) {
		if ( ! is_string( $file ) || ! preg_match( '/^[A-Za-z0-9_-]+\.pdf$/', $file ) ) {
			return '';
		}
		$dir = self::dir();
		if ( ! $dir ) {
			return '';
		}
		$path = $dir . '/' . $file;
		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Quick sanity check that a file really is a PDF (magic bytes), not just
	 * something with a .pdf extension. The PDF spec allows the header to
	 * appear anywhere in the first 1024 bytes.
	 */
	public static function looks_like_pdf( $tmp_path ) {
		$fh = @fopen( $tmp_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return false;
		}
		$head = fread( $fh, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false !== $head && false !== strpos( $head, '%PDF-' );
	}

	/**
	 * Moves a PHP upload (or any temp file) into private storage.
	 *
	 * @param string $tmp_path     Source file.
	 * @param bool   $is_php_upload Use move_uploaded_file() (true for $_FILES).
	 * @return string|WP_Error Stored file name.
	 */
	public static function store( $tmp_path, $is_php_upload = true ) {
		$dir = self::dir();
		if ( ! $dir ) {
			return new WP_Error( 'gfxdoc_dir', __( 'Nie można utworzyć folderu na pliki PDF (sprawdź uprawnienia do wp-content/uploads).', 'gastroflowx-documents' ) );
		}
		$name = wp_generate_password( 32, false, false ) . '.pdf';
		$dest = $dir . '/' . $name;

		$ok = $is_php_upload ? move_uploaded_file( $tmp_path, $dest ) : copy( $tmp_path, $dest );
		if ( ! $ok ) {
			return new WP_Error( 'gfxdoc_move', __( 'Nie udało się zapisać pliku na serwerze.', 'gastroflowx-documents' ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $name;
	}

	public static function delete_file( $file ) {
		$path = self::path( $file );
		if ( $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Everything the UI needs to know about a document's PDF.
	 *
	 * @return array|null Null when no PDF is attached.
	 */
	public static function info( $post_id ) {
		$original = get_post_meta( $post_id, self::META_ORIGINAL, true );
		$path     = self::path( $original );
		if ( ! $path ) {
			return null;
		}
		$current_path = self::path( get_post_meta( $post_id, self::META_CURRENT, true ) );

		return array(
			'name'       => get_post_meta( $post_id, self::META_NAME, true ),
			'size'       => filesize( $path ),
			'uploaded'   => (int) get_post_meta( $post_id, self::META_UPLOADED, true ),
			'saved'      => (int) get_post_meta( $post_id, self::META_SAVED, true ),
			'has_edits'  => (bool) $current_path,
			'edit_count' => self::edit_count( $post_id ),
		);
	}

	/**
	 * Path of the file that should be handed out for this document: the
	 * edited version when there is one, otherwise the original.
	 */
	public static function effective_path( $post_id ) {
		$current = self::path( get_post_meta( $post_id, self::META_CURRENT, true ) );
		return $current ? $current : self::path( get_post_meta( $post_id, self::META_ORIGINAL, true ) );
	}

	/**
	 * Saved editor state, normalised:
	 *   objects — list of editor objects,
	 *   pages   — [{src, rot}] in output order, or null when the pages were
	 *             not touched (no deletion / rotation / reordering).
	 * Version 1.1.0 stored a plain list of objects; that format is accepted.
	 *
	 * @return array{objects: array, pages: array|null}
	 */
	public static function edits( $post_id ) {
		$empty = array(
			'objects' => array(),
			'pages'   => null,
		);
		$raw = get_post_meta( $post_id, self::META_EDITS, true );
		if ( ! $raw ) {
			return $empty;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}
		if ( isset( $data['objects'] ) || array_key_exists( 'pages', $data ) ) {
			return array(
				'objects' => isset( $data['objects'] ) && is_array( $data['objects'] ) ? $data['objects'] : array(),
				'pages'   => isset( $data['pages'] ) && is_array( $data['pages'] ) ? $data['pages'] : null,
			);
		}
		$empty['objects'] = array_values( $data ); // 1.1.0 format
		return $empty;
	}

	/** Number of changes shown in the meta box. */
	public static function edit_count( $post_id ) {
		$e = self::edits( $post_id );
		return count( $e['objects'] ) + ( null === $e['pages'] ? 0 : 1 );
	}

	public static function source( $post_id ) {
		$source = get_post_meta( $post_id, self::META_SOURCE, true );
		return 'pdf' === $source ? 'pdf' : 'editor';
	}

	/**
	 * Removes every PDF file + meta of a document.
	 */
	public static function purge( $post_id ) {
		self::delete_file( get_post_meta( $post_id, self::META_ORIGINAL, true ) );
		self::delete_file( get_post_meta( $post_id, self::META_CURRENT, true ) );
		foreach ( array( self::META_ORIGINAL, self::META_CURRENT, self::META_NAME, self::META_EDITS, self::META_UPLOADED, self::META_SAVED, self::META_SOURCE ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
	}

	/**
	 * Sends a stored PDF to the browser.
	 *
	 * @param string $path     Absolute path (already validated).
	 * @param string $filename Download name.
	 * @param bool   $inline   Show in browser instead of forcing download.
	 */
	public static function stream( $path, $filename, $inline = false ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', $filename ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
