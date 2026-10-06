/* global jQuery, pdfjsLib, tinymce, gfxdocPdfBox */
/**
 * "Plik PDF" meta box on the gfx_document edit screen:
 *  - upload (button or drag & drop) / replace / delete the PDF,
 *  - "Przenieś tekst do treści dokumentu": reads the PDF text with pdf.js
 *    and turns it into clean HTML (headings, paragraphs, lists, bold) for
 *    the Classic editor.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.gfxdocPdfBox;
	var $box = $( '#gfxdoc-pdf-box' );
	if ( ! cfg || ! $box.length ) {
		return;
	}
	var t = cfg.i18n;

	if ( window.pdfjsLib ) {
		pdfjsLib.GlobalWorkerOptions.workerSrc = cfg.workerSrc;
	}

	/* ---------------------------------------------------------------
	 * Upload / replace / delete
	 * ------------------------------------------------------------- */

	function busy( on ) {
		$box.find( '.gfxdoc-pdf-progress' ).prop( 'hidden', ! on );
		$box.find( 'button, input, .button' ).prop( 'disabled', on ).toggleClass( 'disabled', on );
	}

	function request( data, file ) {
		var fd = new FormData();
		fd.append( 'post', cfg.postId );
		fd.append( '_ajax_nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( k ) {
			fd.append( k, data[ k ] );
		} );
		if ( file ) {
			fd.append( 'pdf', file, file.name );
		}
		return $.ajax( { url: cfg.ajaxUrl, method: 'POST', data: fd, processData: false, contentType: false } );
	}

	function fail( xhr ) {
		var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
		window.alert( msg || t.error );
	}

	function upload( file ) {
		if ( ! file ) {
			return;
		}
		if ( ! /\.pdf$/i.test( file.name ) && file.type !== 'application/pdf' ) {
			window.alert( t.notPdf );
			return;
		}
		if ( cfg.maxSize && file.size > cfg.maxSize ) {
			window.alert( t.tooBig );
			return;
		}
		busy( true );
		request( { action: 'gfxdoc_pdf_upload' }, file )
			.done( function ( res ) {
				if ( ! res.success ) {
					return fail();
				}
				$box.html( res.data.html );
				var $title = $( '#title' );
				if ( res.data.title && $title.length && ! $title.val() ) {
					$title.val( res.data.title ).trigger( 'input' );
					$( '#title-prompt-text' ).addClass( 'screen-reader-text' );
				}
			} )
			.fail( fail )
			.always( function () {
				busy( false );
			} );
	}

	$box.on( 'change', '.gfxdoc-pdf-input', function () {
		var file = this.files && this.files[ 0 ];
		var replacing = $( this ).hasClass( 'gfxdoc-pdf-input--replace' );
		this.value = '';
		if ( file && replacing && ! window.confirm( t.confirmReplace ) ) {
			return;
		}
		upload( file );
	} );

	$box.on( 'dragover dragenter', '.gfxdoc-pdf-drop', function ( e ) {
		e.preventDefault();
		$( this ).addClass( 'is-over' );
	} );
	$box.on( 'dragleave drop', '.gfxdoc-pdf-drop', function ( e ) {
		e.preventDefault();
		$( this ).removeClass( 'is-over' );
		if ( e.type === 'drop' ) {
			var dt = e.originalEvent.dataTransfer;
			upload( dt && dt.files && dt.files[ 0 ] );
		}
	} );
	$box.on( 'keydown', '.gfxdoc-pdf-drop', function ( e ) {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			$( this ).find( '.gfxdoc-pdf-input' ).trigger( 'click' );
		}
	} );

	$box.on( 'click', '.gfxdoc-pdf-delete', function () {
		if ( ! window.confirm( t.confirmDelete ) ) {
			return;
		}
		busy( true );
		request( { action: 'gfxdoc_pdf_delete' } )
			.done( function ( res ) {
				if ( res.success ) {
					$box.html( res.data.html );
				}
			} )
			.fail( fail )
			.always( function () {
				busy( false );
			} );
	} );

	/* ---------------------------------------------------------------
	 * PDF text → editor HTML
	 * ------------------------------------------------------------- */

	function esc( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	/**
	 * Reads every page and returns simplified text runs:
	 * { str, x, y (baseline, top-left origin), w, size, bold } in points.
	 */
	async function readPdfRuns( url ) {
		var doc = await pdfjsLib.getDocument( { url: url, withCredentials: true } ).promise;
		var pages = [];
		for ( var n = 1; n <= doc.numPages; n++ ) {
			var page = await doc.getPage( n );
			var vp = page.getViewport( { scale: 1 } );
			var tc = await page.getTextContent();
			var fontNames = {};
			try {
				// Loads fonts into commonObjs so we can read real font names
				// (needed to spot bold text).
				await page.getOperatorList();
			} catch ( e ) {} // eslint-disable-line no-empty
			var runs = [];
			tc.items.forEach( function ( it ) {
				if ( typeof it.str !== 'string' || ! it.str.length ) {
					return;
				}
				var m = pdfjsLib.Util.transform( vp.transform, it.transform );
				var size = Math.hypot( m[ 2 ], m[ 3 ] );
				if ( size < 1 ) {
					return;
				}
				if ( ! ( it.fontName in fontNames ) ) {
					var name = '';
					try {
						if ( page.commonObjs.has( it.fontName ) ) {
							name = page.commonObjs.get( it.fontName ).name || '';
						}
					} catch ( e ) {} // eslint-disable-line no-empty
					var fam = tc.styles[ it.fontName ] ? tc.styles[ it.fontName ].fontFamily : '';
					fontNames[ it.fontName ] = /bold|black|heavy|semibold|demi/i.test( name + ' ' + fam );
				}
				runs.push( { str: it.str, x: m[ 4 ], y: m[ 5 ], w: it.width, size: size, bold: fontNames[ it.fontName ] } );
			} );
			pages.push( { runs: runs, width: vp.width } );
		}
		return pages;
	}

	/**
	 * Merges runs into text + bold segments, inserting spaces where the PDF
	 * only positioned words apart.
	 */
	function buildSegs( runs ) {
		var segs = [];
		var prevEnd = null;
		runs.forEach( function ( r ) {
			var str = r.str;
			if ( prevEnd !== null && r.x - prevEnd > r.size * 0.18 ) {
				var last = segs[ segs.length - 1 ];
				if ( last && ! /\s$/.test( last.text ) && ! /^\s/.test( str ) ) {
					str = ' ' + str;
				}
			}
			prevEnd = r.x + r.w;
			var cur = segs[ segs.length - 1 ];
			if ( cur && cur.bold === r.bold ) {
				cur.text += str;
			} else {
				segs.push( { text: str, bold: r.bold } );
			}
		} );
		return segs;
	}

	function makeCell( runs ) {
		var visible = runs.filter( function ( r ) {
			return r.str.trim();
		} );
		var first = visible[ 0 ] || runs[ 0 ];
		var last = visible[ visible.length - 1 ] || runs[ runs.length - 1 ];
		var segs = buildSegs( runs );
		var chars = 0;
		var bold = 0;
		segs.forEach( function ( sg ) {
			var n = sg.text.replace( /\s/g, '' ).length;
			chars += n;
			if ( sg.bold ) {
				bold += n;
			}
		} );
		return {
			x: first.x,
			right: last.x + last.w,
			segs: segs,
			text: segs.map( function ( sg ) {
				return sg.text;
			} ).join( '' ).replace( /\s+/g, ' ' ).trim(),
			bold: chars > 0 && bold / chars > 0.9,
		};
	}

	/** Groups runs into visual lines (same baseline), left → right. */
	function toLines( runs ) {
		runs.sort( function ( a, b ) {
			return a.y - b.y || a.x - b.x;
		} );
		var lines = [];
		runs.forEach( function ( r ) {
			var line = lines[ lines.length - 1 ];
			if ( line && Math.abs( r.y - line.y ) < Math.max( r.size, line.size ) * 0.45 ) {
				line.runs.push( r );
				line.size = Math.max( line.size, r.size );
			} else {
				lines.push( { y: r.y, size: r.size, runs: [ r ] } );
			}
		} );
		lines.forEach( function ( line ) {
			line.runs.sort( function ( a, b ) {
				return a.x - b.x;
			} );
			line.segs = buildSegs( line.runs );
			var segs = line.segs;

			// Cells: pieces of the line separated by a wide gap — used to
			// recognise tables (columns lining up over several lines).
			line.cells = [];
			var cellRuns = [];
			var cellEnd = null;
			line.runs.forEach( function ( r ) {
				var blank = ! r.str.trim();
				if ( ! blank && cellEnd !== null && r.x - cellEnd > Math.max( r.size * 1.1, 6 ) ) {
					line.cells.push( makeCell( cellRuns ) );
					cellRuns = [];
				}
				cellRuns.push( r );
				if ( ! blank ) {
					cellEnd = r.x + r.w;
				}
			} );
			if ( cellRuns.length ) {
				line.cells.push( makeCell( cellRuns ) );
			}
			line.cells = line.cells.filter( function ( c ) {
				return c.text.length;
			} );
			line.text = segs.map( function ( s ) {
				return s.text;
			} ).join( '' ).replace( /\s+/g, ' ' ).trim();
			line.x = line.runs[ 0 ].x;
			var lastRun = line.runs[ line.runs.length - 1 ];
			line.right = lastRun.x + lastRun.w;
			var boldChars = 0;
			var allChars = 0;
			segs.forEach( function ( s ) {
				var len = s.text.replace( /\s/g, '' ).length;
				allChars += len;
				if ( s.bold ) {
					boldChars += len;
				}
			} );
			line.allBold = allChars > 0 && boldChars / allChars > 0.9;
			// Size used for classification: the size carrying most of the
			// characters (a big bullet glyph must not turn an item into a heading).
			var bySize = {};
			line.runs.forEach( function ( r ) {
				var k = Math.round( r.size * 2 ) / 2;
				bySize[ k ] = ( bySize[ k ] || 0 ) + r.str.replace( /\s/g, '' ).length;
			} );
			var bestCount = -1;
			Object.keys( bySize ).forEach( function ( k ) {
				if ( bySize[ k ] > bestCount ) {
					bestCount = bySize[ k ];
					line.textSize = parseFloat( k );
				}
			} );
		} );
		return lines.filter( function ( l ) {
			return l.text.length;
		} );
	}

	function segsToHtml( segs, stripRe ) {
		var html = '';
		var first = true;
		segs.forEach( function ( s ) {
			var text = s.text;
			if ( first && stripRe ) {
				text = text.replace( /^\s+/, '' ).replace( stripRe, '' );
			}
			first = false;
			if ( ! text ) {
				return;
			}
			html += s.bold ? '<strong>' + esc( text ) + '</strong>' : esc( text );
		} );
		return html
			.replace( /\s+/g, ' ' )
			.replace( /<\/strong>(\s*)<strong>/g, '$1' )
			.replace( /<strong>(\s+)/g, '$1<strong>' )
			.replace( /(\s+)<\/strong>/g, '</strong>$1' )
			.replace( /<strong><\/strong>/g, '' )
			.trim();
	}

	var BULLET = /^[•●▪■◦○·\u2023\u2043*–—-]\s+/;
	var NUMBERED = /^(\d{1,3}|[a-zA-Z])[.)]\s+/;
	var PAGE_NUMBER = /^(\d{1,4}|strona\s+\d+(\s+z\s+\d+)?|page\s+\d+(\s+of\s+\d+)?|\d+\s*\/\s*\d+|-\s*\d+\s*-)$/i;

	/**
	 * Converts extracted pages into editor HTML.
	 *
	 * @return {{html: string, title: string, chars: number}}
	 */
	function pagesToHtml( pages, wantTitle ) {
		var sizes = {};
		var chars = 0;
		pages.forEach( function ( p ) {
			p.runs.forEach( function ( r ) {
				var k = Math.round( r.size * 2 ) / 2;
				var len = r.str.replace( /\s/g, '' ).length;
				sizes[ k ] = ( sizes[ k ] || 0 ) + len;
				chars += len;
			} );
		} );
		var body = 11;
		var best = -1;
		Object.keys( sizes ).forEach( function ( k ) {
			if ( sizes[ k ] > best ) {
				best = sizes[ k ];
				body = parseFloat( k );
			}
		} );

		var title = '';
		var out = [];
		var HEADING_RATIO = 1.18;

		var pageLines = pages.map( function ( p ) {
			return toLines( p.runs ).filter( function ( l ) {
				return ! PAGE_NUMBER.test( l.text );
			} );
		} );

		if ( wantTitle && pageLines.length && pageLines[ 0 ].length ) {
			var top = pageLines[ 0 ].reduce( function ( a, b ) {
				return b.textSize > a.textSize ? b : a;
			}, pageLines[ 0 ][ 0 ] );
			if ( top.textSize >= body * 1.3 && top.text.length < 150 ) {
				title = top.text;
				pageLines[ 0 ] = pageLines[ 0 ].filter( function ( l ) {
					return l !== top;
				} );
			}
		}

		// Heading levels: the largest heading size in the document becomes
		// <h2> (the title is the <h1>), every smaller heading size <h3>.
		var headingSizes = [];
		pageLines.forEach( function ( lines ) {
			lines.forEach( function ( l ) {
				if ( l.textSize / body >= HEADING_RATIO && l.text.length < 200 && headingSizes.indexOf( l.textSize ) === -1 ) {
					headingSizes.push( l.textSize );
				}
			} );
		} );
		var h2Size = headingSizes.length ? Math.max.apply( null, headingSizes ) : Infinity;

		// Typical distance between wrapped lines, per font size: the lower
		// quartile of baseline gaps. Anything clearly bigger is a new block.
		var gapsBySize = {};
		var xCount = {};
		pageLines.forEach( function ( lines ) {
			lines.forEach( function ( l, i ) {
				var kx = Math.round( l.x );
				xCount[ kx ] = ( xCount[ kx ] || 0 ) + 1;
				var prev = lines[ i - 1 ];
				if ( prev && prev.textSize === l.textSize && l.y > prev.y ) {
					( gapsBySize[ l.textSize ] = gapsBySize[ l.textSize ] || [] ).push( l.y - prev.y );
				}
			} );
		} );
		var lineGap = function ( size ) {
			var g = gapsBySize[ size ];
			if ( ! g || g.length < 3 ) {
				return size * 1.45;
			}
			g = g.slice().sort( function ( a, b ) {
				return a - b;
			} );
			return Math.max( size * 1.05, g[ Math.floor( g.length * 0.25 ) ] );
		};
		// Left margin = the most common line start.
		var margin = 0;
		var bestX = -1;
		Object.keys( xCount ).forEach( function ( k ) {
			if ( xCount[ k ] > bestX ) {
				bestX = xCount[ k ];
				margin = parseFloat( k );
			}
		} );

		var COL_TOL = 10;
		var colIndex = function ( cols, x ) {
			var idx = 0;
			cols.forEach( function ( c, j ) {
				if ( c <= x + COL_TOL ) {
					idx = j;
				}
			} );
			return idx;
		};
		var matchesCol = function ( cols, x ) {
			return cols.some( function ( c ) {
				return Math.abs( c - x ) <= COL_TOL;
			} );
		};

		function buildTable( rows, cols ) {
			cols.sort( function ( a, b ) {
				return a - b;
			} );
			var html = '<table class="gfxdoc-table">';
			rows.forEach( function ( rowLines, r ) {
				var cells = cols.map( function () {
					return [];
				} );
				var allBold = true;
				rowLines.forEach( function ( line ) {
					line.cells.forEach( function ( c ) {
						cells[ colIndex( cols, c.x ) ].push( segsToHtml( c.segs ) );
						allBold = allBold && c.bold;
					} );
				} );
				var tag = r === 0 && allBold ? 'th' : 'td';
				html += '<tr>' + cells.map( function ( parts ) {
					var inner = parts.join( ' ' );
					if ( tag === 'th' ) {
						inner = inner.replace( /<\/?strong>/g, '' );
					}
					return '<' + tag + '>' + inner + '</' + tag + '>';
				} ).join( '' ) + '</tr>';
			} );
			return html + '</table>';
		}

		/**
		 * Finds runs of lines whose cells line up in columns (≥ 2 rows with
		 * ≥ 2 cells) and replaces them with a single { table: html } item.
		 * In tables with cell padding, rows are further apart than lines, so a
		 * line at normal line spacing is wrapped text of the current row; in
		 * tables without padding only a line with an empty first column is.
		 */
		// A list marker followed by a wide gap ("•      text") is a list, not a table.
		var MARKER_CELL = /^([•●▪■◦○·\u2023\u2043*–—-]|\d{1,3}[.)]|[a-zA-Z][.)])$/;
		function isMarker( line ) {
			return line.cells.length && MARKER_CELL.test( line.cells[ 0 ].text );
		}

		function detectTables( lines ) {
			var out = [];
			var i = 0;
			while ( i < lines.length ) {
				var L = lines[ i ];
				if ( L.cells.length >= 2 && L.textSize / body < HEADING_RATIO && ! isMarker( L ) ) {
					var cols = L.cells.map( function ( c ) {
						return c.x;
					} );
					// 1) collect the lines that line up with the columns
					var seg = [ L ];
					var gaps = [ 0 ];
					var k = i + 1;
					while ( k < lines.length ) {
						var M = lines[ k ];
						var gap = M.y - seg[ seg.length - 1 ].y;
						if ( gap < 0 || gap > lineGap( M.textSize ) * 3.2 || M.textSize / body >= HEADING_RATIO || isMarker( M ) ) {
							break;
						}
						if ( M.cells.length === 1 ) {
							// A lone piece of text that runs into the next column is
							// a normal paragraph, not a table cell.
							var sorted = cols.slice().sort( function ( x, y ) {
								return x - y;
							} );
							var next = sorted[ colIndex( sorted, M.cells[ 0 ].x ) + 1 ];
							if ( next !== undefined && M.cells[ 0 ].right > next - 2 ) {
								break;
							}
						}
						var aligned = M.cells.filter( function ( c ) {
							return matchesCol( cols, c.x );
						} ).length;
						if ( aligned === M.cells.length || ( M.cells.length >= 2 && aligned >= 2 && aligned >= M.cells.length / 2 ) ) {
							M.cells.forEach( function ( c ) {
								if ( ! matchesCol( cols, c.x ) ) {
									cols.push( c.x );
								}
							} );
							seg.push( M );
							gaps.push( gap );
							k++;
						} else {
							break;
						}
					}
					// 2) padded table (rows further apart than lines)?
					var padded = gaps.some( function ( g, j ) {
						return j > 0 && g > lineGap( seg[ j ].textSize ) * 1.15;
					} );
					var firstCol = Math.min.apply( null, cols );
					// 3) group lines into rows
					var rows = [];
					seg.forEach( function ( M, j ) {
						var inFirst = M.cells.some( function ( c ) {
							return Math.abs( c.x - firstCol ) <= COL_TOL;
						} );
						var newRow = j === 0 || ( padded ? gaps[ j ] > lineGap( M.textSize ) * 1.15 : inFirst );
						if ( newRow ) {
							rows.push( [ M ] );
						} else {
							rows[ rows.length - 1 ].push( M );
						}
					} );
					// Drop trailing single-cell rows (a paragraph right under the table).
					while ( rows.length && rows[ rows.length - 1 ].length === 1 && rows[ rows.length - 1 ][ 0 ].cells.length < 2 ) {
						rows.pop();
						k--;
					}
					var multi = rows.filter( function ( r ) {
						return r.some( function ( l ) {
							return l.cells.length >= 2;
						} );
					} ).length;
					if ( multi >= 2 ) {
						out.push( { table: buildTable( rows, cols ) } );
						i = k;
						continue;
					}
				}
				out.push( L );
				i++;
			}
			return out;
		}

		pages.forEach( function ( p, pageIndex ) {
			var maxRight = 0;
			pageLines[ pageIndex ].forEach( function ( l ) {
				maxRight = Math.max( maxRight, l.right );
			} );
			var lines = detectTables( pageLines[ pageIndex ] );

			// block: { tag, parts[], lastLine, listTag, contX, guessed }
			var block = null;
			var flush = function () {
				if ( ! block ) {
					return;
				}
				var inner = block.parts.join( ' ' );
				if ( block.tag === 'li' ) {
					var prev = out[ out.length - 1 ];
					if ( prev && prev.list === block.listTag ) {
						prev.items.push( inner );
					} else {
						out.push( { list: block.listTag, items: [ inner ] } );
					}
				} else {
					out.push( { html: '<' + block.tag + '>' + inner + '</' + block.tag + '>' } );
				}
				block = null;
			};

			lines.forEach( function ( line ) {
				if ( line.table ) {
					flush();
					out.push( { html: line.table } );
					return;
				}
				var ratio = line.textSize / body;
				var tag = 'p';
				var strip = null;
				var listTag = null;
				var guessed = false;

				if ( ratio >= HEADING_RATIO && line.text.length < 200 ) {
					tag = line.textSize >= h2Size - 0.25 ? 'h2' : 'h3';
				} else if ( BULLET.test( line.text ) ) {
					tag = 'li';
					listTag = 'ul';
					strip = BULLET;
				} else if ( NUMBERED.test( line.text ) && line.text.length > 3 && ! line.allBold ) {
					tag = 'li';
					listTag = 'ol';
					strip = NUMBERED;
				} else if ( line.allBold && line.text.length < 120 && ! /[.,;]$/.test( line.text ) ) {
					tag = 'h4';
				} else if ( line.x > margin + line.textSize * 1.2 ) {
					// Indented text with no visible bullet: most PDF generators
					// draw bullets as graphics, so this is probably a list item.
					tag = 'li';
					listTag = 'ul';
					guessed = true;
				}

				var html = segsToHtml( line.segs, strip );
				if ( tag === 'h2' || tag === 'h3' || tag === 'h4' ) {
					html = html.replace( /<\/?strong>/g, '' );
				}

				var continues = false;
				if ( block ) {
					var gap = line.y - block.lastLine.y;
					var bigGap = gap < 0 || gap > lineGap( line.textSize ) * 1.12;
					var prevShort = block.lastLine.right < maxRight * 0.72 && /[.:!?;]$/.test( block.lastLine.text );

					if ( block.tag === 'p' && tag === 'p' ) {
						continues = ! bigGap && ! prevShort;
					} else if ( block.tag === 'li' && ( tag === 'p' || ( tag === 'li' && guessed ) ) ) {
						continues = ! bigGap && line.x >= block.contX - 2;
						if ( ! continues && block.guessed && ! bigGap && tag === 'p' && Math.abs( line.x - margin ) < 3 ) {
							// It was only a first-line indent — a normal paragraph.
							block.tag = 'p';
							continues = true;
						}
					} else if ( block.tag === tag && ( tag === 'h2' || tag === 'h3' ) ) {
						continues = ! bigGap; // heading wrapped onto two lines
					}
				}

				if ( continues ) {
					var lastPart = block.parts[ block.parts.length - 1 ];
					if ( /[A-Za-z\u00C0-\u017F]-$/.test( lastPart ) && /^[a-z\u00DF-\u017F]/.test( html ) ) {
						// word hyphenated at the end of the line: "przetwa-" + "rzanie"
						block.parts[ block.parts.length - 1 ] = lastPart.slice( 0, -1 ) + html;
					} else {
						block.parts.push( html );
					}
					block.lastLine = line;
				} else {
					flush();
					block = {
						tag: tag,
						parts: [ html ],
						lastLine: line,
						listTag: listTag,
						guessed: guessed,
						contX: guessed ? line.x : line.x + line.textSize * 0.8,
					};
				}
			} );
			flush();

			if ( pageIndex < pages.length - 1 ) {
				out.push( { html: '<p>[gfxpagebreak]</p>' } );
			}
		} );

		var html = out.map( function ( b ) {
			if ( b.list ) {
				return '<' + b.list + '>' + b.items.map( function ( i ) {
					return '<li>' + i + '</li>';
				} ).join( '' ) + '</' + b.list + '>';
			}
			return b.html;
		} ).join( '\n' );

		// Don't finish on a dangling page break (e.g. empty last page).
		html = html.replace( /(\n?<p>\[gfxpagebreak\]<\/p>)+\s*$/, '' );

		return { html: html, title: title, chars: chars };
	}

	// Exposed for testing / reuse.
	window.gfxdocPdfToHtml = { readPdfRuns: readPdfRuns, pagesToHtml: pagesToHtml };

	function getEditor() {
		return window.tinymce && tinymce.get( 'content' ) && ! tinymce.get( 'content' ).isHidden() ? tinymce.get( 'content' ) : null;
	}

	function currentContent() {
		var ed = getEditor();
		return ed ? ed.getContent() : $( '#content' ).val() || '';
	}

	function setContent( html ) {
		var ed = getEditor();
		if ( ed ) {
			ed.setContent( html );
			ed.undoManager.add();
			ed.fire( 'change' );
			ed.save();
		} else {
			$( '#content' ).val( html ).trigger( 'change' );
		}
	}

	$box.on( 'click', '.gfxdoc-pdf-import', async function () {
		var $btn = $( this );
		var label = $btn.html();
		$btn.prop( 'disabled', true ).text( t.importing );
		try {
			var $title = $( '#title' );
			var wantTitle = $title.length && ! $.trim( $title.val() );
			var pages = await readPdfRuns( $btn.data( 'url' ) );
			var res = pagesToHtml( pages, wantTitle );
			if ( res.chars < 5 || ! res.html ) {
				window.alert( t.noText );
				return;
			}
			var existing = $.trim( currentContent().replace( /<[^>]*>|&nbsp;/g, '' ) );
			if ( existing && ! window.confirm( t.importChoice ) ) {
				setContent( currentContent() + '\n' + res.html );
			} else {
				setContent( res.html );
			}
			if ( res.title && wantTitle ) {
				$title.val( res.title ).trigger( 'input' );
				$( '#title-prompt-text' ).addClass( 'screen-reader-text' );
			}
			window.alert( t.imported );
		} catch ( e ) {
			window.console && console.error( e ); // eslint-disable-line no-console
			window.alert( t.error + ( e && e.message ? '\n\n' + e.message : '' ) );
		} finally {
			$btn.prop( 'disabled', false ).html( label );
		}
	} );
} )( jQuery );
