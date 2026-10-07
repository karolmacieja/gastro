/* global Vue, gfxPlikiConfig */
/**
 * GastroFlowX Pliki — SPA (Vue 3 UMD, bez kroku budowania).
 *
 * Montowanie odporne na osadzenie w Hubie: jeśli #gfx-pliki-app nie istnieje
 * w chwili wykonania skryptu (panel dociąga treść dynamicznie), czekamy na
 * niego przez MutationObserver zamiast kończyć na „Ładowanie panelu…”.
 */
(function () {
'use strict';

const cfg = window.gfxPlikiConfig || {};
const MOUNT_ID = 'gfx-pliki-app';

/* =====================================================================
 * HTTP
 * ===================================================================== */

function buildUrl( path, params ) {
    let u = ( cfg.restUrl || '' ) + ( path || '' );
    const qs = params ? new URLSearchParams( params ).toString() : '';
    if ( qs ) u += ( u.indexOf( '?' ) > -1 ? '&' : '?' ) + qs;
    return u;
}

async function parseJsonSafely( res ) {
    const text = await res.text();
    try {
        return JSON.parse( text );
    } catch ( e ) {
        const snippet = text.replace( /\s+/g, ' ' ).trim().slice( 0, 200 );
        throw new Error( 'Serwer zwrócił nieprawidłową odpowiedź (nie-JSON), status ' + res.status + '. Możliwe przyczyny: limit rozmiaru pliku na serwerze, błąd PHP, blokada zabezpieczeń. Fragment odpowiedzi: ' + snippet );
    }
}

async function api( path, opts ) {
    opts = opts || {};
    const init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
    if ( opts.body instanceof FormData ) {
        init.body = opts.body;
    } else if ( opts.body !== undefined ) {
        init.body = JSON.stringify( opts.body );
        init.headers['Content-Type'] = 'application/json';
    }
    const res = await fetch( buildUrl( path ), init );
    const data = await parseJsonSafely( res );
    if ( ! res.ok ) {
        if ( data && ( data.code === 'rest_cookie_invalid_nonce' || data.code === 'gfx_pliki_bad_nonce' ) ) {
            throw new Error( 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.' );
        }
        throw new Error( ( data && data.message ) || 'Błąd komunikacji z serwerem.' );
    }
    return data;
}

function fileUrl( doc, download ) {
    const p = { _wpnonce: cfg.nonce, v: doc.version || '' };
    if ( download ) p.download = '1';
    return buildUrl( '/documents/' + doc.id + '/file', p );
}

function escapeHtml( s ) {
    return String( s == null ? '' : s ).replace( /[&<>"']/g, c => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
}

function formatBytes( b ) {
    if ( ! b ) return '0 B';
    if ( b < 1024 ) return b + ' B';
    if ( b < 1048576 ) return ( b / 1024 ).toFixed( 0 ) + ' KB';
    return ( b / 1048576 ).toFixed( 1 ).replace( '.', ',' ) + ' MB';
}

function normalizeText( s ) {
    return String( s || '' ).toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).replace( /ł/g, 'l' );
}

/* =====================================================================
 * Czyszczenie HTML (wklejanie z Worda/Excela + podgląd/druk)
 * Serwer i tak sanityzuje przez wp_kses_post — to jest warstwa UX.
 * ===================================================================== */

const ALLOWED_TAGS = {
    P: 1, BR: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, S: 1, H1: 1, H2: 1, H3: 1, H4: 1,
    UL: 1, OL: 1, LI: 1, TABLE: 1, THEAD: 1, TBODY: 1, TFOOT: 1, TR: 1, TD: 1, TH: 1,
    HR: 1, SPAN: 1, DIV: 1, BLOCKQUOTE: 1, SUB: 1, SUP: 1,
};
const DROP_TAGS = { SCRIPT: 1, STYLE: 1, META: 1, LINK: 1, TITLE: 1, XML: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, SVG: 1, IMG: 1, VIDEO: 1, AUDIO: 1, FORM: 1, INPUT: 1, BUTTON: 1, SELECT: 1, TEXTAREA: 1, HEAD: 1 };
const ALIGNABLE = { P: 1, H1: 1, H2: 1, H3: 1, H4: 1, TD: 1, TH: 1, DIV: 1, LI: 1 };

function cleanNode( node ) {
    Array.from( node.childNodes ).forEach( child => {
        if ( child.nodeType === 8 ) { child.remove(); return; }
        if ( child.nodeType !== 1 ) return;
        const tag = child.tagName.toUpperCase();
        if ( DROP_TAGS[ tag ] ) { child.remove(); return; }
        cleanNode( child );
        if ( ! ALLOWED_TAGS[ tag ] ) {
            while ( child.firstChild ) child.parentNode.insertBefore( child.firstChild, child );
            child.remove();
            return;
        }
        const align = ALIGNABLE[ tag ] && child.style ? child.style.textAlign : '';
        const cls = ( child.getAttribute( 'class' ) || '' ).split( /\s+/ ).filter( c => c.indexOf( 'gp-' ) === 0 ).join( ' ' );
        const colspan = child.getAttribute( 'colspan' );
        const rowspan = child.getAttribute( 'rowspan' );
        Array.from( child.attributes ).forEach( a => child.removeAttribute( a.name ) );
        if ( cls ) child.setAttribute( 'class', cls );
        if ( align && /^(left|center|right|justify)$/.test( align ) ) child.setAttribute( 'style', 'text-align:' + align );
        if ( ( tag === 'TD' || tag === 'TH' ) ) {
            if ( colspan && /^\d+$/.test( colspan ) ) child.setAttribute( 'colspan', colspan );
            if ( rowspan && /^\d+$/.test( rowspan ) ) child.setAttribute( 'rowspan', rowspan );
        }
    } );
}

function cleanHtml( html ) {
    if ( ! html ) return '';
    const doc = new DOMParser().parseFromString( String( html ), 'text/html' );
    cleanNode( doc.body );
    return doc.body.innerHTML;
}

/* =====================================================================
 * Style dokumentu — jedno źródło dla edytora, podglądu i wydruku.
 * ===================================================================== */

function docCss( s ) {
    return `
${s}{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;font-size:11pt;line-height:1.45;word-wrap:break-word;}
${s} h1,${s} h2,${s} h3,${s} h4{font-family:inherit;color:inherit;letter-spacing:normal;text-transform:none;}
${s} h1{font-size:19pt;line-height:1.2;margin:0 0 10pt;font-weight:700;}
${s} h2{font-size:15pt;line-height:1.25;margin:14pt 0 8pt;font-weight:700;}
${s} h3{font-size:12.5pt;line-height:1.3;margin:10pt 0 5pt;font-weight:700;}
${s} h4{font-size:11pt;margin:10pt 0 4pt;font-weight:700;}
${s} p{margin:0 0 7pt;}
${s} ul,${s} ol{margin:0 0 8pt;padding-left:18pt;}
${s} li{margin:0 0 4pt;}
${s} ul.gp-check{list-style:none;padding-left:0;}
${s} ul.gp-check>li{position:relative;padding-left:20pt;min-height:13pt;}
${s} ul.gp-check>li::before{content:'';position:absolute;left:1pt;top:.18em;width:10pt;height:10pt;border:1.2pt solid #111827;border-radius:2pt;}
${s} table{width:100%;border-collapse:collapse;margin:0 0 10pt;}
${s} td,${s} th{border:1px solid #374151;padding:3pt 6pt;line-height:1.3;vertical-align:top;text-align:left;}
${s} th{background:#F3F4F6;font-weight:700;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
${s} table.gp-table td{height:18pt;}
${s} table.gp-noborder td,${s} table.gp-noborder th{border:none;padding:4pt 8pt 4pt 0;height:auto;background:none;}
${s} table.gp-sign td{padding-top:20pt;width:50%;}
${s} .gp-blank{display:inline-block;min-width:45mm;border-bottom:1px solid #111827;height:1.15em;vertical-align:bottom;}
${s} .gp-blank-full{display:block;width:100%;min-width:0;height:18pt;}
${s} table.gp-sign .gp-blank{display:block;width:85%;margin-top:4pt;}
${s} hr{border:none;border-top:1px solid #9CA3AF;margin:10pt 0;}
${s} .gp-w30{width:30%;}
${s} .gp-w20{width:18%;}
${s} .gp-doc-title{font-size:17pt;font-weight:700;line-height:1.2;margin:0 0 12pt;padding-bottom:6pt;border-bottom:2px solid #111827;}
${s} tr{break-inside:avoid;page-break-inside:avoid;}
${s} h2,${s} h3,${s} h4{break-after:avoid;page-break-after:avoid;}
`;
}

function printPageCss() {
    return `
@page{size:A4;margin:12mm;}
@page gpland{size:A4 landscape;margin:12mm;}
@page gpfull{size:A4;margin:0;}
@page gpfullland{size:A4 landscape;margin:0;}
.gp-p{break-after:page;page-break-after:always;}
.gp-p:last-child{break-after:auto;page-break-after:auto;}
.gp-p-land{page:gpland;}
/* Wgrane pliki (PDF, zdjęcia) mają już własne marginesy — drukujemy je na całą
   kartkę A4 (margines strony 0), bez dodatkowej ramki i bez pomniejszania.
   Wysokość o 1 mm mniejsza od kartki chroni przed pustą stroną z zaokrągleń. */
.gp-p-img{page:gpfull;display:flex;align-items:center;justify-content:center;width:210mm;height:296mm;overflow:hidden;margin:0;padding:0;box-sizing:border-box;}
.gp-p-img.gp-p-land{page:gpfullland;width:297mm;height:209mm;}
.gp-p-img img{display:block;max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;}
`;
}

function injectDocStyles() {
    if ( document.getElementById( 'gfx-pliki-doc-css' ) ) return;
    const st = document.createElement( 'style' );
    st.id = 'gfx-pliki-doc-css';
    st.textContent = docCss( '.gfx-pliki-root .gp-doc' );
    document.head.appendChild( st );
}

/* =====================================================================
 * Pliki → strony (pdf.js renderuje PDF na obrazki; wbudowana przeglądarka
 * PDF w <iframe> nie jest drukowana przez przeglądarki — ta sama lekcja
 * co w Kolorowankach).
 * ===================================================================== */

function pdfjs() {
    const lib = window['pdfjs-dist/build/pdf'];
    if ( ! lib ) throw new Error( 'Nie udało się załadować biblioteki pdf.js (sprawdź blokady skryptów zewnętrznych).' );
    lib.GlobalWorkerOptions.workerSrc = cfg.pdfWorker;
    return lib;
}

async function fetchBytes( doc ) {
    const res = await fetch( fileUrl( doc ), { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } } );
    if ( ! res.ok ) throw new Error( 'Nie udało się pobrać pliku „' + doc.title + '” (status ' + res.status + ').' );
    return res;
}

async function renderPdfPages( doc, scale, onPage ) {
    const lib = pdfjs();
    const res = await fetchBytes( doc );
    const data = new Uint8Array( await res.arrayBuffer() );
    const pdf = await lib.getDocument( { data } ).promise;
    const pages = [];
    for ( let i = 1; i <= pdf.numPages; i++ ) {
        const page = await pdf.getPage( i );
        const vp = page.getViewport( { scale } );
        const canvas = document.createElement( 'canvas' );
        canvas.width = Math.floor( vp.width );
        canvas.height = Math.floor( vp.height );
        const ctx = canvas.getContext( '2d' );
        ctx.fillStyle = '#fff';
        ctx.fillRect( 0, 0, canvas.width, canvas.height );
        await page.render( { canvasContext: ctx, viewport: vp } ).promise;
        const p = { src: canvas.toDataURL( 'image/jpeg', 0.92 ), landscape: vp.width > vp.height };
        canvas.width = 0; canvas.height = 0;
        pages.push( p );
        if ( onPage ) onPage( p, i, pdf.numPages );
    }
    return pages;
}

async function loadImagePage( doc ) {
    const res = await fetchBytes( doc );
    const blob = await res.blob();
    const src = await new Promise( ( resolve, reject ) => {
        const r = new FileReader();
        r.onload = () => resolve( r.result );
        r.onerror = () => reject( new Error( 'Nie udało się odczytać obrazu.' ) );
        r.readAsDataURL( blob );
    } );
    const dims = await new Promise( resolve => {
        const img = new Image();
        img.onload = () => resolve( { w: img.naturalWidth, h: img.naturalHeight } );
        img.onerror = () => resolve( { w: 1, h: 1 } );
        img.src = src;
    } );
    return [ { src, landscape: dims.w > dims.h } ];
}

/* =====================================================================
 * Druk: jedna ścieżka budowania treści, dwie ścieżki dostarczenia
 * (desktop — ukryty iframe; telefon — główne okno z przyciskiem „Wróć”).
 * ===================================================================== */

async function preparePrint( items, onStatus ) {
    const cache = new Map();
    const fileItems = items.filter( it => it.doc.type === 'file' );
    let n = 0;
    for ( const it of fileItems ) {
        n++;
        if ( cache.has( it.doc.id ) ) continue;
        onStatus( 'Przygotowywanie pliku ' + n + '/' + fileItems.length + ': ' + it.doc.title + '…' );
        const pages = it.doc.file && it.doc.file.kind === 'pdf'
            ? await renderPdfPages( it.doc, 2, ( p, i, total ) => onStatus( 'Renderowanie „' + it.doc.title + '”: strona ' + i + '/' + total + '…' ) )
            : await loadImagePage( it.doc );
        cache.set( it.doc.id, pages );
    }
    return cache;
}

function buildSections( targetDoc, container, items, cache ) {
    items.forEach( it => {
        const d = it.doc;
        const copies = Math.max( 1, Math.min( 100, parseInt( it.copies, 10 ) || 1 ) );
        for ( let c = 0; c < copies; c++ ) {
            if ( d.type === 'html' ) {
                const s = targetDoc.createElement( 'section' );
                s.className = 'gp-p gp-doc' + ( d.orientation === 'landscape' ? ' gp-p-land' : '' );
                s.innerHTML = ( d.printTitle ? '<div class="gp-doc-title">' + escapeHtml( d.title ) + '</div>' : '' ) + cleanHtml( d.content || '' );
                container.appendChild( s );
            } else {
                ( cache.get( d.id ) || [] ).forEach( p => {
                    const s = targetDoc.createElement( 'section' );
                    s.className = 'gp-p gp-p-img' + ( p.landscape ? ' gp-p-land' : '' );
                    const img = targetDoc.createElement( 'img' );
                    img.src = p.src;
                    img.alt = '';
                    s.appendChild( img );
                    container.appendChild( s );
                } );
            }
        }
    } );
}

function waitForImages( root ) {
    const imgs = Array.from( root.querySelectorAll( 'img' ) );
    return Promise.all( imgs.map( img => {
        if ( img.complete && img.naturalWidth > 0 ) return Promise.resolve();
        return new Promise( resolve => {
            img.onload = resolve; img.onerror = resolve; setTimeout( resolve, 4000 );
        } );
    } ) );
}

function dataUrlToFile( dataUrl, baseName ) {
    const [ head, b64 ] = dataUrl.split( ',' );
    const mime = ( head.match( /data:([^;]+)/ ) || [] )[1] || 'image/jpeg';
    const bin = atob( b64 );
    const arr = new Uint8Array( bin.length );
    for ( let i = 0; i < bin.length; i++ ) arr[i] = bin.charCodeAt( i );
    const ext = mime === 'image/png' ? 'png' : ( mime === 'image/webp' ? 'webp' : 'jpg' );
    return new File( [ arr ], baseName + '.' + ext, { type: mime } );
}

function isMobileDevice() {
    return /Mobi|Android|iPhone|iPad|iPod/i.test( navigator.userAgent ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 );
}

async function printViaIframe( items, cache, title ) {
    const old = document.getElementById( 'gfx-pliki-print-iframe' );
    if ( old ) old.remove();

    const iframe = document.createElement( 'iframe' );
    iframe.id = 'gfx-pliki-print-iframe';
    iframe.setAttribute( 'aria-hidden', 'true' );
    iframe.style.cssText = 'position:absolute;width:0;height:0;border:0;left:-9999px;top:0;';
    document.body.appendChild( iframe );

    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write( '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>' + escapeHtml( title ) + '</title><style>' +
        'html,body{margin:0;padding:0;background:#fff;}' + printPageCss() + docCss( '.gp-doc' ) +
        '</style></head><body></body></html>' );
    doc.close();

    buildSections( doc, doc.body, items, cache );
    await waitForImages( doc );
    await new Promise( r => setTimeout( r, 250 ) );
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
}

/**
 * Telefon/tablet: drukujemy główne okno (mobilny Chrome ignoruje print() iframe'a).
 * Resztę strony fizycznie odpinamy na czas druku, a przywracamy dopiero po
 * kliknięciu „Gotowe” — ten sam mechanizm co w Kolorowankach i Lunchu.
 * iOS: druk po dłuższym przygotowaniu traci „gest użytkownika” (PWA go blokuje),
 * dlatego w pasku jest przycisk „Drukuj” wywołujący druk wprost z dotknięcia.
 * Pasek jest przypięty, a „Gotowe” reaguje na dotyk i klik (Safari po anulowaniu
 * druku potrafi zgubić zwykły click); przy błędzie przywracania — przeładowanie.
 */
async function printViaMainWindow( items, cache ) {
    const style = document.createElement( 'style' );
    style.id = 'gfx-pliki-print-style';
    style.textContent = printPageCss() + docCss( '#gfx-pliki-print-overlay .gp-doc' ) + `
@media screen{
 #gfx-pliki-print-overlay{background:#E5E7EB;min-height:100vh;padding-bottom:24px;}
 #gfx-pliki-print-overlay .gp-p{background:#fff;max-width:210mm;margin:12px auto;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.15);}
 #gfx-pliki-print-overlay .gp-p-img{width:auto;height:auto;padding:0;}
 #gfx-pliki-print-overlay .gp-p-img img{width:100%;height:auto;}
}
@media print{
 .gfx-pliki-return-bar{display:none!important;}
 #gfx-pliki-print-overlay{background:#fff;}
}`;
    document.head.appendChild( style );

    const overlay = document.createElement( 'div' );
    overlay.id = 'gfx-pliki-print-overlay';

    const btnCss = 'border:none;padding:10px 16px;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap;touch-action:manipulation;-webkit-tap-highlight-color:transparent;';
    const bar = document.createElement( 'div' );
    bar.className = 'gfx-pliki-return-bar';
    bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483647;background:#1F2937;color:#fff;padding:12px 16px;padding-top:calc(12px + env(safe-area-inset-top, 0px));display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;box-sizing:border-box;';
    const msg = document.createElement( 'span' );
    msg.style.cssText = 'font-size:14px;font-weight:600;flex:1 1 180px;';
    msg.textContent = 'Jeśli okno drukowania nie otworzyło się samo, kliknij „Drukuj”.';
    const btns = document.createElement( 'div' );
    btns.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;';
    const printBtn = document.createElement( 'button' );
    printBtn.type = 'button';
    printBtn.textContent = '🖨 Drukuj';
    printBtn.style.cssText = btnCss + 'background:#fff;color:#111827;';
    const btn = document.createElement( 'button' );
    btn.type = 'button';
    btn.textContent = '✓ Gotowe, wróć do listy';
    btn.style.cssText = btnCss + 'background:#2563EB;color:#fff;';
    btns.appendChild( printBtn );
    btns.appendChild( btn );
    bar.appendChild( msg );
    bar.appendChild( btns );

    // Odstęp pod przypiętym paskiem — ta sama klasa, więc znika na wydruku.
    const spacer = document.createElement( 'div' );
    spacer.className = 'gfx-pliki-return-bar';
    spacer.style.cssText = 'height:calc(76px + env(safe-area-inset-top, 0px));';

    const wrap = document.createElement( 'div' );
    buildSections( document, wrap, items, cache );
    overlay.appendChild( bar );
    overlay.appendChild( spacer );
    overlay.appendChild( wrap );

    const detached = Array.from( document.body.children ).map( el => ( { el, next: el.nextSibling } ) );
    detached.forEach( ( { el } ) => el.remove() );
    document.body.appendChild( overlay );
    window.scrollTo( 0, 0 );

    const doPrint = () => {
        try { window.print(); } catch ( e ) { console.error( 'GastroFlowX Pliki: window.print() nie powiodło się', e ); }
    };

    const onAfterPrint = () => {
        msg.textContent = 'Po zakończeniu kliknij „Gotowe, wróć do listy”.';
        // iOS po anulowaniu druku potrafi zostawić nieodświeżony układ — wymuszamy przemalowanie.
        overlay.style.display = 'none';
        void overlay.offsetHeight;
        overlay.style.display = '';
    };
    window.addEventListener( 'afterprint', onAfterPrint );

    let done = false;
    const restore = ( e ) => {
        if ( e ) { e.preventDefault(); e.stopPropagation(); }
        if ( done ) return;
        done = true;
        window.removeEventListener( 'afterprint', onAfterPrint );
        try {
            overlay.remove();
            style.remove();
            detached.forEach( ( { el, next } ) => {
                if ( next && next.parentNode === document.body ) document.body.insertBefore( el, next );
                else document.body.appendChild( el );
            } );
        } catch ( err ) {
            console.error( 'GastroFlowX Pliki: przywracanie strony po druku nie powiodło się', err );
            window.location.reload();
        }
    };
    [ 'touchend', 'pointerup', 'click' ].forEach( ev => btn.addEventListener( ev, restore ) );

    // PWA na iPhonie/iPadzie: Apple wyłącza tam window.print() (wywołanie nic
    // nie robi). Jedyna działająca droga to systemowe menu „Udostępnij” z opcją
    // „Drukuj”. Tu nie ma jednego PDF-a, więc udostępniamy strony jako obrazki
    // (z uwzględnieniem kopii). Dokumentów tekstowych (HTML) nie da się tak
    // przekazać — dla nich trzeba otworzyć stronę w Safari.
    const isIOSStandalone = window.navigator.standalone === true;
    const hasHtmlDocs = items.some( it => it.doc.type === 'html' );
    let shareFiles = [];
    if ( isIOSStandalone ) {
        let n = 0;
        items.forEach( it => {
            if ( it.doc.type === 'html' ) return;
            const copies = Math.max( 1, Math.min( 100, parseInt( it.copies, 10 ) || 1 ) );
            for ( let c = 0; c < copies; c++ ) {
                ( cache.get( it.doc.id ) || [] ).forEach( pg => {
                    n++;
                    shareFiles.push( dataUrlToFile( pg.src, 'strona-' + String( n ).padStart( 2, '0' ) ) );
                } );
            }
        } );
    }
    const canShareFiles = !! ( shareFiles.length && navigator.canShare && navigator.share && navigator.canShare( { files: shareFiles } ) );
    if ( isIOSStandalone ) {
        if ( ! canShareFiles ) msg.textContent = hasHtmlDocs
            ? 'Dokumentów tekstowych nie da się wydrukować z aplikacji na iPhonie — otwórz stronę w Safari.'
            : 'Ta wersja iOS nie pozwala drukować z aplikacji — otwórz stronę w Safari.';
        else msg.textContent = hasHtmlDocs
            ? 'Kliknij „Drukuj”, a potem w menu wybierz „Drukuj”. Dokumenty tekstowe wydrukujesz tylko z Safari.'
            : 'Kliknij „Drukuj”, a potem w menu wybierz „Drukuj”.';
    }

    printBtn.addEventListener( 'click', () => {
        if ( isIOSStandalone && canShareFiles ) {
            navigator.share( { files: shareFiles } ).catch( err => {
                if ( err && err.name === 'AbortError' ) return; // użytkownik zamknął menu
                console.error( 'GastroFlowX Pliki: udostępnianie do druku nie powiodło się', err );
                msg.textContent = 'Nie udało się otworzyć menu drukowania — spróbuj ponownie.';
            } );
            return;
        }
        doPrint();
    } );

    await waitForImages( overlay );
    for ( let i = 0; i < 3; i++ ) await new Promise( r => requestAnimationFrame( r ) );
    // Automatyczna próba druku — poza PWA na iOS (tam druk działa tylko
    // przez przycisk „Drukuj” → menu „Udostępnij”).
    if ( ! isIOSStandalone ) doPrint();
}

/* =====================================================================
 * Komponent: wybór ról (widoczność dokumentu)
 * ===================================================================== */

const RolesPicker = {
    props: { modelValue: { type: Array, default: () => [] }, roles: { type: Object, default: () => ( {} ) } },
    emits: [ 'update:modelValue' ],
    methods: {
        toggle( slug ) {
            const v = this.modelValue.slice();
            const i = v.indexOf( slug );
            if ( i > -1 ) v.splice( i, 1 ); else v.push( slug );
            this.$emit( 'update:modelValue', v );
        },
    },
    template: `
    <div>
        <div class="gp-chips">
            <button type="button" class="gp-chip" :class="{active: !modelValue.length}" @click="$emit('update:modelValue', [])">
                <i class="fas fa-users"></i> Wszyscy z dostępem
            </button>
            <button v-for="(label, slug) in roles" :key="slug" type="button" class="gp-chip" :class="{active: modelValue.includes(slug)}" @click="toggle(slug)">
                <i v-if="modelValue.includes(slug)" class="fas fa-check"></i> {{ label }}
            </button>
        </div>
        <p class="gp-hint" v-if="modelValue.length">Dokument zobaczą tylko wybrane role (oraz osoby zarządzające modułem).</p>
    </div>`,
};

/* =====================================================================
 * Aplikacja
 * ===================================================================== */

const FALLBACK_ROLE_LABELS = { rs_restaurant_manager: 'Manager restauracji', admin_bar: 'Admin baru', admin_kuchnia: 'Admin kuchni', kelner: 'Kelner', barman: 'Barman', kucharz: 'Kucharz', lunch: 'Lunch' };
const ACCEPT_EXT = [ 'pdf', 'jpg', 'jpeg', 'png', 'webp' ];

function emptyForm() {
    return { id: 0, type: 'html', title: '', category: 'cat_default', roles: [], note: '', orientation: 'portrait', printTitle: true, files: [], file: null };
}

function createGfxPlikiApp() {
    const { createApp } = Vue;
    const app = createApp( {
        data() {
            return {
                loading: true,
                initError: '',
                message: null,

                user: {},
                isAdmin: false,
                caps: {},
                actions: [],
                categories: [],
                documents: [],
                roleLabels: {},
                docRoles: {},
                settings: null,
                maxUpload: 0,

                activeCategory: 'all',
                search: '',
                sort: 'title',

                manageCats: false,
                newCatName: '',
                menuOpen: null,

                modal: null,
                form: emptyForm(),
                saving: false,
                savingLabel: '',
                dragOver: false,

                dirty: false,
                inTable: false,
                tablePop: false,
                tableRows: 4,
                tableCols: 3,
                tableHeader: true,

                preview: { doc: null, pages: [], loading: false, error: '', copies: 1 },

                settingsForm: {},

                printing: false,
                printStatus: '',
            };
        },

        computed: {
            filteredDocs() {
                const q = normalizeText( this.search.trim() );
                let list = this.documents;
                if ( this.activeCategory !== 'all' ) list = list.filter( d => d.category === this.activeCategory );
                if ( q ) list = list.filter( d => normalizeText( d.title + ' ' + ( d.note || '' ) ).indexOf( q ) > -1 );
                list = list.slice();
                if ( this.sort === 'updated' ) list.sort( ( a, b ) => ( b.version || '' ).localeCompare( a.version || '', undefined, { numeric: true } ) );
                else list.sort( ( a, b ) => a.title.localeCompare( b.title, 'pl', { sensitivity: 'base', numeric: true } ) );
                return list;
            },
            selectedDocs() {
                return this.documents.filter( d => d._selected );
            },
            selectedCopies() {
                return this.selectedDocs.reduce( ( s, d ) => s + ( parseInt( d._copies, 10 ) || 1 ), 0 );
            },
            maxUploadLabel() {
                return formatBytes( this.maxUpload );
            },
            allRoles() {
                return this.roleLabels || {};
            },
            tools() {
                return [
                    { i: 'fa-rotate-left', t: 'Cofnij', a: () => this.exec( 'undo' ) },
                    { i: 'fa-rotate-right', t: 'Ponów', a: () => this.exec( 'redo' ) },
                    { sep: true },
                    { l: 'Tekst', t: 'Zwykły akapit', a: () => this.block( 'p' ) },
                    { l: 'H1', t: 'Nagłówek duży', a: () => this.block( 'h1' ) },
                    { l: 'H2', t: 'Nagłówek średni', a: () => this.block( 'h2' ) },
                    { l: 'H3', t: 'Nagłówek mały', a: () => this.block( 'h3' ) },
                    { sep: true },
                    { i: 'fa-bold', t: 'Pogrubienie', a: () => this.exec( 'bold' ) },
                    { i: 'fa-italic', t: 'Kursywa', a: () => this.exec( 'italic' ) },
                    { i: 'fa-underline', t: 'Podkreślenie', a: () => this.exec( 'underline' ) },
                    { sep: true },
                    { i: 'fa-list-ul', t: 'Lista punktowana', a: () => this.exec( 'insertUnorderedList' ) },
                    { i: 'fa-list-ol', t: 'Lista numerowana', a: () => this.exec( 'insertOrderedList' ) },
                    { i: 'fa-square-check', l: 'Lista zadań', t: 'Lista z polami do odhaczenia', a: () => this.toggleChecklist() },
                    { sep: true },
                    { i: 'fa-align-left', t: 'Do lewej', a: () => this.exec( 'justifyLeft' ) },
                    { i: 'fa-align-center', t: 'Wyśrodkuj', a: () => this.exec( 'justifyCenter' ) },
                    { i: 'fa-align-right', t: 'Do prawej', a: () => this.exec( 'justifyRight' ) },
                    { sep: true },
                    { i: 'fa-table', l: 'Tabela', t: 'Wstaw tabelę', a: () => { this.tablePop = ! this.tablePop; } },
                    { i: 'fa-grip-lines', l: 'Pole', t: 'Pole do wpisania (linia)', a: () => this.insertHtml( '<span class="gp-blank">&nbsp;</span>&nbsp;' ) },
                    { i: 'fa-ruler-horizontal', l: 'Linia', t: 'Pełna linia do pisania', a: () => this.insertHtml( '<p><span class="gp-blank gp-blank-full">&nbsp;</span></p><p><br></p>' ) },
                    { i: 'fa-signature', l: 'Podpisy', t: 'Miejsce na podpisy', a: () => this.insertSignatures() },
                    { i: 'fa-minus', t: 'Linia pozioma', a: () => this.exec( 'insertHorizontalRule' ) },
                    { sep: true },
                    { i: 'fa-eraser', t: 'Usuń formatowanie', a: () => { this.exec( 'removeFormat' ); this.block( 'p' ); } },
                ];
            },
        },

        watch: {
            modal( v ) {
                document.documentElement.classList.toggle( 'gfx-pliki-lock', !! v );
            },
        },

        async mounted() {
            this._onDocClick = () => { this.menuOpen = null; };
            this._onKey = ( e ) => { if ( e.key === 'Escape' && this.modal ) this.closeModal(); };
            this._onSel = () => this.trackSelection();
            document.addEventListener( 'click', this._onDocClick );
            document.addEventListener( 'keydown', this._onKey );
            document.addEventListener( 'selectionchange', this._onSel );
            window.addEventListener( 'beforeunload', ( e ) => {
                if ( this.modal === 'editor' && this.dirty ) { e.preventDefault(); e.returnValue = ''; }
            } );

            try {
                const data = await api( '/bootstrap' );
                this.user = data.user || {};
                this.isAdmin = !! this.user.isAdmin;
                this.caps = this.user.caps || {};
                this.actions = data.actions || [];
                this.categories = data.categories || [];
                this.documents = ( data.documents || [] ).map( d => this.decorate( d ) );
                this.roleLabels = data.roleLabels || {};
                this.docRoles = data.docRoles || {};
                this.settings = data.settings || null;
                this.maxUpload = data.maxUpload || 0;
            } catch ( e ) {
                console.error( 'GastroFlowX Pliki: błąd inicjalizacji', e );
                this.initError = 'Nie udało się załadować modułu: ' + ( e && e.message ? e.message : e ) + ' Spróbuj odświeżyć stronę.';
            } finally {
                this.loading = false;
            }
        },

        methods: {
            /* ---------- pomocnicze ---------- */
            decorate( d, prev ) {
                return Object.assign( {}, d, { _selected: prev ? prev._selected : false, _copies: prev ? prev._copies : 1 } );
            },
            upsert( d ) {
                const i = this.documents.findIndex( x => x.id === d.id );
                if ( i > -1 ) this.documents.splice( i, 1, this.decorate( d, this.documents[ i ] ) );
                else this.documents.push( this.decorate( d ) );
            },
            fileUrl( doc, dl ) { return fileUrl( doc, dl ); },
            isImage( doc ) { return doc.type === 'file' && doc.file && doc.file.kind === 'image'; },
            isPdf( doc ) { return doc.type === 'file' && doc.file && doc.file.kind === 'pdf'; },
            docIcon( doc ) {
                if ( this.isPdf( doc ) ) return 'fas fa-file-pdf';
                if ( this.isImage( doc ) ) return 'fas fa-file-image';
                return 'fas fa-file-lines';
            },
            typeLabel( doc ) {
                if ( this.isPdf( doc ) ) return 'PDF';
                if ( this.isImage( doc ) ) return 'Obraz';
                return 'Dokument';
            },
            categoryName( id ) {
                const c = this.categories.find( x => x.id === id );
                return c ? c.name : 'Ogólne';
            },
            countIn( id ) { return this.documents.filter( d => d.category === id ).length; },
            roleLabel( slug ) { return this.allRoles[ slug ] || FALLBACK_ROLE_LABELS[ slug ] || slug; },
            rolesLabel( list ) { return ( list || [] ).map( r => this.roleLabel( r ) ).join( ', ' ); },
            showMessage( type, text ) {
                this.message = { type, text };
                clearTimeout( this._msgTimer );
                this._msgTimer = setTimeout( () => { this.message = null; }, 6000 );
            },
            step( obj, key, delta ) {
                const v = ( parseInt( obj[ key ], 10 ) || 1 ) + delta;
                obj[ key ] = Math.max( 1, Math.min( 100, v ) );
            },
            clampCopies( obj, key ) {
                obj[ key ] = Math.max( 1, Math.min( 100, parseInt( obj[ key ], 10 ) || 1 ) );
            },
            toggleMenu( id ) { this.menuOpen = this.menuOpen === id ? null : id; },
            clearSelection() { this.documents.forEach( d => { d._selected = false; } ); },
            docToFormData( f, extra ) {
                const fd = new FormData();
                fd.append( 'title', f.title.trim() );
                fd.append( 'category', f.category );
                fd.append( 'roles', JSON.stringify( f.roles || [] ) );
                fd.append( 'note', f.note || '' );
                Object.keys( extra || {} ).forEach( k => fd.append( k, extra[ k ] ) );
                return fd;
            },

            /* ---------- kategorie ---------- */
            async addCategory() {
                const name = this.newCatName.trim();
                if ( ! name ) return;
                try {
                    const data = await api( '/categories', { method: 'POST', body: { name } } );
                    this.categories = data.categories;
                    this.newCatName = '';
                } catch ( e ) { this.showMessage( 'error', e.message ); }
            },
            async renameCategory( cat ) {
                const name = window.prompt( 'Nowa nazwa kategorii:', cat.name );
                if ( ! name || ! name.trim() || name.trim() === cat.name ) return;
                try {
                    const data = await api( '/categories/' + cat.id, { method: 'POST', body: { name: name.trim() } } );
                    this.categories = data.categories;
                } catch ( e ) { this.showMessage( 'error', e.message ); }
            },
            async deleteCategory( cat ) {
                if ( ! window.confirm( 'Usunąć kategorię „' + cat.name + '”? Dokumenty z niej trafią do „' + this.categoryName( 'cat_default' ) + '”.' ) ) return;
                try {
                    const data = await api( '/categories/' + cat.id, { method: 'DELETE' } );
                    this.categories = data.categories;
                    const prev = new Map( this.documents.map( d => [ d.id, d ] ) );
                    this.documents = ( data.documents || [] ).map( d => this.decorate( d, prev.get( d.id ) ) );
                    if ( this.activeCategory === cat.id ) this.activeCategory = 'all';
                } catch ( e ) { this.showMessage( 'error', e.message ); }
            },

            /* ---------- modale ---------- */
            closeModal() {
                if ( this.modal === 'editor' ) { this.closeEditor(); return; }
                if ( this.saving ) return;
                this.modal = null;
                this.preview = { doc: null, pages: [], loading: false, error: '', copies: 1 };
            },
            defaultCategory() {
                return this.activeCategory !== 'all' ? this.activeCategory : 'cat_default';
            },

            /* ---------- wgrywanie / edycja pliku ---------- */
            openUpload() {
                this.form = Object.assign( emptyForm(), { type: 'file', category: this.defaultCategory() } );
                this.modal = 'file';
            },
            openEditFile( doc ) {
                this.menuOpen = null;
                this.form = Object.assign( emptyForm(), { id: doc.id, type: 'file', title: doc.title, category: doc.category, roles: doc.roles.slice(), note: doc.note || '' } );
                this.modal = 'file';
            },
            onFilePick( e ) {
                this.addPicked( Array.from( e.target.files || [] ) );
                e.target.value = '';
            },
            onDrop( e ) {
                this.dragOver = false;
                this.addPicked( Array.from( ( e.dataTransfer && e.dataTransfer.files ) || [] ) );
            },
            addPicked( list ) {
                const errors = [];
                list.forEach( f => {
                    const ext = ( f.name.split( '.' ).pop() || '' ).toLowerCase();
                    if ( ACCEPT_EXT.indexOf( ext ) === -1 ) { errors.push( f.name + ' — nieobsługiwany format' ); return; }
                    if ( this.maxUpload && f.size > this.maxUpload ) { errors.push( f.name + ' — za duży (limit ' + this.maxUploadLabel + ')' ); return; }
                    if ( this.form.id ) this.form.files = [ f ];
                    else this.form.files.push( f );
                } );
                if ( ! this.form.id && this.form.files.length === 1 && ! this.form.title ) {
                    this.form.title = this.form.files[ 0 ].name.replace( /\.[^.]+$/, '' ).replace( /[_-]+/g, ' ' ).trim();
                }
                if ( errors.length ) this.showMessage( 'error', 'Pominięto: ' + errors.join( '; ' ) );
            },
            removePicked( i ) { this.form.files.splice( i, 1 ); },
            isPdfName( name ) { return /\.pdf$/i.test( name || '' ); },
            formatBytes( b ) { return formatBytes( b ); },

            async saveFileForm() {
                const f = this.form;
                if ( f.id ) {
                    if ( ! f.title.trim() ) { this.showMessage( 'error', 'Podaj nazwę dokumentu.' ); return; }
                    this.saving = true; this.savingLabel = 'Zapisywanie…';
                    try {
                        const fd = this.docToFormData( f );
                        if ( f.files[ 0 ] ) fd.append( 'file', f.files[ 0 ] );
                        const data = await api( '/documents/' + f.id, { method: 'POST', body: fd } );
                        this.upsert( data.document );
                        this.saving = false;
                        this.modal = null;
                        this.showMessage( 'success', 'Zapisano zmiany.' );
                    } catch ( e ) {
                        this.showMessage( 'error', e.message );
                    } finally { this.saving = false; }
                    return;
                }

                if ( ! f.files.length ) { this.showMessage( 'error', 'Wybierz co najmniej jeden plik.' ); return; }
                this.saving = true;
                let ok = 0;
                const errors = [];
                for ( let i = 0; i < f.files.length; i++ ) {
                    const file = f.files[ i ];
                    this.savingLabel = f.files.length > 1 ? 'Wgrywanie ' + ( i + 1 ) + '/' + f.files.length + '…' : 'Wgrywanie…';
                    try {
                        const title = f.files.length === 1 && f.title.trim() ? f.title.trim() : file.name.replace( /\.[^.]+$/, '' );
                        const fd = this.docToFormData( Object.assign( {}, f, { title } ), { type: 'file' } );
                        fd.append( 'file', file );
                        const data = await api( '/documents', { method: 'POST', body: fd } );
                        this.upsert( data.document );
                        ok++;
                    } catch ( e ) {
                        errors.push( file.name + ': ' + e.message );
                    }
                }
                this.saving = false;
                if ( ok ) this.showMessage( errors.length ? 'error' : 'success', 'Dodano dokumentów: ' + ok + ( errors.length ? '. Błędy: ' + errors.join( '; ' ) : '.' ) );
                else this.showMessage( 'error', errors.join( '; ' ) );
                if ( ! errors.length ) this.modal = null;
                else f.files = f.files.filter( file => errors.some( er => er.indexOf( file.name + ':' ) === 0 ) );
            },

            /* ---------- edytor dokumentu ---------- */
            async openEditor( doc ) {
                this.menuOpen = null;
                this.form = doc
                    ? Object.assign( emptyForm(), { id: doc.id, type: 'html', title: doc.title, category: doc.category, roles: doc.roles.slice(), note: doc.note || '', orientation: doc.orientation, printTitle: doc.printTitle } )
                    : Object.assign( emptyForm(), { category: this.defaultCategory() } );
                this.dirty = false;
                this.tablePop = false;
                this.inTable = false;
                this.modal = 'editor';
                await this.$nextTick();
                const ed = this.$refs.editor;
                if ( ed ) {
                    ed.innerHTML = doc ? cleanHtml( doc.content || '' ) : '';
                    try { document.execCommand( 'defaultParagraphSeparator', false, 'p' ); } catch ( e ) {}
                    if ( ! doc && this.$refs.titleInput ) this.$refs.titleInput.focus();
                }
            },
            closeEditor() {
                if ( this.saving ) return;
                if ( this.dirty && ! window.confirm( 'Masz niezapisane zmiany. Zamknąć edytor bez zapisywania?' ) ) return;
                this.dirty = false;
                this.modal = null;
            },
            editorEl() { return this.$refs.editor || null; },
            trackSelection() {
                const ed = this.editorEl();
                if ( ! ed || this.modal !== 'editor' ) return;
                const sel = window.getSelection();
                if ( ! sel || ! sel.rangeCount ) return;
                const r = sel.getRangeAt( 0 );
                if ( ed.contains( r.commonAncestorContainer ) ) {
                    this._range = r.cloneRange();
                    this.inTable = !! this.currentCell();
                }
            },
            restoreSelection() {
                const ed = this.editorEl();
                if ( ! ed ) return;
                ed.focus();
                const sel = window.getSelection();
                if ( this._range && ed.contains( this._range.commonAncestorContainer ) ) {
                    sel.removeAllRanges();
                    sel.addRange( this._range );
                } else if ( ! sel.rangeCount || ! ed.contains( sel.getRangeAt( 0 ).commonAncestorContainer ) ) {
                    const r = document.createRange();
                    r.selectNodeContents( ed );
                    r.collapse( false );
                    sel.removeAllRanges();
                    sel.addRange( r );
                }
            },
            exec( cmd, val ) {
                this.restoreSelection();
                try { document.execCommand( 'styleWithCSS', false, false ); } catch ( e ) {}
                document.execCommand( cmd, false, val === undefined ? null : val );
                this.normalizeBlocks();
                this.dirty = true;
                this.trackSelection();
            },
            block( tag ) { this.exec( 'formatBlock', '<' + tag + '>' ); },
            insertHtml( html ) { this.exec( 'insertHTML', html ); },
            closestInEditor( sel ) {
                const ed = this.editorEl();
                const s = window.getSelection();
                if ( ! ed || ! s || ! s.rangeCount ) return null;
                let node = s.anchorNode;
                if ( ! node ) return null;
                const el = node.nodeType === 1 ? node : node.parentElement;
                const found = el && el.closest( sel );
                return found && ed.contains( found ) ? found : null;
            },
            currentCell() { return this.closestInEditor( 'td,th' ); },
            toggleChecklist() {
                this.restoreSelection();
                let ul = this.closestInEditor( 'ul' );
                if ( ul ) {
                    ul.classList.toggle( 'gp-check' );
                } else {
                    document.execCommand( 'insertUnorderedList', false, null );
                    ul = this.closestInEditor( 'ul' );
                    if ( ul ) ul.classList.add( 'gp-check' );
                }
                this.normalizeBlocks();
                this.dirty = true;
            },
            topBlock() {
                const ed = this.editorEl();
                const s = window.getSelection();
                if ( ! ed || ! s || ! s.rangeCount ) return null;
                let n = s.anchorNode;
                while ( n && n.parentNode !== ed ) n = n.parentNode;
                return n && n.parentNode === ed ? n : null;
            },
            normalizeBlocks() {
                const ed = this.editorEl();
                if ( ! ed ) return;
                const bad = Array.from( ed.querySelectorAll( 'p' ) ).filter( p => p.querySelector( 'ul,ol,table,h1,h2,h3,h4,div,hr' ) );
                if ( ! bad.length ) return;
                const sel = window.getSelection();
                const an = sel && sel.rangeCount ? sel.anchorNode : null;
                const ao = sel && sel.rangeCount ? sel.anchorOffset : 0;
                bad.forEach( p => {
                    if ( ! p.querySelector( 'ul,ol,table,h1,h2,h3,h4,div,hr' ) ) return;
                    const parent = p.parentNode;
                    let buf = null;
                    Array.from( p.childNodes ).forEach( ch => {
                        if ( ch.nodeType === 1 && /^(UL|OL|TABLE|H[1-4]|DIV|HR)$/.test( ch.tagName ) ) {
                            buf = null;
                            parent.insertBefore( ch, p );
                        } else {
                            if ( ch.nodeType === 3 && ! ch.textContent.trim() && ! buf ) return;
                            if ( ! buf ) { buf = document.createElement( 'p' ); parent.insertBefore( buf, p ); }
                            buf.appendChild( ch );
                        }
                    } );
                    p.remove();
                } );
                if ( an && an.isConnected && ed.contains( an ) ) {
                    try {
                        const r = document.createRange();
                        r.setStart( an, Math.min( ao, an.nodeType === 3 ? an.length : an.childNodes.length ) );
                        r.collapse( true );
                        sel.removeAllRanges(); sel.addRange( r );
                        this._range = r.cloneRange();
                    } catch ( e ) {}
                }
            },
            insertTable() {
                const rows = Math.max( 1, Math.min( 60, parseInt( this.tableRows, 10 ) || 1 ) );
                const cols = Math.max( 1, Math.min( 12, parseInt( this.tableCols, 10 ) || 1 ) );
                const table = document.createElement( 'table' );
                table.className = 'gp-table';
                if ( this.tableHeader ) {
                    const tr = table.createTHead().insertRow();
                    for ( let c = 0; c < cols; c++ ) { const th = document.createElement( 'th' ); th.textContent = 'Kolumna ' + ( c + 1 ); tr.appendChild( th ); }
                }
                const tb = table.createTBody();
                for ( let r = 0; r < rows; r++ ) {
                    const tr = tb.insertRow();
                    for ( let c = 0; c < cols; c++ ) tr.insertCell().innerHTML = '<br>';
                }
                this.tablePop = false;
                if ( ! this.insertBlockNode( table ) ) return;
                this.placeCaret( tb.rows[ 0 ].cells[ 0 ] );
                this.inTable = true;
            },
            insertBlockNode( node ) {
                this.restoreSelection();
                const ed = this.editorEl();
                if ( ! ed ) return false;
                const table = node;
                const blk = this.closestInEditor( 'td,th' ) ? this.closestInEditor( 'table' ) : this.topBlock();
                let target = blk;
                while ( target && target.parentNode !== ed ) target = target.parentNode;
                if ( target && target.tagName === 'P' && ! target.textContent.trim() && ! target.querySelector( 'img' ) ) {
                    ed.replaceChild( table, target );
                } else if ( target ) {
                    ed.insertBefore( table, target.nextSibling );
                } else {
                    ed.appendChild( table );
                }
                if ( ! table.nextElementSibling ) {
                    const p = document.createElement( 'p' ); p.innerHTML = '<br>';
                    ed.appendChild( p );
                }
                this.dirty = true;
                return true;
            },
            insertSignatures() {
                const wrap = document.createElement( 'div' );
                wrap.innerHTML = '<table class="gp-table gp-noborder gp-sign"><tbody><tr><td>Podpis<br><span class="gp-blank">&nbsp;</span></td><td>Podpis managera<br><span class="gp-blank">&nbsp;</span></td></tr></tbody></table>';
                const t = wrap.firstChild;
                if ( this.insertBlockNode( t ) ) {
                    const next = t.nextElementSibling;
                    if ( next ) this.placeCaret( next );
                }
            },
            placeCaret( el ) {
                const r = document.createRange();
                r.selectNodeContents( el );
                r.collapse( false );
                const s = window.getSelection();
                s.removeAllRanges();
                s.addRange( r );
                this._range = r.cloneRange();
            },
            tableOp( op ) {
                this.restoreSelection();
                const cell = this.currentCell();
                if ( ! cell ) return;
                const tr = cell.parentElement;
                const table = cell.closest( 'table' );
                const idx = cell.cellIndex;
                if ( op === 'row' ) {
                    const n = Array.from( tr.cells ).reduce( ( s, c ) => s + ( c.colSpan || 1 ), 0 );
                    const nr = document.createElement( 'tr' );
                    for ( let i = 0; i < n; i++ ) { const td = document.createElement( 'td' ); td.innerHTML = '<br>'; nr.appendChild( td ); }
                    if ( tr.parentElement.tagName === 'THEAD' ) {
                        let tb = table.tBodies[ 0 ];
                        if ( ! tb ) { tb = document.createElement( 'tbody' ); table.appendChild( tb ); }
                        tb.insertBefore( nr, tb.firstChild );
                    } else {
                        tr.parentNode.insertBefore( nr, tr.nextSibling );
                    }
                    this.placeCaret( nr.cells[ Math.min( idx, nr.cells.length - 1 ) ] );
                } else if ( op === 'col' ) {
                    Array.from( table.rows ).forEach( row => {
                        const ref = row.cells[ idx ];
                        const nc = document.createElement( ref ? ref.tagName.toLowerCase() : 'td' );
                        nc.innerHTML = '<br>';
                        if ( ref ) row.insertBefore( nc, ref.nextSibling ); else row.appendChild( nc );
                    } );
                } else if ( op === 'delrow' ) {
                    tr.remove();
                    if ( ! table.rows.length ) table.remove();
                } else if ( op === 'delcol' ) {
                    Array.from( table.rows ).forEach( row => { if ( row.cells[ idx ] ) row.cells[ idx ].remove(); } );
                    if ( ! table.querySelector( 'td,th' ) ) table.remove();
                } else if ( op === 'deltable' ) {
                    table.remove();
                }
                this.dirty = true;
                this.inTable = !! this.currentCell();
            },
            onEditorKey( e ) {
                if ( e.key !== 'Tab' ) return;
                const cell = this.currentCell();
                if ( ! cell ) return;
                e.preventDefault();
                const table = cell.closest( 'table' );
                let cells = Array.from( table.querySelectorAll( 'td,th' ) );
                let i = cells.indexOf( cell ) + ( e.shiftKey ? -1 : 1 );
                if ( i >= cells.length ) {
                    this.tableOp( 'row' );
                    return;
                }
                if ( i < 0 ) i = 0;
                this.placeCaret( cells[ i ] );
            },
            onPaste( e ) {
                const cd = e.clipboardData;
                if ( ! cd ) return;
                e.preventDefault();
                const html = cd.getData( 'text/html' );
                const text = cd.getData( 'text/plain' );
                let out;
                if ( html ) {
                    out = cleanHtml( html );
                    out = out.replace( /<table>/g, '<table class="gp-table">' );
                } else {
                    const lines = String( text || '' ).split( /\r?\n/ );
                    out = lines.length > 1
                        ? lines.map( l => '<p>' + ( escapeHtml( l ) || '<br>' ) + '</p>' ).join( '' )
                        : escapeHtml( text );
                }
                document.execCommand( 'insertHTML', false, out );
                this.dirty = true;
            },
            async saveEditor() {
                const f = this.form;
                if ( ! f.title.trim() ) { this.showMessage( 'error', 'Podaj nazwę dokumentu.' ); if ( this.$refs.titleInput ) this.$refs.titleInput.focus(); return; }
                const content = cleanHtml( this.$refs.editor ? this.$refs.editor.innerHTML : '' );
                this.saving = true;
                try {
                    const fd = this.docToFormData( f, { type: 'html', orientation: f.orientation, printTitle: f.printTitle ? '1' : '0', content } );
                    const data = await api( f.id ? '/documents/' + f.id : '/documents', { method: 'POST', body: fd } );
                    this.upsert( data.document );
                    this.dirty = false;
                    this.saving = false;
                    this.modal = null;
                    this.showMessage( 'success', f.id ? 'Zapisano zmiany w dokumencie.' : 'Dodano dokument „' + data.document.title + '”.' );
                } catch ( e ) {
                    this.showMessage( 'error', e.message );
                } finally { this.saving = false; }
            },

            /* ---------- akcje na dokumentach ---------- */
            editDoc( doc ) {
                this.menuOpen = null;
                if ( this.modal === 'preview' ) { this.modal = null; }
                if ( doc.type === 'html' ) this.openEditor( doc ); else this.openEditFile( doc );
            },
            async duplicateDoc( doc ) {
                this.menuOpen = null;
                try {
                    const data = await api( '/documents/' + doc.id + '/duplicate', { method: 'POST' } );
                    this.upsert( data.document );
                    this.showMessage( 'success', 'Utworzono kopię: „' + data.document.title + '”.' );
                } catch ( e ) { this.showMessage( 'error', e.message ); }
            },
            async deleteDoc( doc ) {
                this.menuOpen = null;
                if ( ! window.confirm( 'Usunąć dokument „' + doc.title + '”? Tej operacji nie można cofnąć.' ) ) return;
                try {
                    await api( '/documents/' + doc.id, { method: 'DELETE' } );
                    this.documents = this.documents.filter( d => d.id !== doc.id );
                    if ( this.modal === 'preview' ) this.closeModal();
                    this.showMessage( 'success', 'Usunięto dokument.' );
                } catch ( e ) { this.showMessage( 'error', e.message ); }
            },

            /* ---------- podgląd ---------- */
            async openPreview( doc ) {
                this.menuOpen = null;
                this.preview = { doc, pages: [], loading: false, error: '', copies: doc._copies || 1 };
                this.modal = 'preview';
                if ( this.isPdf( doc ) ) {
                    this.preview.loading = true;
                    try {
                        await renderPdfPages( doc, 1.5, p => {
                            if ( this.preview.doc && this.preview.doc.id === doc.id ) this.preview.pages.push( p );
                        } );
                    } catch ( e ) {
                        this.preview.error = e.message;
                    } finally {
                        this.preview.loading = false;
                    }
                }
            },
            previewHtml( doc ) { return cleanHtml( doc.content || '' ); },

            /* ---------- druk ---------- */
            async printOne( doc, copies ) {
                await this.runPrint( [ { doc, copies: copies || doc._copies || 1 } ] );
            },
            async printSelected() {
                // Kolejność wydruku = kolejność na liście (widoczne najpierw, potem zaznaczone w innych kategoriach).
                const visible = this.filteredDocs.filter( d => d._selected );
                const hidden = this.selectedDocs.filter( d => visible.indexOf( d ) === -1 );
                const items = visible.concat( hidden ).map( d => ( { doc: d, copies: d._copies || 1 } ) );
                if ( ! items.length ) { this.showMessage( 'error', 'Zaznacz co najmniej jeden dokument.' ); return; }
                await this.runPrint( items );
            },
            async runPrint( items ) {
                if ( this.printing ) return;
                this.printing = true;
                this.printStatus = 'Przygotowywanie wydruku…';
                try {
                    const cache = await preparePrint( items, s => { this.printStatus = s; } );
                    this.printStatus = 'Wysyłanie do drukarki…';
                    const title = items.length === 1 ? items[ 0 ].doc.title : 'Pliki GastroFlowX — wydruk';
                    if ( isMobileDevice() ) await printViaMainWindow( items, cache );
                    else await printViaIframe( items, cache, title );
                    this.printStatus = '';
                } catch ( e ) {
                    this.printStatus = '';
                    this.showMessage( 'error', 'Błąd przygotowania wydruku: ' + e.message );
                } finally {
                    this.printing = false;
                }
            },

            /* ---------- ustawienia (administrator) ---------- */
            docCan( doc ) {
                return ( doc && doc.can ) || {};
            },
            openSettings() {
                const src = this.settings || {};
                const form = {};
                this.actions.forEach( a => { form[ a.key ] = ( src[ a.key ] || [] ).slice(); } );
                this.settingsForm = form;
                this.modal = 'settings';
            },
            hasPerm( action, slug ) {
                return !! ( this.settingsForm[ action ] && this.settingsForm[ action ].includes( slug ) );
            },
            setPerm( action, slug, on ) {
                const arr = this.settingsForm[ action ];
                if ( ! arr ) return;
                const i = arr.indexOf( slug );
                if ( on && i === -1 ) arr.push( slug );
                if ( ! on && i > -1 ) arr.splice( i, 1 );
            },
            /* Zależności: każde uprawnienie wymaga „view”; „wszystkie” obejmuje „własne”. */
            togglePerm( action, slug ) {
                const on = ! this.hasPerm( action, slug );
                this.setPerm( action, slug, on );
                const implies = { edit_all: 'edit_own', delete_all: 'delete_own' };
                const impliedBy = { edit_own: 'edit_all', delete_own: 'delete_all' };
                if ( on ) {
                    if ( action !== 'view' ) this.setPerm( 'view', slug, true );
                    if ( implies[ action ] ) this.setPerm( implies[ action ], slug, true );
                } else {
                    if ( action === 'view' ) Object.keys( this.settingsForm ).forEach( k => this.setPerm( k, slug, false ) );
                    if ( impliedBy[ action ] ) this.setPerm( impliedBy[ action ], slug, false );
                }
            },
            permCount( action ) {
                return ( this.settingsForm[ action ] || [] ).length;
            },
            async saveSettings() {
                this.saving = true;
                try {
                    const data = await api( '/settings', { method: 'POST', body: { perms: this.settingsForm } } );
                    this.settings = data.settings;
                    this.docRoles = data.docRoles || {};
                    this.saving = false;
                    this.modal = null;
                    this.showMessage( 'success', 'Zapisano uprawnienia.' );
                } catch ( e ) {
                    this.showMessage( 'error', e.message );
                } finally { this.saving = false; }
            },
        },

        template: `
<div class="gp-app">
    <div v-if="loading" class="gp-loading">Ładowanie panelu…</div>
    <template v-else>
        <div v-if="initError" class="gp-msg gp-msg-error">{{ initError }}</div>
        <div v-if="message && !modal" class="gp-msg" :class="message.type === 'success' ? 'gp-msg-success' : 'gp-msg-error'" role="status">{{ message.text }}</div>

        <!-- NAGŁÓWEK -->
        <div class="gp-head">
            <div class="gp-head-title">
                <h2><i class="fas fa-folder-open"></i> Pliki</h2>
                <p>Wzory raportów, listy obowiązków i inne dokumenty do druku.</p>
            </div>
            <div v-if="caps.create || caps.upload || isAdmin" class="gp-head-actions">
                <button v-if="caps.create" type="button" class="gp-btn gp-btn-primary" @click="openEditor()"><i class="fas fa-pen-to-square"></i> Nowy dokument</button>
                <button v-if="caps.upload" type="button" class="gp-btn" :class="caps.create ? 'gp-btn-secondary' : 'gp-btn-primary'" @click="openUpload()"><i class="fas fa-upload"></i> Wgraj plik</button>
                <button v-if="isAdmin" type="button" class="gp-btn gp-btn-secondary gp-btn-icon" @click="openSettings" title="Uprawnienia" aria-label="Uprawnienia"><i class="fas fa-gear"></i></button>
            </div>
        </div>

        <!-- SZUKAJ / SORTUJ -->
        <div class="gp-toolbar">
            <label class="gp-search">
                <i class="fas fa-magnifying-glass"></i>
                <input v-model="search" type="search" placeholder="Szukaj dokumentu…" aria-label="Szukaj dokumentu">
            </label>
            <select v-model="sort" class="gp-select gp-select-sm" aria-label="Sortowanie">
                <option value="title">Nazwa A–Z</option>
                <option value="updated">Ostatnio zmienione</option>
            </select>
        </div>

        <!-- KATEGORIE -->
        <div class="gp-cat-bar">
            <button type="button" class="gp-cat-chip" :class="{active: activeCategory === 'all'}" @click="activeCategory = 'all'">
                Wszystkie <span class="gp-cat-count">{{ documents.length }}</span>
            </button>
            <div v-for="cat in categories" :key="cat.id" class="gp-cat-chip" :class="{active: activeCategory === cat.id}">
                <span class="gp-cat-name" role="button" tabindex="0" @click="activeCategory = cat.id" @keyup.enter="activeCategory = cat.id">
                    {{ cat.name }} <span class="gp-cat-count">{{ countIn(cat.id) }}</span>
                </span>
                <template v-if="manageCats">
                    <button type="button" class="gp-cat-act" title="Zmień nazwę" @click.stop="renameCategory(cat)"><i class="fas fa-pen"></i></button>
                    <button v-if="cat.id !== 'cat_default'" type="button" class="gp-cat-act gp-cat-del" title="Usuń kategorię" @click.stop="deleteCategory(cat)"><i class="fas fa-xmark"></i></button>
                </template>
            </div>
            <div v-if="caps.categories && manageCats" class="gp-cat-add">
                <input type="text" v-model="newCatName" @keyup.enter="addCategory" placeholder="Nowa kategoria…" maxlength="60">
                <button type="button" @click="addCategory"><i class="fas fa-plus"></i> Dodaj</button>
            </div>
            <button v-if="caps.categories" type="button" class="gp-cat-manage" :class="{active: manageCats}" @click="manageCats = !manageCats">
                <i :class="manageCats ? 'fas fa-check' : 'fas fa-sliders'"></i> {{ manageCats ? 'Gotowe' : 'Kategorie' }}
            </button>
        </div>

        <!-- PANEL DRUKU GRUPOWEGO -->
        <div v-if="selectedDocs.length" class="gp-print-bar">
            <div class="gp-print-bar-label">
                <i class="fas fa-print"></i>
                <span>Zaznaczone: {{ selectedDocs.length }} <span class="gp-muted">· kopii łącznie: {{ selectedCopies }}</span></span>
                <span v-if="printStatus" class="gp-print-status">{{ printStatus }}</span>
            </div>
            <div class="gp-print-bar-actions">
                <button type="button" class="gp-btn gp-btn-secondary" @click="clearSelection">Odznacz</button>
                <button type="button" class="gp-btn gp-btn-primary" :disabled="printing" @click="printSelected">
                    <i class="fas fa-print"></i> {{ printing ? 'Przygotowywanie…' : 'Drukuj zaznaczone' }}
                </button>
            </div>
        </div>
        <div v-else-if="printStatus" class="gp-status-line"><i class="fas fa-spinner fa-spin"></i> {{ printStatus }}</div>

        <!-- LISTA -->
        <div v-if="!filteredDocs.length" class="gp-empty">
            <i class="fas fa-folder-open"></i>
            <p v-if="search">Brak dokumentów pasujących do „{{ search }}”.</p>
            <p v-else-if="caps.create && caps.upload">Ta kategoria jest pusta. Utwórz dokument w edytorze albo wgraj PDF lub zdjęcie.</p>
            <p v-else-if="caps.create">Ta kategoria jest pusta. Utwórz dokument w edytorze.</p>
            <p v-else-if="caps.upload">Ta kategoria jest pusta. Wgraj PDF lub zdjęcie.</p>
            <p v-else>Brak dokumentów w tej kategorii.</p>
            <div v-if="(caps.create || caps.upload) && !search" class="gp-empty-actions">
                <button v-if="caps.create" type="button" class="gp-btn gp-btn-primary" @click="openEditor()"><i class="fas fa-pen-to-square"></i> Nowy dokument</button>
                <button v-if="caps.upload" type="button" class="gp-btn" :class="caps.create ? 'gp-btn-secondary' : 'gp-btn-primary'" @click="openUpload()"><i class="fas fa-upload"></i> Wgraj plik</button>
            </div>
        </div>

        <div v-else class="gp-list">
            <div v-for="doc in filteredDocs" :key="doc.id" class="gp-row" :class="{'is-selected': doc._selected}">
                <label class="gp-row-check" :title="'Zaznacz do druku: ' + doc.title">
                    <input type="checkbox" v-model="doc._selected">
                </label>
                <button type="button" class="gp-thumb" @click="openPreview(doc)" :aria-label="'Podgląd: ' + doc.title">
                    <img v-if="isImage(doc)" :src="fileUrl(doc)" loading="lazy" alt="">
                    <i v-else :class="docIcon(doc)"></i>
                </button>
                <div class="gp-row-main">
                    <button type="button" class="gp-row-title" @click="openPreview(doc)">{{ doc.title }}</button>
                    <div class="gp-row-meta">
                        <span class="gp-badge">{{ categoryName(doc.category) }}</span>
                        <span class="gp-badge gp-badge-soft">{{ typeLabel(doc) }}<template v-if="doc.type === 'html' && doc.orientation === 'landscape'"> · poziomo</template></span>
                        <span v-if="doc.roles.length" class="gp-badge gp-badge-lock" :title="'Widoczne dla: ' + rolesLabel(doc.roles)"><i class="fas fa-lock"></i> {{ rolesLabel(doc.roles) }}</span>
                        <span class="gp-row-date">{{ doc.updated }}</span>
                    </div>
                    <div v-if="doc.note" class="gp-row-note">{{ doc.note }}</div>
                </div>
                <div class="gp-row-actions">
                    <div class="gp-stepper" title="Liczba kopii">
                        <button type="button" @click="step(doc, '_copies', -1)" aria-label="Mniej kopii"><i class="fas fa-minus"></i></button>
                        <input type="number" min="1" max="100" v-model.number="doc._copies" @change="clampCopies(doc, '_copies')" aria-label="Liczba kopii">
                        <button type="button" @click="step(doc, '_copies', 1)" aria-label="Więcej kopii"><i class="fas fa-plus"></i></button>
                    </div>
                    <button type="button" class="gp-btn gp-btn-secondary" @click="openPreview(doc)"><i class="fas fa-eye"></i><span>Podgląd</span></button>
                    <button type="button" class="gp-btn gp-btn-primary" :disabled="printing" @click="printOne(doc)"><i class="fas fa-print"></i><span>Drukuj</span></button>
                    <div v-if="docCan(doc).edit || docCan(doc).duplicate || docCan(doc).delete || docCan(doc).download" class="gp-menu-wrap" @click.stop>
                        <button type="button" class="gp-btn gp-btn-secondary gp-btn-icon" @click="toggleMenu(doc.id)" aria-label="Więcej akcji"><i class="fas fa-ellipsis-vertical"></i></button>
                        <div v-if="menuOpen === doc.id" class="gp-menu">
                            <button v-if="docCan(doc).edit" type="button" @click="editDoc(doc)"><i class="fas fa-pen"></i> Edytuj</button>
                            <button v-if="docCan(doc).duplicate" type="button" @click="duplicateDoc(doc)"><i class="fas fa-copy"></i> Duplikuj</button>
                            <a v-if="docCan(doc).download" :href="fileUrl(doc, true)" @click="menuOpen = null"><i class="fas fa-download"></i> Pobierz plik</a>
                            <button v-if="docCan(doc).delete" type="button" class="gp-menu-danger" @click="deleteDoc(doc)"><i class="fas fa-trash-can"></i> Usuń</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <Teleport to="body">
    <div v-if="modal" class="gfx-pliki-root gp-layer">

        <div v-if="message" class="gp-toast" :class="message.type === 'success' ? 'gp-msg-success' : 'gp-msg-error'" role="status">{{ message.text }}</div>

        <!-- ================= PLIK: WGRAJ / EDYTUJ ================= -->
        <div v-if="modal === 'file'" class="gp-overlay" @click.self="closeModal">
            <div class="gp-modal" role="dialog" aria-modal="true">
                <div class="gp-modal-head">
                    <h3>{{ form.id ? 'Edytuj plik' : 'Wgraj pliki' }}</h3>
                    <button type="button" class="gp-close" @click="closeModal" aria-label="Zamknij"><i class="fas fa-xmark"></i></button>
                </div>
                <div class="gp-modal-body">
                    <label v-if="!form.id || caps.upload" class="gp-drop" :class="{over: dragOver}" @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="onDrop">
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" :multiple="!form.id" @change="onFilePick" hidden>
                        <i class="fas fa-cloud-arrow-up"></i>
                        <strong>{{ form.id ? 'Podmień plik (opcjonalnie)' : 'Wybierz pliki lub upuść je tutaj' }}</strong>
                        <span>PDF, JPG, PNG lub WEBP · do {{ maxUploadLabel }} na plik</span>
                    </label>
                    <ul v-if="form.files.length" class="gp-file-list">
                        <li v-for="(f, i) in form.files" :key="f.name + i">
                            <i :class="isPdfName(f.name) ? 'fas fa-file-pdf' : 'fas fa-file-image'"></i>
                            <span class="gp-file-name">{{ f.name }}</span>
                            <span class="gp-muted">{{ formatBytes(f.size) }}</span>
                            <button type="button" @click="removePicked(i)" aria-label="Usuń z listy"><i class="fas fa-xmark"></i></button>
                        </li>
                    </ul>

                    <div v-if="form.id || form.files.length <= 1" class="gp-field">
                        <label>Nazwa dokumentu</label>
                        <input v-model="form.title" type="text" class="gp-input" maxlength="200" placeholder="np. Raport utargu dziennego">
                    </div>
                    <p v-else class="gp-hint">Każdy plik zostanie dodany jako osobny dokument, z nazwą pliku jako tytułem.</p>

                    <div class="gp-field">
                        <label>Kategoria</label>
                        <select v-model="form.category" class="gp-select">
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                    </div>
                    <div class="gp-field">
                        <label>Notatka dla zespołu <span class="gp-muted">(opcjonalnie)</span></label>
                        <textarea v-model="form.note" class="gp-input" rows="2" maxlength="1000" placeholder="np. Drukuj dwustronnie, oddaj managerowi po zmianie"></textarea>
                    </div>
                    <div class="gp-field">
                        <label>Kto widzi dokument</label>
                        <roles-picker v-model="form.roles" :roles="docRoles"></roles-picker>
                    </div>
                </div>
                <div class="gp-modal-foot">
                    <button type="button" class="gp-btn gp-btn-secondary" :disabled="saving" @click="closeModal">Anuluj</button>
                    <button type="button" class="gp-btn gp-btn-primary" :disabled="saving" @click="saveFileForm">
                        <i class="fas fa-floppy-disk"></i> {{ saving ? savingLabel : (form.id ? 'Zapisz zmiany' : 'Wgraj') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ================= EDYTOR DOKUMENTU ================= -->
        <div v-if="modal === 'editor'" class="gp-overlay gp-overlay-full">
            <div class="gp-editor" role="dialog" aria-modal="true">
                <div class="gp-editor-head">
                    <button type="button" class="gp-close" @click="closeEditor" aria-label="Zamknij edytor"><i class="fas fa-arrow-left"></i></button>
                    <input ref="titleInput" v-model="form.title" @input="dirty = true" type="text" class="gp-editor-title" maxlength="200" placeholder="Nazwa dokumentu, np. Raport utargu">
                    <button type="button" class="gp-btn gp-btn-primary" :disabled="saving" @click="saveEditor">
                        <i class="fas fa-floppy-disk"></i><span>{{ saving ? 'Zapisywanie…' : 'Zapisz' }}</span>
                    </button>
                </div>

                <details class="gp-editor-meta">
                    <summary><i class="fas fa-sliders"></i> Ustawienia dokumentu <span class="gp-muted">· {{ categoryName(form.category) }} · {{ form.orientation === 'landscape' ? 'poziomo' : 'pionowo' }}<template v-if="form.roles.length"> · <i class="fas fa-lock"></i> {{ rolesLabel(form.roles) }}</template></span></summary>
                    <div class="gp-editor-meta-grid">
                        <div class="gp-field">
                            <label>Kategoria</label>
                            <select v-model="form.category" @change="dirty = true" class="gp-select">
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                        </div>
                        <div class="gp-field">
                            <label>Orientacja strony</label>
                            <div class="gp-seg">
                                <button type="button" :class="{active: form.orientation === 'portrait'}" @click="form.orientation = 'portrait'; dirty = true"><i class="fas fa-file"></i> Pionowo</button>
                                <button type="button" :class="{active: form.orientation === 'landscape'}" @click="form.orientation = 'landscape'; dirty = true"><i class="fas fa-file fa-rotate-90"></i> Poziomo</button>
                            </div>
                        </div>
                        <div class="gp-field gp-field-wide">
                            <label class="gp-checkline"><input type="checkbox" v-model="form.printTitle" @change="dirty = true"> Drukuj nazwę dokumentu jako nagłówek</label>
                        </div>
                        <div class="gp-field gp-field-wide">
                            <label>Notatka dla zespołu <span class="gp-muted">(widoczna na liście, nie drukuje się)</span></label>
                            <input v-model="form.note" @input="dirty = true" type="text" class="gp-input" maxlength="1000" placeholder="np. Wypełnij na koniec zmiany">
                        </div>
                        <div class="gp-field gp-field-wide">
                            <label>Kto widzi dokument</label>
                            <roles-picker v-model="form.roles" :roles="docRoles" @update:model-value="dirty = true"></roles-picker>
                        </div>
                    </div>
                </details>

                <div class="gp-tb">
                    <template v-for="(t, i) in tools" :key="i">
                        <span v-if="t.sep" class="gp-tb-sep"></span>
                        <button v-else type="button" class="gp-tb-btn" :class="{'has-label': t.l && t.i, active: t.l === 'Tabela' && tablePop}" :title="t.t" :aria-label="t.t" @mousedown.prevent @click="t.a()">
                            <i v-if="t.i" :class="'fas ' + t.i"></i><span v-if="t.l">{{ t.l }}</span>
                        </button>
                    </template>
                </div>
                <div v-if="tablePop" class="gp-tb-pop">
                    <label>Wiersze <input type="number" min="1" max="60" v-model.number="tableRows"></label>
                    <label>Kolumny <input type="number" min="1" max="12" v-model.number="tableCols"></label>
                    <label class="gp-checkline"><input type="checkbox" v-model="tableHeader"> Wiersz nagłówka</label>
                    <button type="button" class="gp-btn gp-btn-primary" @click="insertTable"><i class="fas fa-table"></i> Wstaw tabelę</button>
                </div>
                <div v-if="inTable" class="gp-tb gp-tb-table">
                    <span class="gp-tb-label"><i class="fas fa-table"></i> Tabela:</span>
                    <button type="button" class="gp-tb-btn has-label" @mousedown.prevent @click="tableOp('row')"><i class="fas fa-plus"></i><span>Wiersz</span></button>
                    <button type="button" class="gp-tb-btn has-label" @mousedown.prevent @click="tableOp('col')"><i class="fas fa-plus"></i><span>Kolumna</span></button>
                    <button type="button" class="gp-tb-btn has-label" @mousedown.prevent @click="tableOp('delrow')"><i class="fas fa-minus"></i><span>Wiersz</span></button>
                    <button type="button" class="gp-tb-btn has-label" @mousedown.prevent @click="tableOp('delcol')"><i class="fas fa-minus"></i><span>Kolumna</span></button>
                    <button type="button" class="gp-tb-btn has-label gp-tb-danger" @mousedown.prevent @click="tableOp('deltable')"><i class="fas fa-trash-can"></i><span>Usuń tabelę</span></button>
                    <span class="gp-tb-hint">Tab — następna komórka</span>
                </div>

                <div class="gp-editor-canvas">
                    <div class="gp-sheet" :class="{'is-land': form.orientation === 'landscape'}">
                        <div v-if="form.printTitle" class="gp-doc"><div class="gp-doc-title" :class="{'is-placeholder': !form.title}">{{ form.title || 'Nazwa dokumentu' }}</div></div>
                        <div ref="editor" class="gp-doc gp-editable" contenteditable="true" spellcheck="true"
                             data-placeholder="Zacznij pisać lub wstaw tabelę, listę zadań, pola do wpisania…"
                             @input="dirty = true" @paste="onPaste" @keydown="onEditorKey"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= PODGLĄD ================= -->
        <div v-if="modal === 'preview' && preview.doc" class="gp-overlay gp-overlay-full" @click.self="closeModal">
            <div class="gp-preview" role="dialog" aria-modal="true">
                <div class="gp-editor-head">
                    <button type="button" class="gp-close" @click="closeModal" aria-label="Zamknij podgląd"><i class="fas fa-arrow-left"></i></button>
                    <div class="gp-preview-title">
                        <strong>{{ preview.doc.title }}</strong>
                        <span class="gp-muted">{{ categoryName(preview.doc.category) }} · {{ typeLabel(preview.doc) }}</span>
                    </div>
                    <button v-if="docCan(preview.doc).edit" type="button" class="gp-btn gp-btn-secondary" @click="editDoc(preview.doc)"><i class="fas fa-pen"></i><span>Edytuj</span></button>
                </div>
                <div class="gp-editor-canvas">
                    <p v-if="preview.doc.note" class="gp-preview-note"><i class="fas fa-circle-info"></i> {{ preview.doc.note }}</p>

                    <div v-if="preview.doc.type === 'html'" class="gp-sheet" :class="{'is-land': preview.doc.orientation === 'landscape'}">
                        <div class="gp-doc">
                            <div v-if="preview.doc.printTitle" class="gp-doc-title">{{ preview.doc.title }}</div>
                            <div v-html="previewHtml(preview.doc)"></div>
                        </div>
                    </div>

                    <div v-else-if="isImage(preview.doc)" class="gp-sheet gp-sheet-media">
                        <img :src="fileUrl(preview.doc)" alt="">
                    </div>

                    <template v-else>
                        <div v-for="(p, i) in preview.pages" :key="i" class="gp-sheet gp-sheet-media" :class="{'is-land': p.landscape}">
                            <img :src="p.src" :alt="'Strona ' + (i + 1)">
                        </div>
                        <div v-if="preview.loading" class="gp-status-line"><i class="fas fa-spinner fa-spin"></i> Wczytywanie stron…</div>
                        <div v-if="preview.error" class="gp-msg gp-msg-error">{{ preview.error }}</div>
                    </template>
                </div>
                <div class="gp-preview-foot">
                    <div class="gp-stepper" title="Liczba kopii">
                        <button type="button" @click="step(preview, 'copies', -1)" aria-label="Mniej kopii"><i class="fas fa-minus"></i></button>
                        <input type="number" min="1" max="100" v-model.number="preview.copies" @change="clampCopies(preview, 'copies')" aria-label="Liczba kopii">
                        <button type="button" @click="step(preview, 'copies', 1)" aria-label="Więcej kopii"><i class="fas fa-plus"></i></button>
                    </div>
                    <span v-if="printStatus" class="gp-print-status">{{ printStatus }}</span>
                    <a v-if="preview.doc.type === 'file'" class="gp-btn gp-btn-secondary" :href="fileUrl(preview.doc, true)"><i class="fas fa-download"></i><span>Pobierz</span></a>
                    <button type="button" class="gp-btn gp-btn-primary" :disabled="printing" @click="printOne(preview.doc, preview.copies)">
                        <i class="fas fa-print"></i> {{ printing ? 'Przygotowywanie…' : 'Drukuj' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ================= USTAWIENIA DOSTĘPU ================= -->
        <div v-if="modal === 'settings'" class="gp-overlay" @click.self="closeModal">
            <div class="gp-modal" role="dialog" aria-modal="true">
                <div class="gp-modal-head">
                    <h3>Uprawnienia</h3>
                    <button type="button" class="gp-close" @click="closeModal" aria-label="Zamknij"><i class="fas fa-xmark"></i></button>
                </div>
                <div class="gp-modal-body">
                    <p class="gp-hint" style="margin-top:0;">Zaznacz, które role mogą wykonywać daną czynność. <strong>Administrator</strong> ma zawsze wszystkie uprawnienia i jako jedyny zmienia te ustawienia.</p>
                    <div v-for="(a, i) in actions" :key="a.key" class="gp-perm">
                        <div v-if="i === 0 || actions[i - 1].group !== a.group" class="gp-perm-group">{{ a.group }}</div>
                        <div class="gp-perm-head">
                            <strong>{{ a.label }}</strong>
                            <span class="gp-perm-count">{{ permCount(a.key) ? permCount(a.key) + ' ' + (permCount(a.key) === 1 ? 'rola' : (permCount(a.key) < 5 ? 'role' : 'ról')) : 'tylko administrator' }}</span>
                        </div>
                        <p class="gp-perm-desc">{{ a.desc }}</p>
                        <div class="gp-perm-roles">
                            <button v-for="(label, slug) in allRoles" :key="slug" type="button" class="gp-perm-chip" :class="{on: hasPerm(a.key, slug)}" :aria-pressed="hasPerm(a.key, slug) ? 'true' : 'false'" @click="togglePerm(a.key, slug)">
                                <i :class="hasPerm(a.key, slug) ? 'fas fa-check' : 'fas fa-plus'"></i> {{ label }}
                            </button>
                        </div>
                    </div>
                    <p class="gp-hint">Nadanie dowolnego uprawnienia daje też dostęp do modułu. „Wszystkie dokumenty” obejmuje „własne”. Odebranie dostępu do modułu odbiera roli wszystkie uprawnienia.</p>
                </div>
                <div class="gp-modal-foot">
                    <button type="button" class="gp-btn gp-btn-secondary" :disabled="saving" @click="closeModal">Anuluj</button>
                    <button type="button" class="gp-btn gp-btn-primary" :disabled="saving" @click="saveSettings"><i class="fas fa-floppy-disk"></i> {{ saving ? 'Zapisywanie…' : 'Zapisz' }}</button>
                </div>
            </div>
        </div>
    </div>
    </Teleport>
</div>
`,
    } );
    app.component( 'roles-picker', RolesPicker );
    return app;
}

/* =====================================================================
 * Start
 * ===================================================================== */

function tryMount() {
    const el = document.getElementById( MOUNT_ID );
    if ( ! el ) return false;
    if ( el.getAttribute( 'data-gp-mounted' ) ) return true;
    el.setAttribute( 'data-gp-mounted', '1' );

    if ( typeof Vue === 'undefined' ) {
        el.innerHTML = '<div style="padding:30px;text-align:center;color:#EF4444;">Nie udało się załadować biblioteki Vue (sprawdź połączenie z unpkg.com lub blokady skryptów zewnętrznych).</div>';
        return true;
    }
    try {
        createGfxPlikiApp().mount( el );
    } catch ( e ) {
        console.error( 'GastroFlowX Pliki: błąd montowania', e );
        el.innerHTML = '<div style="padding:30px;text-align:center;color:#EF4444;">Wystąpił błąd podczas ładowania modułu: ' + escapeHtml( e && e.message ? e.message : e ) + '</div>';
    }
    return true;
}

function boot() {
    injectDocStyles();
    if ( tryMount() ) return;
    const obs = new MutationObserver( () => { if ( tryMount() ) obs.disconnect(); } );
    obs.observe( document.documentElement, { childList: true, subtree: true } );
    setTimeout( () => obs.disconnect(), 60000 );
}

if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
else boot();

})();
