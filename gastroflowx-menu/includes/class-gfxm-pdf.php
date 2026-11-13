<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generowanie PDF przez Dompdf (vendor/dompdf) — układ dwukolumnowy wg wzoru
 * Weranda: logo nagłówka u góry, kategorie w dwóch kolumnach (każda z
 * własną kolumną i opcjonalną stałą pozycją od góry), logo stopki na dole.
 *
 * Algorytm (dwa przebiegi Dompdf):
 *  1. POMIAR — każda kategoria renderowana w kolumnie o docelowej szerokości;
 *     callback end_frame zwraca wysokości bloków i szerokości tekstów.
 *  2. SKŁAD — pozycja kategorii = max(stała pozycja, koniec poprzedniej +
 *     odstęp). Dzięki temu układ 1:1 jak we wzorze, a dłuższa treść zsuwa
 *     kolejną kategorię niżej zamiast na nią nachodzić. Strona = jedna wersja.
 *
 * A4 w 96 DPI = 794 × 1123 px. Rozmiary pisma podawane w pt (jak w edytorze
 * grafiki: 20 / 14 / 10 pt), przeliczane na px (×96/72).
 */
class GFXM_PDF {

	const PAGE_W = 794;
	const PAGE_H = 1123;

	/** Rozmiary pisma w pt — wg szablonu InDesign (listopad). */
	const HEAD_PT = 22;   // Poppins Black — „zupy”, „przystawki”.
	const SUB_PT  = 10.3; // Sweet Belly Sans — „NA POCZĄTEK”, „AUTORSKIE”.
	const NAME_PT = 9;   // Fabrikat Mono Bold — nazwa dania.
	const DESC_PT = 9;   // Fabrikat Mono Regular — opis dania.
	const LINE_H  = 1.2; // interlinia 1.2
	const TRACK   = 0.05; // domyślne światło między znakami (em) — zmienne w Ustawieniach
	const HEAD_LH = 1.2;  // interlinia nagłówka kategorii
	const SUB_TRACK = 0.14; // światło podtytułów (em) — Sweet Belly we wzorze
	const HEAD_MT = -0.12; // nagłówek: korekta górnej krawędzi (em nagłówka)

	/**
	 * Różnica położenia linii bazowej Dompdf − Chromium (em) przy interlinii
	 * projektu, zmierzona dla dołączonych krojów. Używana TYLKO w podglądzie
	 * (przesunięcie wizualne), żeby tekst leżał tam, gdzie w PDF.
	 */
	const BASELINE_DY = array(
		'FabrikatMono'   => 0.03,
		'Poppins'        => 0.06,
		'SweetBelly'     => 0,
		'AdornGarland'   => 0.24,
		'Allura'         => 0.19,
		'MyriadPro'      => 0.11,
		'SourceSans3'    => 0.03,
	);

	/**
	 * Położenie linii bazowej w Dompdf (em od górnej krawędzi bloku) przy
	 * interlinii z base_css(): nazwa kategorii (Poppins, razem z korektą
	 * HEAD_MT) i podtytuł (interlinia 1.2 — wynik jednakowy dla Sweet Belly,
	 * Adorn Garland i Allury). Zmierzone w Dompdf 3.1; dzięki temu podtytuł
	 * stawiamy względem linii bazowej nazwy, tak jak w InDesignie.
	 */
	const HEAD_BL = 0.84;
	const SUB_BL  = 0.704;

	/** Czcionki podtytułów do wyboru w Ustawieniach PDF: klucz => [ etykieta, pliki ]. */
	public static function sub_fonts() {
		return array(
			'SweetBelly-Sans'   => array( 'Sweet Belly Sans', array( 'SweetBelly-Sans.ttf' ) ),
			'SweetBelly-Script' => array( 'Sweet Belly Script', array( 'SweetBelly-Script.ttf' ) ),
			'AdornGarland'      => array( 'Adorn Garland', array( 'AdornGarland.ttf', 'AdornGarland-Regular.ttf', 'Adorn-Garland-Regular.ttf' ) ),
		);
	}

	public static function sub_font_key( $key ) {
		return array_key_exists( (string) $key, self::sub_fonts() ) ? (string) $key : 'SweetBelly-Sans';
	}

	public static function baseline_dy( $file ) {
		foreach ( self::BASELINE_DY as $prefix => $dy ) {
			if ( 0 === strpos( (string) $file, $prefix ) ) {
				return $dy;
			}
		}
		return 0;
	}

	/**
	 * Wysokość kroju wg Dompdf (em, już × fontHeightRatio) dla każdej roli.
	 * Dompdf liczy wysokość linii jako (line-height ÷ rozmiar) × wysokość
	 * kroju — dlatego interlinię 1.2 trzeba podać jako 1.2 ÷ H, inaczej np.
	 * Adorn Garland (H = 1.47) dostałby realnie ~1.76. Wynik w cache opcji.
	 */
	public static function font_heights( $fonts ) {
		$key   = md5( implode( '|', wp_list_pluck( $fonts, 'family' ) ) );
		$cache = get_option( 'gfxm_font_heights', array() );
		if ( is_array( $cache ) && isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$css  = '';
		$body = '';
		foreach ( $fonts as $f ) {
			if ( $f['path'] ) {
				$css  .= "@font-face { font-family: '{$f['family']}'; src: url('{$f['path']}') format('truetype'); }";
				$body .= "<span style=\"font-family:'{$f['family']}'\">x</span>";
			}
		}
		$out = array();
		try {
			$d  = self::make_dompdf( '<html><head><style>' . $css . '</style></head><body>' . $body . '</body></html>' );
			$fm = $d->getFontMetrics();
			foreach ( $fonts as $role => $f ) {
				$file         = $fm->getFont( $f['family'] );
				$h            = $file ? (float) $fm->getFontHeight( $file, 1 ) : 0;
				$out[ $role ] = $h > 0.2 ? $h : 1.0;
			}
		} catch ( \Throwable $e ) {
			foreach ( $fonts as $role => $f ) {
				$out[ $role ] = 1.0;
			}
		}
		$cache         = is_array( $cache ) ? array_slice( $cache, -5, null, true ) : array();
		$cache[ $key ] = $out;
		update_option( 'gfxm_font_heights', $cache, false );
		return $out;
	}

	public static function fonts_dir() {
		return plugin_dir_path( GFXM_FILE ) . 'assets/fonts/';
	}

	/** Dostępne grubości nazwy dania (Fabrikat Mono). */
	public static function name_weights() {
		return array( 'Bold', 'Medium' );
	}

	public static function heading_weights() {
		return array( 'Regular', 'Medium', 'SemiBold', 'Bold', 'ExtraBold', 'Black' );
	}

	/**
	 * Role czcionek: pierwszy istniejący plik wygrywa. Własne pliki (Adorn
	 * Garland, Myriad Pro) wgrywasz do assets/fonts/ — do tego czasu używane
	 * są dołączone zamienniki na licencji OFL.
	 */
	public static function font_roles( $heading_weight = 'Black', $name_weight = 'Bold', $sub_font = 'SweetBelly-Sans' ) {
		$sf = self::sub_fonts()[ self::sub_font_key( $sub_font ) ];
		$w  = in_array( $heading_weight, self::heading_weights(), true ) ? $heading_weight : 'Black';
		$nw = in_array( $name_weight, self::name_weights(), true ) ? $name_weight : 'Bold';
		$nk = strtolower( $nw );
		return array(
			'heading' => array(
				'label' => 'Kategorie („zupy”) — Poppins ' . $w,
				'files' => array( 'Poppins-' . $w . '.ttf' ),
			),
			// Podtytuły: wybrany krój → Sweet Belly Sans → Allura (OFL).
			'script'  => array(
				'label' => 'Podtytuły („NA POCZĄTEK”) — ' . $sf[0],
				'files' => array_values( array_unique( array_merge( $sf[1], array( 'SweetBelly-Sans.ttf', 'Allura-Regular.ttf' ) ) ) ),
			),
			// Fabrikat Mono: wykrywany po nazwie pliku (dowolny zapis, np.
			// „FabrikatMono-Bold.ttf”, „Fabrikat Mono Regular.ttf”). Do czasu
			// wgrania — Myriad Pro, a w ostateczności Source Sans 3.
			'body'    => array(
				'label' => 'Opis dania — Fabrikat Mono Regular',
				'files' => array( '@fabrikat.*mono.*(regular|book|normal)|fabrikat.*mono\.ttf$', 'MyriadPro-Regular.ttf', 'SourceSans3-Regular.ttf' ),
			),
			// Nazwa dania: Fabrikat Mono Medium albo Bold — wybór w Ustawieniach PDF.
			// Gdy wybranej grubości brak, próbujemy drugiej, potem Myriad Pro Bold.
			'bold'    => array(
				'label' => 'Nazwa dania — Fabrikat Mono ' . $nw,
				'files' => array( '@fabrikat.*mono.*' . $nk, '@fabrikat.*mono.*' . ( 'bold' === $nk ? 'medium' : 'bold' ), 'MyriadPro-Bold.ttf', 'SourceSans3-Bold.ttf' ),
			),
		);
	}

	/** [ rola => [ 'file' => nazwa, 'path' => ścieżka, 'family' => unikalna rodzina, 'fallback' => bool ] ] */
	public static function resolve_fonts( $heading_weight = 'Black', $name_weight = 'Bold', $sub_font = 'SweetBelly-Sans' ) {
		$dir = self::fonts_dir();
		$out = array();
		foreach ( self::font_roles( $heading_weight, $name_weight, $sub_font ) as $role => $def ) {
			$out[ $role ] = null;
			$last         = count( $def['files'] ) - 1;
			foreach ( $def['files'] as $i => $file ) {
				if ( '@' === $file[0] ) {
					$file = self::find_font( substr( $file, 1 ) );
					if ( ! $file ) {
						continue;
					}
				}
				if ( file_exists( $dir . $file ) ) {
					// Nazwa rodziny z pliku + daty modyfikacji: podmiana pliku
					// automatycznie omija stary cache metryk Dompdf.
					$out[ $role ] = array(
						'file'     => $file,
						'path'     => $dir . $file,
						'url'      => GFXM_URL . 'assets/fonts/' . rawurlencode( $file ),
						'family'   => 'gfxm' . substr( md5( $file . '|' . filemtime( $dir . $file ) ), 0, 10 ),
						'fallback' => ( 'heading' !== $role && (bool) preg_match( '/^(Allura|SourceSans3)/', $file ) )
							|| ( 'script' === $role && $i > 0 )
							|| ( in_array( $role, array( 'body', 'bold' ), true ) && 0 === strpos( $file, 'MyriadPro' ) )
							|| ( 'bold' === $role && $i > 0 && false !== stripos( $file, 'fabrikat' ) ),
						'label'    => $def['label'],
						// Zamiennik Allura jest optycznie dużo mniejszy niż Adorn Garland.
						'factor'   => ( 'script' === $role && 'Allura-Regular.ttf' === $file ) ? 1.45 : 1.0,
					);
					break;
				}
			}
			if ( ! $out[ $role ] ) {
				$out[ $role ] = array( 'file' => '', 'path' => '', 'url' => '', 'family' => 'DejaVu Sans', 'fallback' => true, 'label' => $def['label'], 'factor' => 1.0 );
			}
		}
		return $out;
	}

	/** Pierwszy plik .ttf w assets/fonts/, którego nazwa pasuje do wzorca (bez wielkości liter). */
	private static function find_font( $pattern ) {
		static $list = null;
		if ( null === $list ) {
			$list = array();
			foreach ( (array) glob( self::fonts_dir() . '*.ttf' ) as $path ) {
				$list[] = basename( $path );
			}
			sort( $list );
		}
		foreach ( $list as $name ) {
			$flat = strtolower( str_replace( array( ' ', '-', '_' ), '', $name ) );
			if ( preg_match( '/' . $pattern . '/i', $flat ) && ! preg_match( '/italic|oblique/i', $flat ) ) {
				return $name;
			}
		}
		return '';
	}

	private static function load_dompdf() {
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			$autoload = GFXM_DIR . 'vendor/dompdf/autoload.inc.php';
			if ( ! file_exists( $autoload ) ) {
				throw new Exception( 'Brak biblioteki Dompdf (vendor/dompdf). Zainstaluj wtyczkę ponownie z pełnej paczki.' );
			}
			require_once $autoload;
		}
	}

	private static function font_cache_dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'gastroflowx-menu/dompdf-fonts/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/** Rozmiar pisma (pt) z ustawień, z domyślnymi wartościami projektu. */
	public static function size( $L, $key ) {
		$def = array( 'head_pt' => self::HEAD_PT, 'sub_pt' => self::SUB_PT, 'name_pt' => self::NAME_PT, 'desc_pt' => self::DESC_PT );
		$v   = isset( $L[ $key ] ) ? (float) $L[ $key ] : 0;
		return $v > 0 ? $v : $def[ $key ];
	}

	public static function pt( $pt ) {
		return $pt * 96 / 72;
	}

	/* ------------------------------------------------------------------ */
	/* Szablony                                                           */
	/* ------------------------------------------------------------------ */

	/** Dostępne szablony: klucz => etykieta. */
	public static function templates() {
		return array(
			'columns' => 'Dwie kolumny',
			'split'   => 'Podzielona strona (2 × ten sam układ)',
		);
	}

	public static function template_key( $tpl ) {
		return array_key_exists( (string) $tpl, self::templates() ) ? (string) $tpl : 'columns';
	}

	/**
	 * Ustawienia efektywne dla szablonu — ten sam kształt co ustawienia
	 * główne. „split” nadpisuje tylko geometrię i rozmiary logo; obraz logo,
	 * czcionki, rozmiary pisma i światło są wspólne dla obu szablonów.
	 */
	public static function template_settings( $settings, $tpl ) {
		if ( 'split' !== self::template_key( $tpl ) || empty( $settings['split'] ) ) {
			return $settings;
		}
		$sp               = $settings['split'];
		$out              = $settings;
		$out['layout']    = array_merge( $settings['layout'], isset( $sp['layout'] ) ? (array) $sp['layout'] : array() );
		foreach ( array( 'header', 'footer' ) as $k ) {
			$sizes     = array_intersect_key( isset( $sp[ $k ] ) ? (array) $sp[ $k ] : array(), array_flip( array( 'width', 'width_unit', 'height', 'height_unit' ) ) );
			$out[ $k ] = array_merge( $settings[ $k ], $sizes );
		}
		return $out;
	}

	/** Kolumny 1:1 z prowadnic (px od lewej krawędzi strony). */
	public static function geometry( $L ) {
		$lx = (float) $L['left_x'];
		$rx = (float) $L['right_x'];
		return array(
			'x_left'    => $lx,
			'w_left'    => max( 80, (float) $L['left_end'] - $lx ),
			'x_right'   => $rx,
			'w_right'   => max( 80, (float) $L['right_end'] - $rx ),
			'content_w' => max( 200, (float) $L['right_end'] - $lx ),
		);
	}

	/**
	 * Rozmiar logo w px. width: px | % obszaru treści | auto;
	 * height: px | % wysokości strony | auto (z proporcji obrazu).
	 */
	public static function logo_box( $logo, $content_w ) {
		$nw    = isset( $logo['natural_w'] ) ? (int) $logo['natural_w'] : 0;
		$nh    = isset( $logo['natural_h'] ) ? (int) $logo['natural_h'] : 0;
		$ratio = ( $nw > 0 && $nh > 0 ) ? $nh / $nw : 0;
		$w     = null;
		$h     = null;
		if ( 'px' === $logo['width_unit'] && $logo['width'] > 0 ) {
			$w = (float) $logo['width'];
		} elseif ( '%' === $logo['width_unit'] && $logo['width'] > 0 ) {
			$w = $content_w * (float) $logo['width'] / 100;
		}
		if ( 'px' === $logo['height_unit'] && $logo['height'] > 0 ) {
			$h = (float) $logo['height'];
		} elseif ( '%' === $logo['height_unit'] && $logo['height'] > 0 ) {
			$h = self::PAGE_H * (float) $logo['height'] / 100;
		}
		if ( null === $w && null === $h ) {
			$w = $content_w * 0.25;
		}
		if ( null === $h && null !== $w ) {
			$h = $ratio ? $w * $ratio : null;
		}
		if ( null === $w && null !== $h ) {
			$w = $ratio ? $h / $ratio : null;
		}
		if ( $w && $w > self::PAGE_W ) {
			$s = self::PAGE_W / $w;
			$w = self::PAGE_W;
			$h = $h ? $h * $s : $h;
		}
		return array(
			'w'       => $w ? round( $w, 2 ) : null,
			'h'       => $h ? round( $h, 2 ) : null,
			'reserve' => $h ? $h : ( $w ? $w * 0.4 : 60 ),
		);
	}

	private static function image_data_uri( $attachment_id, $url ) {
		$path = $attachment_id ? get_attached_file( $attachment_id ) : '';
		$body = '';
		$mime = '';
		if ( $path && file_exists( $path ) ) {
			$body = file_get_contents( $path ); // phpcs:ignore
			$ft   = wp_check_filetype( $path );
			$mime = $ft['type'];
		} elseif ( $url ) {
			$res = wp_remote_get( $url, array( 'timeout' => 15 ) );
			if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
				$body = wp_remote_retrieve_body( $res );
				$mime = wp_remote_retrieve_header( $res, 'content-type' );
			}
		}
		if ( ! $body ) {
			return '';
		}
		return 'data:' . ( $mime ? $mime : 'image/png' ) . ';base64,' . base64_encode( $body ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ */
	/* CSS i HTML                                                         */
	/* ------------------------------------------------------------------ */

	/** Światło (letter-spacing, em) z ustawień: head_track / sub_track / text_track. */
	public static function track( $L, $key ) {
		$def = array( 'head_track' => self::TRACK, 'sub_track' => self::SUB_TRACK, 'text_track' => 0 );
		return isset( $L[ $key ] ) && is_numeric( $L[ $key ] ) ? round( (float) $L[ $key ], 3 ) : $def[ $key ];
	}

	private static function base_css( $fonts, $L = array() ) {
		$css = '';
		foreach ( $fonts as $f ) {
			if ( $f['path'] ) {
				// Absolutna ścieżka serwera (plugin_dir_path) — Dompdf czyta plik lokalnie.
				$css .= "@font-face { font-family: '{$f['family']}'; font-style: normal; font-weight: normal; src: url('{$f['path']}') format('truetype'); }\n";
			}
		}
		$H  = self::font_heights( $fonts );
		$lh = function ( $role, $target ) use ( $H ) {
			return round( $target / max( 0.2, $H[ $role ] ), 4 );
		};
		$th = self::track( $L, 'head_track' );
		$ts = self::track( $L, 'sub_track' );
		$t  = self::track( $L, 'text_track' );
		$l  = self::LINE_H;
		// Interlinia dań (nazwa, opis, tekst dodatkowy) — z ustawień; szablon InDesign: 13 pt przy 10 pt = 1.3.
		$tl = isset( $L['text_lh'] ) && (float) $L['text_lh'] > 0 ? (float) $L['text_lh'] : self::LINE_H;
		return $css . "
			@page { size: A4 portrait; margin: 0; }
			body { margin: 0; padding: 0; color: #000000; }
			.cat-head { position: relative; }
			.cat-h { font-family: '{$fonts['heading']['family']}'; font-weight: normal; line-height: " . $lh( 'heading', self::HEAD_LH ) . "; letter-spacing: {$th}em; text-transform: lowercase; white-space: nowrap; }
			.cat-h span, .cat-s span { display: inline-block; }
			.cat-s { position: absolute; font-family: '{$fonts['script']['family']}'; font-weight: normal; line-height: " . $lh( 'script', $l ) . "; letter-spacing: {$ts}em; white-space: nowrap;" . ( ! isset( $L['sub_upper'] ) || ! empty( $L['sub_upper'] ) ? ' text-transform: uppercase;' : '' ) . " }
			.dish-name { font-family: '{$fonts['bold']['family']}'; font-weight: normal; line-height: " . $lh( 'bold', $tl ) . "; letter-spacing: {$t}em; text-transform: uppercase; }
			.dish-desc { font-family: '{$fonts['body']['family']}'; font-weight: normal; line-height: " . $lh( 'body', $tl ) . "; letter-spacing: {$t}em; color: #1A1A1A; }
			.extra { font-family: '{$fonts['body']['family']}'; font-weight: normal; line-height: " . $lh( 'body', $tl ) . "; letter-spacing: {$t}em; text-align: center; white-space: pre-line; }
		";
	}

	/** Style zależne od skali (dopasowanie do strony) — zakres .s{n}. */
	private static function scale_css( $cls, $scale, $L, $fonts ) {
		$px = function ( $v ) use ( $scale ) {
			return round( $v * $scale, 3 ) . 'px';
		};
		$head = self::pt( self::size( $L, 'head_pt' ) );
		return "
			.{$cls} .cat-head { margin: 0 0 " . $px( $L['heading_gap'] ) . " 0; }
			.{$cls} .cat-h { font-size: " . $px( $head ) . '; margin-top: ' . $px( $head * self::HEAD_MT ) . "; }
			.{$cls} .dish { margin: 0; }
			.{$cls} .dish-next { margin-top: " . $px( $L['dish_gap'] ) . "; }
			.{$cls} .dish-name { font-size: " . $px( self::pt( self::size( $L, 'name_pt' ) ) ) . "; }
			.{$cls} .dish-desc { font-size: " . $px( self::pt( self::size( $L, 'desc_pt' ) ) ) . "; }
			.{$cls} .extra { font-size: " . $px( self::pt( self::size( $L, 'desc_pt' ) ) ) . "; }
		";
	}

	/**
	 * Krótkie słowa, które nie mogą zostać same na końcu linii — łączymy je
	 * twardą spacją (U+00A0) ze słowem następnym. Ta sama lista jest w
	 * assets/app.js (NBSP_WORDS), żeby podgląd łamał wiersze identycznie.
	 */
	const NBSP_WORDS = 'a|i|o|u|w|z|ze|we|na|do|od|po|za|ku|bez|dla|nad|pod|przy|przez|oraz|lub|albo|czy|ale|lecz|że|niż|jak|an|the|and|or|of|in|on|with|to|at|by|for|&';

	public static function nbsp( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return $text;
		}
		// (?<=...) zamiast grupy — dzięki temu łańcuchy typu „i z serem” też się sklejają.
		$out = preg_replace( '/(?<=^|[\s\x{00A0}(„"\'\/-])(' . self::NBSP_WORDS . ')[ \t]+(?=\S)/iu', "$1\u{00A0}", $text );
		return null === $out ? $text : $out;
	}

	private static function label( $row, $key, $lang ) {
		$en = isset( $row[ $key . '_en' ] ) ? trim( (string) $row[ $key . '_en' ] ) : '';
		return ( 'en' === $lang && '' !== $en ) ? $en : (string) ( isset( $row[ $key . '_pl' ] ) ? $row[ $key . '_pl' ] : '' );
	}

	/** Kategorie wersji z listą dań (puste pominięte). */
	public static function version_cats( $version, $categories, $dishes ) {
		$lang = 'en' === $version['lang'] ? 'en' : 'pl';
		$out  = array();
		foreach ( $categories as $cat ) {
			$ids  = isset( $version['items'][ $cat['id'] ] ) ? (array) $version['items'][ $cat['id'] ] : array();
			$list = array();
			foreach ( $ids as $id ) {
				if ( isset( $dishes[ $id ] ) ) {
					$list[] = array(
						'name' => self::nbsp( self::label( $dishes[ $id ], 'name', $lang ) ),
						'desc' => self::nbsp( self::label( $dishes[ $id ], 'desc', $lang ) ),
					);
				}
			}
			if ( $list ) {
				$out[] = array(
					'id'     => $cat['id'],
					'name'   => self::nbsp( self::label( $cat, 'name', $lang ) ),
					'sub'    => self::nbsp( self::label( $cat, 'sub', $lang ) ),
					'column' => ( isset( $cat['column'] ) && 'right' === $cat['column'] ) ? 'right' : 'left',
					'align'  => ( isset( $cat['align'] ) && 'bottom' === $cat['align'] ) ? 'bottom' : 'top',
					'sub_dx' => isset( $cat['sub_dx'] ) ? (float) $cat['sub_dx'] : 0,
					'sub_dy' => isset( $cat['sub_dy'] ) ? (float) $cat['sub_dy'] : 0,
					'sub_pt' => isset( $cat['sub_pt'] ) ? (float) $cat['sub_pt'] : 0,
					'dx'     => isset( $cat['dx'] ) ? (float) $cat['dx'] : 0,
					'gap'    => isset( $cat['gap'] ) && is_numeric( $cat['gap'] ) ? (float) $cat['gap'] : null,
					'dishes' => $list,
				);
			}
		}
		return $out;
	}

	/**
	 * Pozycja podtytułu w bloku nagłówka (px): sub_dx = od początku nazwy
	 * kategorii do początku podtytułu, sub_dy = od linii bazowej nazwy do linii
	 * bazowej podtytułu (ujemne = nad nazwą). Wszystko w skali dopasowania.
	 *
	 * @return array{left:float,top:float,size:float}
	 */
	public static function sub_box( $c, $L, $fonts, $scale ) {
		$head = self::pt( self::size( $L, 'head_pt' ) ) * $scale;
		$sub  = self::pt( ! empty( $c['sub_pt'] ) ? (float) $c['sub_pt'] : self::size( $L, 'sub_pt' ) ) * $fonts['script']['factor'] * $scale;
		return array(
			'left' => round( (float) $c['sub_dx'] * $scale, 2 ),
			'top'  => round( $head * self::HEAD_BL + (float) $c['sub_dy'] * $scale - $sub * self::SUB_BL, 2 ),
			'size' => round( $sub, 3 ),
		);
	}

	/** HTML jednej kategorii. $m = prefiks znaczników pomiaru (null = bez), $sub = sub_box() (null = bez podtytułu). */
	private static function cat_html( $c, $m = null, $sub = null, $scale = 1.0 ) {
		$mark = function ( $k ) use ( $m ) {
			return null === $m ? '' : ' data-m="' . esc_attr( $m . '|' . $k ) . '"';
		};
		// Własny odstęp nazwa kategorii → pierwsze danie (null = z Ustawień PDF).
		$gap = isset( $c['gap'] ) && null !== $c['gap'] ? ' style="margin-bottom:' . round( $c['gap'] * $scale, 3 ) . 'px"' : '';
		$h   = '<div class="cat-head"' . $gap . '><div class="cat-h"><span' . $mark( 'hw' ) . '>' . esc_html( $c['name'] ) . '</span></div>';
		// Podtytuł pozycjonowany absolutnie względem nazwy — nie zmienia
		// wysokości bloku, więc nazwy kategorii zostają na swoich liniach.
		if ( null !== $sub && '' !== trim( $c['sub'] ) ) {
			$h .= '<div class="cat-s" style="left:' . $sub['left'] . 'px;top:' . $sub['top'] . 'px;font-size:' . $sub['size'] . 'px"><span>' . esc_html( $c['sub'] ) . '</span></div>';
		}
		$h .= '</div>';
		foreach ( $c['dishes'] as $n => $d ) {
			// Odstęp tylko MIĘDZY daniami (margin-top od drugiego) — ostatnia linia
			// opisu = dolna krawędź bloku, więc wyrównanie do prowadnicy jest co do piksela.
			$h .= '<div class="dish' . ( $n ? ' dish-next' : '' ) . '"><div class="dish-name">' . self::breaks( $d['name'] ) . '</div>';
			if ( '' !== trim( $d['desc'] ) ) {
				$h .= '<div class="dish-desc">' . nl2br( esc_html( $d['desc'] ) ) . '</div>';
			}
			$h .= '</div>';
		}
		return $h;
	}

	/**
	 * Nazwa dania: „|” = ręczne złamanie wiersza (pole nazwy jest
	 * jednowierszowe), np. „Sałata Lazurowa | z gruszką i serem pleśniowym”.
	 */
	public static function breaks( $text ) {
		$parts = array_map( 'trim', explode( '|', (string) $text ) );
		return implode( '<br>', array_map( 'esc_html', $parts ) );
	}

	private static function make_dompdf( $html, $paper = 'A4', &$marks = null ) {
		$cache   = self::font_cache_dir();
		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'isFontSubsettingEnabled', true );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'dpi', 96 );
		// Dompdf bierze max(line-height, wysokość czcionki × ratio). Przy 0.75
		// wysokość kroju ≈ interlinia 1.2, więc 1.2 z projektu jest respektowane.
		$options->set( 'fontHeightRatio', 0.75 );
		$options->set( 'fontDir', $cache );
		$options->set( 'fontCache', $cache );
		$options->set( 'tempDir', get_temp_dir() );
		$options->set( 'chroot', array( GFXM_DIR, WP_CONTENT_DIR, ABSPATH ) );

		$dompdf = new \Dompdf\Dompdf( $options );
		if ( null !== $marks ) {
			$dompdf->setCallbacks(
				array(
					array(
						'event' => 'end_frame',
						'f'     => function ( $frame ) use ( &$marks ) {
							$node = $frame->get_node();
							if ( $node instanceof \DOMElement && $node->hasAttribute( 'data-m' ) ) {
								$b = $frame->get_border_box();
								// Dompdf liczy w pt — przeliczamy na px (96 DPI).
								$marks[ $node->getAttribute( 'data-m' ) ] = array( 'w' => $b['w'] / 0.75, 'h' => $b['h'] / 0.75 );
							}
						},
					),
				)
			);
		}
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( $paper, 'portrait' );
		$dompdf->render();
		return $dompdf;
	}

	/**
	 * Przebieg 1: pomiar wysokości kategorii i szerokości nagłówków dla
	 * podanych wersji w podanych skalach. Zwraca [ idx => [ 'cats' => [...], 'extra' => h ] ].
	 */
	private static function measure( $jobs, $settings, $fonts ) {
		$css  = self::base_css( $fonts, $settings['layout'] );
		$body = '';
		foreach ( $jobs as $idx => $job ) {
			$S    = $job['S'];
			$g    = self::geometry( $S['layout'] );
			$cls  = 's' . $idx;
			$css .= self::scale_css( $cls, $job['scale'], $S['layout'], $fonts );
			$body .= '<div class="' . $cls . '">';
			foreach ( $job['cats'] as $c ) {
				// „split”: jedna kolumna (lewa połówka); „columns”: szerokość wg kolumny kategorii.
				$w     = ( 'split' !== $job['tpl'] && 'right' === $c['column'] ) ? $g['w_right'] : $g['w_left'];
				$body .= '<div data-m="' . $idx . '|cat|' . esc_attr( $c['id'] ) . '" style="width:' . $w . 'px;page-break-inside:avoid">' . self::cat_html( $c, $idx . '|' . $c['id'], null, $job['scale'] ) . '</div>';
			}
			if ( '' !== $job['extra'] ) {
				$ew    = 'split' === $job['tpl'] ? $g['w_left'] : round( $g['content_w'] * 0.8, 2 );
				$body .= '<div class="extra" data-m="' . $idx . '|extra" style="width:' . $ew . 'px;page-break-inside:avoid">' . esc_html( $job['extra'] ) . '</div>';
			}
			$body .= '</div>';
		}
		$marks = array();
		self::make_dompdf( '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>' . $body . '</body></html>', array( 0, 0, 595.28, 3000 ), $marks );

		$out = array();
		foreach ( $jobs as $idx => $job ) {
			$cats = array();
			foreach ( $job['cats'] as $c ) {
				$k            = $idx . '|' . $c['id'];
				$cats[ $c['id'] ] = array(
					'h'  => isset( $marks[ $idx . '|cat|' . $c['id'] ] ) ? $marks[ $idx . '|cat|' . $c['id'] ]['h'] : 0,
					'hw' => isset( $marks[ $k . '|hw' ] ) ? $marks[ $k . '|hw' ]['w'] : 0,				);
			}
			$out[ $idx ] = array(
				'cats'  => $cats,
				'extra' => isset( $marks[ $idx . '|extra' ] ) ? $marks[ $idx . '|extra' ]['h'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Skład strony z pomiarów (ta sama logika w assets/app.js → lay).
	 * W każdej kolumnie: kategorie „top” układane od content_top w dół,
	 * kategorie „bottom” od content_bottom w górę (ostatnia kończy się
	 * dokładnie na prowadnicy — obie kolumny mają równy dół).
	 */
	public static function layout_page( $cats, $meas, $extra_pos, $settings, $scale ) {
		$L      = $settings['layout'];
		$g      = self::geometry( $L );
		$gap    = (float) $L['category_gap'] * $scale;
		$top    = (float) $L['content_top'];
		$bottom = (float) $L['content_bottom'];
		$extra  = null;

		if ( $meas['extra'] > 0 ) {
			if ( 'top' === $extra_pos ) {
				$extra = array( 'top' => $top, 'h' => $meas['extra'] );
				$top  += $meas['extra'] + $gap;
			} else {
				$extra  = array( 'top' => $bottom - $meas['extra'], 'h' => $meas['extra'] );
				$bottom = $extra['top'] - $gap;
			}
		}

		$pos      = array();
		$overflow = false;
		foreach ( array( 'left', 'right' ) as $col ) {
			$tops = array();
			$bots = array();
			foreach ( $cats as $c ) {
				if ( $c['column'] === $col ) {
					if ( 'bottom' === $c['align'] ) {
						$bots[] = $c;
					} else {
						$tops[] = $c;
					}
				}
			}
			$y = $top;
			foreach ( $tops as $c ) {
				$pos[ $c['id'] ] = array( 'y' => $y );
				$y += $meas['cats'][ $c['id'] ]['h'] + $gap;
			}
			$top_end = $tops ? $y - $gap : $top;
			$y       = $bottom;
			foreach ( array_reverse( $bots ) as $c ) {
				$y              -= $meas['cats'][ $c['id'] ]['h'];
				$pos[ $c['id'] ] = array( 'y' => $y );
				$y              -= $gap;
			}
			$bot_start = $bots ? $y + $gap : $bottom;
			if ( $top_end > $bottom + 0.5 || ( $tops && $bots && $top_end + $gap > $bot_start + 0.5 ) || $bot_start < $top - 0.5 ) {
				$overflow = true;
			}
		}
		foreach ( $cats as $c ) {
			$pos[ $c['id'] ]['x'] = ( 'right' === $c['column'] ? $g['x_right'] : $g['x_left'] ) + $c['dx'];
			$pos[ $c['id'] ]['w'] = 'right' === $c['column'] ? $g['w_right'] : $g['w_left'];
		}
		return array(
			'pos'      => $pos,
			'extra'    => $extra,
			'overflow' => $overflow,
			'foot_top' => (float) $L['footer_top'],
		);
	}

	/**
	 * Skład szablonu „split”: jedna kolumna (lewa połówka), kategorie
	 * w kolejności z bazy, od content_top w dół. Prawa połówka to kopia
	 * przesunięta o (right_x − left_x). Lustro w assets/app.js → laySplit.
	 */
	public static function layout_split( $cats, $meas, $extra_pos, $settings, $scale ) {
		$L      = $settings['layout'];
		$g      = self::geometry( $L );
		$gap    = (float) $L['category_gap'] * $scale;
		$top    = (float) $L['content_top'];
		$bottom = (float) $L['content_bottom'];
		$extra  = null;
		if ( $meas['extra'] > 0 ) {
			if ( 'top' === $extra_pos ) {
				$extra = array( 'top' => $top, 'h' => $meas['extra'] );
				$top  += $meas['extra'] + $gap;
			} else {
				$extra  = array( 'top' => $bottom - $meas['extra'], 'h' => $meas['extra'] );
				$bottom = $extra['top'] - $gap;
			}
		}
		$pos = array();
		$y   = $top;
		foreach ( $cats as $c ) {
			$m               = $meas['cats'][ $c['id'] ];
			$pos[ $c['id'] ] = array( 'x' => $g['x_left'] + $c['dx'], 'y' => $y, 'w' => $g['w_left'] );
			$y += $m['h'] + $gap;
		}
		$end = $cats ? $y - $gap : $top;
		return array(
			'pos'      => $pos,
			'extra'    => $extra,
			'overflow' => $end > $bottom + 0.5,
			'foot_top' => (float) $L['footer_top'],
			'dx'       => (float) $L['right_x'] - (float) $L['left_x'],
		);
	}

	/** Pełny dokument (przebieg 2). */
	public static function compose( $versions, $settings, $categories, $dishes ) {
		$L     = $settings['layout'];
		$fonts = self::resolve_fonts( isset( $L['heading_weight'] ) ? $L['heading_weight'] : 'Black', isset( $L['name_weight'] ) ? $L['name_weight'] : 'Bold', isset( $L['sub_font'] ) ? $L['sub_font'] : 'SweetBelly-Sans' );

		$jobs = array();
		foreach ( array_values( $versions ) as $i => $v ) {
			$tpl        = self::template_key( isset( $v['template'] ) ? $v['template'] : 'columns' );
			$jobs[ $i ] = array(
				'tpl'   => $tpl,
				'S'     => self::template_settings( $settings, $tpl ),
				'cats'  => self::version_cats( $v, $categories, $dishes ),
				'extra' => self::nbsp( trim( (string) $v['extra_text'] ) ),
				'pos'   => 'top' === $v['extra_position'] ? 'top' : 'bottom',
				'scale' => 1.0,
			);
		}

		// Dopasowanie do strony: zmniejszamy krokami, aż skład się zmieści.
		$steps   = ! empty( $L['fit_page'] ) ? array( 1.0, 0.94, 0.88, 0.82, 0.76, 0.70 ) : array( 1.0 );
		$pending = array_keys( $jobs );
		$final   = array();
		foreach ( $steps as $step ) {
			if ( ! $pending ) {
				break;
			}
			$batch = array();
			foreach ( $pending as $i ) {
				$batch[ $i ]          = $jobs[ $i ];
				$batch[ $i ]['scale'] = $step;
			}
			$meas = self::measure( $batch, $settings, $fonts );
			foreach ( $pending as $k => $i ) {
				$J   = $jobs[ $i ];
				$lay = 'split' === $J['tpl']
					? self::layout_split( $J['cats'], $meas[ $i ], $J['pos'], $J['S'], $step )
					: self::layout_page( $J['cats'], $meas[ $i ], $J['pos'], $J['S'], $step );
				if ( ! $lay['overflow'] || end( $steps ) === $step ) {
					$final[ $i ] = array( 'scale' => $step, 'lay' => $lay );
					unset( $pending[ $k ] );
				}
			}
		}

		$h_img = ( $settings['header']['id'] || $settings['header']['url'] ) ? self::image_data_uri( (int) $settings['header']['id'], $settings['header']['url'] ) : '';
		$f_img = ( $settings['footer']['id'] || $settings['footer']['url'] ) ? self::image_data_uri( (int) $settings['footer']['id'], $settings['footer']['url'] ) : '';
		$size  = function ( $b ) {
			return ( $b['w'] ? 'width:' . $b['w'] . 'px;' : '' ) . ( $b['h'] ? 'height:' . $b['h'] . 'px;' : '' );
		};

		$css = self::base_css( $fonts, $L ) . '
			.pg { position: relative; width: ' . self::PAGE_W . 'px; height: ' . ( self::PAGE_H - 1 ) . 'px; overflow: hidden; }
			.brk { page-break-before: always; }
			.abs { position: absolute; }
			.logo { position: absolute; text-align: center; }
			.cut { position: absolute; top: 0; width: 0; height: ' . ( self::PAGE_H - 1 ) . 'px; border-left: 0.5px solid #C8C8C8; }
		';
		$html = '';
		foreach ( $jobs as $i => $job ) {
			$S    = $job['S'];
			$SL   = $S['layout'];
			$g    = self::geometry( $SL );
			$cls  = 's' . $i;
			$lay  = $final[ $i ]['lay'];
			$sc   = $final[ $i ]['scale'];
			$css .= self::scale_css( $cls, $final[ $i ]['scale'], $SL, $fonts );

			$html .= '<div class="pg ' . $cls . ( $i ? ' brk' : '' ) . '">';

			if ( 'split' === $job['tpl'] ) {
				// Logo wyśrodkowane nad kolumną każdej połówki; treść 2× (lewa + kopia).
				$hbox = self::logo_box( $S['header'], $g['w_left'] );
				$fbox = self::logo_box( $S['footer'], $g['w_left'] );
				if ( ! empty( $SL['cut_line'] ) ) {
					$html .= '<div class="cut" style="left:' . round( self::PAGE_W / 2, 2 ) . 'px"></div>';
				}
				foreach ( array( 0, $lay['dx'] ) as $dx ) {
					$lx = round( $g['x_left'] + $dx, 2 );
					if ( $h_img ) {
						$html .= '<div class="logo" style="left:' . $lx . 'px;width:' . $g['w_left'] . 'px;top:' . (float) $SL['header_top'] . 'px"><img src="' . esc_attr( $h_img ) . '" style="' . $size( $hbox ) . '" alt=""></div>';
					}
					foreach ( $job['cats'] as $c ) {
						$p     = $lay['pos'][ $c['id'] ];
						$html .= '<div class="abs" style="left:' . round( $p['x'] + $dx, 2 ) . 'px;top:' . round( $p['y'], 2 ) . 'px;width:' . round( $p['w'], 2 ) . 'px">' . self::cat_html( $c, null, self::sub_box( $c, $SL, $fonts, $sc ), $sc ) . '</div>';
					}
					if ( $lay['extra'] ) {
						$html .= '<div class="abs extra" style="left:' . $lx . 'px;top:' . round( $lay['extra']['top'], 2 ) . 'px;width:' . $g['w_left'] . 'px">' . esc_html( $job['extra'] ) . '</div>';
					}
					if ( $f_img ) {
						$html .= '<div class="logo" style="left:' . $lx . 'px;width:' . $g['w_left'] . 'px;top:' . round( $lay['foot_top'], 2 ) . 'px"><img src="' . esc_attr( $f_img ) . '" style="' . $size( $fbox ) . '" alt=""></div>';
					}
				}
			} else {
				$hbox = self::logo_box( $S['header'], $g['content_w'] );
				$fbox = self::logo_box( $S['footer'], $g['content_w'] );
				if ( $h_img ) {
					$html .= '<div class="logo" style="left:0;width:' . self::PAGE_W . 'px;top:' . (float) $SL['header_top'] . 'px"><img src="' . esc_attr( $h_img ) . '" style="' . $size( $hbox ) . '" alt=""></div>';
				}
				foreach ( $job['cats'] as $c ) {
					$p     = $lay['pos'][ $c['id'] ];
					$html .= '<div class="abs" style="left:' . round( $p['x'], 2 ) . 'px;top:' . round( $p['y'], 2 ) . 'px;width:' . round( $p['w'], 2 ) . 'px">' . self::cat_html( $c, null, self::sub_box( $c, $SL, $fonts, $sc ), $sc ) . '</div>';
				}
				if ( $lay['extra'] ) {
					$w     = round( $g['content_w'] * 0.8, 2 );
					$html .= '<div class="abs extra" style="left:' . round( $g['x_left'] + ( $g['content_w'] - $w ) / 2, 2 ) . 'px;top:' . round( $lay['extra']['top'], 2 ) . 'px;width:' . $w . 'px">' . esc_html( $job['extra'] ) . '</div>';
				}
				if ( $f_img ) {
					$html .= '<div class="logo" style="left:0;width:' . self::PAGE_W . 'px;top:' . round( $lay['foot_top'], 2 ) . 'px"><img src="' . esc_attr( $f_img ) . '" style="' . $size( $fbox ) . '" alt=""></div>';
				}
			}
			$html .= '</div>';
		}
		return '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>' . $html . '</body></html>';
	}

	/** Renderuje PDF (każda wersja na osobnej stronie) i zwraca bajty. */
	public static function render( $versions ) {
		self::load_dompdf();
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'gfxm_pdf' );
		}
		@set_time_limit( 180 ); // phpcs:ignore
		$html = self::compose( $versions, GFXM_Data::settings(), GFXM_Data::categories(), GFXM_Data::dishes_by_id() );
		return self::make_dompdf( $html )->output();
	}

	public static function filename( $versions ) {
		if ( 1 === count( $versions ) ) {
			$slug = sanitize_title( remove_accents( $versions[0]['name'] ) );
			return 'menu-' . ( $slug ? $slug : 'podglad' ) . '.pdf';
		}
		return 'menu-zestaw-' . count( $versions ) . '-wersji-' . gmdate( 'Y-m-d' ) . '.pdf';
	}

	public static function stream( $bytes, $filename ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $bytes; // phpcs:ignore
		exit;
	}
}
