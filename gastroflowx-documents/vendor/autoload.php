<?php
/**
 * Minimal PSR-4 autoloader for the bundled PDF libraries (dompdf and its
 * dependencies), so the plugin works on hosts without Composer/CLI access.
 * Mirrors the "manual installation" instructions published by each project.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

spl_autoload_register(
	function ( $class ) {
		// dompdf's legacy, non-namespaced-file PDF backend (class Dompdf\Cpdf
		// lives directly under lib/, outside the src/ PSR-4 root).
		if ( 'Dompdf\\Cpdf' === $class ) {
			require __DIR__ . '/dompdf/lib/Cpdf.php';
			return;
		}

		static $prefixes = array(
			'Dompdf\\'          => __DIR__ . '/dompdf/src/',
			'FontLib\\'         => __DIR__ . '/php-font-lib/src/FontLib/',
			'Svg\\'             => __DIR__ . '/php-svg-lib/src/Svg/',
			'Masterminds\\'     => __DIR__ . '/html5-php/src/',
			'Sabberworm\\CSS\\' => __DIR__ . '/php-css-parser/src/',
		);

		foreach ( $prefixes as $prefix => $base_dir ) {
			$len = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				continue;
			}
			$relative = substr( $class, $len );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $file ) ) {
				require $file;
			}
			return;
		}
	}
);
