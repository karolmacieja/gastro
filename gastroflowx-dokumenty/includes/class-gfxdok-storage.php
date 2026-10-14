<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Przechowywanie plików Archiwum (tylko gdy Archiwum jest włączone).
 *
 * Pliki trafiają do wp-content/uploads/gfx-dokumenty/RRRR/MM/DD/ pod losowymi
 * nazwami. Katalog jest zablokowany dla bezpośredniego dostępu (.htaccess +
 * index.php), a pliki są wydawane WYŁĄCZNIE przez REST API po sprawdzeniu
 * uprawnień — raporty utargu to dane finansowe, nie mogą być publiczne.
 * (Na serwerach nginx .htaccess nie działa — losowe nazwy + brak listingu
 * katalogu są wtedy dodatkowym zabezpieczeniem; zalecana reguła nginx w readme.)
 */
class GFXDOK_Storage {

	const DIR_NAME = 'gfx-dokumenty';

	public static function base_dir() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . self::DIR_NAME;
	}

	public static function ensure_base_dir() {
		$base = self::base_dir();
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}
		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "# Dokumenty GastroFlowX — brak bezpośredniego dostępu\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\nOptions -Indexes\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $base . '/index.php' ) ) {
			@file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
		return $base;
	}

	/** Ścieżka względna katalogu dnia, np. "2026/09/22". */
	public static function day_rel_dir( $date ) {
		return str_replace( '-', '/', $date );
	}

	public static function abs_path( $rel ) {
		$rel = ltrim( str_replace( array( '..', '\\' ), '', (string) $rel ), '/' );
		return self::base_dir() . '/' . $rel;
	}

	/**
	 * Przenosi przesłany plik tymczasowy do katalogu dnia pod losową nazwą.
	 *
	 * @return string|WP_Error Ścieżka względna.
	 */
	public static function store_upload( $tmp_name, $date, $ext, $suffix = '' ) {
		self::ensure_base_dir();
		$rel_dir = self::day_rel_dir( $date );
		$abs_dir = self::base_dir() . '/' . $rel_dir;
		if ( ! wp_mkdir_p( $abs_dir ) ) {
			return new WP_Error( 'gfxdok_mkdir', 'Nie udało się utworzyć katalogu na serwerze.', array( 'status' => 500 ) );
		}
		$name = strtolower( wp_generate_password( 24, false, false ) ) . $suffix . '.' . $ext;
		$dest = $abs_dir . '/' . $name;

		$ok = is_uploaded_file( $tmp_name ) ? @move_uploaded_file( $tmp_name, $dest ) : @rename( $tmp_name, $dest ); // phpcs:ignore
		if ( ! $ok ) {
			return new WP_Error( 'gfxdok_move', 'Nie udało się zapisać pliku na serwerze.', array( 'status' => 500 ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore
		return $rel_dir . '/' . $name;
	}

	public static function delete( $rel ) {
		if ( empty( $rel ) ) {
			return;
		}
		$abs = self::abs_path( $rel );
		if ( is_file( $abs ) ) {
			wp_delete_file( $abs );
		}
	}

	/** Usuwa puste katalogi DD, MM, RRRR po skasowaniu plików dnia. */
	public static function cleanup_day_dir( $date ) {
		$parts = explode( '-', $date );
		$base  = self::base_dir();
		$paths = array(
			$base . '/' . $parts[0] . '/' . $parts[1] . '/' . $parts[2],
			$base . '/' . $parts[0] . '/' . $parts[1],
			$base . '/' . $parts[0],
		);
		foreach ( $paths as $p ) {
			if ( is_dir( $p ) && count( (array) @scandir( $p ) ) <= 2 ) { // phpcs:ignore
				@rmdir( $p ); // phpcs:ignore
			}
		}
	}

	/** Usuwa WSZYSTKIE pliki Archiwum (zostawia zabezpieczony katalog bazowy). */
	public static function purge_all() {
		$base = self::base_dir();
		if ( ! is_dir( $base ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $file ) {
			$path = $file->getPathname();
			if ( dirname( $path ) === $base && in_array( $file->getFilename(), array( '.htaccess', 'index.php' ), true ) ) {
				continue;
			}
			if ( $file->isDir() ) {
				@rmdir( $path ); // phpcs:ignore
			} else {
				wp_delete_file( $path );
			}
		}
	}

	/** Usuwa katalog całkowicie (uninstall). */
	public static function remove_base_dir() {
		self::purge_all();
		$base = self::base_dir();
		foreach ( array( '.htaccess', 'index.php' ) as $f ) {
			if ( file_exists( $base . '/' . $f ) ) {
				wp_delete_file( $base . '/' . $f );
			}
		}
		@rmdir( $base ); // phpcs:ignore
	}

	/**
	 * Wysyła plik do przeglądarki i kończy żądanie.
	 */
	public static function send_file( $abs, $mime, $filename, $disposition = 'inline', $cache = true ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( ! $cache ) {
			nocache_headers();
		}
		$ascii = preg_replace( '/[^A-Za-z0-9._-]+/', '_', remove_accents( $filename ) );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $abs ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: ' . ( $cache ? 'private, max-age=86400' : 'private, no-store' ) );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		readfile( $abs ); // phpcs:ignore
		exit;
	}
}
