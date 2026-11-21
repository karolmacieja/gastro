/* global PDFLib */
/**
 * Weranda Lunch — silnik układu karty lunchowej.
 *
 * Karta jest budowana w całości z danych (bez szablonu PDF): logo, nagłówki,
 * ceny, treść z panelu, stopka i linia cięcia. Strona A4 jest podzielona na
 * dwie identyczne połówki (do przecięcia) — wszystko rysujemy dwa razy, na
 * środkach lewej i prawej kolumny.
 *
 * Domyślne wartości odwzorowują 1:1 szablony z Canvy (szablon-1/2/3):
 * pozycje są w punktach PDF, liczone od GÓRNEJ krawędzi strony, tak jak
 * w Canvie. Bloki tekstu „płyną” w dół — po zupie z 1, 2 czy 3 liniami
 * „lunch dnia” sam przesuwa się niżej, więc jeden układ zastępuje trzy
 * dawne szablony.
 *
 * Model jak w Canvie: każda linia ma „pudełko” o wysokości rozmiar × interlinia,
 * a tekst jest w nim wyśrodkowany według metryk czcionki (half-leading).
 * Kolejny blok zaczyna się pod dolną krawędzią poprzedniego + odstęp (pt).
 * Domyślne odstępy wyliczono z linii bazowych oryginalnych PDF z Canvy.
 *
 * Położenie każdego bloku (od 2.1):
 *   pos 'flow'  — pod poprzednim blokiem + odstęp (domyślnie, jak w Canvie),
 *   pos 'line'  — w tej samej wysokości co poprzedni blok (+ odstęp), np. cena
 *                 obok nagłówka; nie przesuwa kolejnych bloków niżej, niż sięga
 *                 wyższy z dwóch,
 *   pos 'fixed' — w stałym miejscu: górna krawędź = top (pt od góry strony);
 *                 nie wpływa na bloki „flow”.
 *   align 'center' | 'left' | 'right' — względem kolumny o szerokości
 *                 max_width wokół środka połówki; dx — przesunięcie w poziomie (pt).
 * Kolejność bloków = kolejność w tablicy blocks (zmieniana w Ustawieniach).
 *
 * Ten sam plik działa w przeglądarce (window.WerandaLunchLayout) i w Node
 * (module.exports) — dzięki temu układ można testować poza WordPressem.
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.WerandaLunchLayout = factory();
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var PAGE_W = 595.5;
	var PAGE_H = 842.25;

	/** Dołączone czcionki: klucz => [plik, etykieta]. */
	var FONTS = {
		'Poppins-ExtraLight':      [ 'Poppins-ExtraLight.ttf', 'Poppins ExtraLight' ],
		'Poppins-Light':           [ 'Poppins-Light.ttf', 'Poppins Light' ],
		'Poppins-Regular':         [ 'Poppins-Regular.ttf', 'Poppins Regular' ],
		'Poppins-Medium':          [ 'Poppins-Medium.ttf', 'Poppins Medium' ],
		'Poppins-SemiBold':        [ 'Poppins-SemiBold.ttf', 'Poppins SemiBold' ],
		'Poppins-Bold':            [ 'Poppins-Bold.ttf', 'Poppins Bold' ],
		'Poppins-ExtraBold':       [ 'Poppins-ExtraBold.ttf', 'Poppins ExtraBold' ],
		'Poppins-Black':           [ 'Poppins-Black.ttf', 'Poppins Black' ],
		'BarlowCondensed-Light':   [ 'BarlowCondensed-Light.ttf', 'Barlow Condensed Light' ],
		'BarlowCondensed-Regular': [ 'BarlowCondensed-Regular.ttf', 'Barlow Condensed Regular' ],
		'BarlowCondensed-Medium':  [ 'BarlowCondensed-Medium.ttf', 'Barlow Condensed Medium' ],
		'BarlowCondensed-SemiBold':[ 'BarlowCondensed-SemiBold.ttf', 'Barlow Condensed SemiBold' ],
		'BarlowCondensed-Bold':    [ 'BarlowCondensed-Bold.ttf', 'Barlow Condensed Bold' ],
	};

	/** Pola wypełniane w panelu (zakładka Lunch). */
	var FIELDS = {
		soup:   'Zupa dnia',
		weight: 'Gramatura',
		lunch:  'Opis lunchu',
		rec:    'Polecamy (lista)',
	};

	/**
	 * Domyślny układ — zmierzony z szablonów Canvy (A4 595,5 × 842,25 pt).
	 * kind: 'text' = stały tekst z ustawień (nagłówki, ceny),
	 *       'field' = treść z panelu (draft[field]).
	 */
	function defaults() {
		return {
			version: 1,
			page: {
				center_left: 150.1,   // środek lewej połówki
				center_right: 446.9,  // środek prawej połówki (kopia)
				max_width: 250,       // maks. szerokość linii tekstu
				content_top: 179.04,  // górna krawędź pierwszego bloku („zupa”)
				content_bottom: 765,  // tu musi się skończyć treść (nad stopką)
				fit_page: 1,          // zmniejsz treść (do min_scale), gdy się nie mieści
				min_scale: 0.7,
				color: '#000000',
				keep_short_words: 0,  // 1 = „w”, „z”, „i”… nie zostają na końcu linii (Canva tego nie robi)
				cut_line: 1,
				cut_x: 297.1,
				cut_width: 0.4,
				cut_color: '#000000',
			},
			logo: {
				enabled: 1,
				id: 0,
				url: '',              // puste = logo dołączone do wtyczki
				top: 59.16,           // zmierzone z wydruku Canvy (ramka obrazka w PDF Canvy jest przesunięta)
				width: 261.1,
				left_x: 19.7,
				right_x: 313.6,
			},
			footer: {
				enabled: 1,
				text: 'werandafamily.com',
				font: 'Poppins-ExtraLight',
				size: 8,
				lh: 1.4,
				top: 783.21,
			},
			blocks: [
				{ key: 'soup_title',  label: 'Nagłówek zupy',       kind: 'text',  text: 'zupa\nprzy zakupie lunchu', font: 'Poppins-Black',           size: 21,   lh: 1.3934, gap: 0,     enabled: 1, align: 'center', dx: 0, pos: 'flow', top: 0 },
				{ key: 'soup_price',  label: 'Cena zupy',           kind: 'text',  text: '14 zł',                     font: 'Poppins-Black',           size: 21,   lh: 1.3934, gap: 0,     enabled: 1, align: 'center', dx: 0, pos: 'flow', top: 0, price: 1, short: 'zupa' },
				{ key: 'soup',        label: 'Zupa dnia',           kind: 'field', field: 'soup',                      font: 'Poppins-ExtraLight',      size: 19,   lh: 1.3821, gap: -0.05, enabled: 1, align: 'center', dx: 0, pos: 'flow', top: 0 },
				{ key: 'lunch_title', label: 'Nagłówek lunchu',     kind: 'text',  text: 'lunch dnia',                font: 'Poppins-Black',           size: 32,   lh: 1.4,    gap: 29.66, enabled: 1, align: 'center', dx: 0, pos: 'flow', top: 0 },
				{ key: 'lunch_price', label: 'Cena lunchu',         kind: 'text',  text: '43 ZŁ',                     font: 'Poppins-Black',           size: 25,   lh: 1.4,    gap: -0.69, enabled: 1, align: 'center', dx: 0, pos: 'flow', top: 0, price: 1, short: 'lunch' },
				{ key: 'weight',      label: 'Gramatura',           kind: 'field', field: 'weight',                    font: 'BarlowCondensed-Regular', size: 12,   lh: 1.2,    gap: -3.44, enabled: 1, align: 'center', dx: 0, pos: 'flow', top: 0 },
				{ key: 'lunch',       label: 'Opis lunchu',         kind: 'field', field: 'lunch',                     font: 'Poppins-ExtraLight',      size: 16.5, lh: 1.3642, gap: 8.38,  enabled: 1, align: 'center', dx: 0, pos: 'flow', top: 0 },
				{ key: 'rec_title',   label: 'Nagłówek „polecamy”', kind: 'text',  text: 'polecamy',                  font: 'Poppins-Black',           size: 24.5, lh: 1.4,    gap: 5.21,  enabled: 1, align: 'center', dx: 0, pos: 'flow', top: 0 },
				{ key: 'rec',         label: 'Polecamy (lista)',    kind: 'field', field: 'rec',                       font: 'Poppins-ExtraLight',      size: 12,   lh: 1.3755, gap: 6.08,  enabled: 1, align: 'center', dx: 0, pos: 'flow', top: 0 },
			],
		};
	}

	function num( v, d ) {
		var n = parseFloat( v );
		return isFinite( n ) ? n : d;
	}

	/** Uzupełnia zapisany układ brakującymi kluczami z domyślnego (nowe opcje po aktualizacji). */
	function merge( saved ) {
		var d = defaults();
		if ( ! saved || typeof saved !== 'object' ) return d;
		[ 'page', 'logo', 'footer' ].forEach( function ( k ) {
			if ( saved[ k ] && typeof saved[ k ] === 'object' ) {
				Object.keys( d[ k ] ).forEach( function ( p ) {
					if ( saved[ k ][ p ] !== undefined && saved[ k ][ p ] !== null ) d[ k ][ p ] = saved[ k ][ p ];
				} );
			}
		} );
		if ( Array.isArray( saved.blocks ) ) {
			var byKey = {};
			saved.blocks.forEach( function ( b ) { if ( b && b.key ) byKey[ b.key ] = b; } );
			var order = saved.blocks.map( function ( b ) { return b && b.key; } );
			d.blocks.forEach( function ( b ) {
				var s = byKey[ b.key ];
				if ( ! s ) return;
				[ 'text', 'font', 'size', 'lh', 'gap', 'enabled', 'align', 'dx', 'pos', 'top' ].forEach( function ( p ) {
					if ( s[ p ] !== undefined && s[ p ] !== null ) b[ p ] = s[ p ];
				} );
			} );
			// zachowaj kolejność z zapisu; nowe bloki — na swoich domyślnych miejscach na końcu
			d.blocks.sort( function ( a, b ) {
				var ia = order.indexOf( a.key ), ib = order.indexOf( b.key );
				return ( ia < 0 ? 999 : ia ) - ( ib < 0 ? 999 : ib );
			} );
		}
		return d;
	}

	/** Twarda spacja po krótkich słowach (z, w, i, a, o, u…) — jak w module Menu. */
	var NBSP_WORDS = /(^|\s)(a|i|o|u|w|z|ze|we|na|do|od|po|za|ku|bez|dla|nad|pod|przy|oraz|lub|albo|czy|ale|że|niż|jak)\s+/gi;
	function nbsp( text ) {
		return String( text ).replace( NBSP_WORDS, function ( m, pre, w ) { return pre + w + ' '; } );
	}

	/** Łamanie tekstu na linie o maks. szerokości. measure(text) => szerokość w pt. */
	function wrap( text, measure, maxWidth, keepShort ) {
		var out = [];
		String( text || '' ).replace( /\r/g, '' ).split( '\n' ).forEach( function ( para ) {
			if ( para.trim() === '' ) { out.push( '' ); return; }
			var words = ( keepShort ? nbsp( para.trim() ) : para.trim() ).split( / +/ );
			var line = '';
			words.forEach( function ( w ) {
				var test = line === '' ? w : line + ' ' + w;
				if ( line !== '' && measure( test.replace( / /g, ' ' ) ) > maxWidth ) {
					out.push( line );
					line = w;
				} else {
					line = test;
				}
			} );
			if ( line !== '' ) out.push( line );
		} );
		// Usuń puste linie na początku i końcu bloku (np. przypadkowy Enter na końcu).
		while ( out.length && out[ 0 ] === '' ) out.shift();
		while ( out.length && out[ out.length - 1 ] === '' ) out.pop();
		return out.map( function ( l ) { return l.replace( / /g, ' ' ); } );
	}

	/**
	 * Oblicza rozmieszczenie wszystkich linii.
	 * @param {object} layout   układ (po merge())
	 * @param {object} draft    { soup, weight, lunch, rec }
	 * @param {function} measure (fontKey, size, text) => szerokość w pt
	 * @return {{lines: Array<{font,size,text,top}>, scale:number, bottom:number, overflow:boolean}}
	 */
	function compute( layout, draft, measure ) {
		var page = layout.page;
		var maxW = num( page.max_width, 250 );

		function flow( scale ) {
			var lines = [];
			var lastBottom = null;   // dół ostatniego bloku w przepływie
			var prevTop = null;      // góra poprzedniego bloku (dla pos 'line')
			var maxBottom = null;    // najniższy punkt treści (kontrola przepełnienia)
			layout.blocks.forEach( function ( b ) {
				if ( ! +b.enabled ) return;
				var text = b.kind === 'field' ? ( draft && draft[ b.field ] ) || '' : ( b.text || '' );
				var size = num( b.size, 12 ) * scale;
				var step = size * num( b.lh, 1.3 );
				var wrapped = wrap( text, function ( t ) { return measure( b.font, size, t ); }, maxW, +page.keep_short_words );
				if ( ! wrapped.length ) return; // pusty blok nie zajmuje miejsca
				var pos = b.pos === 'fixed' || b.pos === 'line' ? b.pos : 'flow';
				var gap = num( b.gap, 0 ) * scale;
				var top;
				if ( pos === 'fixed' ) {
					top = num( b.top, 0 );
				} else if ( pos === 'line' && prevTop !== null ) {
					top = prevTop + gap;
				} else {
					// górna krawędź pudełka linii; kolejny blok pod dolną krawędzią poprzedniego + odstęp
					top = lastBottom === null ? num( page.content_top, 179.04 ) : lastBottom + gap;
				}
				wrapped.forEach( function ( l, i ) {
					if ( l !== '' ) lines.push( { font: b.font, size: size, step: step, text: l, top: top + i * step, key: b.key, align: b.align || 'center', dx: num( b.dx, 0 ) } );
				} );
				var bottom = top + wrapped.length * step;
				if ( pos !== 'fixed' ) {
					lastBottom = ( pos === 'line' && lastBottom !== null ) ? Math.max( lastBottom, bottom ) : bottom;
				}
				prevTop = top;
				maxBottom = maxBottom === null ? bottom : Math.max( maxBottom, bottom );
			} );
			return { lines: lines, bottom: maxBottom === null ? num( page.content_top, 0 ) : maxBottom };
		}

		var limit = num( page.content_bottom, 765 );
		var res = flow( 1 ), scale = 1;
		if ( res.bottom > limit && +page.fit_page ) {
			var lo = Math.min( 1, Math.max( 0.3, num( page.min_scale, 0.7 ) ) ), hi = 1;
			var low = flow( lo );
			if ( low.bottom > limit ) {
				res = low; scale = lo;
			} else {
				for ( var i = 0; i < 20; i++ ) {
					var mid = ( lo + hi ) / 2, r = flow( mid );
					if ( r.bottom > limit ) hi = mid; else { lo = mid; res = r; }
				}
				scale = lo;
			}
		}
		return { lines: res.lines, scale: scale, bottom: res.bottom, overflow: res.bottom > limit + 0.5 };
	}

	function hexToRgb( hex ) {
		var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec( String( hex || '' ) );
		return m ? [ parseInt( m[ 1 ], 16 ) / 255, parseInt( m[ 2 ], 16 ) / 255, parseInt( m[ 3 ], 16 ) / 255 ] : [ 0, 0, 0 ];
	}

	/** Wszystkie czcionki potrzebne dla układu (bloki + stopka). */
	function usedFonts( layout ) {
		var set = {};
		layout.blocks.forEach( function ( b ) { if ( +b.enabled && FONTS[ b.font ] ) set[ b.font ] = 1; } );
		if ( +layout.footer.enabled && FONTS[ layout.footer.font ] ) set[ layout.footer.font ] = 1;
		return Object.keys( set );
	}

	/**
	 * Buduje dokument PDF.
	 * @param {object} lib      biblioteka pdf-lib (window.PDFLib albo require('pdf-lib'))
	 * @param {object} fontkit  @pdf-lib/fontkit
	 * @param {object} layout   układ (merge())
	 * @param {object} draft    treść z panelu
	 * @param {object} assets   { fonts: {klucz: ArrayBuffer}, logo: ArrayBuffer|null }
	 * @return {Promise<{doc: PDFDocument, info: object}>}
	 */
	async function build( lib, fontkit, layout, draft, assets ) {
		var doc = await lib.PDFDocument.create();
		doc.registerFontkit( fontkit );
		doc.setTitle( 'Lunch — Weranda' );
		doc.setCreator( 'Weranda Lunch (GastroFlowx)' );
		var page = doc.addPage( [ PAGE_W, PAGE_H ] );

		var fonts = {};
		var keys = usedFonts( layout );
		for ( var i = 0; i < keys.length; i++ ) {
			var bytes = assets.fonts[ keys[ i ] ];
			if ( ! bytes ) throw new Error( 'Brak czcionki: ' + keys[ i ] );
			fonts[ keys[ i ] ] = await doc.embedFont( bytes, { subset: true } );
		}
		function measure( key, size, text ) { return fonts[ key ].widthOfTextAtSize( text, size ); }
		// Linia bazowa w pudełku linii (half-leading, jak w Canvie):
		// top + krok/2 + (ascent − |descent|)/2 × rozmiar.
		function baseline( key, size, top, step ) {
			var asc = fonts[ key ].heightAtSize( size, { descender: false } );
			var full = fonts[ key ].heightAtSize( size, { descender: true } );
			return PAGE_H - ( top + step / 2 + asc - full / 2 );
		}

		var p = layout.page;
		var color = hexToRgb( p.color );
		var rgb = lib.rgb( color[ 0 ], color[ 1 ], color[ 2 ] );
		var centers = [ num( p.center_left, 150.1 ), num( p.center_right, 446.9 ) ];

		var colW = num( p.max_width, 250 );
		function drawCentered( key, size, text, top, step, align, dx ) {
			var w = measure( key, size, text );
			var y = baseline( key, size, top, step );
			dx = num( dx, 0 );
			centers.forEach( function ( cx ) {
				var x = align === 'left' ? cx - colW / 2 : ( align === 'right' ? cx + colW / 2 - w : cx - w / 2 );
				page.drawText( text, { x: x + dx, y: y, size: size, font: fonts[ key ], color: rgb } );
			} );
		}

		// Logo (lewa i prawa połówka).
		var lg = layout.logo;
		if ( +lg.enabled && assets.logo ) {
			var img;
			var head = new Uint8Array( assets.logo.slice ? assets.logo.slice( 0, 4 ) : assets.logo );
			img = ( head[ 0 ] === 0xFF && head[ 1 ] === 0xD8 ) ? await doc.embedJpg( assets.logo ) : await doc.embedPng( assets.logo );
			var lw = num( lg.width, 261.1 );
			var lh = lw * img.height / img.width;
			var ly = PAGE_H - num( lg.top, 59.16 ) - lh;
			[ num( lg.left_x, 19.7 ), num( lg.right_x, 313.6 ) ].forEach( function ( x ) {
				page.drawImage( img, { x: x, y: ly, width: lw, height: lh } );
			} );
		}

		// Treść.
		var res = compute( layout, draft, measure );
		res.lines.forEach( function ( l ) { drawCentered( l.font, l.size, l.text, l.top, l.step, l.align, l.dx ); } );

		// Stopka.
		var ft = layout.footer;
		if ( +ft.enabled && ft.text ) {
			var fstep = num( ft.size, 8 ) * num( ft.lh, 1.4 );
			String( ft.text ).split( '\n' ).forEach( function ( t, i ) {
				if ( t.trim() ) drawCentered( ft.font, num( ft.size, 8 ), t.trim(), num( ft.top, 783.21 ) + i * fstep, fstep );
			} );
		}

		// Linia cięcia.
		if ( +p.cut_line ) {
			var cc = hexToRgb( p.cut_color );
			page.drawLine( {
				start: { x: num( p.cut_x, 297.1 ), y: 0 },
				end: { x: num( p.cut_x, 297.1 ), y: PAGE_H },
				thickness: Math.max( 0.1, num( p.cut_width, 0.4 ) ),
				color: lib.rgb( cc[ 0 ], cc[ 1 ], cc[ 2 ] ),
			} );
		}

		return { doc: doc, info: { scale: res.scale, overflow: res.overflow, bottom: res.bottom } };
	}

	return {
		PAGE_W: PAGE_W,
		PAGE_H: PAGE_H,
		FONTS: FONTS,
		FIELDS: FIELDS,
		defaults: defaults,
		merge: merge,
		wrap: wrap,
		compute: compute,
		usedFonts: usedFonts,
		build: build,
	};
} ) );
