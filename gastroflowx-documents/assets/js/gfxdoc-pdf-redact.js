/**
 * True redaction for the PDF editor: removes the glyphs that lie under
 * "cover" rectangles from the page content (and from Form XObjects the
 * page draws), removes annotations under them, and drops every object the
 * file no longer references — so the covered text is really gone from the
 * file, not just painted over.
 *
 * Method: the content stream is tokenised and interpreted (graphics state,
 * CTM, text state, font widths), each glyph's position is computed, and
 * show-text operators containing covered glyphs are rewritten as TJ arrays
 * in which the removed glyphs are replaced by an equal horizontal offset, so
 * the text that stays keeps its exact position.
 *
 * Fonts whose metrics can't be determined reliably are left untouched and
 * reported; the editor then checks the result with pdf.js (findLeftovers)
 * and, if any covered character survived, rasterises that page as a last
 * resort. Pure pdf-lib, no DOM — testable in Node.
 *
 * @package GastroFlowx_Documents
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.GFXDocRedact = factory();
	}
} )( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	// Standard-14 font widths (WinAnsi codes 32–255), from the AFM files
	// bundled with dompdf. Courier* is monospaced (600).
	var STD14 = {"Helvetica":[278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,0,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500],"Helvetica-Bold":[278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,0,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556],"Helvetica-Oblique":[278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,0,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500],"Helvetica-BoldOblique":[278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,0,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556],"Times-Roman":[250,333,408,500,500,833,778,180,333,333,500,564,250,333,250,278,500,500,500,500,500,500,500,500,500,500,278,278,564,564,564,444,921,722,667,667,722,611,556,722,722,333,389,722,611,889,722,722,556,722,667,556,611,722,722,944,722,722,611,333,278,333,469,500,333,444,500,444,500,444,333,500,500,278,278,500,278,778,500,500,500,500,333,389,278,500,500,722,500,500,444,480,200,480,541,0,500,0,333,500,444,1000,500,500,333,1000,556,333,889,0,611,0,0,333,333,444,444,350,500,1000,333,980,389,333,722,0,444,722,250,333,500,500,500,500,200,500,333,760,276,500,564,333,760,333,400,564,300,300,333,500,453,250,333,300,310,500,750,750,750,444,722,722,722,722,722,722,889,667,611,611,611,611,333,333,333,333,722,722,722,722,722,722,722,564,722,722,722,722,722,722,556,500,444,444,444,444,444,444,667,444,444,444,444,444,278,278,278,278,500,500,500,500,500,500,500,564,500,500,500,500,500,500,500,500],"Times-Bold":[250,333,555,500,500,1000,833,278,333,333,500,570,250,333,250,278,500,500,500,500,500,500,500,500,500,500,333,333,570,570,570,500,930,722,667,722,722,667,611,778,778,389,500,778,667,944,722,778,611,778,722,556,667,722,722,1000,722,722,667,333,278,333,581,500,333,500,556,444,556,444,333,500,556,278,333,556,278,833,556,500,556,556,444,389,333,556,500,722,500,500,444,394,220,394,520,0,500,0,333,500,500,1000,500,500,333,1000,556,333,1000,0,667,0,0,333,333,500,500,350,500,1000,333,1000,389,333,722,0,444,722,250,333,500,500,500,500,220,500,333,747,300,500,570,333,747,333,400,570,300,300,333,556,540,250,333,300,330,500,750,750,750,500,722,722,722,722,722,722,1000,722,667,667,667,667,389,389,389,389,722,722,778,778,778,778,778,570,778,722,722,722,722,722,611,556,500,500,500,500,500,500,722,444,444,444,444,444,278,278,278,278,500,556,500,500,500,500,500,570,500,556,556,556,556,500,556,500],"Times-Italic":[250,333,420,500,500,833,778,214,333,333,500,675,250,333,250,278,500,500,500,500,500,500,500,500,500,500,333,333,675,675,675,500,920,611,611,667,722,611,611,722,722,333,444,667,556,833,667,722,611,722,611,500,556,722,611,833,611,556,556,389,278,389,422,500,333,500,500,444,500,444,278,500,500,278,278,444,278,722,500,500,500,500,389,389,278,500,444,667,444,444,389,400,275,400,541,0,500,0,333,500,556,889,500,500,333,1000,500,333,944,0,556,0,0,333,333,556,556,350,500,889,333,980,389,333,667,0,389,556,250,389,500,500,500,500,275,500,333,760,276,500,675,333,760,333,400,675,300,300,333,500,523,250,333,300,310,500,750,750,750,500,611,611,611,611,611,611,889,667,611,611,611,611,333,333,333,333,722,667,722,722,722,722,722,675,722,722,722,722,722,556,611,500,500,500,500,500,500,500,667,444,444,444,444,444,278,278,278,278,500,500,500,500,500,500,500,675,500,500,500,500,500,444,500,444],"Times-BoldItalic":[250,389,555,500,500,833,778,278,333,333,500,570,250,333,250,278,500,500,500,500,500,500,500,500,500,500,333,333,570,570,570,500,832,667,667,667,722,667,667,722,778,389,500,667,611,889,722,722,611,722,667,556,611,722,667,889,667,611,611,333,278,333,570,500,333,500,500,444,500,444,333,500,556,278,278,500,278,778,556,500,500,500,389,389,278,556,444,667,500,444,389,348,220,348,570,0,500,0,333,500,500,1000,500,500,333,1000,556,333,944,0,611,0,0,333,333,500,500,350,500,1000,333,1000,389,333,722,0,389,611,250,389,500,500,500,500,220,500,333,747,266,500,606,333,747,333,400,570,300,300,333,576,500,250,333,300,300,500,750,750,750,500,667,667,667,667,667,667,944,667,667,667,667,667,389,389,389,389,722,722,722,722,722,722,722,570,722,722,722,722,722,611,611,500,500,500,500,500,500,500,722,444,444,444,444,444,278,278,278,278,500,556,500,500,500,500,500,570,500,556,556,556,556,444,500,444],"Symbol":[250,333,713,500,549,833,778,439,333,333,500,549,250,549,250,278,500,500,500,500,500,500,500,500,500,500,278,278,549,549,549,444,549,722,667,722,612,611,763,603,722,333,631,722,686,889,722,722,768,741,556,592,611,690,439,768,645,795,611,333,863,333,658,500,500,631,549,549,494,439,521,411,603,329,603,549,549,576,521,549,549,521,549,603,439,576,713,686,493,686,494,480,200,480,549,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,750,620,247,549,167,713,500,753,753,753,753,1042,987,603,987,603,400,549,411,549,549,713,494,460,549,549,549,549,1000,603,1000,658,823,686,795,987,768,768,823,768,768,713,713,713,713,713,713,713,768,713,790,790,890,823,549,250,713,603,603,1042,987,603,987,603,494,329,790,790,786,713,384,384,384,384,384,384,494,494,494,494,0,329,274,686,686,686,384,384,384,384,384,384,494,494,494,0],"ZapfDingbats":[278,974,961,974,980,719,789,790,791,690,960,939,549,855,911,933,911,945,974,755,846,762,761,571,677,763,760,759,754,494,552,537,577,692,786,788,788,790,793,794,816,823,789,841,823,833,816,831,923,744,723,749,790,792,695,776,768,792,759,707,708,682,701,826,815,789,789,707,687,696,689,786,787,713,791,785,791,873,761,762,762,759,759,892,892,788,784,438,138,277,415,392,392,668,668,0,390,390,317,317,276,276,509,509,410,410,234,234,334,334,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,732,544,544,910,667,760,760,776,595,694,626,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,788,894,838,1016,458,748,924,748,918,927,928,928,834,873,828,924,924,917,930,931,463,883,836,836,867,867,696,696,874,0,874,760,946,771,865,771,888,967,888,831,873,927,970,918,0],"Courier":[600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,0,600,0,600,600,600,600,600,600,600,600,600,600,600,0,600,0,0,600,600,600,600,600,600,600,600,600,600,600,600,0,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600]};
	var STD14_ALIASES = {
		Arial: 'Helvetica', 'Arial-Bold': 'Helvetica-Bold', 'Arial,Bold': 'Helvetica-Bold',
		'Arial-Italic': 'Helvetica-Oblique', 'Arial,Italic': 'Helvetica-Oblique',
		'Arial-BoldItalic': 'Helvetica-BoldOblique', 'Arial,BoldItalic': 'Helvetica-BoldOblique',
		TimesNewRoman: 'Times-Roman', 'TimesNewRoman,Bold': 'Times-Bold',
		'TimesNewRoman,Italic': 'Times-Italic', 'TimesNewRoman,BoldItalic': 'Times-BoldItalic',
	};

	/* ---------------------------------------------------------------
	 * Matrices (PDF row-vector convention: p' = p × M)
	 * ------------------------------------------------------------- */

	function mul( a, b ) {
		return [
			a[ 0 ] * b[ 0 ] + a[ 1 ] * b[ 2 ],
			a[ 0 ] * b[ 1 ] + a[ 1 ] * b[ 3 ],
			a[ 2 ] * b[ 0 ] + a[ 3 ] * b[ 2 ],
			a[ 2 ] * b[ 1 ] + a[ 3 ] * b[ 3 ],
			a[ 4 ] * b[ 0 ] + a[ 5 ] * b[ 2 ] + b[ 4 ],
			a[ 4 ] * b[ 1 ] + a[ 5 ] * b[ 3 ] + b[ 5 ],
		];
	}
	function apply( m, x, y ) {
		return [ m[ 0 ] * x + m[ 2 ] * y + m[ 4 ], m[ 1 ] * x + m[ 3 ] * y + m[ 5 ] ];
	}
	var ID = [ 1, 0, 0, 1, 0, 0 ];

	function inRects( rects, x, y ) {
		for ( var i = 0; i < rects.length; i++ ) {
			var r = rects[ i ];
			if ( x >= r[ 0 ] && x <= r[ 2 ] && y >= r[ 1 ] && y <= r[ 3 ] ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------
	 * Content stream tokenizer (works on a latin1 "byte string")
	 * ------------------------------------------------------------- */

	function bytesToStr( u8 ) {
		var out = '';
		for ( var i = 0; i < u8.length; i += 8192 ) {
			out += String.fromCharCode.apply( null, u8.subarray( i, i + 8192 ) );
		}
		return out;
	}
	function strToBytes( s ) {
		var u8 = new Uint8Array( s.length );
		for ( var i = 0; i < s.length; i++ ) {
			u8[ i ] = s.charCodeAt( i ) & 0xff;
		}
		return u8;
	}

	var WS = { 0: 1, 9: 1, 10: 1, 12: 1, 13: 1, 32: 1 };
	var DELIM = { 40: 1, 41: 1, 60: 1, 62: 1, 91: 1, 93: 1, 123: 1, 125: 1, 47: 1, 37: 1 };
	var NUM = /[+-]?(\d+\.?\d*|\.\d+)/y;

	function tokenize( s ) {
		var ops = [];
		var pos = 0;
		var n = s.length;
		var stack = [];
		var stackStart = -1;
		var containers = []; // { kind: '[' | '<<', items: [] }

		function pushValue( v, start ) {
			if ( containers.length ) {
				containers[ containers.length - 1 ].items.push( v );
			} else {
				if ( ! stack.length ) {
					stackStart = start;
				}
				stack.push( v );
			}
		}

		while ( pos < n ) {
			var c = s.charCodeAt( pos );
			if ( WS[ c ] ) {
				pos++;
				continue;
			}
			if ( c === 37 ) { // % comment
				while ( pos < n && s.charCodeAt( pos ) !== 10 && s.charCodeAt( pos ) !== 13 ) {
					pos++;
				}
				continue;
			}
			var start = pos;

			if ( c === 40 ) { // ( literal string
				var depth = 1;
				var out = '';
				pos++;
				while ( pos < n && depth > 0 ) {
					var ch = s[ pos ];
					if ( ch === '\\' ) {
						var nx = s[ pos + 1 ];
						pos += 2;
						if ( nx === 'n' ) {
							out += '\n';
						} else if ( nx === 'r' ) {
							out += '\r';
						} else if ( nx === 't' ) {
							out += '\t';
						} else if ( nx === 'b' ) {
							out += '\b';
						} else if ( nx === 'f' ) {
							out += '\f';
						} else if ( nx >= '0' && nx <= '7' ) {
							var oct = nx;
							while ( oct.length < 3 && s[ pos ] >= '0' && s[ pos ] <= '7' ) {
								oct += s[ pos++ ];
							}
							out += String.fromCharCode( parseInt( oct, 8 ) & 0xff );
						} else if ( nx === '\r' ) {
							if ( s[ pos ] === '\n' ) {
								pos++;
							}
						} else if ( nx === '\n' ) {
							// line continuation
						} else if ( nx !== undefined ) {
							out += nx;
						}
						continue;
					}
					if ( ch === '(' ) {
						depth++;
					} else if ( ch === ')' ) {
						depth--;
						if ( ! depth ) {
							pos++;
							break;
						}
					}
					out += ch;
					pos++;
				}
				pushValue( { t: 's', v: out }, start );
				continue;
			}
			if ( c === 60 ) { // <
				if ( s.charCodeAt( pos + 1 ) === 60 ) {
					if ( ! containers.length && ! stack.length ) {
						stackStart = start;
					}
					containers.push( { kind: '<<', items: [], start: start } );
					pos += 2;
					continue;
				}
				var end = s.indexOf( '>', pos );
				if ( end === -1 ) {
					end = n;
				}
				var hex = s.slice( pos + 1, end ).replace( /[^0-9a-fA-F]/g, '' );
				if ( hex.length % 2 ) {
					hex += '0';
				}
				var hs = '';
				for ( var h = 0; h < hex.length; h += 2 ) {
					hs += String.fromCharCode( parseInt( hex.substr( h, 2 ), 16 ) );
				}
				pos = end + 1;
				pushValue( { t: 's', v: hs }, start );
				continue;
			}
			if ( c === 62 && s.charCodeAt( pos + 1 ) === 62 ) { // >>
				pos += 2;
				var d = containers.pop();
				var obj = {};
				if ( d ) {
					for ( var k = 0; k + 1 < d.items.length; k += 2 ) {
						obj[ d.items[ k ] && d.items[ k ].n ] = d.items[ k + 1 ];
					}
					pushValue( { t: 'd', v: obj }, d.start );
				}
				continue;
			}
			if ( c === 91 ) { // [
				if ( ! containers.length && ! stack.length ) {
					stackStart = start;
				}
				containers.push( { kind: '[', items: [], start: start } );
				pos++;
				continue;
			}
			if ( c === 93 ) { // ]
				pos++;
				var a = containers.pop();
				if ( a ) {
					pushValue( { t: 'a', v: a.items }, a.start );
				}
				continue;
			}
			if ( c === 47 ) { // /Name
				pos++;
				var nm = '';
				while ( pos < n && ! WS[ s.charCodeAt( pos ) ] && ! DELIM[ s.charCodeAt( pos ) ] ) {
					nm += s[ pos++ ];
				}
				nm = nm.replace( /#([0-9a-fA-F]{2})/g, function ( m, x ) {
					return String.fromCharCode( parseInt( x, 16 ) );
				} );
				pushValue( { n: nm }, start );
				continue;
			}
			if ( ( c >= 48 && c <= 57 ) || c === 43 || c === 45 || c === 46 ) {
				NUM.lastIndex = pos;
				var m = NUM.exec( s );
				if ( m ) {
					pos += m[ 0 ].length;
					pushValue( parseFloat( m[ 0 ] ), start );
					continue;
				}
			}
			if ( DELIM[ c ] ) { // stray delimiter ({, }, ), >)
				pos++;
				continue;
			}
			// keyword
			var kw = '';
			while ( pos < n && ! WS[ s.charCodeAt( pos ) ] && ! DELIM[ s.charCodeAt( pos ) ] ) {
				kw += s[ pos++ ];
			}
			if ( kw === 'true' || kw === 'false' ) {
				pushValue( kw === 'true', start );
				continue;
			}
			if ( kw === 'null' ) {
				pushValue( null, start );
				continue;
			}
			if ( kw === 'BI' ) { // inline image: skip its binary data
				var idm = /\sID[\s]/g;
				idm.lastIndex = pos;
				var idx = idm.exec( s );
				var dataStart = idx ? idx.index + idx[ 0 ].length : n;
				var eim = /\sEI(?=[\s]|$)/g;
				eim.lastIndex = dataStart;
				var ei = eim.exec( s );
				pos = ei ? ei.index + ei[ 0 ].length : n;
				ops.push( { op: 'BI', args: [], start: start, end: pos } );
				stack = [];
				stackStart = -1;
				continue;
			}
			ops.push( { op: kw, args: stack, start: stackStart >= 0 ? stackStart : start, end: pos } );
			stack = [];
			stackStart = -1;
			containers = [];
		}
		return ops;
	}

	/* ---------------------------------------------------------------
	 * Fonts
	 * ------------------------------------------------------------- */

	function num( PDFLib, o, def ) {
		return o instanceof PDFLib.PDFNumber ? o.asNumber() : def;
	}
	function nameOf( PDFLib, o ) {
		return o instanceof PDFLib.PDFName ? o.decodeText ? o.decodeText() : o.asString().replace( /^\//, '' ) : '';
	}
	function arr( PDFLib, dict, key ) {
		var v = dict.lookup( PDFLib.PDFName.of( key ) );
		return v instanceof PDFLib.PDFArray ? v : null;
	}

	/**
	 * @return {{bytes:number, width:function(number):number, certain:boolean}}
	 *         width() is in text space units for font size 1.
	 */
	function fontInfo( PDFLib, font ) {
		var info = { bytes: 1, certain: false, width: function () {
			return 0.5;
		} };
		if ( ! ( font instanceof PDFLib.PDFDict ) ) {
			return info;
		}
		var N = PDFLib.PDFName;
		var subtype = nameOf( PDFLib, font.lookup( N.of( 'Subtype' ) ) );

		if ( subtype === 'Type0' ) {
			info.bytes = 2;
			var enc = font.lookup( N.of( 'Encoding' ) );
			info.certain = nameOf( PDFLib, enc ) === 'Identity-H';
			var desc = arr( PDFLib, font, 'DescendantFonts' );
			var cid = desc ? desc.lookup( 0 ) : null;
			var dw = 1000;
			var map = {};
			if ( cid instanceof PDFLib.PDFDict ) {
				dw = num( PDFLib, cid.lookup( N.of( 'DW' ) ), 1000 );
				var W = arr( PDFLib, cid, 'W' );
				if ( W ) {
					var items = W.asArray().map( function ( x ) {
						return W.context.lookup( x );
					} );
					for ( var i = 0; i < items.length; ) {
						var first = num( PDFLib, items[ i ], 0 );
						var next = items[ i + 1 ];
						if ( next instanceof PDFLib.PDFArray ) {
							next.asArray().forEach( function ( w, j ) {
								map[ first + j ] = num( PDFLib, W.context.lookup( w ), dw );
							} );
							i += 2;
						} else {
							var last = num( PDFLib, next, first );
							var w = num( PDFLib, items[ i + 2 ], dw );
							for ( var c = first; c <= last && c - first < 65536; c++ ) {
								map[ c ] = w;
							}
							i += 3;
						}
					}
				}
			} else {
				info.certain = false;
			}
			info.width = function ( code ) {
				return ( code in map ? map[ code ] : dw ) / 1000;
			};
			return info;
		}

		var scale = 0.001;
		if ( subtype === 'Type3' ) {
			var fm = arr( PDFLib, font, 'FontMatrix' );
			scale = fm ? num( PDFLib, fm.lookup( 0 ), 0.001 ) : 0.001;
		}
		var widths = arr( PDFLib, font, 'Widths' );
		var firstChar = num( PDFLib, font.lookup( N.of( 'FirstChar' ) ), 0 );
		var fd = font.lookup( N.of( 'FontDescriptor' ) );
		var missing = fd instanceof PDFLib.PDFDict ? num( PDFLib, fd.lookup( N.of( 'MissingWidth' ) ), 0 ) : 0;

		if ( widths ) {
			var list = widths.asArray().map( function ( x ) {
				return num( PDFLib, widths.context.lookup( x ), missing );
			} );
			info.certain = true;
			info.width = function ( code ) {
				var w = list[ code - firstChar ];
				return ( w === undefined ? missing : w ) * scale;
			};
			return info;
		}

		// No /Widths: only the standard 14 fonts have known metrics.
		var base = nameOf( PDFLib, font.lookup( N.of( 'BaseFont' ) ) ).replace( /^[A-Z]{6}\+/, '' );
		base = STD14_ALIASES[ base ] || base;
		var encDict = font.lookup( N.of( 'Encoding' ) );
		var hasDifferences = encDict instanceof PDFLib.PDFDict && encDict.lookup( N.of( 'Differences' ) );
		if ( /^Courier/.test( base ) ) {
			info.certain = true;
			info.width = function () {
				return 0.6;
			};
		} else if ( STD14[ base ] ) {
			var table = STD14[ base ];
			info.certain = ! hasDifferences;
			info.width = function ( code ) {
				var w = table[ code - 32 ];
				return ( w || 0 ) / 1000;
			};
		}
		return info;
	}

	/* ---------------------------------------------------------------
	 * Stream processing
	 * ------------------------------------------------------------- */

	function decodeStream( PDFLib, stream ) {
		if ( stream instanceof PDFLib.PDFRawStream ) {
			return PDFLib.decodePDFRawStream( stream ).decode();
		}
		if ( stream && typeof stream.getUnencodedContents === 'function' ) {
			return stream.getUnencodedContents();
		}
		if ( stream && typeof stream.getContents === 'function' ) {
			return stream.getContents();
		}
		return new Uint8Array( 0 );
	}

	function hexOf( str ) {
		var out = '<';
		for ( var i = 0; i < str.length; i++ ) {
			out += ( '0' + str.charCodeAt( i ).toString( 16 ) ).slice( -2 );
		}
		return out + '>';
	}
	function fmt( x ) {
		return String( Math.round( x * 1000 ) / 1000 );
	}

	/**
	 * Interprets one content stream and rewrites covered glyphs.
	 *
	 * @return {{ text: string|null, removed: number, uncertain: number }}
	 *         text is null when nothing changed.
	 */
	function processContent( PDFLib, ctx, content, resources, ctm0, rects, depth ) {
		var ops = tokenize( content );
		var N = PDFLib.PDFName;
		var result = { removed: 0, uncertain: 0 };
		var edits = []; // { start, end, text }

		var gs = { ctm: ctm0, Tc: 0, Tw: 0, Th: 1, TL: 0, Tfs: 0, Trise: 0, font: null };
		var stack = [];
		var Tm = ID;
		var Tlm = ID;

		var fontsDict = resources instanceof PDFLib.PDFDict ? resources.lookup( N.of( 'Font' ) ) : null;
		var xobjDict = resources instanceof PDFLib.PDFDict ? resources.lookup( N.of( 'XObject' ) ) : null;

		function setFont( name ) {
			var f = fontsDict instanceof PDFLib.PDFDict ? fontsDict.lookup( N.of( name ) ) : null;
			if ( ! f ) {
				gs.font = { bytes: 1, certain: false, width: function () {
					return 0.5;
				} };
				return;
			}
			var key = f;
			if ( ! ctx.fontCache.has( key ) ) {
				ctx.fontCache.set( key, fontInfo( PDFLib, f ) );
			}
			gs.font = ctx.fontCache.get( key );
		}

		function nextLine( tx, ty ) {
			Tlm = mul( [ 1, 0, 0, 1, tx, ty ], Tlm );
			Tm = Tlm;
		}

		/** Shows a string; returns the TJ elements to emit, or null if unchanged. */
		function show( str, elements ) {
			var f = gs.font;
			var codes = [];
			if ( f && f.bytes === 2 ) {
				for ( var i = 0; i + 1 < str.length; i += 2 ) {
					codes.push( [ str.substr( i, 2 ), ( str.charCodeAt( i ) << 8 ) | str.charCodeAt( i + 1 ) ] );
				}
			} else {
				for ( var j = 0; j < str.length; j++ ) {
					codes.push( [ str[ j ], str.charCodeAt( j ) ] );
				}
			}
			var changed = false;
			var kept = '';
			var fontOk = f && f.certain && gs.Tfs !== 0 && gs.Th !== 0;

			codes.forEach( function ( cc ) {
				var w0 = f ? f.width( cc[ 1 ] ) : 0.5;
				var trm = mul( [ gs.Tfs * gs.Th, 0, 0, gs.Tfs, 0, gs.Trise ], mul( Tm, gs.ctm ) );
				var p = apply( trm, w0 / 2, 0.3 );
				var covered = inRects( rects, p[ 0 ], p[ 1 ] );
				var tx = ( w0 * gs.Tfs + gs.Tc + ( ( ! f || f.bytes === 1 ) && cc[ 1 ] === 32 ? gs.Tw : 0 ) ) * gs.Th;
				if ( covered && ! fontOk ) {
					result.uncertain++;
				}
				if ( covered && fontOk ) {
					if ( kept ) {
						elements.push( { s: kept } );
						kept = '';
					}
					var n = -tx * 1000 / ( gs.Tfs * gs.Th );
					var last = elements[ elements.length - 1 ];
					if ( last && typeof last.n === 'number' ) {
						last.n += n;
					} else {
						elements.push( { n: n } );
					}
					changed = true;
					result.removed++;
				} else {
					kept += cc[ 0 ];
				}
				Tm = mul( [ 1, 0, 0, 1, tx, 0 ], Tm );
			} );
			if ( kept ) {
				elements.push( { s: kept } );
			}
			return changed;
		}

		function tjText( elements ) {
			return '[' + elements.map( function ( e ) {
				return e.s !== undefined ? hexOf( e.s ) : fmt( e.n );
			} ).join( ' ' ) + '] TJ';
		}

		ops.forEach( function ( o ) {
			var a = o.args;
			switch ( o.op ) {
				case 'q':
					stack.push( Object.assign( {}, gs ) );
					break;
				case 'Q':
					if ( stack.length ) {
						gs = stack.pop();
					}
					break;
				case 'cm':
					if ( a.length === 6 ) {
						gs.ctm = mul( a, gs.ctm );
					}
					break;
				case 'BT':
					Tm = ID;
					Tlm = ID;
					break;
				case 'Tc':
					gs.Tc = a[ 0 ] || 0;
					break;
				case 'Tw':
					gs.Tw = a[ 0 ] || 0;
					break;
				case 'Tz':
					gs.Th = ( a[ 0 ] == null ? 100 : a[ 0 ] ) / 100;
					break;
				case 'TL':
					gs.TL = a[ 0 ] || 0;
					break;
				case 'Ts':
					gs.Trise = a[ 0 ] || 0;
					break;
				case 'Tf':
					if ( a[ 0 ] && a[ 0 ].n !== undefined ) {
						setFont( a[ 0 ].n );
					}
					gs.Tfs = typeof a[ 1 ] === 'number' ? a[ 1 ] : 0;
					break;
				case 'Td':
					nextLine( a[ 0 ] || 0, a[ 1 ] || 0 );
					break;
				case 'TD':
					gs.TL = -( a[ 1 ] || 0 );
					nextLine( a[ 0 ] || 0, a[ 1 ] || 0 );
					break;
				case 'Tm':
					if ( a.length === 6 ) {
						Tm = a.slice();
						Tlm = a.slice();
					}
					break;
				case 'T*':
					nextLine( 0, -gs.TL );
					break;
				case 'Tj':
				case "'":
				case '"':
					var prefix = '';
					if ( o.op === '"' ) {
						gs.Tw = a[ 0 ] || 0;
						gs.Tc = a[ 1 ] || 0;
						prefix = fmt( gs.Tw ) + ' Tw ' + fmt( gs.Tc ) + ' Tc T* ';
					} else if ( o.op === "'" ) {
						prefix = 'T* ';
					}
					if ( o.op !== 'Tj' ) {
						nextLine( 0, -gs.TL );
					}
					var sv = a[ a.length - 1 ];
					if ( sv && sv.t === 's' ) {
						var els = [];
						if ( show( sv.v, els ) ) {
							edits.push( { start: o.start, end: o.end, text: prefix + tjText( els ) } );
						}
					}
					break;
				case 'TJ':
					var list = a[ 0 ] && a[ 0 ].t === 'a' ? a[ 0 ].v : [];
					var out = [];
					var any = false;
					list.forEach( function ( item ) {
						if ( typeof item === 'number' ) {
							var last = out[ out.length - 1 ];
							if ( last && typeof last.n === 'number' ) {
								last.n += item;
							} else {
								out.push( { n: item } );
							}
							Tm = mul( [ 1, 0, 0, 1, -item / 1000 * gs.Tfs * gs.Th, 0 ], Tm );
						} else if ( item && item.t === 's' ) {
							if ( show( item.v, out ) ) {
								any = true;
							}
						}
					} );
					if ( any ) {
						edits.push( { start: o.start, end: o.end, text: tjText( out ) } );
					}
					break;
				case 'Do':
					if ( depth > 12 || ! ( xobjDict instanceof PDFLib.PDFDict ) || ! a[ 0 ] || a[ 0 ].n === undefined ) {
						break;
					}
					var xref = xobjDict.get( N.of( a[ 0 ].n ) );
					var xo = ctx.pdfCtx.lookup( xref );
					if ( ! xo || ! xo.dict || nameOf( PDFLib, xo.dict.lookup( N.of( 'Subtype' ) ) ) !== 'Form' ) {
						break;
					}
					var fm = xo.dict.lookup( N.of( 'Matrix' ) );
					var mtx = fm instanceof PDFLib.PDFArray ? fm.asArray().map( function ( x ) {
						return num( PDFLib, ctx.pdfCtx.lookup( x ), 0 );
					} ) : ID;
					var fres = xo.dict.lookup( N.of( 'Resources' ) ) || resources;
					var sub = processContent( PDFLib, ctx, bytesToStr( decodeStream( PDFLib, xo ) ), fres, mul( mtx, gs.ctm ), rects, depth + 1 );
					result.removed += sub.removed;
					result.uncertain += sub.uncertain;
					if ( sub.text !== null ) {
						// New copy of the form (the original may be used elsewhere).
						var dict = {};
						xo.dict.entries().forEach( function ( e ) {
							var k = e[ 0 ].asString();
							if ( k !== '/Length' && k !== '/Filter' && k !== '/DecodeParms' ) {
								dict[ k.slice( 1 ) ] = e[ 1 ];
							}
						} );
						var newStream = ctx.pdfCtx.flateStream( strToBytes( sub.text ), dict );
						var newRef = ctx.pdfCtx.register( newStream );
						var newName = 'GfxRd' + ( ++ctx.counter );
						xobjDict.set( N.of( newName ), newRef );
						edits.push( { start: o.start, end: o.end, text: '/' + newName + ' Do' } );
					}
					break;
			}
		} );

		if ( ! edits.length ) {
			result.text = null;
			return result;
		}
		edits.sort( function ( x, y ) {
			return x.start - y.start;
		} );
		var text = '';
		var at = 0;
		edits.forEach( function ( e ) {
			text += content.slice( at, e.start ) + ' ' + e.text + ' ';
			at = e.end;
		} );
		text += content.slice( at );
		result.text = text;
		return result;
	}

	/* ---------------------------------------------------------------
	 * Annotations
	 * ------------------------------------------------------------- */

	function removeFromFieldTree( PDFLib, context, array, ref ) {
		if ( ! ( array instanceof PDFLib.PDFArray ) ) {
			return;
		}
		for ( var i = array.size() - 1; i >= 0; i-- ) {
			var item = array.get( i );
			if ( item === ref ) {
				array.remove( i );
				continue;
			}
			var d = context.lookup( item );
			if ( d instanceof PDFLib.PDFDict ) {
				removeFromFieldTree( PDFLib, context, d.lookup( PDFLib.PDFName.of( 'Kids' ) ), ref );
			}
		}
	}

	function redactAnnotations( PDFLib, doc, page, rects ) {
		var N = PDFLib.PDFName;
		var annots = page.node.lookup( N.of( 'Annots' ) );
		if ( ! ( annots instanceof PDFLib.PDFArray ) ) {
			return 0;
		}
		var removed = 0;
		var acro = doc.catalog.lookup( N.of( 'AcroForm' ) );
		for ( var i = annots.size() - 1; i >= 0; i-- ) {
			var ref = annots.get( i );
			var a = doc.context.lookup( ref );
			if ( ! ( a instanceof PDFLib.PDFDict ) ) {
				continue;
			}
			var r = a.lookup( N.of( 'Rect' ) );
			if ( ! ( r instanceof PDFLib.PDFArray ) ) {
				continue;
			}
			var v = r.asArray().map( function ( x ) {
				return num( PDFLib, doc.context.lookup( x ), 0 );
			} );
			var ax0 = Math.min( v[ 0 ], v[ 2 ] );
			var ax1 = Math.max( v[ 0 ], v[ 2 ] );
			var ay0 = Math.min( v[ 1 ], v[ 3 ] );
			var ay1 = Math.max( v[ 1 ], v[ 3 ] );
			var hit = rects.some( function ( rc ) {
				return ax0 < rc[ 2 ] && ax1 > rc[ 0 ] && ay0 < rc[ 3 ] && ay1 > rc[ 1 ];
			} );
			if ( hit ) {
				annots.remove( i );
				if ( acro instanceof PDFLib.PDFDict ) {
					removeFromFieldTree( PDFLib, doc.context, acro.lookup( N.of( 'Fields' ) ), ref );
				}
				removed++;
			}
		}
		return removed;
	}

	/* ---------------------------------------------------------------
	 * Public API
	 * ------------------------------------------------------------- */

	/**
	 * @param {Object} PDFLib
	 * @param {PDFDocument} doc
	 * @param {Object} rectsByPage { pageIndex: [[x0,y0,x1,y1], …] } in PDF user space
	 * @return {{removed:number, uncertain:number, annots:number, pages:Object}}
	 */
	function redactDocument( PDFLib, doc, rectsByPage ) {
		var N = PDFLib.PDFName;
		var pages = doc.getPages();
		var ctx = { pdfCtx: doc.context, fontCache: new Map(), counter: 0 };
		var report = { removed: 0, uncertain: 0, annots: 0, pages: {} };

		Object.keys( rectsByPage ).forEach( function ( key ) {
			var page = pages[ key ];
			var rects = rectsByPage[ key ];
			if ( ! page || ! rects || ! rects.length ) {
				return;
			}
			var node = page.node;
			var contents = node.lookup( N.of( 'Contents' ) );
			var streams = [];
			if ( contents instanceof PDFLib.PDFArray ) {
				contents.asArray().forEach( function ( r ) {
					var st = doc.context.lookup( r );
					if ( st ) {
						streams.push( st );
					}
				} );
			} else if ( contents ) {
				streams.push( contents );
			}
			var content = streams.map( function ( st ) {
				return bytesToStr( decodeStream( PDFLib, st ) );
			} ).join( '\n' );

			var res = processContent( PDFLib, ctx, content, node.Resources(), ID, rects, 0 );
			if ( res.text !== null ) {
				var ref = doc.context.register( doc.context.flateStream( strToBytes( res.text ) ) );
				node.set( N.of( 'Contents' ), ref );
			}
			var annots = redactAnnotations( PDFLib, doc, page, rects );
			report.removed += res.removed;
			report.uncertain += res.uncertain;
			report.annots += annots;
			report.pages[ key ] = { removed: res.removed, uncertain: res.uncertain, annots: annots };
		} );
		return report;
	}

	/**
	 * Deletes every indirect object no longer reachable from the trailer —
	 * pdf-lib writes all registered objects, so without this the old content
	 * streams (with the removed text) would still be saved in the file.
	 */
	function collectGarbage( PDFLib, doc ) {
		var context = doc.context;
		var seen = new Set();
		var queue = [];
		var t = context.trailerInfo;
		[ t.Root, t.Info, t.Encrypt, t.ID ].forEach( function ( x ) {
			if ( x ) {
				queue.push( x );
			}
		} );
		while ( queue.length ) {
			var o = queue.pop();
			if ( o instanceof PDFLib.PDFRef ) {
				if ( seen.has( o ) ) {
					continue;
				}
				seen.add( o );
				var target = context.lookup( o );
				if ( target ) {
					queue.push( target );
				}
			} else if ( o instanceof PDFLib.PDFDict ) {
				o.entries().forEach( function ( e ) {
					queue.push( e[ 1 ] );
				} );
			} else if ( o instanceof PDFLib.PDFArray ) {
				o.asArray().forEach( function ( x ) {
					queue.push( x );
				} );
			} else if ( o && o.dict instanceof PDFLib.PDFDict ) {
				queue.push( o.dict );
			}
		}
		var removed = 0;
		context.enumerateIndirectObjects().forEach( function ( pair ) {
			if ( ! seen.has( pair[ 0 ] ) ) {
				context.delete( pair[ 0 ] );
				removed++;
			}
		} );
		return removed;
	}

	/**
	 * Checks pdf.js text items (getTextContent().items of one page) for
	 * characters whose centre lies inside a redaction rect.
	 *
	 * @return {string[]} surviving covered text fragments
	 */
	function findLeftovers( items, rects ) {
		var found = [];
		items.forEach( function ( it ) {
			var str = it.str || '';
			if ( ! str.trim() || ! it.transform ) {
				return;
			}
			var t = it.transform;
			var dirLen = Math.hypot( t[ 0 ], t[ 1 ] ) || 1;
			var nLen = Math.hypot( t[ 2 ], t[ 3 ] ) || 1;
			var dx = t[ 0 ] / dirLen;
			var dy = t[ 1 ] / dirLen;
			var nx = t[ 2 ] / nLen;
			var ny = t[ 3 ] / nLen;
			var size = nLen;
			var chars = Array.from( str );
			var hit = '';
			chars.forEach( function ( ch, k ) {
				if ( ! ch.trim() ) {
					return;
				}
				var along = it.width * ( k + 0.5 ) / chars.length;
				var x = t[ 4 ] + dx * along + nx * size * 0.3;
				var y = t[ 5 ] + dy * along + ny * size * 0.3;
				// shrink rects a little: characters touching the edge are fine
				for ( var i = 0; i < rects.length; i++ ) {
					var r = rects[ i ];
					if ( x > r[ 0 ] + 0.75 && x < r[ 2 ] - 0.75 && y > r[ 1 ] + 0.75 && y < r[ 3 ] - 0.75 ) {
						hit += ch;
						return;
					}
				}
			} );
			if ( hit ) {
				found.push( hit );
			}
		} );
		return found;
	}

	return {
		redactDocument: redactDocument,
		collectGarbage: collectGarbage,
		findLeftovers: findLeftovers,
		tokenize: tokenize,
		_fontInfo: fontInfo,
	};
} );
