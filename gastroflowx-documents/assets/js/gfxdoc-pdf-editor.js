/* global pdfjsLib, PDFLib, fontkit, GFXDocFlatten, gfxdocPdfEditor */
/**
 * GastroFlowx — edytor PDF.
 *
 * Renders the ORIGINAL uploaded PDF with pdf.js and lets the admin place
 * objects on top of it:
 *   - text      (new text, or a replacement for existing text: "Zmień tekst"
 *                covers the clicked fragment and puts an editable copy on it),
 *   - rect      (white "cover" to hide something, or a highlighter mark),
 *   - image     (scanned signature, stamp, logo — PNG/JPG).
 *
 * Pages can be moved, rotated and deleted. Objects + page list are stored
 * as JSON on the server, so everything stays editable. On "Zapisz PDF" the
 * original is rebuilt with pdf-lib (GFXDocFlatten): text under covers is cut
 * out of the file (GFXDocRedact), objects are drawn, pages rearranged, and
 * the resulting file is uploaded as the current version.
 *
 * All object coordinates are in view points of their page as displayed
 * (rotation included, top-left origin, 1 unit = 1 pt); an object can be
 * turned itself by `rot` when its page was rotated after it was placed.
 * The DOM just multiplies coordinates by the zoom.
 */
( function () {
	'use strict';

	var cfg = window.gfxdocPdfEditor;
	var root = document.querySelector( '.gfxdoc-pe' );
	if ( ! cfg || ! root ) {
		return;
	}

	pdfjsLib.GlobalWorkerOptions.workerSrc = cfg.workerSrc;

	var LH = GFXDocFlatten.LINE_HEIGHT;
	var BASELINE = GFXDocFlatten.BASELINE;
	var FONT = 'GFXDocDejaVu';

	var els = {
		toolbar: root.querySelector( '.gfxdoc-pe__toolbar' ),
		pages: root.querySelector( '.gfxdoc-pe__pages' ),
		viewport: root.querySelector( '.gfxdoc-pe__viewport' ),
		status: root.querySelector( '.gfxdoc-pe__status' ),
		imageInput: root.querySelector( '.gfxdoc-pe__image-input' ),
		zoom: root.querySelector( '[data-action="zoom"]' ),
		undo: root.querySelector( '[data-action="undo"]' ),
		redo: root.querySelector( '[data-action="redo"]' ),
		save: root.querySelector( '[data-action="save"]' ),
	};

	var state = {
		bytes: null, // original PDF (Uint8Array)
		pdf: null, // pdf.js document
		pages: [], // by source index: { index, page, base, extra, vp, el, canvas, overlay, renderedScale, chunks }
		order: [], // source indexes in display order (deleted pages are left out)
		objects: [],
		selected: null,
		editing: null,
		tool: 'select',
		scale: parseFloat( els.zoom.value ) || 1.25,
		defaults: { size: 11, color: '#000000', bold: false },
		undo: [],
		redo: [],
		snapshot: '',
		dirty: false,
		saving: false,
	};

	var nextId = 1;
	function uid() {
		return 'o' + nextId++;
	}

	/* ---------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------- */

	function status( msg, type ) {
		els.status.textContent = msg || '';
		els.status.className = 'gfxdoc-pe__status' + ( type ? ' is-' + type : '' );
	}

	function round( n ) {
		return Math.round( n * 100 ) / 100;
	}

	function findObj( id ) {
		for ( var i = 0; i < state.objects.length; i++ ) {
			if ( state.objects[ i ].id === id ) {
				return state.objects[ i ];
			}
		}
		return null;
	}

	function cleanObjects() {
		return state.objects.map( function ( o ) {
			var c = {};
			Object.keys( o ).forEach( function ( k ) {
				if ( k !== 'id' ) {
					c[ k ] = o[ k ];
				}
			} );
			return c;
		} );
	}

	/** Page list for saving: null when nothing about the pages changed. */
	function pageList() {
		var touched = state.order.length !== state.pages.length || state.order.some( function ( src, i ) {
			return src !== i || state.pages[ src ].extra;
		} );
		if ( ! touched ) {
			return null;
		}
		return state.order.map( function ( src ) {
			return { src: src, rot: state.pages[ src ].extra };
		} );
	}

	function serialize() {
		return JSON.stringify( {
			objects: cleanObjects(),
			order: state.order,
			extra: state.pages.map( function ( p ) {
				return p.extra;
			} ),
		} );
	}

	function toHex( r, g, b ) {
		return '#' + [ r, g, b ].map( function ( v ) {
			return ( '0' + Math.max( 0, Math.min( 255, Math.round( v ) ) ).toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	/* ---------------------------------------------------------------
	 * History (undo / redo) — snapshots of the object list
	 * ------------------------------------------------------------- */

	function commit() {
		var snap = serialize();
		if ( snap === state.snapshot ) {
			return;
		}
		state.undo.push( state.snapshot );
		if ( state.undo.length > 100 ) {
			state.undo.shift();
		}
		state.redo = [];
		state.snapshot = snap;
		setDirty( true );
		updateHistoryButtons();
	}

	function restore( snap ) {
		var data = JSON.parse( snap );
		state.objects = data.objects.map( function ( o ) {
			o.id = uid();
			return o;
		} );
		data.extra.forEach( function ( e, i ) {
			setExtraRotation( state.pages[ i ], e );
		} );
		state.order = data.order.slice();
		state.snapshot = snap;
		state.selected = null;
		state.editing = null;
		applyOrder();
		layoutPages();
		renderVisiblePages();
		renderAllObjects();
		updateProps();
		setDirty( true );
		updateHistoryButtons();
	}

	function undo() {
		if ( ! state.undo.length ) {
			return;
		}
		finishEditing();
		state.redo.push( state.snapshot );
		restore( state.undo.pop() );
	}

	function redo() {
		if ( ! state.redo.length ) {
			return;
		}
		finishEditing();
		state.undo.push( state.snapshot );
		restore( state.redo.pop() );
	}

	function updateHistoryButtons() {
		els.undo.disabled = ! state.undo.length;
		els.redo.disabled = ! state.redo.length;
	}

	function setDirty( dirty ) {
		state.dirty = dirty;
		root.classList.toggle( 'is-dirty', dirty );
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( state.dirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	/* ---------------------------------------------------------------
	 * Loading + page rendering
	 * ------------------------------------------------------------- */

	function injectFonts() {
		var css =
			'@font-face{font-family:"' + FONT + '";src:url("' + cfg.fontRegular + '") format("truetype");font-weight:400;}' +
			'@font-face{font-family:"' + FONT + '";src:url("' + cfg.fontBold + '") format("truetype");font-weight:700;}';
		var style = document.createElement( 'style' );
		style.textContent = css;
		document.head.appendChild( style );
		if ( document.fonts && document.fonts.load ) {
			document.fonts.load( '12px "' + FONT + '"' );
			document.fonts.load( 'bold 12px "' + FONT + '"' );
		}
	}

	async function load() {
		injectFonts();
		try {
			var res = await fetch( cfg.originalUrl, { credentials: 'same-origin' } );
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			state.bytes = new Uint8Array( await res.arrayBuffer() );
			// pdf.js may transfer (detach) the buffer it gets — give it a copy.
			state.pdf = await pdfjsLib.getDocument( { data: state.bytes.slice() } ).promise;
		} catch ( e ) {
			els.pages.innerHTML = '';
			status( 'Nie udało się wczytać pliku PDF: ' + ( e && e.message ? e.message : e ), 'error' );
			return;
		}

		els.pages.innerHTML = '';
		for ( var n = 1; n <= state.pdf.numPages; n++ ) {
			var page = await state.pdf.getPage( n );
			var p = {
				index: n - 1,
				page: page,
				base: ( ( page.rotate || 0 ) % 360 + 360 ) % 360,
				extra: 0,
				vp: page.getViewport( { scale: 1 } ),
				renderedKey: '',
				rendering: null,
				chunks: null,
			};
			buildPageDom( p );
			state.pages.push( p );
		}

		// Saved state: { objects, pages } (1.1.0 stored a plain object list).
		var saved = window.gfxdocPdfEdits || {};
		if ( Array.isArray( saved ) ) {
			saved = { objects: saved, pages: null };
		}
		state.order = state.pages.map( function ( pp ) {
			return pp.index;
		} );
		if ( Array.isArray( saved.pages ) && saved.pages.length ) {
			var order = [];
			saved.pages.forEach( function ( sp ) {
				var pg = state.pages[ sp.src ];
				if ( pg && order.indexOf( sp.src ) === -1 ) {
					order.push( sp.src );
					setExtraRotation( pg, sp.rot || 0 );
				}
			} );
			if ( order.length ) {
				state.order = order;
			}
		}
		state.objects = ( saved.objects || [] )
			.filter( function ( o ) {
				return o && state.pages[ o.page ];
			} )
			.map( function ( o ) {
				o.id = uid();
				return o;
			} );
		state.snapshot = serialize();

		applyOrder();
		layoutPages();
		renderAllObjects();
		observePages();
		var removedPages = state.pages.length - state.order.length;
		status(
			pagesLabel( state.order.length ) +
				( removedPages ? ' (usunięto: ' + removedPages + ')' : '' ) +
				( state.objects.length ? ' · zapisanych zmian: ' + state.objects.length : '' )
		);
	}

	function pagesLabel( n ) {
		var mod10 = n % 10;
		var mod100 = n % 100;
		var word = n === 1 ? 'strona' : ( mod10 >= 2 && mod10 <= 4 && ( mod100 < 12 || mod100 > 14 ) ? 'strony' : 'stron' );
		return n + ' ' + word;
	}

	function rotationOf( p ) {
		return ( p.base + p.extra ) % 360;
	}

	/** Sets a page's extra rotation (0/90/180/270) without touching objects. */
	function setExtraRotation( p, extra ) {
		extra = ( ( extra % 360 ) + 360 ) % 360;
		if ( p.extra === extra && p.vp ) {
			return;
		}
		p.extra = extra;
		p.vp = p.page.getViewport( { scale: 1, rotation: rotationOf( p ) } );
		p.chunks = null;
		p.renderedKey = '';
	}

	function buildPageDom( p ) {
		var el = document.createElement( 'div' );
		el.className = 'gfxdoc-pe__page';
		el.dataset.page = p.index;

		var canvas = document.createElement( 'canvas' );
		var overlay = document.createElement( 'div' );
		overlay.className = 'gfxdoc-pe__overlay';
		overlay.dataset.page = p.index;

		var bar = document.createElement( 'div' );
		bar.className = 'gfxdoc-pe__pagebar';
		bar.innerHTML =
			'<span class="gfxdoc-pe__pagenum"></span>' +
			'<span class="gfxdoc-pe__pagebtns">' +
			'<button type="button" data-page-action="up" title="Przesuń stronę wyżej">↑</button>' +
			'<button type="button" data-page-action="down" title="Przesuń stronę niżej">↓</button>' +
			'<button type="button" data-page-action="rotl" title="Obróć w lewo">⟲</button>' +
			'<button type="button" data-page-action="rotr" title="Obróć w prawo">⟳</button>' +
			'<button type="button" data-page-action="delete" class="is-danger" title="Usuń stronę">🗑</button>' +
			'</span>';

		el.appendChild( bar );
		el.appendChild( canvas );
		el.appendChild( overlay );
		els.pages.appendChild( el );

		p.el = el;
		p.canvas = canvas;
		p.overlay = overlay;
		p.label = bar.firstChild;
	}

	/** Puts page elements in display order, hides deleted pages, renumbers. */
	function applyOrder() {
		state.pages.forEach( function ( p ) {
			p.el.hidden = state.order.indexOf( p.index ) === -1;
		} );
		state.order.forEach( function ( src, i ) {
			var p = state.pages[ src ];
			els.pages.appendChild( p.el );
			p.label.textContent = 'Strona ' + ( i + 1 ) + ' / ' + state.order.length + ( src !== i ? ' (oryg. ' + ( src + 1 ) + ')' : '' );
			var btns = p.el.querySelectorAll( '[data-page-action]' );
			btns.forEach( function ( b ) {
				var act = b.dataset.pageAction;
				b.disabled = ( act === 'up' && i === 0 ) || ( act === 'down' && i === state.order.length - 1 ) || ( act === 'delete' && state.order.length === 1 );
			} );
		} );
	}

	function layoutPages() {
		state.pages.forEach( function ( p ) {
			p.el.style.width = p.vp.width * state.scale + 'px';
			p.el.style.height = p.vp.height * state.scale + 'px';
		} );
	}

	var observer = null;
	function observePages() {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			state.pages.forEach( renderPage );
			return;
		}
		observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( en ) {
					if ( en.isIntersecting ) {
						renderPage( state.pages[ en.target.dataset.page ] );
					}
				} );
			},
			{ root: null, rootMargin: '600px 0px' }
		);
		state.pages.forEach( function ( p ) {
			observer.observe( p.el );
		} );
	}

	function renderVisiblePages() {
		state.pages.forEach( function ( p ) {
			if ( p.el.hidden ) {
				return;
			}
			var r = p.el.getBoundingClientRect();
			if ( r.bottom > -600 && r.top < window.innerHeight + 600 ) {
				renderPage( p );
			}
		} );
	}

	function renderPage( p ) {
		if ( ! p ) {
			return null;
		}
		var key = state.scale + ':' + rotationOf( p );
		if ( p.renderedKey === key ) {
			return p.rendering;
		}
		if ( p.renderTask ) {
			p.renderTask.cancel();
		}
		var scale = state.scale;
		var dpr = window.devicePixelRatio || 1;
		var vp = p.page.getViewport( { scale: scale, rotation: rotationOf( p ) } );
		var canvas = document.createElement( 'canvas' );
		canvas.width = Math.floor( vp.width * dpr );
		canvas.height = Math.floor( vp.height * dpr );
		canvas.style.width = vp.width + 'px';
		canvas.style.height = vp.height + 'px';
		var task = p.page.render( {
			canvasContext: canvas.getContext( '2d' ),
			viewport: vp,
			transform: dpr !== 1 ? [ dpr, 0, 0, dpr, 0, 0 ] : null,
		} );
		p.renderTask = task;
		p.rendering = task.promise.then(
			function () {
				p.el.replaceChild( canvas, p.canvas );
				p.canvas = canvas;
				p.canvasScale = scale * dpr;
				p.renderedKey = key;
				p.renderTask = null;
			},
			function () {} // cancelled
		);
		return p.rendering;
	}

	/* ---------------------------------------------------------------
	 * Page operations
	 * ------------------------------------------------------------- */

	/** Rotates a page by ±90° together with the objects placed on it. */
	function rotatePage( p, dir ) {
		var W = p.vp.width;
		var H = p.vp.height;
		state.objects.forEach( function ( o ) {
			if ( o.page !== p.index ) {
				return;
			}
			var x = o.x;
			var y = o.y;
			if ( dir > 0 ) { // clockwise: (x, y) → (H − y, x)
				o.x = round( H - y );
				o.y = round( x );
			} else { // counter-clockwise: (x, y) → (y, W − x)
				o.x = round( y );
				o.y = round( W - x );
			}
			o.rot = ( ( ( o.rot || 0 ) + ( dir > 0 ? 90 : 270 ) ) % 360 );
			if ( ! o.rot ) {
				delete o.rot;
			}
		} );
		setExtraRotation( p, p.extra + ( dir > 0 ? 90 : -90 ) );
	}

	els.pages.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-page-action]' );
		if ( ! btn || btn.disabled ) {
			return;
		}
		finishEditing();
		var p = state.pages[ btn.closest( '.gfxdoc-pe__page' ).dataset.page ];
		var pos = state.order.indexOf( p.index );
		switch ( btn.dataset.pageAction ) {
			case 'up':
			case 'down':
				var to = pos + ( btn.dataset.pageAction === 'up' ? -1 : 1 );
				if ( to < 0 || to >= state.order.length ) {
					return;
				}
				state.order.splice( pos, 1 );
				state.order.splice( to, 0, p.index );
				applyOrder();
				p.el.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
				break;
			case 'rotl':
			case 'rotr':
				rotatePage( p, btn.dataset.pageAction === 'rotr' ? 1 : -1 );
				layoutPages();
				renderPage( p );
				renderAllObjects();
				break;
			case 'delete':
				if ( state.order.length === 1 ) {
					return;
				}
				state.order.splice( pos, 1 );
				if ( state.selected && findObj( state.selected ) && findObj( state.selected ).page === p.index ) {
					select( null );
				}
				applyOrder();
				status( 'Usunięto stronę ' + ( pos + 1 ) + '. Możesz to cofnąć (Ctrl+Z).' );
				break;
		}
		commit();
	} );

	function setZoom( scale ) {
		finishEditing();
		state.scale = scale;
		layoutPages();
		renderAllObjects();
		renderVisiblePages();
	}

	/* ---------------------------------------------------------------
	 * Objects → DOM
	 * ------------------------------------------------------------- */

	function renderAllObjects() {
		state.pages.forEach( function ( p ) {
			p.overlay.querySelectorAll( '.gfxdoc-pe__obj' ).forEach( function ( n ) {
				n.remove();
			} );
		} );
		state.objects.forEach( function ( o ) {
			renderObject( o );
		} );
	}

	function objEl( o ) {
		return root.querySelector( '.gfxdoc-pe__obj[data-id="' + o.id + '"]' );
	}

	function renderObject( o ) {
		var p = state.pages[ o.page ];
		if ( ! p ) {
			return null;
		}
		var el = objEl( o );
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.className = 'gfxdoc-pe__obj gfxdoc-pe__obj--' + o.type + ( o.kind ? ' gfxdoc-pe__obj--' + o.kind : '' );
			el.dataset.id = o.id;
			if ( o.type === 'text' ) {
				var t = document.createElement( 'div' );
				t.className = 'gfxdoc-pe__text';
				t.spellcheck = true;
				t.lang = 'pl';
				el.appendChild( t );
			} else if ( o.type === 'image' ) {
				var img = document.createElement( 'img' );
				img.alt = '';
				img.draggable = false;
				img.src = o.src;
				el.appendChild( img );
			}
			if ( o.type !== 'text' ) {
				var h = document.createElement( 'span' );
				h.className = 'gfxdoc-pe__handle';
				el.appendChild( h );
			}
			// keep DOM order == stacking order == object order
			var after = null;
			var idx = state.objects.indexOf( o );
			for ( var i = idx + 1; i < state.objects.length; i++ ) {
				if ( state.objects[ i ].page === o.page ) {
					after = objEl( state.objects[ i ] );
					if ( after ) {
						break;
					}
				}
			}
			p.overlay.insertBefore( el, after );
		}

		var s = state.scale;
		el.style.left = o.x * s + 'px';
		el.style.top = o.y * s + 'px';
		el.style.transform = o.rot ? 'rotate(' + o.rot + 'deg)' : '';
		el.classList.toggle( 'gfxdoc-pe__obj--redact', o.type === 'rect' && o.kind === 'cover' && o.redact !== false );

		if ( o.type === 'text' ) {
			var tx = el.firstChild;
			if ( state.editing !== o.id ) {
				tx.textContent = o.text;
			}
			tx.style.fontFamily = '"' + FONT + '", "DejaVu Sans", sans-serif';
			tx.style.fontSize = o.size * s + 'px';
			tx.style.lineHeight = LH;
			tx.style.color = o.color;
			tx.style.fontWeight = o.bold ? '700' : '400';
			el.style.width = '';
			el.style.height = '';
		} else {
			el.style.width = o.w * s + 'px';
			el.style.height = o.h * s + 'px';
			if ( o.type === 'rect' ) {
				el.style.background = o.fill;
				el.style.opacity = o.opacity;
			}
		}
		el.classList.toggle( 'is-selected', state.selected === o.id );
		el.classList.toggle( 'is-editing', state.editing === o.id );
		return el;
	}

	/** Stores the rendered size of a text object (used by the server + covers). */
	function measureText( o ) {
		var el = objEl( o );
		if ( el && o.type === 'text' ) {
			o.w = round( Math.max( 1, el.firstChild.offsetWidth / state.scale ) );
			o.h = round( Math.max( 1, el.firstChild.offsetHeight / state.scale ) );
		}
	}

	/* ---------------------------------------------------------------
	 * Selection, editing, properties
	 * ------------------------------------------------------------- */

	function select( id ) {
		if ( state.editing && state.editing !== id ) {
			finishEditing();
		}
		var prev = state.selected;
		state.selected = id;
		[ prev, id ].forEach( function ( x ) {
			var o = x && findObj( x );
			if ( o ) {
				renderObject( o );
			}
		} );
		updateProps();
	}

	function startEditing( o, selectAll ) {
		if ( ! o || o.type !== 'text' ) {
			return;
		}
		state.selected = o.id;
		state.editing = o.id;
		var el = renderObject( o );
		var tx = el.firstChild;
		tx.contentEditable = 'true';
		tx.focus();
		var range = document.createRange();
		range.selectNodeContents( tx );
		if ( ! selectAll ) {
			range.collapse( false );
		}
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
		updateProps();
	}

	function finishEditing() {
		if ( ! state.editing ) {
			return;
		}
		var o = findObj( state.editing );
		state.editing = null;
		if ( ! o ) {
			return;
		}
		var el = objEl( o );
		var tx = el && el.firstChild;
		if ( tx ) {
			tx.contentEditable = 'false';
			o.text = readText( tx );
		}
		if ( ! o.text.trim() ) {
			removeObject( o, true );
		} else {
			renderObject( o );
			measureText( o );
		}
		commit();
		updateProps();
	}

	function readText( tx ) {
		// innerText maps <br>/<div> to "\n"; drop the trailing newline some
		// browsers add after a final <br>.
		return ( tx.innerText || tx.textContent || '' ).replace( /\u00a0/g, ' ' ).replace( /\n$/, '' );
	}

	function removeObject( o, silent ) {
		var i = state.objects.indexOf( o );
		if ( i === -1 ) {
			return;
		}
		state.objects.splice( i, 1 );
		var el = objEl( o );
		if ( el ) {
			el.remove();
		}
		if ( state.selected === o.id ) {
			state.selected = null;
		}
		if ( ! silent ) {
			commit();
			updateProps();
		}
	}

	function addObject( o ) {
		o.id = uid();
		state.objects.push( o );
		renderObject( o );
		return o;
	}

	function updateProps() {
		var o = state.selected && findObj( state.selected );
		root.querySelectorAll( '[data-props]' ).forEach( function ( g ) {
			var t = g.dataset.props;
			g.hidden = ! o || ( t !== 'any' && t !== o.type );
		} );
		var src = o || null;
		var get = function ( prop ) {
			return root.querySelector( '[data-props] [data-prop="' + prop + '"]' );
		};
		if ( src && src.type === 'text' ) {
			get( 'size' ).value = src.size;
			get( 'color' ).value = src.color;
			get( 'bold' ).setAttribute( 'aria-pressed', src.bold ? 'true' : 'false' );
		} else if ( src && src.type === 'rect' ) {
			get( 'fill' ).value = src.fill;
			get( 'opacity' ).value = src.opacity;
			var rd = get( 'redact' );
			rd.checked = src.redact !== false;
			rd.closest( 'label' ).hidden = src.kind !== 'cover';
		}
	}

	els.toolbar.addEventListener( 'input', function ( e ) {
		var prop = e.target.dataset.prop;
		var o = state.selected && findObj( state.selected );
		if ( ! prop || ! o ) {
			return;
		}
		if ( prop === 'size' ) {
			var v = parseFloat( e.target.value );
			if ( ! ( v >= 4 && v <= 144 ) ) {
				return;
			}
			o.size = v;
			state.defaults.size = v;
		} else if ( prop === 'color' ) {
			o.color = e.target.value;
			state.defaults.color = o.color;
		} else if ( prop === 'fill' ) {
			o.fill = e.target.value;
		} else if ( prop === 'opacity' ) {
			o.opacity = parseFloat( e.target.value );
		} else if ( prop === 'redact' ) {
			o.redact = !! e.target.checked;
		}
		renderObject( o );
		if ( o.type === 'text' ) {
			measureText( o );
		}
	} );
	els.toolbar.addEventListener( 'change', function ( e ) {
		if ( e.target.dataset.prop ) {
			commit();
		}
	} );

	/* ---------------------------------------------------------------
	 * Tools
	 * ------------------------------------------------------------- */

	function setTool( tool ) {
		finishEditing();
		state.tool = tool;
		root.dataset.tool = tool;
		root.querySelectorAll( '[data-tool]' ).forEach( function ( b ) {
			b.classList.toggle( 'is-active', b.dataset.tool === tool );
		} );
		hideHover();
	}

	els.toolbar.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( 'button, a' );
		if ( ! btn ) {
			return;
		}
		if ( btn.dataset.tool ) {
			setTool( btn.dataset.tool );
			return;
		}
		if ( btn.dataset.prop === 'bold' ) {
			var o = state.selected && findObj( state.selected );
			if ( o && o.type === 'text' ) {
				o.bold = ! o.bold;
				state.defaults.bold = o.bold;
				renderObject( o );
				measureText( o );
				commit();
				updateProps();
			}
			return;
		}
		switch ( btn.dataset.action ) {
			case 'delete':
				deleteSelected();
				break;
			case 'undo':
				undo();
				break;
			case 'redo':
				redo();
				break;
			case 'save':
				save();
				break;
			case 'reset':
				resetAll();
				break;
			case 'download':
				if ( state.dirty ) {
					e.preventDefault();
					if ( window.confirm( 'Masz niezapisane zmiany. Zapisać je przed pobraniem?' ) ) {
						save().then( function ( ok ) {
							if ( ok ) {
								window.location.href = btn.href;
							}
						} );
					} else {
						window.location.href = btn.href;
					}
				}
				break;
		}
	} );

	els.zoom.addEventListener( 'change', function () {
		setZoom( parseFloat( els.zoom.value ) || 1 );
	} );

	function deleteSelected() {
		var o = state.selected && findObj( state.selected );
		if ( o ) {
			state.editing = null;
			removeObject( o );
		}
	}

	function resetAll() {
		if ( ! state.objects.length && ! pageList() ) {
			status( 'Dokument nie ma żadnych zmian — to oryginał.' );
			return;
		}
		if ( ! window.confirm( 'Usunąć wszystkie zmiany i wrócić do oryginału? Możesz to cofnąć (Ctrl+Z) do czasu zapisania.' ) ) {
			return;
		}
		finishEditing();
		state.objects = [];
		state.selected = null;
		state.pages.forEach( function ( p ) {
			setExtraRotation( p, 0 );
		} );
		state.order = state.pages.map( function ( p ) {
			return p.index;
		} );
		applyOrder();
		layoutPages();
		renderVisiblePages();
		renderAllObjects();
		commit();
		updateProps();
		status( 'Usunięto wszystkie zmiany. Kliknij „Zapisz PDF”, aby przywrócić oryginał na stałe.' );
	}

	/* ---------------------------------------------------------------
	 * Existing text detection ("Zmień tekst")
	 * ------------------------------------------------------------- */

	async function pageChunks( p ) {
		if ( p.chunks ) {
			return p.chunks;
		}
		var tc = await p.page.getTextContent();
		var items = [];
		tc.items.forEach( function ( it ) {
			if ( ! it.str || ! it.str.trim() ) {
				return;
			}
			var m = pdfjsLib.Util.transform( p.vp.transform, it.transform );
			if ( Math.abs( m[ 1 ] ) > 0.01 || Math.abs( m[ 2 ] ) > 0.01 || m[ 0 ] <= 0 ) {
				return; // rotated / vertical text — not supported for in-place edits
			}
			var size = Math.abs( m[ 3 ] );
			if ( size < 2 ) {
				return;
			}
			items.push( { str: it.str, x: m[ 4 ], base: m[ 5 ], size: size, w: it.width, fontName: it.fontName } );
		} );
		items.sort( function ( a, b ) {
			return a.base - b.base || a.x - b.x;
		} );

		// Merge neighbouring runs on the same baseline into editable chunks.
		var chunks = [];
		items.forEach( function ( it ) {
			var c = chunks[ chunks.length - 1 ];
			if (
				c &&
				Math.abs( it.base - c.base ) < c.size * 0.3 &&
				Math.abs( it.size - c.size ) < c.size * 0.12 &&
				it.x - ( c.x + c.w ) < c.size * 0.9 &&
				it.x > c.x
			) {
				var gap = it.x - ( c.x + c.w );
				c.str += ( gap > c.size * 0.18 && ! /\s$/.test( c.str ) && ! /^\s/.test( it.str ) ? ' ' : '' ) + it.str;
				c.w = it.x + it.w - c.x;
				c.fonts.push( it.fontName );
			} else {
				chunks.push( { str: it.str, x: it.x, base: it.base, size: it.size, w: it.w, fonts: [ it.fontName ] } );
			}
		} );
		chunks.forEach( function ( c ) {
			c.top = c.base - c.size * 0.93;
			c.bottom = c.base + c.size * 0.26;
			c.str = c.str.replace( /\s+/g, ' ' ).trim();
		} );
		p.chunks = chunks;
		return chunks;
	}

	function chunkAt( chunks, x, y ) {
		var best = null;
		chunks.forEach( function ( c ) {
			if ( x >= c.x - 2 && x <= c.x + c.w + 2 && y >= c.top - 1 && y <= c.bottom + 1 ) {
				if ( ! best || c.size < best.size ) {
					best = c;
				}
			}
		} );
		return best;
	}

	function isBoldChunk( p, c ) {
		try {
			var name = '';
			c.fonts.some( function ( f ) {
				if ( p.page.commonObjs.has( f ) ) {
					name = p.page.commonObjs.get( f ).name || '';
					return true;
				}
				return false;
			} );
			return /bold|black|heavy|semibold|demi/i.test( name );
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * Looks at the rendered page under a text chunk: the colour along the
	 * box edge is the background (what the cover should be painted with),
	 * the darkest-contrast pixel inside is the text colour.
	 */
	function sampleColors( p, c ) {
		var out = { bg: '#ffffff', fg: '#000000' };
		try {
			var k = p.canvasScale || state.scale;
			var ctx = p.canvas.getContext( '2d' );
			var x0 = Math.max( 0, Math.floor( ( c.x - 2 ) * k ) );
			var y0 = Math.max( 0, Math.floor( ( c.top - 2 ) * k ) );
			var w = Math.min( p.canvas.width - x0, Math.ceil( ( c.w + 4 ) * k ) );
			var h = Math.min( p.canvas.height - y0, Math.ceil( ( c.bottom - c.top + 4 ) * k ) );
			if ( w < 2 || h < 2 ) {
				return out;
			}
			var d = ctx.getImageData( x0, y0, w, h ).data;
			var counts = {};
			var edge = function ( px, py ) {
				var i = ( py * w + px ) * 4;
				var key = ( d[ i ] >> 3 ) + ',' + ( d[ i + 1 ] >> 3 ) + ',' + ( d[ i + 2 ] >> 3 );
				counts[ key ] = counts[ key ] || { n: 0, r: 0, g: 0, b: 0 };
				counts[ key ].n++;
				counts[ key ].r += d[ i ];
				counts[ key ].g += d[ i + 1 ];
				counts[ key ].b += d[ i + 2 ];
			};
			for ( var px = 0; px < w; px++ ) {
				edge( px, 0 );
				edge( px, h - 1 );
			}
			for ( var py = 0; py < h; py++ ) {
				edge( 0, py );
				edge( w - 1, py );
			}
			var bg = null;
			Object.keys( counts ).forEach( function ( key ) {
				if ( ! bg || counts[ key ].n > bg.n ) {
					bg = counts[ key ];
				}
			} );
			var br = bg.r / bg.n;
			var bgg = bg.g / bg.n;
			var bb = bg.b / bg.n;
			out.bg = toHex( br, bgg, bb );

			var far = -1;
			for ( var i = 0; i < d.length; i += 4 ) {
				var dist = Math.abs( d[ i ] - br ) + Math.abs( d[ i + 1 ] - bgg ) + Math.abs( d[ i + 2 ] - bb );
				if ( dist > far ) {
					far = dist;
					out.fg = toHex( d[ i ], d[ i + 1 ], d[ i + 2 ] );
				}
			}
			if ( far < 60 ) {
				out.fg = '#000000';
			}
		} catch ( e ) {} // eslint-disable-line no-empty
		return out;
	}

	async function editExistingText( p, x, y ) {
		var chunks = await pageChunks( p );
		var c = chunkAt( chunks, x, y );
		if ( ! c ) {
			status( 'Tu nie ma tekstu do zmiany. Kliknij bezpośrednio na słowo — albo użyj „Dodaj tekst”.' );
			return;
		}
		await renderPage( p );
		var colors = sampleColors( p, c );
		var size = Math.round( c.size * 2 ) / 2;
		addObject( {
			type: 'rect',
			kind: 'cover',
			page: p.index,
			x: round( c.x - 1.5 ),
			y: round( c.top - 1 ),
			w: round( c.w + 3 ),
			h: round( c.bottom - c.top + 2 ),
			fill: colors.bg,
			opacity: 1,
			redact: true,
		} );
		var t = addObject( {
			type: 'text',
			page: p.index,
			x: round( c.x ),
			y: round( c.base - BASELINE * size ),
			w: c.w,
			h: size * LH,
			text: c.str,
			size: size,
			color: colors.fg,
			bold: isBoldChunk( p, c ),
		} );
		measureText( t );
		commit();
		startEditing( t, true );
		status( 'Wpisz nowy tekst. Oryginalny fragment został zakryty kolorem tła.' );
	}

	var hoverEl = null;
	function hideHover() {
		if ( hoverEl ) {
			hoverEl.hidden = true;
		}
	}
	async function showHover( p, x, y ) {
		var chunks = p.chunks || ( await pageChunks( p ) );
		var c = chunkAt( chunks, x, y );
		if ( ! hoverEl ) {
			hoverEl = document.createElement( 'div' );
			hoverEl.className = 'gfxdoc-pe__hover';
		}
		if ( ! c || state.tool !== 'edittext' ) {
			hideHover();
			return;
		}
		if ( hoverEl.parentNode !== p.overlay ) {
			p.overlay.appendChild( hoverEl );
		}
		var s = state.scale;
		hoverEl.hidden = false;
		hoverEl.style.left = ( c.x - 2 ) * s + 'px';
		hoverEl.style.top = ( c.top - 1 ) * s + 'px';
		hoverEl.style.width = ( c.w + 4 ) * s + 'px';
		hoverEl.style.height = ( c.bottom - c.top + 2 ) * s + 'px';
	}

	/* ---------------------------------------------------------------
	 * Pointer interaction
	 * ------------------------------------------------------------- */

	function pointOn( p, e ) {
		var r = p.overlay.getBoundingClientRect();
		return { x: ( e.clientX - r.left ) / state.scale, y: ( e.clientY - r.top ) / state.scale };
	}

	var drag = null;

	els.pages.addEventListener( 'pointerdown', function ( e ) {
		if ( e.button !== 0 ) {
			return;
		}
		var overlay = e.target.closest( '.gfxdoc-pe__overlay' );
		if ( ! overlay ) {
			return;
		}
		var p = state.pages[ overlay.dataset.page ];
		var pt = pointOn( p, e );
		var objNode = e.target.closest( '.gfxdoc-pe__obj' );

		if ( objNode ) {
			var o = findObj( objNode.dataset.id );
			if ( ! o ) {
				return;
			}
			if ( state.editing === o.id ) {
				return; // clicking inside the text being edited: move the caret
			}
			e.preventDefault();
			select( o.id );
			var resizing = e.target.classList.contains( 'gfxdoc-pe__handle' );
			drag = {
				mode: resizing ? 'resize' : 'move',
				obj: o,
				p: p,
				start: pt,
				orig: { x: o.x, y: o.y, w: o.w, h: o.h },
				moved: false,
			};
			objNode.setPointerCapture( e.pointerId );
			return;
		}

		e.preventDefault();
		finishEditing();

		switch ( state.tool ) {
			case 'select':
				select( null );
				break;
			case 'text':
				var t = addObject( {
					type: 'text',
					page: p.index,
					x: round( pt.x ),
					y: round( pt.y - state.defaults.size * 0.6 ),
					w: 10,
					h: state.defaults.size * LH,
					text: '',
					size: state.defaults.size,
					color: state.defaults.color,
					bold: state.defaults.bold,
				} );
				startEditing( t );
				setToolSilently( 'select' );
				break;
			case 'edittext':
				editExistingText( p, pt.x, pt.y );
				break;
			case 'cover':
			case 'highlight':
				var isCover = state.tool === 'cover';
				var r = addObject( {
					type: 'rect',
					kind: isCover ? 'cover' : 'highlight',
					page: p.index,
					x: round( pt.x ),
					y: round( pt.y ),
					w: 1,
					h: 1,
					fill: isCover ? '#ffffff' : '#fde047',
					opacity: isCover ? 1 : 0.45,
				} );
				if ( isCover ) {
					r.redact = true;
				}
				select( r.id );
				drag = { mode: 'draw', obj: r, p: p, start: pt, moved: false };
				overlay.setPointerCapture( e.pointerId );
				break;
		}
	} );

	function setToolSilently( tool ) {
		state.tool = tool;
		root.dataset.tool = tool;
		root.querySelectorAll( '[data-tool]' ).forEach( function ( b ) {
			b.classList.toggle( 'is-active', b.dataset.tool === tool );
		} );
	}

	els.pages.addEventListener( 'pointermove', function ( e ) {
		if ( ! drag ) {
			if ( state.tool === 'edittext' ) {
				var overlay = e.target.closest( '.gfxdoc-pe__overlay' );
				if ( overlay && ! e.target.closest( '.gfxdoc-pe__obj' ) ) {
					var hp = state.pages[ overlay.dataset.page ];
					var hpt = pointOn( hp, e );
					showHover( hp, hpt.x, hpt.y );
				} else {
					hideHover();
				}
			}
			return;
		}
		var pt = pointOn( drag.p, e );
		var dx = pt.x - drag.start.x;
		var dy = pt.y - drag.start.y;
		if ( Math.abs( dx ) + Math.abs( dy ) > 0.5 ) {
			drag.moved = true;
		}
		var o = drag.obj;
		if ( drag.mode === 'move' ) {
			o.x = round( drag.orig.x + dx );
			o.y = round( drag.orig.y + dy );
		} else if ( drag.mode === 'resize' ) {
			// drag delta in the object's own (possibly rotated) frame
			var rr = ( o.rot || 0 ) * Math.PI / 180;
			var lx = dx * Math.cos( rr ) + dy * Math.sin( rr );
			var ly = -dx * Math.sin( rr ) + dy * Math.cos( rr );
			if ( o.type === 'image' ) {
				var ratio = drag.orig.h / drag.orig.w;
				o.w = round( Math.max( 5, drag.orig.w + lx ) );
				o.h = round( o.w * ratio );
			} else {
				o.w = round( Math.max( 2, drag.orig.w + lx ) );
				o.h = round( Math.max( 2, drag.orig.h + ly ) );
			}
		} else if ( drag.mode === 'draw' ) {
			o.x = round( Math.min( drag.start.x, pt.x ) );
			o.y = round( Math.min( drag.start.y, pt.y ) );
			o.w = round( Math.max( 1, Math.abs( dx ) ) );
			o.h = round( Math.max( 1, Math.abs( dy ) ) );
		}
		renderObject( o );
	} );

	function endDrag() {
		if ( ! drag ) {
			return;
		}
		var d = drag;
		drag = null;
		if ( d.mode === 'draw' && ( d.obj.w < 3 || d.obj.h < 3 ) ) {
			// A click instead of a drag: drop a default-size box.
			var o = d.obj;
			o.w = o.kind === 'cover' ? 120 : 150;
			o.h = o.kind === 'cover' ? 16 : 14;
			o.y = round( o.y - o.h / 2 );
			renderObject( o );
		}
		if ( d.moved || d.mode === 'draw' ) {
			commit();
		}
	}
	els.pages.addEventListener( 'pointerup', endDrag );
	els.pages.addEventListener( 'pointercancel', endDrag );

	els.pages.addEventListener( 'dblclick', function ( e ) {
		var objNode = e.target.closest( '.gfxdoc-pe__obj--text' );
		if ( objNode ) {
			startEditing( findObj( objNode.dataset.id ) );
		}
	} );

	// Plain-text editing: Enter = new line, paste = text only.
	els.pages.addEventListener( 'keydown', function ( e ) {
		if ( ! e.target.classList || ! e.target.classList.contains( 'gfxdoc-pe__text' ) ) {
			return;
		}
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			document.execCommand( 'insertLineBreak' );
		} else if ( e.key === 'Escape' ) {
			e.preventDefault();
			finishEditing();
		}
	} );
	els.pages.addEventListener( 'paste', function ( e ) {
		if ( e.target.closest && e.target.closest( '.gfxdoc-pe__text' ) ) {
			e.preventDefault();
			var text = ( e.clipboardData || window.clipboardData ).getData( 'text/plain' );
			document.execCommand( 'insertText', false, text );
		}
	} );
	els.pages.addEventListener( 'focusout', function ( e ) {
		if ( e.target.classList && e.target.classList.contains( 'gfxdoc-pe__text' ) ) {
			// Wait a tick: clicking a toolbar control shouldn't lose the text.
			setTimeout( function () {
				if ( state.editing && ! root.contains( document.activeElement ) ) {
					finishEditing();
				} else if ( state.editing && ! document.activeElement.closest( '.gfxdoc-pe__text, .gfxdoc-pe__toolbar' ) ) {
					finishEditing();
				}
			}, 0 );
		}
	} );
	els.pages.addEventListener( 'input', function ( e ) {
		if ( e.target.classList && e.target.classList.contains( 'gfxdoc-pe__text' ) ) {
			var o = state.editing && findObj( state.editing );
			if ( o ) {
				o.text = readText( e.target );
			}
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		var tag = ( e.target.tagName || '' ).toLowerCase();
		var inField = tag === 'input' || tag === 'select' || tag === 'textarea' || e.target.isContentEditable;
		var mod = e.ctrlKey || e.metaKey;

		if ( mod && e.key.toLowerCase() === 's' ) {
			e.preventDefault();
			save();
			return;
		}
		if ( inField ) {
			return;
		}
		if ( mod && e.key.toLowerCase() === 'z' ) {
			e.preventDefault();
			if ( e.shiftKey ) {
				redo();
			} else {
				undo();
			}
			return;
		}
		if ( mod && e.key.toLowerCase() === 'y' ) {
			e.preventDefault();
			redo();
			return;
		}
		var o = state.selected && findObj( state.selected );
		if ( ( e.key === 'Delete' || e.key === 'Backspace' ) && o ) {
			e.preventDefault();
			deleteSelected();
			return;
		}
		if ( e.key === 'Escape' ) {
			select( null );
			setTool( 'select' );
			return;
		}
		if ( e.key === 'Enter' && o && o.type === 'text' ) {
			e.preventDefault();
			startEditing( o );
			return;
		}
		if ( o && /^Arrow/.test( e.key ) ) {
			e.preventDefault();
			var step = e.shiftKey ? 10 : 1;
			if ( e.key === 'ArrowLeft' ) {
				o.x -= step;
			} else if ( e.key === 'ArrowRight' ) {
				o.x += step;
			} else if ( e.key === 'ArrowUp' ) {
				o.y -= step;
			} else {
				o.y += step;
			}
			renderObject( o );
			clearTimeout( state.nudgeTimer );
			state.nudgeTimer = setTimeout( commit, 400 );
			return;
		}
		if ( ! mod && ! e.altKey ) {
			var map = { v: 'select', e: 'edittext', t: 'text', w: 'cover', h: 'highlight' };
			var tool = map[ e.key.toLowerCase() ];
			if ( tool ) {
				setTool( tool );
			}
		}
	} );

	/* ---------------------------------------------------------------
	 * Images (signature / stamp / logo)
	 * ------------------------------------------------------------- */

	function readImage( file ) {
		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onerror = reject;
			reader.onload = function () {
				var img = new Image();
				img.onerror = reject;
				img.onload = function () {
					// Downscale big photos/scans — keeps the saved data small.
					var max = 1600;
					var k = Math.min( 1, max / Math.max( img.width, img.height ) );
					var isPng = file.type === 'image/png';
					if ( k === 1 && /^data:image\/(png|jpeg)/.test( reader.result ) ) {
						resolve( { src: reader.result, w: img.width, h: img.height } );
						return;
					}
					var c = document.createElement( 'canvas' );
					c.width = Math.round( img.width * k );
					c.height = Math.round( img.height * k );
					c.getContext( '2d' ).drawImage( img, 0, 0, c.width, c.height );
					resolve( { src: c.toDataURL( isPng ? 'image/png' : 'image/jpeg', 0.9 ), w: c.width, h: c.height } );
				};
				img.src = reader.result;
			};
			reader.readAsDataURL( file );
		} );
	}

	function mostVisiblePage() {
		var best = state.pages[ state.order[ 0 ] ];
		var bestArea = -1;
		state.pages.forEach( function ( p ) {
			if ( p.el.hidden ) {
				return;
			}
			var r = p.el.getBoundingClientRect();
			var vis = Math.max( 0, Math.min( r.bottom, window.innerHeight ) - Math.max( r.top, 0 ) );
			if ( vis > bestArea ) {
				bestArea = vis;
				best = p;
			}
		} );
		return best;
	}

	els.imageInput.addEventListener( 'change', async function () {
		var file = this.files && this.files[ 0 ];
		this.value = '';
		if ( ! file ) {
			return;
		}
		if ( ! /^image\/(png|jpeg)$/.test( file.type ) ) {
			status( 'Obsługiwane są obrazy PNG i JPG.', 'error' );
			return;
		}
		try {
			var im = await readImage( file );
			var p = mostVisiblePage();
			var w = Math.min( 180, im.w * 0.75 );
			var h = w * im.h / im.w;
			var r = p.el.getBoundingClientRect();
			var cy = ( Math.min( window.innerHeight, r.bottom ) + Math.max( 0, r.top ) ) / 2 - r.top;
			var o = addObject( {
				type: 'image',
				page: p.index,
				x: round( ( p.vp.width - w ) / 2 ),
				y: round( Math.max( 0, cy / state.scale - h / 2 ) ),
				w: round( w ),
				h: round( h ),
				src: im.src,
			} );
			setTool( 'select' );
			select( o.id );
			commit();
			status( 'Przeciągnij obraz w odpowiednie miejsce; róg w prawym dolnym rogu zmienia rozmiar. Najlepiej sprawdza się PNG z przezroczystym tłem.' );
		} catch ( e ) {
			status( 'Nie udało się wczytać obrazu.', 'error' );
		}
	} );

	/* ---------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------- */

	var fontCache = {};
	function loadFont( bold ) {
		var url = bold ? cfg.fontBold : cfg.fontRegular;
		if ( ! fontCache[ url ] ) {
			fontCache[ url ] = fetch( url, { credentials: 'same-origin' } ).then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'Nie można pobrać czcionki (' + r.status + ')' );
				}
				return r.arrayBuffer();
			} );
		}
		return fontCache[ url ];
	}

	/**
	 * Re-reads the redacted file with pdf.js and lists any characters still
	 * inside a redaction area (fonts whose metrics couldn't be trusted).
	 */
	async function verifyRedaction( bytes, rectsByPage ) {
		var doc = await pdfjsLib.getDocument( { data: bytes.slice() } ).promise;
		var out = {};
		try {
			var keys = Object.keys( rectsByPage );
			for ( var i = 0; i < keys.length; i++ ) {
				var page = await doc.getPage( parseInt( keys[ i ], 10 ) + 1 );
				var tc = await page.getTextContent();
				out[ keys[ i ] ] = GFXDocRedact.findLeftovers( tc.items, rectsByPage[ keys[ i ] ] );
			}
		} finally {
			doc.destroy();
		}
		return out;
	}

	/**
	 * Last resort for a page whose covered text couldn't be cut out: render
	 * it (with the covers painted on) to a ~200 dpi JPEG that replaces the page.
	 */
	async function rasterizePage( bytes, pageIndex, rects ) {
		var doc = await pdfjsLib.getDocument( { data: bytes.slice() } ).promise;
		try {
			var page = await doc.getPage( pageIndex + 1 );
			var scale = 200 / 72;
			var vp = page.getViewport( { scale: scale, rotation: 0 } );
			var canvas = document.createElement( 'canvas' );
			canvas.width = Math.ceil( vp.width );
			canvas.height = Math.ceil( vp.height );
			var ctx = canvas.getContext( '2d' );
			ctx.fillStyle = '#ffffff';
			ctx.fillRect( 0, 0, canvas.width, canvas.height );
			await page.render( { canvasContext: ctx, viewport: vp } ).promise;
			// paint the redaction areas (user space → canvas)
			var fills = {};
			state.objects.forEach( function ( o ) {
				if ( o.page === pageIndex && o.type === 'rect' && o.kind === 'cover' && o.redact !== false ) {
					fills[ JSON.stringify( GFXDocFlatten.userBox( o, state.pages[ o.page ].vp ).map( function ( n ) {
						return Math.round( n * 100 ) / 100;
					} ) ) ] = o.fill;
				}
			} );
			rects.forEach( function ( r ) {
				var a = vp.convertToViewportPoint( r[ 0 ], r[ 1 ] );
				var b = vp.convertToViewportPoint( r[ 2 ], r[ 3 ] );
				var key = JSON.stringify( r.map( function ( n ) {
					return Math.round( n * 100 ) / 100;
				} ) );
				ctx.fillStyle = fills[ key ] || '#ffffff';
				ctx.fillRect( Math.min( a[ 0 ], b[ 0 ] ) - 1, Math.min( a[ 1 ], b[ 1 ] ) - 1, Math.abs( b[ 0 ] - a[ 0 ] ) + 2, Math.abs( b[ 1 ] - a[ 1 ] ) + 2 );
			} );
			var blob = await new Promise( function ( resolve ) {
				canvas.toBlob( resolve, 'image/jpeg', 0.88 );
			} );
			return { jpeg: new Uint8Array( await blob.arrayBuffer() ), view: page.view.slice() };
		} finally {
			doc.destroy();
		}
	}

	async function save() {
		if ( state.saving || ! state.bytes ) {
			return false;
		}
		finishEditing();
		state.saving = true;
		els.save.disabled = true;
		status( 'Zapisywanie…' );

		try {
			var pages = pageList();
			var objects = cleanObjects().filter( function ( o ) {
				return state.order.indexOf( o.page ) !== -1; // drop objects of deleted pages
			} );
			var fd = new FormData();
			fd.append( 'action', 'gfxdoc_pdf_save' );
			fd.append( 'post', cfg.postId );
			fd.append( '_ajax_nonce', cfg.nonce );
			fd.append( 'edits', JSON.stringify( { objects: objects, pages: pages } ) );

			var report = null;
			if ( objects.length || pages ) {
				status( 'Przygotowywanie pliku…' );
				var out = await GFXDocFlatten.flatten( {
					bytes: state.bytes,
					objects: objects,
					pages: pages,
					viewport: function ( src, rotation ) {
						return state.pages[ src ].page.getViewport( { scale: 1, rotation: rotation } );
					},
					baseRotation: state.pages.map( function ( p ) {
						return p.base;
					} ),
					loadFont: loadFont,
					verify: verifyRedaction,
					rasterize: rasterizePage,
					PDFLib: PDFLib,
					fontkit: fontkit,
					Redact: window.GFXDocRedact,
				} );
				report = out.report;
				var blob = new Blob( [ out.bytes ], { type: 'application/pdf' } );
				if ( cfg.maxSize && blob.size > cfg.maxSize ) {
					throw new Error( 'Plik po zmianach jest większy niż limit serwera. Zmniejsz lub usuń wstawione obrazy.' );
				}
				fd.append( 'pdf', blob, 'edited.pdf' );
			}

			var res = await fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } );
			var json = null;
			try {
				json = await res.json();
			} catch ( e ) {} // eslint-disable-line no-empty
			if ( ! res.ok || ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) || 'Błąd serwera (' + res.status + ')' );
			}
			setDirty( false );
			var time = new Date().toLocaleTimeString( 'pl-PL', { hour: '2-digit', minute: '2-digit' } );
			var extra = [];
			if ( report && report.removed ) {
				extra.push( 'trwale usunięto znaków spod zakryć: ' + report.removed );
			}
			if ( report && report.annots ) {
				extra.push( 'usunięto adnotacji/pól: ' + report.annots );
			}
			if ( report && report.rasterized.length ) {
				extra.push( 'strony zapisane jako obraz (nietypowa czcionka): ' + report.rasterized.map( function ( i ) {
					return state.order.indexOf( i ) + 1;
				} ).join( ', ' ) );
			}
			status( '✓ ' + json.data.message + ( extra.length ? ' — ' + extra.join( '; ' ) : '' ) + ' (' + time + ')', 'success' );
			return true;
		} catch ( e ) {
			window.console && console.error( e ); // eslint-disable-line no-console
			status( 'Nie zapisano: ' + ( e && e.message ? e.message : e ), 'error' );
			return false;
		} finally {
			state.saving = false;
			els.save.disabled = false;
		}
	}

	root.dataset.tool = state.tool;
	updateHistoryButtons();
	load();
} )();
