/* global Vue, wp, gfxmConfig */
/**
 * Menu GastroFlowX — SPA (Vue 3 UMD, bez kroku budowania).
 * Zakładki: Kreator menu · Wersje standardowe · Baza dań · Ustawienia PDF.
 * Komunikacja: REST gastroflowx-menu/v1, nonce wp_rest w X-WP-Nonce.
 */
( function () {
'use strict';

const cfg   = window.gfxmConfig || {};
const PAGE_W = cfg.pageW || 794;
const PAGE_H = cfg.pageH || 1123;
const FIT_STEPS = [ 1, 0.94, 0.88, 0.82, 0.76, 0.70 ]; // identyczne jak w GFXM_PDF::fit_scales()

/* ------------------------------------------------------------------ */
/* Komunikacja z API                                                  */
/* ------------------------------------------------------------------ */

async function parseJsonSafely( res ) {
	const text = await res.text();
	try {
		return JSON.parse( text );
	} catch ( e ) {
		const snippet = text.replace( /\s+/g, ' ' ).trim().slice( 0, 200 );
		throw new Error( 'Serwer zwrócił nieprawidłową odpowiedź (status ' + res.status + '). Fragment: ' + snippet );
	}
}

async function api( path, options ) {
	options = options || {};
	const init = {
		method: options.method || 'GET',
		headers: { 'X-WP-Nonce': cfg.nonce },
		credentials: 'same-origin',
	};
	if ( options.body !== undefined ) {
		init.headers[ 'Content-Type' ] = 'application/json';
		init.body = JSON.stringify( options.body );
	}
	const res  = await fetch( cfg.restUrl + path, init );
	const data = await parseJsonSafely( res );
	if ( ! res.ok ) {
		throw new Error( ( data && data.message ) || 'Błąd komunikacji z serwerem.' );
	}
	return data;
}

/** Pobiera PDF (binarnie) — błędy serwer i tak zwraca jako JSON. */
async function apiPdf( body ) {
	const res = await fetch( cfg.restUrl + '/pdf', {
		method: 'POST',
		headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
		credentials: 'same-origin',
		body: JSON.stringify( body ),
	} );
	const type = res.headers.get( 'Content-Type' ) || '';
	if ( ! res.ok || type.indexOf( 'application/pdf' ) === -1 ) {
		const data = await parseJsonSafely( res );
		throw new Error( ( data && data.message ) || 'Nie udało się wygenerować PDF.' );
	}
	const disp = res.headers.get( 'Content-Disposition' ) || '';
	const m    = disp.match( /filename="([^"]+)"/ );
	return { blob: await res.blob(), filename: m ? m[ 1 ] : 'menu.pdf' };
}

function downloadBlob( blob, filename ) {
	if ( blob && blob.type !== 'application/pdf' && /\.pdf$/i.test( filename ) ) {
		blob = new Blob( [ blob ], { type: 'application/pdf' } );
	}
	const url = URL.createObjectURL( blob );
	const a   = document.createElement( 'a' );
	a.href     = url;
	a.download = filename;
	a.rel      = 'noopener';
	a.style.display = 'none';
	document.body.appendChild( a );
	a.click();
	// Link i blob: zostają dłużej — przeglądarka (zwł. w oknie PWA) może
	// rozpocząć zapis z opóźnieniem; 4 s bywało za mało.
	setTimeout( function () { a.remove(); }, 1000 );
	setTimeout( function () { URL.revokeObjectURL( url ); }, 120000 );
}

/* ------------------------------------------------------------------ */
/* Zapis pliku w trybie zainstalowanej aplikacji (PWA)                 */
/* W oknie PWA na macOS <a download> + blob: potrafi zgłosić „pobrano”, */
/* a pliku nie ma w Pobranych. Gdzie jest File System Access API       */
/* (Chrome/Edge), pytamy o miejsce i zapisujemy plik prosto na dysk.   */
/* ------------------------------------------------------------------ */

function isStandalone() {
	try {
		return window.matchMedia( '(display-mode: standalone)' ).matches ||
			window.matchMedia( '(display-mode: window-controls-overlay)' ).matches ||
			window.matchMedia( '(display-mode: minimal-ui)' ).matches ||
			window.navigator.standalone === true;
	} catch ( e ) {
		return false;
	}
}
const CAN_PICK_SAVE = typeof window.showSaveFilePicker === 'function';

/**
 * WYWOŁYWAĆ PRZED pierwszym await w obsłudze kliknięcia — okno zapisu
 * wymaga świeżej aktywacji użytkownika (generowanie PDF na serwerze trwa).
 * Zwraca uchwyt pliku lub null (= zwykłe pobieranie). AbortError = anulowano.
 */
function pickSaveHandle( name ) {
	if ( ! CAN_PICK_SAVE || ! isStandalone() ) return Promise.resolve( null );
	let p;
	try {
		p = window.showSaveFilePicker( {
			suggestedName: name,
			types: [ { description: 'Dokument PDF', accept: { 'application/pdf': [ '.pdf' ] } } ],
		} );
	} catch ( e ) {
		return Promise.resolve( null );
	}
	return p.catch( function ( e ) {
		if ( e && e.name === 'AbortError' ) throw e;
		return null;
	} );
}

async function saveBlob( blob, filename, handle ) {
	if ( ! handle ) {
		downloadBlob( blob, filename );
		return false;
	}
	try {
		const w = await handle.createWritable();
		await w.write( blob );
		await w.close();
		return true;
	} catch ( e ) {
		downloadBlob( blob, filename ); // awaryjnie
		return false;
	}
}

/** Przybliżenie sanitize_title( remove_accents() ) z PHP — tylko jako podpowiedź nazwy. */
function slugify( str ) {
	const map = { 'ł': 'l', 'Ł': 'l' };
	return String( str || '' )
		.replace( /[łŁ]/g, ( c ) => map[ c ] )
		.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
		.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
}

/* ------------------------------------------------------------------ */
/* Pomocnicze                                                         */
/* ------------------------------------------------------------------ */

const clone = ( o ) => JSON.parse( JSON.stringify( o ) );

function dishName( d, lang ) {
	const n = ( lang === 'en' && d.name_en && d.name_en.trim() ) ? d.name_en : d.name_pl;
	// „|” = ręczne złamanie wiersza (jak GFXM_PDF::breaks()).
	return String( n || '' ).split( '|' ).map( ( x ) => x.trim() ).join( '\n' );
}
function dishDesc( d, lang ) {
	return ( lang === 'en' && d.desc_en && d.desc_en.trim() ) ? d.desc_en : ( d.desc_pl || '' );
}

/** Lustrzane odbicie GFXM_PDF::nbsp() — spójniki/przyimki sklejone ze słowem następnym. */
const NBSP_WORDS = 'a|i|o|u|w|z|ze|we|na|do|od|po|za|ku|bez|dla|nad|pod|przy|przez|oraz|lub|albo|czy|ale|lecz|że|niż|jak|an|the|and|or|of|in|on|with|to|at|by|for|&';
const NBSP_RE = new RegExp( '(?<=^|[\\s\\u00A0(„"\'/-])(' + NBSP_WORDS + ')[ \\t]+(?=\\S)', 'giu' );
function nbsp( t ) { return t ? String( t ).replace( NBSP_RE, '$1\u00A0' ) : ''; }
function catName( c, lang ) {
	return ( lang === 'en' && c.name_en && c.name_en.trim() ) ? c.name_en : c.name_pl;
}

/** Lustrzane odbicie GFXM_PDF::logo_box() — podgląd = PDF. */
function logoBox( logo, contentW ) {
	const nw = +logo.natural_w || 0, nh = +logo.natural_h || 0;
	const ratio = ( nw > 0 && nh > 0 ) ? nh / nw : 0;
	let w = null, h = null;
	if ( logo.width_unit === 'px' && +logo.width > 0 ) w = +logo.width;
	else if ( logo.width_unit === '%' && +logo.width > 0 ) w = contentW * +logo.width / 100;
	if ( logo.height_unit === 'px' && +logo.height > 0 ) h = +logo.height;
	else if ( logo.height_unit === '%' && +logo.height > 0 ) h = PAGE_H * +logo.height / 100;
	if ( w === null && h === null ) w = contentW * 0.25;
	if ( h === null && w !== null ) h = ratio ? w * ratio : null;
	if ( w === null && h !== null ) w = ratio ? h / ratio : null;
	if ( w && w > PAGE_W ) { const s = PAGE_W / w; w = PAGE_W; h = h ? h * s : h; }
	return { w: w, h: h, reserve: h ? h : ( w ? w * 0.4 : 60 ) };
}

/** Lustro GFXM_PDF::template_settings(): „split” nadpisuje geometrię i rozmiary logo. */
function templateSettings( settings, tpl ) {
	if ( tpl !== 'split' || ! settings || ! settings.split ) return settings;
	const sp = settings.split, pick = ( o ) => ( { width: o.width, width_unit: o.width_unit, height: o.height, height_unit: o.height_unit } );
	return Object.assign( {}, settings, {
		layout: Object.assign( {}, settings.layout, sp.layout || {} ),
		header: Object.assign( {}, settings.header, pick( sp.header || {} ) ),
		footer: Object.assign( {}, settings.footer, pick( sp.footer || {} ) ),
	} );
}

/** Lustrzane odbicie GFXM_PDF::geometry() — kolumny 1:1 z prowadnic. */
function geometry( L ) {
	const lx = +L.left_x || 0, rx = +L.right_x || 0;
	return {
		xLeft: lx, wLeft: Math.max( 80, ( +L.left_end || 0 ) - lx ),
		xRight: rx, wRight: Math.max( 80, ( +L.right_end || 0 ) - rx ),
		contentW: Math.max( 200, ( +L.right_end || 0 ) - lx ),
	};
}

const pt = ( v ) => v * 96 / 72;
const DEF_PT = { head_pt: 22, sub_pt: 10.3, name_pt: 9, desc_pt: 9 };
const SUB_FONTS = { 'SweetBelly-Sans': 'Sweet Belly Sans', 'SweetBelly-Script': 'Sweet Belly Script', AdornGarland: 'Adorn Garland' };
const subFontKey = ( k ) => ( SUB_FONTS[ k ] ? k : 'SweetBelly-Sans' );
const size = ( L, k ) => ( +L[ k ] > 0 ? +L[ k ] : DEF_PT[ k ] );

/** Czcionki podglądu — te same pliki TTF, których używa Dompdf. */
( function injectFonts() {
	const f = cfg.fonts || {};
	let css = '';
	const face = ( fam, url ) => { if ( url ) css += "@font-face{font-family:'" + fam + "';src:url('" + url + "') format('truetype');font-display:swap}"; };
	Object.keys( f.heading || {} ).forEach( ( w ) => face( 'GFXM Head ' + w, f.heading[ w ] ) );
	face( 'GFXM Script', f.script );
	// Podtytuły — osobna rodzina na każdy krój z listy (przełącznik działa bez zapisu).
	Object.keys( f.sub || {} ).forEach( ( k ) => face( 'GFXM Sub ' + k, f.sub[ k ].url ) );
	face( 'GFXM Body', f.body );
	face( 'GFXM Bold', f.bold );
	// Nazwa dania — osobna rodzina na każdą grubość (Medium / Bold), żeby
	// przełącznik w Ustawieniach działał w podglądzie od razu, bez zapisu.
	Object.keys( f.name || {} ).forEach( ( w ) => face( 'GFXM Name ' + w, f.name[ w ] ) );
	const el = document.createElement( 'style' );
	el.id = 'gfxm-fonts';
	el.textContent = css;
	document.head.appendChild( el );
} )();

/* Wartości domyślne (z PHP: GFXM_Install::default_settings / default_categories). */
const DEFAULTS = cfg.defaults || { settings: null, categories: [] };
const LOGO_SIZE_KEYS = [ 'width', 'width_unit', 'height', 'height_unit' ];
const GUIDE_KEYS = [ 'header_top', 'content_top', 'content_bottom', 'footer_top', 'left_x', 'left_end', 'right_x', 'right_end', 'heading_gap', 'dish_gap', 'category_gap', 'fit_page', 'cut_line' ];
const TYPO_KEYS = [ 'heading_weight', 'name_weight', 'head_pt', 'sub_font', 'sub_upper', 'sub_pt', 'text_lh', 'name_pt', 'desc_pt', 'head_track', 'sub_track', 'text_track' ];
const copyKeys = ( target, source, keys ) => { keys.forEach( ( k ) => { if ( source && source[ k ] !== undefined ) target[ k ] = clone( source[ k ] ); } ); };

function emptyDraft() {
	return { id: null, name: '', lang: 'pl', items: {}, extra_text: '', extra_position: 'bottom', template: 'columns' };
}

function fmtDate( s ) {
	if ( ! s ) return '';
	const d = new Date( s.replace( ' ', 'T' ) );
	if ( isNaN( d ) ) return s;
	return d.toLocaleDateString( 'pl-PL', { day: 'numeric', month: 'short', year: 'numeric' } ) + ', ' +
		d.toLocaleTimeString( 'pl-PL', { hour: '2-digit', minute: '2-digit' } );
}

/* ------------------------------------------------------------------ */
/* Komponent: podgląd arkusza A4                                      */
/* ------------------------------------------------------------------ */

const PaperPreview = {
	props: [ 'version', 'settings', 'categories', 'dishById', 'template' ],
	data() {
		// subBl — linia bazowa podtytułu w przeglądarce (em od góry jego pola),
		// headBlPx — linia bazowa nazwy kategorii (px od góry bloku nagłówka,
		// przy skali 1); obie mierzone w measure() — odpowiedniki SUB_BL / HEAD_BL.
		return { k: 0.5, fit: 1, meas: {}, extraH: 0, subBl: 0.8, headBlPx: null, pageW: PAGE_W, pageH: PAGE_H, ro: null, fitToken: 0, ready: false };
	},
	computed: {
		tpl() { return this.template === 'split' ? 'split' : 'columns'; },
		isSplit() { return this.tpl === 'split'; },
		/** Ustawienia efektywne wybranego szablonu. */
		S() { return templateSettings( this.settings, this.tpl ); },
		L() { return this.S.layout; },
		g() { return geometry( this.L ); },
		logoW() { return this.isSplit ? this.g.wLeft : this.g.contentW; },
		hBox() { return logoBox( this.S.header, this.logoW ); },
		fBox() { return logoBox( this.S.footer, this.logoW ); },
		hasFoot() { return !! this.S.footer.url; },
		/** Przesunięcia kopii: „split” rysuje ten sam skład w lewej i prawej połówce. */
		copies() { return this.isSplit ? [ 0, ( +this.L.right_x || 0 ) - ( +this.L.left_x || 0 ) ] : [ 0 ]; },
		logoBoxStyle() {
			return ( dx ) => ( this.isSplit
				? { left: ( this.g.xLeft + dx ) + 'px', width: this.g.wLeft + 'px' }
				: { left: 0, width: '100%' } );
		},
		lang() { return this.version.lang === 'en' ? 'en' : 'pl'; },
		cats() {
			const out = [];
			( this.categories || [] ).forEach( ( c ) => {
				const ids = ( this.version.items && this.version.items[ c.id ] ) || [];
				const dishes = ids.map( ( id ) => this.dishById[ id ] ).filter( Boolean );
				if ( ! dishes.length ) return;
				const sub = ( this.lang === 'en' && ( c.sub_en || '' ).trim() ) ? c.sub_en : ( c.sub_pl || '' );
				out.push( {
					id: c.id, name: catName( c, this.lang ), sub: sub, column: c.column === 'right' ? 'right' : 'left', align: c.align === 'bottom' ? 'bottom' : 'top', dishes: dishes,
					subDx: +c.sub_dx || 0, subDy: +c.sub_dy || 0, subPt: +c.sub_pt > 0 ? +c.sub_pt : 0,
					dx: +c.dx || 0, gap: ( c.gap === '' || c.gap === null || c.gap === undefined || isNaN( +c.gap ) ) ? null : +c.gap,
				} );
			} );
			return out;
		},
		extra() { return ( this.version.extra_text || '' ).trim(); },
		sizes() {
			const head = pt( size( this.L, 'head_pt' ) );
			const k = this.fit;
			const F = cfg.fonts || {}, dy = F.dy || {};
			const name = pt( size( this.L, 'name_pt' ) ) * k, desc = pt( size( this.L, 'desc_pt' ) ) * k;
			return {
				head: head * k, headMt: head * ( F.headMt !== undefined ? F.headMt : -0.12 ) * k,
				headLh: F.headLh || 1.2,
				// Linia bazowa nazwy kategorii w PDF (od góry bloku) — od niej liczymy podtytuł.
				headBl: head * ( F.headBl || 0.84 ) * k,
				// Przesunięcie wizualne linii bazowej, żeby tekst leżał jak w PDF.
				dyHead: ( dy.heading || 0 ) * head * k,
				dyName: ( ( F.nameDy && F.nameDy[ this.nameWeight ] !== undefined ) ? F.nameDy[ this.nameWeight ] : ( dy.bold || 0 ) ) * name, dyDesc: ( dy.body || 0 ) * desc,
				name: name,
				desc: desc,
				headGap: ( +this.L.heading_gap || 0 ) * k,
				dishGap: ( +this.L.dish_gap || 0 ) * k,
				catGap: ( +this.L.category_gap || 0 ) * k,
			};
		},
		headFamily() { return "'GFXM Head " + ( this.L.heading_weight || 'ExtraBold' ) + "', 'Poppins', sans-serif"; },
		nameWeight() { return this.L.name_weight === 'Medium' ? 'Medium' : 'Bold'; },
		/** Światło z ustawień (em) — jak GFXM_PDF::track(). */
		track() {
			const t = ( k, d ) => ( this.L[ k ] !== undefined && this.L[ k ] !== '' && ! isNaN( +this.L[ k ] ) ? +this.L[ k ] : d );
			return { head: t( 'head_track', 0.05 ) + 'em', sub: t( 'sub_track', 0.14 ) + 'em', text: t( 'text_track', 0 ) + 'em' };
		},
		subFont() {
			const key = subFontKey( this.L.sub_font ), list = ( cfg.fonts && cfg.fonts.sub ) || {};
			return { family: "'GFXM Sub " + key + "', 'GFXM Script', cursive", factor: ( list[ key ] && list[ key ].factor ) || 1 };
		},
		subUpper() { return this.L.sub_upper === undefined || !! +this.L.sub_upper; },
		/** Interlinia dań (Ustawienia PDF → Typografia), jak GFXM_PDF::base_css(). */
		textLh() { return +this.L.text_lh > 0 ? +this.L.text_lh : 1.2; },
		nameFamily() { return "'GFXM Name " + this.nameWeight + "', 'GFXM Bold', sans-serif"; },
		/** Lustrzane odbicie GFXM_PDF::layout_page(): „top” od górnej prowadnicy w dół, „bottom” od dolnej w górę. */
		lay() {
			return this.isSplit ? this.laySplit : this.layColumns;
		},
		/** Lustro GFXM_PDF::layout_split(): jedna kolumna od content_top w dół. */
		laySplit() {
			const L = this.L, gap = this.sizes.catGap, g = this.g;
			let top = +L.content_top || 0, bottom = +L.content_bottom || 0, extra = null;
			if ( this.extra && this.extraH > 0 ) {
				if ( this.version.extra_position === 'top' ) { extra = { top: top }; top += this.extraH + gap; }
				else { extra = { top: bottom - this.extraH }; bottom = extra.top - gap; }
			}
			const pos = {};
			let y = top;
			this.cats.forEach( ( c ) => {
				const m = this.meas[ c.id ] || { h: 0 };
				pos[ c.id ] = { x: g.xLeft + c.dx, y: y, w: g.wLeft };
				y += m.h + gap;
			} );
			const end = this.cats.length ? y - gap : top;
			return { pos: pos, extra: extra, footTop: +L.footer_top || 0, overflow: this.cats.length > 0 && end > bottom + 0.5 };
		},
		layColumns() {
			const L = this.L, gap = this.sizes.catGap, g = this.g;
			let top = +L.content_top || 0, bottom = +L.content_bottom || 0, extra = null;
			if ( this.extra && this.extraH > 0 ) {
				if ( this.version.extra_position === 'top' ) { extra = { top: top }; top += this.extraH + gap; }
				else { extra = { top: bottom - this.extraH }; bottom = extra.top - gap; }
			}
			const pos = {};
			let overflow = false;
			[ 'left', 'right' ].forEach( ( col ) => {
				const inCol = this.cats.filter( ( c ) => c.column === col );
				const tops = inCol.filter( ( c ) => c.align !== 'bottom' ), bots = inCol.filter( ( c ) => c.align === 'bottom' );
				const h = ( c ) => ( this.meas[ c.id ] ? this.meas[ c.id ].h : 0 );
				let y = top;
				tops.forEach( ( c ) => { pos[ c.id ] = { y: y }; y += h( c ) + gap; } );
				const topEnd = tops.length ? y - gap : top;
				y = bottom;
				bots.slice().reverse().forEach( ( c ) => { y -= h( c ); pos[ c.id ] = { y: y }; y -= gap; } );
				const botStart = bots.length ? y + gap : bottom;
				if ( topEnd > bottom + 0.5 || ( tops.length && bots.length && topEnd + gap > botStart + 0.5 ) || botStart < top - 0.5 ) overflow = true;
			} );
			this.cats.forEach( ( c ) => {
				pos[ c.id ].x = ( c.column === 'right' ? g.xRight : g.xLeft ) + c.dx;
				pos[ c.id ].w = c.column === 'right' ? g.wRight : g.wLeft;
			} );
			return { pos: pos, extra: extra, footTop: +L.footer_top || 0, overflow: this.cats.length > 0 && overflow };
		},
		signature() { return JSON.stringify( [ this.version, this.settings, this.categories, this.template ] ); },
	},
	watch: {
		signature() { this.refit(); },
	},
	mounted() {
		const vp = this.$refs.vp;
		// Moduł może startować w ukrytym widoku Huba (display:none) — gdy
		// arkusz stanie się widoczny, liczymy skalę i skład od nowa.
		let visible = false;
		const update = () => {
			if ( ! vp.clientWidth ) { visible = false; return; }
			this.k = vp.clientWidth / PAGE_W;
			if ( ! visible ) { visible = true; this.refit(); }
		};
		update();
		if ( window.ResizeObserver ) {
			this.ro = new ResizeObserver( update );
			this.ro.observe( vp );
		}
		if ( document.fonts && document.fonts.ready ) document.fonts.ready.then( () => this.refit() );
		this.refit();
	},
	beforeUnmount() { if ( this.ro ) this.ro.disconnect(); },
	methods: {
		dishName, dishDesc, nbsp,
		px( v ) { return v + 'px'; },
		imgStyle( b ) { return { width: b.w ? b.w + 'px' : 'auto', height: b.h ? b.h + 'px' : 'auto' }; },
		/** Lustro GFXM_PDF::sub_box(): podtytuł od początku i linii bazowej nazwy kategorii. */
		subStyle( c ) {
			const k = this.fit;
			const s = pt( c.subPt > 0 ? c.subPt : size( this.L, 'sub_pt' ) ) * this.subFont.factor * k;
			return {
				fontFamily: this.subFont.family, letterSpacing: this.track.sub, fontSize: s + 'px',
				textTransform: this.subUpper ? 'uppercase' : 'none',
				left: ( c.subDx * k ) + 'px',
				top: ( ( this.headBlPx !== null ? this.headBlPx * k : this.sizes.headBl ) + c.subDy * k - s * this.subBl ) + 'px',
			};
		},
		measure() {
			const root = this.$refs.paper;
			if ( ! root || ! root.offsetParent ) return false;
			const meas = {};
			this.cats.forEach( ( c ) => {
				const el = root.querySelector( '[data-cat="' + c.id + '"]' );
				if ( ! el ) return;
				meas[ c.id ] = { h: el.offsetHeight };
			} );
			this.meas = meas;
			// Linia bazowa podtytułu: znacznik o zerowej wysokości na linii bazowej.
			const hbl = root.querySelector( '.p-h .p-bl' );
			if ( hbl && this.fit > 0 ) this.headBlPx = ( hbl.offsetTop + hbl.offsetParent.offsetTop ) / this.fit;
			const bl = root.querySelector( '.p-s .p-bl' );
			if ( bl ) {
				const fs = parseFloat( getComputedStyle( bl.parentNode ).fontSize ) || 0;
				if ( fs > 0 ) this.subBl = bl.offsetTop / fs;
			}
			const ex = root.querySelector( '.p-extra' );
			this.extraH = ex ? ex.offsetHeight : 0;
			return true;
		},
		/** Ten sam algorytm co PDF: skala 100% → 70%, aż skład się zmieści. */
		async refit() {
			const token = ++this.fitToken;
			const steps = +this.L.fit_page ? FIT_STEPS : [ 1 ];
			for ( let i = 0; i < steps.length; i++ ) {
				this.fit = steps[ i ];
				await this.$nextTick();
				if ( token !== this.fitToken ) return;
				if ( ! this.measure() ) return;
				await this.$nextTick();
				if ( ! this.lay.overflow ) break;
			}
			this.ready = true;
		},
	},
	template: `
	<div class="gfxm-paper-wrap">
		<div class="gfxm-paper-viewport" ref="vp" :style="{ height: ( pageH * k ) + 'px' }">
			<div class="gfxm-paper" ref="paper" :style="{ width: pageW + 'px', height: pageH + 'px', transform: 'scale(' + k + ')' }">
				<div v-if="isSplit && +L.cut_line" class="p-cut" :style="{ left: ( pageW / 2 ) + 'px' }"></div>

				<template v-for="( dx, ci ) in copies" :key="'copy' + ci">
					<div class="p-logo" :style="Object.assign( { top: L.header_top + 'px' }, logoBoxStyle( dx ) )">
						<img v-if="S.header.url" :src="S.header.url" :style="imgStyle( hBox )" alt="" @load="refit">
						<div v-else class="p-logo-ph" :style="{ width: ( hBox.w || 145 ) + 'px', height: ( hBox.h || 110 ) + 'px' }">logo nagłówka</div>
					</div>

					<div v-for="c in cats" :key="c.id + '-' + ci" class="p-cat" :data-cat="ci === 0 ? c.id : null"
						:style="{ left: ( ( lay.pos[ c.id ] ? lay.pos[ c.id ].x : g.xLeft ) + dx ) + 'px', top: ( lay.pos[ c.id ] ? lay.pos[ c.id ].y : L.content_top ) + 'px', width: ( lay.pos[ c.id ] ? lay.pos[ c.id ].w : g.wLeft ) + 'px' }">
						<div class="p-head" :style="{ marginBottom: px( c.gap !== null ? c.gap * fit : sizes.headGap ) }">
							<div class="p-h" :style="{ fontFamily: headFamily, letterSpacing: track.head, fontSize: px( sizes.head ), marginTop: px( sizes.headMt ), lineHeight: sizes.headLh, top: px( sizes.dyHead ) }"><span>{{ nbsp( c.name ) }}</span><i class="p-bl"></i></div>
							<div v-if="c.sub.trim()" class="p-s" :style="subStyle( c )"><span>{{ nbsp( c.sub ) }}</span><i class="p-bl"></i></div>
						</div>
						<div v-for="( d, n ) in c.dishes" :key="d.id" :style="{ marginTop: n ? px( sizes.dishGap ) : 0 }">
							<div class="p-name" :style="{ fontFamily: nameFamily, lineHeight: textLh, letterSpacing: track.text, fontSize: px( sizes.name ), top: px( sizes.dyName ) }">{{ nbsp( dishName( d, lang ) ) }}</div>
							<div v-if="dishDesc( d, lang )" class="p-desc" :style="{ lineHeight: textLh, letterSpacing: track.text, fontSize: px( sizes.desc ), top: px( sizes.dyDesc ) }">{{ nbsp( dishDesc( d, lang ) ) }}</div>
						</div>
					</div>

					<div v-if="extra" class="p-extra" :style="isSplit
						? { top: ( lay.extra ? lay.extra.top : -9999 ) + 'px', left: ( g.xLeft + dx ) + 'px', width: g.wLeft + 'px', lineHeight: textLh, letterSpacing: track.text, fontSize: px( sizes.desc ) }
						: { top: ( lay.extra ? lay.extra.top : -9999 ) + 'px', left: ( g.xLeft + g.contentW * 0.1 ) + 'px', width: ( g.contentW * 0.8 ) + 'px', lineHeight: textLh, letterSpacing: track.text, fontSize: px( sizes.desc ) }">{{ nbsp( extra ) }}</div>

					<div v-if="! cats.length" class="p-placeholder" :style="Object.assign( { top: L.content_top + 'px' }, logoBoxStyle( dx ) )">Zaznacz dania, aby zobaczyć menu</div>

					<div v-if="hasFoot" class="p-logo" :style="Object.assign( { top: lay.footTop + 'px' }, logoBoxStyle( dx ) )">
						<img :src="S.footer.url" :style="imgStyle( fBox )" alt="" @load="refit">
					</div>
				</template>
			</div>
		</div>
		<div v-if="ready && lay.overflow" class="gfxm-overflow"><i class="fa-solid fa-triangle-exclamation"></i> Treść nie mieści się między prowadnicami nawet po zmniejszeniu. Usuń część dań albo zmniejsz odstępy/rozmiary w Ustawieniach PDF.</div>
		<div v-else-if="fit < 1" class="gfxm-sub" style="margin-top:8px;">Treść zmniejszona do {{ Math.round( fit * 100 ) }}%, żeby zmieściła się między prowadnicami.</div>
	</div>`,
};

/* ------------------------------------------------------------------ */
/* Aplikacja                                                          */
/* ------------------------------------------------------------------ */

function createGfxmApp() {
	return Vue.createApp( {
		components: { 'gfxm-paper': PaperPreview },
		data() {
			return {
				loading: true,
				initError: '',
				tab: 'creator',
				canUpload: !! cfg.canUpload,
				weights: cfg.weights || [ 'Regular', 'Medium', 'SemiBold', 'Bold', 'ExtraBold', 'Black' ],
				nameWeights: cfg.nameWeights || [ 'Bold', 'Medium' ],
				subFonts: SUB_FONTS,
				templates: cfg.templates || { columns: 'Dwie kolumny', split: 'Podzielona strona' },
				editTpl: 'columns',

				categories: [],
				dishes: [],
				versions: [],
				settings: null,
				fonts: [],

				draft: emptyDraft(),
				draftSnapshot: '',
				editingName: '',
				openCats: {},

				selected: [],

				dishForm: null,
				dishSearch: '',
				dishCat: '',
				catRows: [],

				settingsForm: null,

				busy: { save: false, pdf: null, dish: false, cats: false, settings: false },
				toasts: [],
			};
		},

		computed: {
			dishById() {
				const m = {};
				this.dishes.forEach( ( d ) => { m[ d.id ] = d; } );
				return m;
			},
			dishesByCat() {
				const m = {};
				this.dishes.forEach( ( d ) => { ( m[ d.category_id ] = m[ d.category_id ] || [] ).push( d ); } );
				return m;
			},
			draftCount() {
				return Object.values( this.draft.items ).reduce( ( a, ids ) => a + ids.length, 0 );
			},
			isDirty() {
				return this.draftSignature() !== this.draftSnapshot;
			},
			missingEn() {
				if ( this.draft.lang !== 'en' ) return 0;
				let n = 0;
				Object.values( this.draft.items ).forEach( ( ids ) => ids.forEach( ( id ) => {
					const d = this.dishById[ id ];
					if ( d && ! ( d.name_en || '' ).trim() ) n++;
				} ) );
				return n;
			},
			filteredDishes() {
				const q = this.dishSearch.trim().toLowerCase();
				return this.dishes.filter( ( d ) => {
					if ( this.dishCat && d.category_id !== this.dishCat ) return false;
					if ( ! q ) return true;
					return ( d.name_pl + ' ' + d.name_en + ' ' + d.desc_pl + ' ' + d.desc_en ).toLowerCase().indexOf( q ) > -1;
				} );
			},
			filteredGroups() {
				return this.categories.map( ( c ) => ( {
					cat: c,
					dishes: this.filteredDishes.filter( ( d ) => d.category_id === c.id ),
				} ) ).filter( ( g ) => g.dishes.length );
			},
			/** Podgląd w Ustawieniach: bieżący szkic, a gdy pusty — pierwsza wersja. */
			settingsPreviewSource() {
				if ( this.draftCount ) return this.draft;
				return this.versions[ 0 ] ? this.normalizeVersion( this.versions[ 0 ] ) : this.draft;
			},
			/** Prowadnice edytowanego szablonu. */
			tplLayout() {
				return this.editTpl === 'split' ? this.settingsForm.split.layout : this.settingsForm.layout;
			},
			catsDirty() {
				return JSON.stringify( this.catRows ) !== JSON.stringify( this.categories );
			},
		},

		async mounted() {
			try {
				const data = await api( '/bootstrap' );
				this.categories   = data.categories || [];
				this.dishes       = data.dishes || [];
				this.versions     = data.versions || [];
				this.settings     = data.settings;
				this.settingsForm = clone( data.settings );
				this.fonts        = data.fonts || [];
				this.catRows      = clone( this.categories );
				this.categories.forEach( ( c ) => { this.openCats[ c.id ] = true; } );
				this.draft = this.normalizeVersion( emptyDraft() );
				this.snapshot();
				this.resetDishForm();
			} catch ( e ) {
				this.initError = e.message;
			}
			this.loading = false;
		},

		methods: {
			dishName, dishDesc, catName, fmtDate,

			notify( msg, type ) {
				const id = Date.now() + Math.random();
				this.toasts.push( { id: id, msg: msg, type: type || 'info' } );
				if ( this.toasts.length > 3 ) this.toasts.splice( 0, this.toasts.length - 3 );
				setTimeout( () => { this.toasts = this.toasts.filter( ( t ) => t.id !== id ); }, type === 'error' ? 6000 : 3000 );
			},

			/* ---------- Kreator ---------- */

			normalizeVersion( v ) {
				const items = {};
				const src   = ( v.items && ! Array.isArray( v.items ) ) ? v.items : {};
				this.categories.forEach( ( c ) => {
					items[ c.id ] = Array.isArray( src[ c.id ] ) ? src[ c.id ].map( Number ).filter( ( id ) => this.dishById[ id ] ) : [];
				} );
				return {
					id: v.id || null,
					name: v.name || '',
					lang: v.lang === 'en' ? 'en' : 'pl',
					items: items,
					extra_text: v.extra_text || '',
					extra_position: v.extra_position === 'top' ? 'top' : 'bottom',
					template: v.template === 'split' ? 'split' : 'columns',
				};
			},
			draftSignature() {
				const d = this.draft;
				return JSON.stringify( [ d.name, d.lang, d.items, d.extra_text, d.extra_position, d.template ] );
			},
			snapshot() { this.draftSnapshot = this.draftSignature(); },
			confirmDiscard() {
				return ! this.isDirty || ! this.draftCount || window.confirm( 'Masz niezapisane zmiany w kreatorze. Porzucić je?' );
			},
			newDraft() {
				if ( ! this.confirmDiscard() ) return;
				this.draft = this.normalizeVersion( emptyDraft() );
				this.editingName = '';
				this.snapshot();
				this.tab = 'creator';
			},
			clearDraft() {
				Object.keys( this.draft.items ).forEach( ( k ) => { this.draft.items[ k ] = []; } );
			},

			isOpen( id ) { return this.openCats[ id ] !== false; },
			toggleOpen( id ) { this.openCats[ id ] = ! this.isOpen( id ); },
			selCount( cat ) { return ( this.draft.items[ cat ] || [] ).length; },
			isOn( cat, id ) { return ( this.draft.items[ cat ] || [] ).indexOf( id ) > -1; },
			pos( cat, id ) { return ( this.draft.items[ cat ] || [] ).indexOf( id ); },
			toggleDish( cat, id ) {
				if ( ! this.draft.items[ cat ] ) this.draft.items[ cat ] = [];
				const list = this.draft.items[ cat ];
				const i = list.indexOf( id );
				if ( i > -1 ) list.splice( i, 1 ); else list.push( id );
			},
			move( cat, id, dir ) {
				const list = this.draft.items[ cat ];
				const i = list.indexOf( id ), j = i + dir;
				if ( i < 0 || j < 0 || j >= list.length ) return;
				list.splice( i, 1 );
				list.splice( j, 0, id );
			},
			/** Wybrane (w kolejności menu), potem pozostałe dania kategorii. */
			orderedDishes( cat ) {
				const all = this.dishesByCat[ cat ] || [];
				const sel = ( this.draft.items[ cat ] || [] ).map( ( id ) => this.dishById[ id ] ).filter( Boolean );
				return sel.concat( all.filter( ( d ) => sel.indexOf( d ) === -1 ) );
			},

			async saveVersion( asNew ) {
				if ( ! this.draft.name.trim() ) { this.notify( 'Podaj nazwę wersji.', 'error' ); return; }
				if ( ! this.draftCount ) { this.notify( 'Zaznacz co najmniej jedno danie.', 'error' ); return; }
				this.busy.save = true;
				try {
					const body = clone( this.draft );
					const id   = asNew ? null : this.draft.id;
					if ( asNew && this.draft.id && body.name === this.editingName ) body.name += ' (kopia)';
					const saved = await api( id ? '/versions/' + id : '/versions', { method: 'POST', body: body } );
					const idx = this.versions.findIndex( ( v ) => v.id === saved.id );
					if ( idx > -1 ) this.versions.splice( idx, 1 );
					this.versions.unshift( saved );
					this.draft = this.normalizeVersion( saved );
					this.editingName = saved.name;
					this.snapshot();
					this.notify( id ? 'Zapisano zmiany w wersji.' : 'Zapisano nową Wersję Standardową.', 'success' );
				} catch ( e ) {
					this.notify( e.message, 'error' );
				}
				this.busy.save = false;
			},

			/* ---------- Wersje ---------- */

			editVersion( v ) {
				if ( this.draft.id !== v.id && ! this.confirmDiscard() ) return;
				this.draft = this.normalizeVersion( v );
				this.editingName = v.name;
				this.snapshot();
				this.tab = 'creator';
				this.$nextTick( () => { const el = document.getElementById( 'gfxm-app' ); if ( el && el.scrollIntoView ) el.scrollIntoView( { behavior: 'smooth', block: 'start' } ); } );
			},
			duplicateVersion( v ) {
				if ( ! this.confirmDiscard() ) return;
				const d = this.normalizeVersion( v );
				d.id = null;
				d.name = v.name + ' (kopia)';
				this.draft = d;
				this.editingName = '';
				this.snapshot();
				this.draftSnapshot = ''; // kopia jest „niezapisana”
				this.tab = 'creator';
			},
			async deleteVersion( v ) {
				if ( ! window.confirm( 'Usunąć wersję „' + v.name + '”? Tej operacji nie można cofnąć.' ) ) return;
				try {
					await api( '/versions/' + v.id, { method: 'DELETE' } );
					this.versions = this.versions.filter( ( x ) => x.id !== v.id );
					this.selected = this.selected.filter( ( id ) => id !== v.id );
					if ( this.draft.id === v.id ) { this.draft.id = null; this.editingName = ''; }
					this.notify( 'Usunięto wersję.', 'success' );
				} catch ( e ) {
					this.notify( e.message, 'error' );
				}
			},
			countItems( v ) {
				const items = ( v.items && ! Array.isArray( v.items ) ) ? v.items : {};
				return Object.values( items ).reduce( ( a, ids ) => a + ids.filter( ( id ) => this.dishById[ id ] ).length, 0 );
			},
			selIndex( id ) { return this.selected.indexOf( id ); },
			toggleSelect( id ) {
				const i = this.selected.indexOf( id );
				if ( i > -1 ) this.selected.splice( i, 1 ); else this.selected.push( id );
			},
			selectAll() {
				this.selected = this.selected.length === this.versions.length ? [] : this.versions.map( ( v ) => v.id );
			},

			async downloadPdf( body, key, suggestedName ) {
				if ( this.busy.pdf ) return;
				// Okno zapisu musi się otworzyć PRZED zapytaniem do serwera.
				let handle = null;
				try {
					handle = await pickSaveHandle( suggestedName || 'menu.pdf' );
				} catch ( e ) {
					return; // anulowano okno zapisu
				}
				this.busy.pdf = key;
				try {
					const out = await apiPdf( body );
					const saved = await saveBlob( out.blob, out.filename, handle );
					if ( saved ) this.notify( 'Zapisano plik PDF.' );
				} catch ( e ) {
					this.notify( e.message, 'error' );
				}
				this.busy.pdf = null;
			},
			downloadVersion( v ) {
				this.downloadPdf( { ids: [ v.id ] }, 'v' + v.id, 'menu-' + ( slugify( v.name ) || 'podglad' ) + '.pdf' );
			},
			downloadSelected() {
				if ( ! this.selected.length ) return;
				const n = this.selected.length;
				const name = n === 1
					? 'menu-' + ( slugify( ( this.versions.find( ( v ) => v.id === this.selected[ 0 ] ) || {} ).name ) || 'podglad' ) + '.pdf'
					: 'menu-zestaw-' + n + '-wersji-' + new Date().toISOString().slice( 0, 10 ) + '.pdf';
				this.downloadPdf( { ids: this.selected.slice() }, 'bulk', name );
			},
			previewDraftPdf() {
				if ( ! this.draftCount ) { this.notify( 'Zaznacz co najmniej jedno danie.', 'error' ); return; }
				this.downloadPdf( { draft: clone( this.draft ) }, 'draft', 'menu-' + ( slugify( this.draft.name ) || 'podglad' ) + '.pdf' );
			},

			/* ---------- Baza dań ---------- */

			resetDishForm( cat ) {
				this.dishForm = { id: null, category_id: cat || this.dishCat || ( this.categories[ 0 ] && this.categories[ 0 ].id ) || '', name_pl: '', desc_pl: '', name_en: '', desc_en: '' };
			},
			editDish( d ) {
				this.dishForm = clone( d );
				this.$nextTick( () => { const el = this.$refs.dishFormCard; if ( el && el.scrollIntoView && window.innerWidth < 1024 ) el.scrollIntoView( { behavior: 'smooth', block: 'start' } ); } );
			},
			async saveDish() {
				const f = this.dishForm;
				if ( ! f.name_pl.trim() ) { this.notify( 'Podaj nazwę dania (PL).', 'error' ); return; }
				this.busy.dish = true;
				try {
					const saved = await api( f.id ? '/dishes/' + f.id : '/dishes', { method: 'POST', body: f } );
					const i = this.dishes.findIndex( ( d ) => d.id === saved.id );
					if ( i > -1 ) this.dishes.splice( i, 1, saved ); else this.dishes.push( saved );
					this.notify( f.id ? 'Zapisano danie.' : 'Dodano danie do bazy.', 'success' );
					this.resetDishForm( saved.category_id );
				} catch ( e ) {
					this.notify( e.message, 'error' );
				}
				this.busy.dish = false;
			},
			usageCount( id ) {
				return this.versions.filter( ( v ) => Object.values( v.items || {} ).some( ( ids ) => ids.indexOf( id ) > -1 ) ).length;
			},
			async deleteDish( d ) {
				const used = this.usageCount( d.id );
				const msg = 'Usunąć „' + d.name_pl + '” z bazy?' + ( used ? '\n\nDanie jest używane w wersjach: ' + used + ' — zostanie z nich usunięte.' : '' );
				if ( ! window.confirm( msg ) ) return;
				try {
					const res = await api( '/dishes/' + d.id, { method: 'DELETE' } );
					this.dishes = this.dishes.filter( ( x ) => x.id !== d.id );
					this.versions = res.versions || this.versions;
					Object.keys( this.draft.items ).forEach( ( k ) => { this.draft.items[ k ] = this.draft.items[ k ].filter( ( id ) => id !== d.id ); } );
					if ( this.dishForm && this.dishForm.id === d.id ) this.resetDishForm();
					this.notify( 'Usunięto danie.', 'success' );
				} catch ( e ) {
					this.notify( e.message, 'error' );
				}
			},

			/* ---------- Kategorie ---------- */

			addCat() { this.catRows.push( { id: '', name_pl: '', name_en: '', sub_pl: '', sub_en: '', column: this.catRows.length % 2 ? 'right' : 'left', align: 'top', sub_dx: 40, sub_dy: 12.2, sub_pt: 0, dx: 0, gap: '' } ); },
			moveCat( i, dir ) {
				const j = i + dir;
				if ( j < 0 || j >= this.catRows.length ) return;
				const row = this.catRows.splice( i, 1 )[ 0 ];
				this.catRows.splice( j, 0, row );
			},
			removeCat( i ) {
				const r = this.catRows[ i ];
				if ( r.id && ( this.dishesByCat[ r.id ] || [] ).length ) {
					this.notify( 'Kategoria ma przypisane dania — najpierw je przenieś lub usuń.', 'error' );
					return;
				}
				this.catRows.splice( i, 1 );
			},
			async saveCats() {
				this.busy.cats = true;
				try {
					const cats = await api( '/categories', { method: 'POST', body: { categories: this.catRows } } );
					this.categories = cats;
					this.catRows = clone( cats );
					cats.forEach( ( c ) => { if ( this.openCats[ c.id ] === undefined ) this.openCats[ c.id ] = true; } );
					this.draft.items = this.normalizeVersion( this.draft ).items;
					this.notify( 'Zapisano kategorie.', 'success' );
				} catch ( e ) {
					this.notify( e.message, 'error' );
				}
				this.busy.cats = false;
			},

			/* ---------- Ustawienia ---------- */

			pickLogo( which ) {
				if ( ! window.wp || ! wp.media ) {
					this.notify( 'Media Uploader niedostępny — brak uprawnienia do wgrywania plików.', 'error' );
					return;
				}
				const frame = wp.media( {
					title: which === 'header' ? 'Logo nagłówka' : 'Logo stopki',
					library: { type: 'image' },
					multiple: false,
					button: { text: 'Użyj tego obrazu' },
				} );
				frame.on( 'select', () => {
					const a = frame.state().get( 'selection' ).first().toJSON();
					const t = this.settingsForm[ which ];
					t.id = a.id;
					t.url = a.url;
					t.natural_w = a.width || 0;
					t.natural_h = a.height || 0;
				} );
				frame.open();
			},
			removeLogo( which ) {
				Object.assign( this.settingsForm[ which ], { id: 0, url: '', natural_w: 0, natural_h: 0 } );
			},
			async saveSettings() {
				this.busy.settings = true;
				try {
					const res = await api( '/settings', { method: 'POST', body: this.settingsForm } );
					const s = res.settings;
					if ( res.fonts ) this.fonts = res.fonts;
					this.settings = s;
					this.settingsForm = clone( s );
					this.notify( 'Zapisano ustawienia PDF.', 'success' );
				} catch ( e ) {
					this.notify( e.message, 'error' );
				}
				this.busy.settings = false;
			},
			/* ---------- Przywracanie wartości domyślnych ----------
			 * Zmienia tylko formularz — nic nie jest zapisywane, dopóki
			 * użytkownik nie kliknie „Zapisz”. Można więc po prostu nie zapisać. */

			tplName() { return this.editTpl === 'split' ? 'Podzielona strona' : 'Dwie kolumny'; },
			noDefaults() {
				if ( DEFAULTS.settings ) return false;
				this.notify( 'Brak wartości domyślnych — odśwież stronę.', 'error' );
				return true;
			},
			/** Rozmiar logo (nagłówek/stopka) edytowanego szablonu; obraz zostaje. */
			resetLogo( which ) {
				if ( this.noDefaults() ) return;
				const src = this.editTpl === 'split' ? DEFAULTS.settings.split[ which ] : DEFAULTS.settings[ which ];
				copyKeys( this.tplLogo( which ), src, LOGO_SIZE_KEYS );
				this.notify( 'Przywrócono domyślny rozmiar ' + ( which === 'header' ? 'logo nagłówka' : 'logo stopki' ) + ' (' + this.tplName() + '). Kliknij „Zapisz ustawienia”.', 'success' );
			},
			/** Prowadnice edytowanego szablonu. */
			resetGuides() {
				if ( this.noDefaults() ) return;
				const src = this.editTpl === 'split' ? DEFAULTS.settings.split.layout : DEFAULTS.settings.layout;
				copyKeys( this.tplLayout, src, GUIDE_KEYS.filter( ( k ) => src[ k ] !== undefined ) );
				this.notify( 'Przywrócono domyślne prowadnice (' + this.tplName() + '). Kliknij „Zapisz ustawienia”.', 'success' );
			},
			/** Typografia — wspólna dla obu szablonów. */
			resetTypography() {
				if ( this.noDefaults() ) return;
				copyKeys( this.settingsForm.layout, DEFAULTS.settings.layout, TYPO_KEYS );
				this.notify( 'Przywrócono domyślną typografię (oba szablony). Kliknij „Zapisz ustawienia”.', 'success' );
			},
			/**
			 * Kategorie: domyślne kategorie wracają do fabrycznych nazw, podtytułów,
			 * kolumn, wyrównania i kolejności (także usunięte). Własne kategorie
			 * zostają na końcu listy bez zmian — nie tracisz ich ani ich dań.
			 */
			resetCategories() {
				const defs = DEFAULTS.categories || [];
				if ( ! defs.length ) { this.notify( 'Brak wartości domyślnych — odśwież stronę.', 'error' ); return; }
				const ids = defs.map( ( c ) => c.id );
				const custom = this.catRows.filter( ( r ) => r.id && ids.indexOf( r.id ) === -1 || ( ! r.id && ( r.name_pl || '' ).trim() ) );
				this.catRows = clone( defs ).concat( custom );
				this.notify( 'Przywrócono domyślne kategorie' + ( custom.length ? ' (własne kategorie zostały na końcu listy)' : '' ) + '. Kliknij „Zapisz kategorie”.', 'success' );
			},

			/** Obiekt rozmiarów logo edytowanego szablonu (obraz zawsze wspólny). */
			tplLogo( which ) {
				return this.editTpl === 'split' ? this.settingsForm.split[ which ] : this.settingsForm[ which ];
			},
			settingsDirty() {
				return this.settings && JSON.stringify( this.settings ) !== JSON.stringify( this.settingsForm );
			},
		},

		template: `
<div v-if="loading" class="gfxm-boot"><span class="gfxm-spin" style="margin-right:10px"></span> Ładowanie modułu Menu…</div>
<div v-else-if="initError" class="gfxm-msg gfxm-msg-error">{{ initError }}</div>
<div v-else>

	<nav class="gfxm-tabs" role="tablist">
		<button type="button" class="gfxm-tab" :class="{ active: tab === 'creator' }" @click="tab = 'creator'"><i class="fa-solid fa-wand-magic-sparkles"></i> Kreator menu</button>
		<button type="button" class="gfxm-tab" :class="{ active: tab === 'versions' }" @click="tab = 'versions'"><i class="fa-solid fa-layer-group"></i> Wersje <span class="gfxm-count">{{ versions.length }}</span></button>
		<button type="button" class="gfxm-tab" :class="{ active: tab === 'dishes' }" @click="tab = 'dishes'"><i class="fa-solid fa-bowl-food"></i> Baza dań <span class="gfxm-count">{{ dishes.length }}</span></button>
		<button type="button" class="gfxm-tab" :class="{ active: tab === 'settings' }" @click="tab = 'settings'"><i class="fa-solid fa-sliders"></i> Ustawienia PDF</button>
	</nav>

	<!-- ================= KREATOR ================= -->
	<section v-show="tab === 'creator'" class="gfxm-grid gfxm-grid-creator">
		<div>
			<div v-if="draft.id" class="gfxm-editing">
				<span><i class="fa-solid fa-pen"></i> Edytujesz wersję: {{ editingName }}</span>
				<button type="button" class="gfxm-btn gfxm-btn-sm gfxm-btn-secondary" @click="newDraft">Nowe menu</button>
			</div>

			<div class="gfxm-card">
				<div class="gfxm-card-head">
					<h3>Kreator menu</h3>
					<span v-if="isDirty && draftCount" class="gfxm-sub"><i class="fa-solid fa-circle" style="font-size:7px;color:#F59E0B;vertical-align:2px"></i> Niezapisane zmiany</span>
				</div>

				<div class="gfxm-row">
					<div class="gfxm-field" style="flex:2 1 220px">
						<label class="gfxm-label" for="gfxm-vname">Nazwa wersji</label>
						<input id="gfxm-vname" class="gfxm-input" v-model="draft.name" placeholder="np. Kwiecień — wersja A" maxlength="190">
					</div>
					<div class="gfxm-field" style="flex:0 0 auto">
						<span class="gfxm-label">Język menu</span>
						<div class="gfxm-seg">
							<button type="button" :class="{ active: draft.lang === 'pl' }" @click="draft.lang = 'pl'">Polski</button>
							<button type="button" :class="{ active: draft.lang === 'en' }" @click="draft.lang = 'en'">English</button>
						</div>
					</div>
				</div>
				<div class="gfxm-field">
					<span class="gfxm-label">Szablon</span>
					<div class="gfxm-seg">
						<button type="button" :class="{ active: draft.template !== 'split' }" @click="draft.template = 'columns'"><i class="fa-solid fa-table-columns"></i> Dwie kolumny</button>
						<button type="button" :class="{ active: draft.template === 'split' }" @click="draft.template = 'split'"><i class="fa-solid fa-scissors"></i> Podzielona strona</button>
					</div>
					<div class="gfxm-sub" style="margin-top:6px">{{ draft.template === 'split' ? 'Jedna kolumna w lewej połówce A4, ta sama treść powielona w prawej — do przecięcia na pół.' : 'Kategorie w dwóch kolumnach wg ustawień kategorii (kolumna i wyrównanie).' }}</div>
				</div>
				<div v-if="missingEn" class="gfxm-overflow" style="margin:0 0 14px">Dania bez tłumaczenia EN: {{ missingEn }} — w PDF pojawią się po polsku. Uzupełnij je w „Bazie dań”.</div>

				<div v-for="cat in categories" :key="cat.id" class="gfxm-cat" :class="{ open: isOpen( cat.id ) }">
					<button type="button" class="gfxm-cat-head" @click="toggleOpen( cat.id )" :aria-expanded="isOpen( cat.id )">
						<span class="gfxm-cat-title">{{ catName( cat, draft.lang ) }}</span>
						<span class="gfxm-cat-meta"><span>{{ selCount( cat.id ) }} z {{ ( dishesByCat[ cat.id ] || [] ).length }}</span><i class="fa-solid fa-chevron-down gfxm-chev"></i></span>
					</button>
					<ul v-show="isOpen( cat.id )" class="gfxm-dish-list">
						<li v-if="! ( dishesByCat[ cat.id ] || [] ).length" class="gfxm-empty">Brak dań w tej kategorii. Dodasz je w zakładce „Baza dań”.</li>
						<li v-for="d in orderedDishes( cat.id )" :key="d.id" class="gfxm-dish" :class="{ on: isOn( cat.id, d.id ) }">
							<label>
								<input type="checkbox" :checked="isOn( cat.id, d.id )" @change="toggleDish( cat.id, d.id )">
								<span class="gfxm-dish-text">
									<span class="gfxm-dish-name">{{ dishName( d, draft.lang ) }}<span v-if="draft.lang === 'en' && ! d.name_en" class="gfxm-warn">brak EN</span></span>
									<span v-if="dishDesc( d, draft.lang )" class="gfxm-dish-desc">{{ dishDesc( d, draft.lang ) }}</span>
								</span>
							</label>
							<div v-if="isOn( cat.id, d.id )" class="gfxm-order">
								<span class="gfxm-order-num">{{ pos( cat.id, d.id ) + 1 }}</span>
								<button type="button" @click="move( cat.id, d.id, -1 )" :disabled="pos( cat.id, d.id ) === 0" aria-label="Przesuń wyżej"><i class="fa-solid fa-arrow-up"></i></button>
								<button type="button" @click="move( cat.id, d.id, 1 )" :disabled="pos( cat.id, d.id ) === selCount( cat.id ) - 1" aria-label="Przesuń niżej"><i class="fa-solid fa-arrow-down"></i></button>
							</div>
						</li>
					</ul>
				</div>

				<div class="gfxm-field" style="margin-top:18px">
					<label class="gfxm-label" for="gfxm-extra">Dodatkowy tekst w PDF <span class="gfxm-sub" style="font-weight:400">(opcjonalnie)</span></label>
					<textarea id="gfxm-extra" class="gfxm-textarea" rows="3" v-model="draft.extra_text" placeholder="np. Informacji o alergenach udzieli obsługa."></textarea>
					<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px">
						<span class="gfxm-sub">Położenie:</span>
						<div class="gfxm-seg">
							<button type="button" :class="{ active: draft.extra_position === 'top' }" @click="draft.extra_position = 'top'">Nad daniami</button>
							<button type="button" :class="{ active: draft.extra_position !== 'top' }" @click="draft.extra_position = 'bottom'">Pod daniami</button>
						</div>
					</div>
				</div>

				<div class="gfxm-actions" style="margin-top:6px">
					<button type="button" class="gfxm-btn gfxm-btn-primary" @click="saveVersion( false )" :disabled="busy.save">
						<span v-if="busy.save" class="gfxm-spin"></span><i v-else class="fa-solid fa-floppy-disk"></i>
						{{ draft.id ? 'Zapisz zmiany' : 'Zapisz jako Wersję Standardową' }}
					</button>
					<button v-if="draft.id" type="button" class="gfxm-btn gfxm-btn-secondary" @click="saveVersion( true )" :disabled="busy.save"><i class="fa-regular fa-copy"></i> Zapisz jako nową</button>
					<button type="button" class="gfxm-btn gfxm-btn-secondary" @click="previewDraftPdf" :disabled="!! busy.pdf">
						<span v-if="busy.pdf === 'draft'" class="gfxm-spin"></span><i v-else class="fa-solid fa-file-pdf"></i> Pobierz PDF
					</button>
					<button type="button" class="gfxm-btn gfxm-btn-ghost" @click="clearDraft" :disabled="! draftCount">Odznacz wszystko</button>
				</div>
			</div>
		</div>

		<aside class="gfxm-sticky">
			<div class="gfxm-card">
				<div class="gfxm-card-head"><h3>Podgląd</h3><span class="gfxm-sub">A4, {{ draft.lang === 'en' ? 'angielski' : 'polski' }}</span></div>
				<gfxm-paper :version="draft" :template="draft.template" :settings="settingsForm" :categories="categories" :dish-by-id="dishById"></gfxm-paper>
				<p class="gfxm-sub" style="margin:10px 0 0">Podgląd jest poglądowy — łamanie wierszy w PDF może się minimalnie różnić.</p>
			</div>
		</aside>
	</section>

	<!-- ================= WERSJE ================= -->
	<section v-show="tab === 'versions'">
		<div class="gfxm-card">
			<div class="gfxm-card-head">
				<div>
					<h3>Wersje standardowe</h3>
					<div class="gfxm-sub">Zaznacz kilka wersji, by pobrać je jako jeden PDF — w kolejności zaznaczania, każda od nowej strony.</div>
				</div>
				<div class="gfxm-actions">
					<button v-if="versions.length > 1" type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-sm" @click="selectAll">{{ selected.length === versions.length ? 'Odznacz wszystkie' : 'Zaznacz wszystkie' }}</button>
					<button type="button" class="gfxm-btn gfxm-btn-primary gfxm-btn-sm" @click="newDraft"><i class="fa-solid fa-plus"></i> Nowe menu</button>
				</div>
			</div>

			<div v-if="selected.length" class="gfxm-bulkbar">
				<span><strong>{{ selected.length }}</strong> {{ selected.length === 1 ? 'wersja' : ( selected.length < 5 ? 'wersje' : 'wersji' ) }} w pliku</span>
				<div class="gfxm-actions">
					<button type="button" class="gfxm-btn gfxm-btn-sm gfxm-btn-ghost" @click="selected = []">Wyczyść</button>
					<button type="button" class="gfxm-btn gfxm-btn-sm gfxm-btn-secondary" @click="downloadSelected" :disabled="!! busy.pdf">
						<span v-if="busy.pdf === 'bulk'" class="gfxm-spin"></span><i v-else class="fa-solid fa-file-pdf"></i> Pobierz połączony PDF
					</button>
				</div>
			</div>

			<div v-if="! versions.length" class="gfxm-empty">Nie masz jeszcze zapisanych wersji. Ułóż menu w kreatorze i zapisz je jako Wersję Standardową.</div>

			<div v-for="v in versions" :key="v.id" class="gfxm-ver" :class="{ selected: selIndex( v.id ) > -1 }">
				<button type="button" class="gfxm-ver-check" @click="toggleSelect( v.id )" :aria-pressed="selIndex( v.id ) > -1" :aria-label="'Zaznacz ' + v.name">{{ selIndex( v.id ) > -1 ? selIndex( v.id ) + 1 : '' }}</button>
				<div class="gfxm-ver-main">
					<div class="gfxm-ver-name">{{ v.name }}</div>
					<div class="gfxm-ver-meta">
						<span class="gfxm-badge" :class="v.lang">{{ v.lang.toUpperCase() }}</span>
						<span class="gfxm-tpl-badge"><i :class="v.template === 'split' ? 'fa-solid fa-scissors' : 'fa-solid fa-table-columns'"></i> {{ v.template === 'split' ? 'Podzielona strona' : 'Dwie kolumny' }}</span>
						<span>Dań: {{ countItems( v ) }}</span>
						<span>{{ fmtDate( v.updated_at ) }}<template v-if="v.updated_by">, {{ v.updated_by }}</template></span>
					</div>
				</div>
				<div class="gfxm-actions">
					<button type="button" class="gfxm-btn gfxm-btn-sm gfxm-btn-secondary" @click="editVersion( v )"><i class="fa-solid fa-pen"></i> Edytuj</button>
					<button type="button" class="gfxm-btn gfxm-btn-sm gfxm-btn-secondary" @click="duplicateVersion( v )"><i class="fa-regular fa-copy"></i> Duplikuj</button>
					<button type="button" class="gfxm-btn gfxm-btn-sm gfxm-btn-primary" @click="downloadVersion( v )" :disabled="!! busy.pdf">
						<span v-if="busy.pdf === 'v' + v.id" class="gfxm-spin"></span><i v-else class="fa-solid fa-file-pdf"></i> PDF
					</button>
					<button type="button" class="gfxm-btn gfxm-btn-sm gfxm-btn-danger" @click="deleteVersion( v )" aria-label="Usuń"><i class="fa-solid fa-trash"></i> Usuń</button>
				</div>
			</div>
		</div>
	</section>

	<!-- ================= BAZA DAŃ ================= -->
	<section v-show="tab === 'dishes'" class="gfxm-grid gfxm-grid-2">
		<div>
			<div class="gfxm-card" ref="dishFormCard">
				<div class="gfxm-card-head">
					<h3>{{ dishForm.id ? 'Edycja dania' : 'Nowe danie' }}</h3>
					<button v-if="dishForm.id" type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-sm" @click="resetDishForm()">Anuluj edycję</button>
				</div>
				<div class="gfxm-field">
					<label class="gfxm-label" for="gfxm-dcat">Kategoria</label>
					<select id="gfxm-dcat" class="gfxm-select" v-model="dishForm.category_id">
						<option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name_pl }}</option>
					</select>
				</div>
				<div class="gfxm-field">
					<label class="gfxm-label" for="gfxm-npl">Nazwa <span class="gfxm-lang-tag">PL</span></label>
					<input id="gfxm-npl" class="gfxm-input" v-model="dishForm.name_pl" placeholder="np. Tatar wołowy">
				</div>
				<div class="gfxm-field">
					<label class="gfxm-label" for="gfxm-dpl">Opis <span class="gfxm-lang-tag">PL</span></label>
					<textarea id="gfxm-dpl" class="gfxm-textarea" rows="3" v-model="dishForm.desc_pl" placeholder="np. z żółtkiem, kaparami i ogórkiem kiszonym"></textarea>
				</div>
				<div class="gfxm-field">
					<label class="gfxm-label" for="gfxm-nen">Nazwa <span class="gfxm-lang-tag">EN</span></label>
					<input id="gfxm-nen" class="gfxm-input" v-model="dishForm.name_en" placeholder="e.g. Beef tartare">
				</div>
				<div class="gfxm-field">
					<label class="gfxm-label" for="gfxm-den">Opis <span class="gfxm-lang-tag">EN</span></label>
					<textarea id="gfxm-den" class="gfxm-textarea" rows="3" v-model="dishForm.desc_en" placeholder="e.g. with egg yolk, capers and pickled cucumber"></textarea>
				</div>
				<button type="button" class="gfxm-btn gfxm-btn-primary gfxm-btn-block" @click="saveDish" :disabled="busy.dish">
					<span v-if="busy.dish" class="gfxm-spin"></span><i v-else class="fa-solid fa-check"></i> {{ dishForm.id ? 'Zapisz danie' : 'Dodaj do bazy' }}
				</button>
			</div>

			<div class="gfxm-card">
				<div class="gfxm-card-head">
					<div><h3>Kategorie</h3><div class="gfxm-sub">Szablon „Dwie kolumny”: „do górnej” — kategoria startuje od górnej prowadnicy, „do dolnej” — kończy się na dolnej, więc ostatnie pozycje obu kolumn są na równej linii. W szablonie „Podzielona strona” kategorie idą jedna pod drugą w tej kolejności.</div></div>
					<div class="gfxm-actions">
						<button type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-sm gfxm-reset" @click="resetCategories" title="Przywróć domyślne kategorie"><i class="fa-solid fa-rotate-left"></i> Przywróć domyślne</button>
						<button type="button" class="gfxm-btn gfxm-btn-secondary gfxm-btn-sm" @click="addCat"><i class="fa-solid fa-plus"></i> Dodaj</button>
					</div>
				</div>
				<div v-for="( r, i ) in catRows" :key="i" class="gfxm-cat-edit">
					<div><span class="gfxm-mini">Nazwa PL (Poppins)</span><input class="gfxm-input" v-model="r.name_pl" placeholder="np. zupy"></div>
					<div><span class="gfxm-mini">Podtytuł PL</span><input class="gfxm-input" v-model="r.sub_pl" placeholder="np. na początek"></div>
					<div><span class="gfxm-mini">Nazwa EN</span><input class="gfxm-input" v-model="r.name_en" placeholder="e.g. soups"></div>
					<div><span class="gfxm-mini">Podtytuł EN</span><input class="gfxm-input" v-model="r.sub_en" placeholder="e.g. to begin"></div>
					<div class="gfxm-cat-foot">
						<div><span class="gfxm-mini">Kolumna</span>
							<select class="gfxm-select" v-model="r.column"><option value="left">Lewa</option><option value="right">Prawa</option></select></div>
						<div><span class="gfxm-mini">Wyrównanie w kolumnie</span>
							<select class="gfxm-select" v-model="r.align"><option value="top">Do górnej prowadnicy</option><option value="bottom">Do dolnej prowadnicy</option></select></div>
					</div>
					<div class="gfxm-cat-foot">
						<div><span class="gfxm-mini" title="Od początku nazwy kategorii do początku podtytułu (px)">Podtytuł → w prawo (px)</span>
							<input class="gfxm-input" type="number" step="any" v-model.number="r.sub_dx"></div>
						<div><span class="gfxm-mini" title="Od linii bazowej nazwy do linii bazowej podtytułu; wartość ujemna = nad nazwą (px)">Podtytuł → w dół (px, minus = nad)</span>
							<input class="gfxm-input" type="number" step="any" v-model.number="r.sub_dy"></div>
						<div><span class="gfxm-mini" title="Puste lub 0 = rozmiar z Ustawień PDF">Rozmiar podtytułu (pt)</span>
							<input class="gfxm-input" type="number" min="0" max="60" step="0.1" v-model.number="r.sub_pt" placeholder="jak w Ustawieniach"></div>
						<div><span class="gfxm-mini" title="Przesunięcie całej kategorii względem kolumny; minus = w lewo (px)">Kategoria → w prawo (px)</span>
							<input class="gfxm-input" type="number" step="any" v-model.number="r.dx"></div>
						<div><span class="gfxm-mini" title="Odstęp od nazwy kategorii do pierwszego dania; puste = z Ustawień PDF (px)">Nazwa → pierwsze danie (px)</span>
							<input class="gfxm-input" type="number" min="0" step="any" v-model="r.gap" placeholder="jak w Ustawieniach"></div>
						<div class="gfxm-actions">
							<button type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-icon" @click="moveCat( i, -1 )" :disabled="i === 0" aria-label="Wyżej"><i class="fa-solid fa-arrow-up"></i></button>
							<button type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-icon" @click="moveCat( i, 1 )" :disabled="i === catRows.length - 1" aria-label="Niżej"><i class="fa-solid fa-arrow-down"></i></button>
							<button type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-icon" @click="removeCat( i )" aria-label="Usuń kategorię"><i class="fa-solid fa-xmark"></i></button>
						</div>
					</div>
				</div>
				<button type="button" class="gfxm-btn gfxm-btn-primary gfxm-btn-block" style="margin-top:14px" @click="saveCats" :disabled="busy.cats || ! catsDirty">
					<span v-if="busy.cats" class="gfxm-spin"></span> Zapisz kategorie
				</button>
			</div>
		</div>

		<div class="gfxm-card">
			<div class="gfxm-card-head"><h3>Dania w bazie</h3><span class="gfxm-sub">{{ filteredDishes.length }} z {{ dishes.length }}</span></div>
			<div class="gfxm-filter">
				<input class="gfxm-input" type="search" v-model="dishSearch" placeholder="Szukaj po nazwie lub składniku…" aria-label="Szukaj dań">
				<select class="gfxm-select" v-model="dishCat" aria-label="Filtr kategorii">
					<option value="">Wszystkie kategorie</option>
					<option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name_pl }}</option>
				</select>
			</div>
			<div v-if="! filteredGroups.length" class="gfxm-empty">Brak dań spełniających kryteria.</div>
			<template v-for="g in filteredGroups" :key="g.cat.id">
				<div class="gfxm-db-group">{{ g.cat.name_pl }}</div>
				<div v-for="d in g.dishes" :key="d.id" class="gfxm-db-row" :class="{ editing: dishForm.id === d.id }">
					<div class="gfxm-db-main">
						<div class="gfxm-dish-name">{{ d.name_pl }}</div>
						<div v-if="d.desc_pl" class="gfxm-dish-desc">{{ d.desc_pl }}</div>
						<div class="gfxm-db-en"><span class="gfxm-lang-tag" style="margin:0 6px 0 0">EN</span><template v-if="d.name_en">{{ d.name_en }}</template><span v-else class="gfxm-warn" style="margin:0">brak tłumaczenia</span></div>
					</div>
					<div class="gfxm-actions" style="flex-wrap:nowrap">
						<button type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-icon" @click="editDish( d )" aria-label="Edytuj danie"><i class="fa-solid fa-pen"></i></button>
						<button type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-icon" @click="deleteDish( d )" aria-label="Usuń danie"><i class="fa-solid fa-trash"></i></button>
					</div>
				</div>
			</template>
		</div>
	</section>

	<!-- ================= USTAWIENIA ================= -->
	<section v-show="tab === 'settings'" class="gfxm-grid gfxm-grid-creator">
		<div>
			<div class="gfxm-card">
				<div class="gfxm-card-head"><h3>Edytowany szablon</h3></div>
				<div class="gfxm-seg">
					<button type="button" :class="{ active: editTpl === 'columns' }" @click="editTpl = 'columns'"><i class="fa-solid fa-table-columns"></i> Dwie kolumny</button>
					<button type="button" :class="{ active: editTpl === 'split' }" @click="editTpl = 'split'"><i class="fa-solid fa-scissors"></i> Podzielona strona</button>
				</div>
				<p class="gfxm-sub" style="margin:10px 0 0">Każdy szablon ma własne prowadnice i rozmiary logo. Obrazy logo, czcionki, rozmiary pisma i światło są wspólne. Szablon dla konkretnego menu wybierasz w Kreatorze.</p>
			</div>
			<div v-for="which in [ 'header', 'footer' ]" :key="which" class="gfxm-card">
				<div class="gfxm-card-head">
					<div><h3>{{ which === 'header' ? 'Logo nagłówka' : 'Logo stopki' }}</h3><span class="gfxm-sub">rozmiar: {{ editTpl === 'split' ? 'Podzielona strona' : 'Dwie kolumny' }}</span></div>
					<button type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-sm gfxm-reset" @click="resetLogo( which )" :title="'Przywróć domyślny rozmiar — ' + tplName()"><i class="fa-solid fa-rotate-left"></i> Przywróć domyślne</button>
				</div>
				<div class="gfxm-logo-box">
					<img v-if="settingsForm[ which ].url" :src="settingsForm[ which ].url" alt="">
					<span v-else class="gfxm-sub">Nie wybrano obrazu</span>
				</div>
				<div class="gfxm-actions" style="margin-bottom:14px">
					<button type="button" class="gfxm-btn gfxm-btn-secondary" @click="pickLogo( which )" :disabled="! canUpload"><i class="fa-regular fa-image"></i> {{ settingsForm[ which ].url ? 'Zmień obraz' : 'Wybierz z biblioteki' }}</button>
					<button v-if="settingsForm[ which ].url" type="button" class="gfxm-btn gfxm-btn-ghost" @click="removeLogo( which )">Usuń</button>
				</div>
				<div class="gfxm-row gfxm-row-dims">
					<div class="gfxm-field">
						<span class="gfxm-label">Szerokość</span>
						<div class="gfxm-dim">
							<input class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLogo( which ).width" :disabled="tplLogo( which ).width_unit === 'auto'" aria-label="Szerokość">
							<select class="gfxm-select" v-model="tplLogo( which ).width_unit" aria-label="Jednostka szerokości"><option value="%">%</option><option value="px">px</option><option value="auto">auto</option></select>
						</div>
					</div>
					<div class="gfxm-field">
						<span class="gfxm-label">Wysokość</span>
						<div class="gfxm-dim">
							<input class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLogo( which ).height" :disabled="tplLogo( which ).height_unit === 'auto'" aria-label="Wysokość">
							<select class="gfxm-select" v-model="tplLogo( which ).height_unit" aria-label="Jednostka wysokości"><option value="auto">auto</option><option value="px">px</option><option value="%">%</option></select>
						</div>
					</div>
				</div>
				<p class="gfxm-sub" style="margin:0">Szerokość w % liczona od obszaru treści ({{ editTpl === 'split' ? 'kolumny połówki' : 'obu kolumn' }}), wysokość w % — od wysokości strony A4 (1123 px). „auto” zachowuje proporcje obrazu.</p>
			</div>

			<div class="gfxm-card">
				<div class="gfxm-card-head">
					<div><h3>Prowadnice — {{ editTpl === 'split' ? 'Podzielona strona' : 'Dwie kolumny' }}</h3><span class="gfxm-sub">px na A4 794 × 1123 — te same liczby co w Canvie</span></div>
					<button type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-sm gfxm-reset" @click="resetGuides" :title="'Przywróć domyślne prowadnice — ' + tplName()"><i class="fa-solid fa-rotate-left"></i> Przywróć domyślne</button>
				</div>
				<div class="gfxm-row">
					<div class="gfxm-field"><label class="gfxm-label" for="l-ht">Logo nagłówka — góra</label><input id="l-ht" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.header_top"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="l-ct">Treść — górna prowadnica</label><input id="l-ct" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.content_top"></div>
				</div>
				<div class="gfxm-row">
					<div class="gfxm-field"><label class="gfxm-label" for="l-cb">Treść — dolna prowadnica</label><input id="l-cb" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.content_bottom"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="l-ft">Logo stopki — góra</label><input id="l-ft" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.footer_top"></div>
				</div>
				<div class="gfxm-row">
					<div class="gfxm-field"><label class="gfxm-label" for="l-lx">{{ editTpl === 'split' ? 'Lewa połówka — kolumna od' : 'Lewa kolumna — od' }}</label><input id="l-lx" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.left_x"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="l-le">{{ editTpl === 'split' ? 'Lewa połówka — kolumna do' : 'Lewa kolumna — do' }}</label><input id="l-le" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.left_end"></div>
				</div>
				<div class="gfxm-row">
					<div class="gfxm-field"><label class="gfxm-label" for="l-rx">{{ editTpl === 'split' ? 'Prawa połówka — kolumna od' : 'Prawa kolumna — od' }}</label><input id="l-rx" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.right_x"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="l-re">{{ editTpl === 'split' ? 'Prawa połówka — kolumna do' : 'Prawa kolumna — do' }}</label><input id="l-re" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.right_end"></div>
				</div>
				<div class="gfxm-row">
					<div class="gfxm-field"><label class="gfxm-label" for="l-hg">Nagłówek → dania</label><input id="l-hg" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.heading_gap"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="l-dg">Między daniami</label><input id="l-dg" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.dish_gap"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="l-cg">Min. między kategoriami</label><input id="l-cg" class="gfxm-input" type="number" min="0" step="any" v-model.number="tplLayout.category_gap"></div>
				</div>
				<p v-if="editTpl === 'split'" class="gfxm-sub" style="margin:0 0 12px">„Treść — górna prowadnica” to górna krawędź linii tekstu: przy 202 litery „zupy” zaczynają się dokładnie na prowadnicy 210 z Canvy, a pierwsze danie na 252.</p>
				<label v-if="editTpl === 'split'" class="gfxm-switch" style="margin-bottom:12px">
					<input type="checkbox" :checked="!! +tplLayout.cut_line" @change="tplLayout.cut_line = $event.target.checked ? 1 : 0">
					<span><strong>Linia cięcia</strong><br><span class="gfxm-sub">Cienka szara linia na środku strony (397 px) — ułatwia przecięcie kartki na pół.</span></span>
				</label>
				<label class="gfxm-switch">
					<input type="checkbox" :checked="!! +settingsForm.layout.fit_page" @change="settingsForm.layout.fit_page = $event.target.checked ? 1 : 0">
					<span><strong>Dopasuj do strony</strong><br><span class="gfxm-sub">Gdy treść nie mieści się między prowadnicami, pismo i odstępy są zmniejszane (maks. do 70%). Logo i prowadnice zostają bez zmian.</span></span>
				</label>
			</div>

			<div class="gfxm-card">
				<div class="gfxm-card-head">
					<div><h3>Typografia</h3><span class="gfxm-sub">wspólna dla obu szablonów · rozmiary w pt, interlinia 1.2</span></div>
					<button type="button" class="gfxm-btn gfxm-btn-ghost gfxm-btn-sm gfxm-reset" @click="resetTypography" title="Przywróć domyślną typografię (oba szablony)"><i class="fa-solid fa-rotate-left"></i> Przywróć domyślne</button>
				</div>
				<div class="gfxm-row">
					<div class="gfxm-field"><label class="gfxm-label" for="t-hw">Grubość Poppins</label>
						<select id="t-hw" class="gfxm-select" v-model="settingsForm.layout.heading_weight"><option v-for="w in weights" :key="w" :value="w">{{ w }}</option></select></div>
					<div class="gfxm-field"><label class="gfxm-label" for="t-h">Kategoria (Poppins)</label><input id="t-h" class="gfxm-input" type="number" min="5" max="60" step="0.5" v-model.number="settingsForm.layout.head_pt"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="t-s">Podtytuł (pt)</label><input id="t-s" class="gfxm-input" type="number" min="5" max="60" step="0.1" v-model.number="settingsForm.layout.sub_pt"></div>
				</div>
				<div class="gfxm-row">
					<div class="gfxm-field"><label class="gfxm-label" for="t-sf">Czcionka podtytułów</label>
						<select id="t-sf" class="gfxm-select" v-model="settingsForm.layout.sub_font"><option v-for="( label, key ) in subFonts" :key="key" :value="key">{{ label }}</option></select></div>
					<div class="gfxm-field" style="display:flex;align-items:flex-end">
						<label class="gfxm-switch" style="margin:0">
							<input type="checkbox" :checked="settingsForm.layout.sub_upper === undefined || !! +settingsForm.layout.sub_upper" @change="settingsForm.layout.sub_upper = $event.target.checked ? 1 : 0">
							<span><strong>Podtytuły wersalikami</strong><br><span class="gfxm-sub">„na początek” → „NA POCZĄTEK”</span></span>
						</label>
					</div>
				</div>
				<p class="gfxm-sub" style="margin:0 0 12px">Położenie podtytułu względem nazwy (w prawo / w dół) i ewentualny własny rozmiar ustawiasz przy każdej kategorii w zakładce z kategoriami.</p>
				<div class="gfxm-row">
					<div class="gfxm-field"><label class="gfxm-label" for="t-nw">Grubość nazwy dania</label>
						<select id="t-nw" class="gfxm-select" v-model="settingsForm.layout.name_weight"><option v-for="w in nameWeights" :key="w" :value="w">Fabrikat Mono {{ w }}</option></select></div>
					<div class="gfxm-field"><label class="gfxm-label" for="t-n">Nazwa dania (pt)</label><input id="t-n" class="gfxm-input" type="number" min="5" max="60" step="0.5" v-model.number="settingsForm.layout.name_pt"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="t-d">Opis — Regular (pt)</label><input id="t-d" class="gfxm-input" type="number" min="5" max="60" step="0.5" v-model.number="settingsForm.layout.desc_pt"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="t-lh">Interlinia dań</label><input id="t-lh" class="gfxm-input" type="number" min="0.8" max="3" step="0.05" v-model.number="settingsForm.layout.text_lh"></div>
				</div>
				<div class="gfxm-row">
					<div class="gfxm-field"><label class="gfxm-label" for="t-th">Światło — kategorie</label><input id="t-th" class="gfxm-input" type="number" min="-0.1" max="0.5" step="0.01" v-model.number="settingsForm.layout.head_track"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="t-ts">Światło — podtytuły</label><input id="t-ts" class="gfxm-input" type="number" min="-0.1" max="0.5" step="0.01" v-model.number="settingsForm.layout.sub_track"></div>
					<div class="gfxm-field"><label class="gfxm-label" for="t-tt">Światło — dania</label><input id="t-tt" class="gfxm-input" type="number" min="-0.1" max="0.5" step="0.01" v-model.number="settingsForm.layout.text_track"></div>
				</div>
				<p class="gfxm-sub" style="margin:0">Światło w em (0.04 = 40/1000 szerokości litery, jak „VA” w edytorze). 0 = bez dodatkowego odstępu (domyślne dla dań) — przy Fabrikat Mono daje łamanie wierszy jak we wzorze.</p>
			</div>

			<div class="gfxm-card">
				<div class="gfxm-card-head"><h3>Czcionki PDF</h3><span class="gfxm-sub">assets/fonts/</span></div>
				<div v-for="f in fonts" :key="f.role" class="gfxm-font-row">
					<span>{{ f.label }}<small>plik: <code>{{ f.file || 'brak' }}</code></small></span>
					<span v-if="! f.fallback" class="gfxm-ok"><i class="fa-solid fa-check"></i> właściwa czcionka</span>
					<span v-else class="gfxm-bad" style="color:#B45309"><i class="fa-solid fa-circle-info"></i> zamiennik — wgraj {{ f.wanted }}</span>
				</div>
			</div>

			<button type="button" class="gfxm-btn gfxm-btn-primary gfxm-btn-block" style="margin-top:16px" @click="saveSettings" :disabled="busy.settings || ! settingsDirty()">
				<span v-if="busy.settings" class="gfxm-spin"></span><i v-else class="fa-solid fa-floppy-disk"></i> {{ settingsDirty() ? 'Zapisz ustawienia' : 'Ustawienia zapisane' }}
			</button>
		</div>

		<aside class="gfxm-sticky">
			<div class="gfxm-card">
				<div class="gfxm-card-head"><h3>Podgląd na żywo</h3><span class="gfxm-sub">{{ draftCount ? 'bieżący szkic' : ( versions[0] ? versions[0].name : '' ) }}</span></div>
				<gfxm-paper :version="settingsPreviewSource" :template="editTpl" :settings="settingsForm" :categories="categories" :dish-by-id="dishById"></gfxm-paper>
			</div>
		</aside>
	</section>

	<div class="gfxm-toasts" aria-live="polite">
		<div v-for="t in toasts" :key="t.id" class="gfxm-toast" :class="t.type">
			<i :class="t.type === 'error' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-check'"></i> {{ t.msg }}
		</div>
	</div>
</div>`,
	} );
}

function gfxmBoot() {
	const el = document.getElementById( 'gfxm-app' );
	if ( ! el || el.__gfxmMounted ) return;
	if ( typeof Vue === 'undefined' ) {
		el.innerHTML = '<div class="gfxm-msg gfxm-msg-error">Nie udało się załadować biblioteki Vue (sprawdź dostęp do unpkg.com).</div>';
		return;
	}
	try {
		createGfxmApp().mount( el );
		el.__gfxmMounted = true;
	} catch ( e ) {
		console.error( 'Menu GastroFlowX: błąd montowania', e );
		el.innerHTML = '<div class="gfxm-msg gfxm-msg-error">Błąd ładowania modułu: ' + ( e && e.message ? e.message : e ) + '</div>';
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', gfxmBoot );
} else {
	gfxmBoot();
}
} )();
