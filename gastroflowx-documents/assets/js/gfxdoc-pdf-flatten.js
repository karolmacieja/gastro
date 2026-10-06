/**
 * Builds the edited PDF from the untouched original:
 *
 *   1. redaction — glyphs under "cover" rectangles (redact !== false) are
 *      removed from the file (GFXDocRedact), then checked with pdf.js; a page
 *      where covered text still survives (unusual fonts) is replaced by an
 *      image of itself as a last resort,
 *   2. drawing — covers, highlights, images and text are drawn with pdf-lib,
 *   3. pages — rotation, deletion and new order are applied,
 *   4. clean-up — unreferenced objects (old content) are dropped.
 *
 * Coordinates of every object are in "view points" of its page as displayed
 * in the editor: rotated by (original /Rotate + extra rotation), origin
 * top-left, 1 unit = 1 pt. An object may itself be turned by `rot`
 * (0/90/180/270, clockwise) — this happens when a page with objects on it
 * is rotated. Mapping into PDF user space uses the pdf.js viewport of that
 * page (viewport.convertToPdfPoint), which also handles non-zero MediaBox
 * origins.
 *
 * No DOM code here, so it can be tested in Node.
 *
 * @package GastroFlowx_Documents
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.GFXDocFlatten = factory();
	}
} )( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	// DejaVu Sans vertical metrics (hhea): ascender 1901 / descender -483
	// on 2048 units per em. With CSS line-height 1.2 the first baseline sits
	// half-leading + ascent below the top of the text box.
	var LINE_HEIGHT = 1.2;
	var BASELINE = ( LINE_HEIGHT - ( 1901 + 483 ) / 2048 ) / 2 + 1901 / 2048; // ≈ 0.946

	function norm( deg ) {
		return ( ( ( deg || 0 ) % 360 ) + 360 ) % 360;
	}

	function hexToRgb( PDFLib, hex ) {
		var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec( hex || '' );
		if ( ! m ) {
			return PDFLib.rgb( 0, 0, 0 );
		}
		return PDFLib.rgb( parseInt( m[ 1 ], 16 ) / 255, parseInt( m[ 2 ], 16 ) / 255, parseInt( m[ 3 ], 16 ) / 255 );
	}

	function dataUrlToBytes( dataUrl ) {
		var b64 = dataUrl.split( ',' )[ 1 ] || '';
		var bin = typeof atob === 'function' ? atob( b64 ) : Buffer.from( b64, 'base64' ).toString( 'binary' ); // eslint-disable-line no-undef
		var out = new Uint8Array( bin.length );
		for ( var i = 0; i < bin.length; i++ ) {
			out[ i ] = bin.charCodeAt( i );
		}
		return out;
	}

	/** Point in the object's own (unrotated) frame → view point. */
	function localToView( o, lx, ly ) {
		var r = norm( o.rot ) * Math.PI / 180;
		var c = Math.round( Math.cos( r ) );
		var s = Math.round( Math.sin( r ) );
		return [ o.x + lx * c - ly * s, o.y + lx * s + ly * c ];
	}

	/** Axis-aligned box of an object in PDF user space: [x0, y0, x1, y1]. */
	function userBox( o, vp ) {
		var pts = [ [ 0, 0 ], [ o.w, 0 ], [ 0, o.h ], [ o.w, o.h ] ].map( function ( p ) {
			var v = localToView( o, p[ 0 ], p[ 1 ] );
			return vp.convertToPdfPoint( v[ 0 ], v[ 1 ] );
		} );
		var xs = pts.map( function ( p ) {
			return p[ 0 ];
		} );
		var ys = pts.map( function ( p ) {
			return p[ 1 ];
		} );
		return [ Math.min.apply( null, xs ), Math.min.apply( null, ys ), Math.max.apply( null, xs ), Math.max.apply( null, ys ) ];
	}

	function isRedaction( o ) {
		return o.type === 'rect' && o.kind === 'cover' && o.redact !== false;
	}

	/** Default page list: every page, original order, no extra rotation. */
	function identityPages( n ) {
		var out = [];
		for ( var i = 0; i < n; i++ ) {
			out.push( { src: i, rot: 0 } );
		}
		return out;
	}

	/**
	 * Swaps a page's content for a full-page image (used when covered text
	 * could not be removed from the content itself).
	 */
	function replaceWithImage( PDFLib, doc, pageIndex, raster ) {
		var page = doc.getPage( pageIndex );
		var ctx = doc.context;
		var N = PDFLib.PDFName;
		return doc.embedJpg( raster.jpeg ).then( function ( img ) {
			var v = raster.view; // [x0, y0, x1, y1]
			var w = v[ 2 ] - v[ 0 ];
			var h = v[ 3 ] - v[ 1 ];
			page.node.set( N.of( 'Resources' ), ctx.obj( { XObject: { GfxRaster: img.ref } } ) );
			var ops = 'q ' + w + ' 0 0 ' + h + ' ' + v[ 0 ] + ' ' + v[ 1 ] + ' cm /GfxRaster Do Q';
			var bytes = new Uint8Array( ops.length );
			for ( var i = 0; i < ops.length; i++ ) {
				bytes[ i ] = ops.charCodeAt( i );
			}
			page.node.set( N.of( 'Contents' ), ctx.register( ctx.flateStream( bytes ) ) );
		} );
	}

	/**
	 * @param {Object}     o
	 * @param {Uint8Array} o.bytes        Original PDF.
	 * @param {Array}      o.objects      Editor objects.
	 * @param {Array|null} o.pages        [{src, rot}] in output order (null = unchanged).
	 * @param {Function}   o.viewport     (srcIndex, rotation) => pdf.js viewport at scale 1.
	 * @param {Array}      o.baseRotation original /Rotate of each page.
	 * @param {Function}   o.loadFont     async (bold:boolean) => font bytes.
	 * @param {Function}  [o.verify]      async (bytes, rectsByPage) => { pageIndex: [leftover strings] }
	 * @param {Function}  [o.rasterize]   async (bytes, pageIndex, rects) => { jpeg, view }
	 * @param {Object}     o.PDFLib, o.fontkit, o.Redact
	 * @return {Promise<{bytes: Uint8Array, report: Object}>}
	 */
	async function flatten( o ) {
		var PDFLib = o.PDFLib;
		var Redact = o.Redact;
		var loadOpts = { ignoreEncryption: true, updateMetadata: false };
		var report = { removed: 0, annots: 0, rasterized: [], deleted: 0 };

		var probe = await PDFLib.PDFDocument.load( o.bytes, loadOpts );
		var pageCount = probe.getPageCount();
		var pages = o.pages && o.pages.length ? o.pages : identityPages( pageCount );
		var kept = {};
		pages.forEach( function ( p ) {
			kept[ p.src ] = p;
		} );
		report.deleted = pageCount - pages.length;

		var rotationOf = function ( src ) {
			return norm( ( o.baseRotation[ src ] || 0 ) + ( kept[ src ] ? kept[ src ].rot : 0 ) );
		};
		var vpCache = {};
		var vpOf = function ( src ) {
			var r = rotationOf( src );
			var key = src + ':' + r;
			if ( ! vpCache[ key ] ) {
				vpCache[ key ] = o.viewport( src, r );
			}
			return vpCache[ key ];
		};

		var objects = o.objects.filter( function ( obj ) {
			return kept[ obj.page ] && obj.page < pageCount;
		} );

		/* 1. Redaction ------------------------------------------------ */
		var rectsByPage = {};
		objects.forEach( function ( obj ) {
			if ( isRedaction( obj ) ) {
				( rectsByPage[ obj.page ] = rectsByPage[ obj.page ] || [] ).push( userBox( obj, vpOf( obj.page ) ) );
			}
		} );

		var base = o.bytes;
		var needsClean = report.deleted > 0;
		if ( Redact && Object.keys( rectsByPage ).length ) {
			var docA = probe;
			var r = Redact.redactDocument( PDFLib, docA, rectsByPage );
			report.removed = r.removed;
			report.annots = r.annots;
			Redact.collectGarbage( PDFLib, docA );
			base = await docA.save();

			if ( o.verify ) {
				var left = await o.verify( base, rectsByPage );
				var bad = Object.keys( left ).filter( function ( k ) {
					return left[ k ] && left[ k ].length;
				} );
				if ( bad.length && o.rasterize ) {
					var docR = await PDFLib.PDFDocument.load( base, loadOpts );
					for ( var b = 0; b < bad.length; b++ ) {
						var idx = parseInt( bad[ b ], 10 );
						var raster = await o.rasterize( base, idx, rectsByPage[ idx ] );
						await replaceWithImage( PDFLib, docR, idx, raster );
						report.rasterized.push( idx );
					}
					Redact.collectGarbage( PDFLib, docR );
					base = await docR.save();
				} else if ( bad.length ) {
					report.leftovers = left;
				}
			}
			needsClean = true;
		}

		/* 2. Drawing ---------------------------------------------------- */
		var doc = await PDFLib.PDFDocument.load( base, loadOpts );
		doc.registerFontkit( o.fontkit );

		var fonts = {};
		async function font( bold ) {
			var key = bold ? 'bold' : 'regular';
			if ( ! fonts[ key ] ) {
				fonts[ key ] = await doc.embedFont( await o.loadFont( bold ), { subset: true } );
			}
			return fonts[ key ];
		}
		var images = {};
		var docPages = doc.getPages();

		for ( var i = 0; i < objects.length; i++ ) {
			var obj = objects[ i ];
			var page = docPages[ obj.page ];
			var vp = vpOf( obj.page );
			var R = rotationOf( obj.page );
			var angle = PDFLib.degrees( norm( R - norm( obj.rot ) ) );
			var toPdf = function ( lx, ly ) {
				var v = localToView( obj, lx, ly );
				return vp.convertToPdfPoint( v[ 0 ], v[ 1 ] );
			};

			if ( obj.type === 'rect' ) {
				var box = userBox( obj, vp );
				var rectOpts = {
					x: box[ 0 ],
					y: box[ 1 ],
					width: box[ 2 ] - box[ 0 ],
					height: box[ 3 ] - box[ 1 ],
					color: hexToRgb( PDFLib, obj.fill ),
					opacity: obj.opacity == null ? 1 : obj.opacity,
					borderWidth: 0,
				};
				if ( obj.kind === 'highlight' && PDFLib.BlendMode ) {
					rectOpts.blendMode = PDFLib.BlendMode.Multiply;
				}
				page.drawRectangle( rectOpts );
			} else if ( obj.type === 'image' ) {
				var img = images[ obj.src ];
				if ( ! img ) {
					var imgBytes = dataUrlToBytes( obj.src );
					img = /^data:image\/png/.test( obj.src ) ? await doc.embedPng( imgBytes ) : await doc.embedJpg( imgBytes );
					images[ obj.src ] = img;
				}
				// The image's own bottom-left corner.
				var bl = toPdf( 0, obj.h );
				page.drawImage( img, { x: bl[ 0 ], y: bl[ 1 ], width: obj.w, height: obj.h, rotate: angle } );
			} else if ( obj.type === 'text' ) {
				var f = await font( !! obj.bold );
				var color = hexToRgb( PDFLib, obj.color );
				var lines = String( obj.text ).replace( /\t/g, '    ' ).split( '\n' );
				for ( var l = 0; l < lines.length; l++ ) {
					if ( ! lines[ l ] ) {
						continue;
					}
					var at = toPdf( 0, ( l * LINE_HEIGHT + BASELINE ) * obj.size );
					page.drawText( lines[ l ], { x: at[ 0 ], y: at[ 1 ], size: obj.size, font: f, color: color, rotate: angle } );
				}
			}
		}

		/* 3. Pages ------------------------------------------------------- */
		var changedPages = !! ( o.pages && o.pages.length );
		if ( changedPages ) {
			pages.forEach( function ( p ) {
				var pg = docPages[ p.src ];
				if ( pg && norm( p.rot ) ) {
					pg.setRotation( PDFLib.degrees( rotationOf( p.src ) ) );
				}
			} );
			var identity = pages.length === docPages.length && pages.every( function ( p, k ) {
				return p.src === k;
			} );
			if ( ! identity ) {
				for ( var d = docPages.length - 1; d >= 0; d-- ) {
					doc.removePage( d );
				}
				pages.forEach( function ( p ) {
					doc.addPage( docPages[ p.src ] );
				} );
			}
		}

		/* 4. Clean-up ------------------------------------------------------ */
		if ( needsClean && Redact ) {
			Redact.collectGarbage( PDFLib, doc );
		}

		return { bytes: await doc.save(), report: report };
	}

	return {
		flatten: flatten,
		localToView: localToView,
		userBox: userBox,
		LINE_HEIGHT: LINE_HEIGHT,
		BASELINE: BASELINE,
		dataUrlToBytes: dataUrlToBytes,
	};
} );
