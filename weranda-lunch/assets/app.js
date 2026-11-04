/* global Vue, PDFLib, werandaLunchConfig, WerandaLunchLayout, wp */
(function () {
'use strict';
const { createApp } = Vue;

const cfg = window.werandaLunchConfig || {};

/* ------------------------------------------------------------------------
 * JAKOŚĆ WYDRUKU
 *
 * Strony PDF są renderowane na obrazek w 300 DPI (standard jakości druku).
 * Wcześniej było to scale: 2, czyli tylko 144 DPI — drukarka musiała taki
 * obrazek rozciągać ponad 2× i wydruki wychodziły rozmazane.
 *
 * Każda strona jest od razu zamieniana na plik PNG (blob: URL), a canvas
 * zwalniany — dzięki temu przy wielu stronach w pamięci nie wisi naraz
 * kilka dużych canvasów (~35 MB każdy w 300 DPI), co na tabletach i
 * telefonach mogłoby wyczerpać pamięć przeglądarki.
 * ---------------------------------------------------------------------- */
const PRINT_DPI = 300;
// iOS/Safari nie wyrenderuje canvasu większego niż ~16,7 mln pikseli (wyjdzie
// pusta strona). A4 w 300 DPI to ~8,7 mln, więc limit dotyczy tylko większych
// formatów (np. A3) — wtedy rozdzielczość jest proporcjonalnie obniżana.
const MAX_CANVAS_PIXELS = 16000000;

let lastPrintUrls = [];

// Zwalnia pliki stron z poprzedniego wydruku (wywoływane przed kolejnym drukiem).
function releasePrintUrls() {
    lastPrintUrls.forEach( ( u ) => { try { URL.revokeObjectURL( u ); } catch ( e ) { /* nic */ } } );
    lastPrintUrls = [];
}

function getPdfJs() {
    const pdfjsLib = window['pdfjs-dist/build/pdf'];
    if ( ! pdfjsLib ) throw new Error( 'Nie udało się załadować biblioteki pdf.js.' );
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
    return pdfjsLib;
}

function escapeAttr( s ) {
    return String( s ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
}

/**
 * Renderuje jedną stronę pdf.js do obrazka PNG w 300 DPI.
 * Zwraca { src, ratio } — ratio to proporcja szerokość/wysokość strony.
 */
async function renderPdfPageToUrl( page ) {
    const base = page.getViewport( { scale: 1 } ); // 1 = 72 DPI (jednostki PDF)
    let scale = PRINT_DPI / 72;
    const pixels = base.width * base.height * scale * scale;
    if ( pixels > MAX_CANVAS_PIXELS ) scale *= Math.sqrt( MAX_CANVAS_PIXELS / pixels );

    const viewport = page.getViewport( { scale } );
    const canvas = document.createElement( 'canvas' );
    canvas.width = Math.floor( viewport.width );
    canvas.height = Math.floor( viewport.height );
    const ctx = canvas.getContext( '2d' );
    ctx.fillStyle = '#fff';
    ctx.fillRect( 0, 0, canvas.width, canvas.height );
    await page.render( { canvasContext: ctx, viewport } ).promise;

    const blob = await new Promise( ( resolve, reject ) => {
        canvas.toBlob( ( b ) => ( b ? resolve( b ) : reject( new Error( 'Nie udało się przygotować strony do druku (za mało pamięci?).' ) ) ), 'image/png' );
    } );
    canvas.width = 0; canvas.height = 0; // natychmiastowe zwolnienie pamięci canvasu
    page.cleanup();

    const src = URL.createObjectURL( blob );
    lastPrintUrls.push( src );
    return { src, ratio: base.width / base.height };
}

// Czeka, aż wszystkie obrazki stron będą w pełni wczytane i zdekodowane,
// zanim trafią do okna druku (duże pliki potrzebują chwili).
async function preloadPrintImages( pages ) {
    const unique = Array.from( new Set( pages.map( p => p.src ) ) );
    await Promise.all( unique.map( ( src ) => new Promise( ( resolve, reject ) => {
        const img = new Image();
        img.onload = () => {
            if ( img.decode ) img.decode().then( resolve, resolve ); else resolve();
        };
        img.onerror = () => {
            const p = pages.find( x => x.src === src );
            reject( new Error( 'Nie udało się wczytać pliku do druku: ' + ( ( p && p.name ) || src ) ) );
        };
        img.src = src;
    } ) ) );
}

async function parseJsonSafely( res ) {
    const text = await res.text();
    try {
        return JSON.parse( text );
    } catch ( e ) {
        // Serwer nie zwrócił JSON — najczęściej PHP Fatal Error (strona błędu WP),
        // limit rozmiaru pliku po stronie serwera, albo blokada firewalla/security
        // pluginu. Pokazujemy fragment surowej odpowiedzi, żeby dało się to zdiagnozować.
        const snippet = text.replace( /\s+/g, ' ' ).trim().slice( 0, 200 );
        throw new Error( 'Serwer zwrócił nieprawidłową odpowiedź (nie-JSON), status ' + res.status + '. Możliwe przyczyny: limit rozmiaru pliku na serwerze, błąd PHP, blokada zabezpieczeń. Fragment odpowiedzi: ' + snippet );
    }
}

async function apiFetch( path, options = {} ) {
    const headers = options.headers || {};
    headers['X-WP-Nonce'] = cfg.nonce;
    const res = await fetch( cfg.restUrl + path, { ...options, headers, credentials: 'same-origin' } );
    const data = await parseJsonSafely( res );
    if ( ! res.ok ) {
        throw new Error( data.message || 'Błąd komunikacji z serwerem.' );
    }
    return data;
}

const L = window.WerandaLunchLayout;

function clone( o ) {
    return JSON.parse( JSON.stringify( o ) );
}

function weranda_lunch_boot() {
    const mountEl = document.getElementById( 'weranda-lunch-app' );
    if ( ! mountEl ) return; // Shortcode nie jest na tej stronie — nic do zrobienia.

    if ( typeof Vue === 'undefined' ) {
        mountEl.innerHTML = '<div style="padding:30px;text-align:center;color:#cc0000;">Nie udało się załadować biblioteki Vue (sprawdź połączenie z unpkg.com lub blokady skryptów zewnętrznych).</div>';
        return;
    }

    try {
        weranda_lunch_createVueApp().mount( '#weranda-lunch-app' );
    } catch ( e ) {
        console.error( 'Weranda Lunch: błąd montowania panelu:', e );
        mountEl.innerHTML = '<div style="padding:30px;text-align:center;color:#cc0000;">Wystąpił błąd podczas ładowania panelu: ' + ( e && e.message ? e.message : e ) + '</div>';
    }
}

function weranda_lunch_createVueApp() {
  return createApp( {
    data() {
        return {
            activeTab: 'generator',
            isAdmin: !! cfg.isAdmin,
            loading: true,

            // Układ karty (Ustawienia PDF): zapisany i edytowany (niezapisany) w zakładce Ustawienia.
            layout: null,
            layoutEdit: null,
            layoutDirty: false,
            savingLayout: false,
            layoutMsg: '',
            layoutMsgError: false,
            previewInfo: null,

            draft: { soup: '', weight: '', lunch: '', rec: '' },
            archive: [],
            checklist: [],
            newChecklistItem: '',

            previewReady: false,
            previewError: '',
            generating: false,
            savingDraftLabel: false,

            fontCache: {},
            logoCache: { url: '', bytes: null },
            renderSeq: 0,
            appReady: false,

            saveTimer: null,
            previewTimer: null,

            initError: '',
        };
    },

    computed: {
        fontOptions() {
            return Object.keys( L.FONTS ).map( k => ( { key: k, label: L.FONTS[ k ][ 1 ] } ) );
        },
        editBlocks() {
            return this.layoutEdit ? this.layoutEdit.blocks : [];
        },
        priceBlocks() {
            return this.editBlocks.filter( b => b.price );
        },
        textBlocks() {
            return this.editBlocks.filter( b => b.kind === 'text' && ! b.price );
        },
        priceSummary() {
            if ( ! this.layout ) return '';
            return this.layout.blocks.filter( b => b.price && +b.enabled && b.text ).map( b => ( b.short || b.label ) + ': ' + b.text ).join( ' · ' );
        },
        logoPreviewUrl() {
            const l = this.layoutEdit && this.layoutEdit.logo;
            return ( l && l.url ) || cfg.logoDefault;
        },
        fitNote() {
            const i = this.previewInfo;
            if ( ! i ) return '';
            if ( i.overflow ) return 'Treść nie mieści się nad stopką nawet po zmniejszeniu — skróć tekst albo zmień ustawienia strony.';
            if ( i.scale < 0.999 ) return 'Treść zmniejszona do ' + Math.round( i.scale * 100 ) + '%, żeby zmieściła się nad stopką.';
            return '';
        },
    },

    async mounted() {
        try {
            const data = await apiFetch( '/bootstrap' );
            if ( ! L ) throw new Error( 'Nie załadował się silnik układu (lunch-layout.js).' );
            this.layout = L.merge( data.layout );
            this.draft = Object.assign( { soup: '', weight: '', lunch: '', rec: '' }, data.draft || {} );
            this.archive = data.archive || [];
            this.checklist = data.checklist || [];
            this.isAdmin = !! data.isAdmin;
        } catch ( e ) {
            console.error( 'Nie udało się zainicjalizować panelu Weranda Lunch:', e );
            this.initError = 'Nie udało się w pełni załadować panelu: ' + ( e && e.message ? e.message : e ) + '. Spróbuj odświeżyć stronę.';
            this.loading = false;
            return;
        }

        // WAŻNE: dopiero teraz przełączamy loading na false, żeby Vue wyrenderował
        // właściwy widok (z elementem <canvas>) — dopiero gdy on istnieje w DOM,
        // wolno uruchamiać generatePreview(). Wcześniej ref na canvas był
        // niedostępny (bo v-else z canvasem jeszcze się nie wyrenderował), co
        // powodowało ciche, trwałe "zawieszenie" podglądu bez żadnego błędu.
        this.loading = false;
        await this.$nextTick();

        try {
            await this.loadAssets( this.layout );
            this.appReady = true;
            await this.generatePreview();
        } catch ( e ) {
            console.error( 'Nie udało się przygotować podglądu Weranda Lunch:', e );
            this.previewError = 'Nie udało się przygotować podglądu: ' + ( e && e.message ? e.message : e );
        }
    },

    methods: {
        switchTab( tab ) {
            this.activeTab = tab;
            if ( tab === 'settings' && ! this.layoutEdit ) {
                this.layoutEdit = clone( this.layout );
                this.layoutDirty = false;
            }
            this.$nextTick( () => this.generatePreview() );
        },

        fontUrl( key ) {
            return cfg.fontsBase + L.FONTS[ key ][ 0 ] + '?ver=' + encodeURIComponent( cfg.version || '' );
        },

        /** Pobiera (raz) czcionki użyte w układzie i logo. */
        async loadAssets( layout ) {
            const need = L.usedFonts( layout ).filter( k => ! this.fontCache[ k ] );
            await Promise.all( need.map( async ( k ) => {
                const res = await fetch( this.fontUrl( k ), { credentials: 'same-origin' } );
                if ( ! res.ok ) throw new Error( 'Nie udało się pobrać czcionki ' + L.FONTS[ k ][ 1 ] + ' (błąd ' + res.status + ').' );
                this.fontCache[ k ] = await res.arrayBuffer();
            } ) );
            const logoUrl = ( layout.logo && layout.logo.url ) || cfg.logoDefault;
            if ( +layout.logo.enabled && this.logoCache.url !== logoUrl ) {
                const res = await fetch( logoUrl, { credentials: 'same-origin' } );
                if ( ! res.ok ) throw new Error( 'Nie udało się pobrać logo (błąd ' + res.status + '): ' + logoUrl );
                this.logoCache = { url: logoUrl, bytes: await res.arrayBuffer() };
            }
        },

        /** Buduje PDF karty. layout domyślnie = zapisany układ (tak powstaje plik do archiwum). */
        async buildPdf( layout ) {
            layout = layout || this.layout;
            await this.loadAssets( layout );
            const fonts = {};
            L.usedFonts( layout ).forEach( k => { fonts[ k ] = this.fontCache[ k ].slice( 0 ); } );
            const logo = +layout.logo.enabled && this.logoCache.bytes ? this.logoCache.bytes.slice( 0 ) : null;
            const out = await L.build( PDFLib, window.fontkit, layout, this.draft, { fonts, logo } );
            this.previewInfo = out.info;
            return out.doc;
        },

        async generatePreview() {
            if ( ! this.appReady ) return;
            const seq = ++this.renderSeq; // tylko ostatnie wywołanie rysuje (szybkie pisanie)
            const inSettings = this.activeTab === 'settings' && this.layoutEdit;
            this.previewError = '';
            try {
                const pdfDoc = await this.buildPdf( inSettings ? this.layoutEdit : this.layout );
                const pdfBytes = await pdfDoc.save();
                if ( seq !== this.renderSeq ) return;
                const pdf = await getPdfJs().getDocument( { data: pdfBytes } ).promise;
                const pdfPage = await pdf.getPage( 1 );
                const viewport = pdfPage.getViewport( { scale: 1.5 } );
                const canvas = inSettings ? this.$refs.settingsCanvas : this.$refs.previewCanvas;
                if ( ! canvas || seq !== this.renderSeq ) return;
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                await pdfPage.render( { canvasContext: canvas.getContext( '2d' ), viewport } ).promise;
                this.previewReady = true;
            } catch ( e ) {
                console.error( 'Błąd generowania podglądu PDF:', e );
                this.previewError = 'Nie udało się wygenerować podglądu: ' + ( e && e.message ? e.message : e );
            }
        },

        onFieldInput() {
            clearTimeout( this.previewTimer );
            this.previewTimer = setTimeout( () => this.generatePreview(), 300 );
            this.queueDraftSave();
        },

        queueDraftSave( immediate = false ) {
            clearTimeout( this.saveTimer );
            const run = () => this.saveDraft();
            if ( immediate ) { run(); return; }
            this.saveTimer = setTimeout( run, 1000 );
        },

        async saveDraft() {
            try {
                const body = new URLSearchParams( {
                    soup: this.draft.soup || '',
                    weight: this.draft.weight || '',
                    lunch: this.draft.lunch || '',
                    rec: this.draft.rec || '',
                } );
                await apiFetch( '/draft', { method: 'POST', body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } } );
                this.savingDraftLabel = true;
                clearTimeout( this._labelTimer );
                this._labelTimer = setTimeout( () => { this.savingDraftLabel = false; }, 1800 );
            } catch ( e ) {
                console.error( 'Błąd autozapisu:', e );
            }
        },

        async generateAndSave() {
            if ( ! this.appReady ) return;
            this.generating = true;
            try {
                const pdfDoc = await this.buildPdf();
                const pdfBytes = await pdfDoc.save();
                const blob = new Blob( [ pdfBytes ], { type: 'application/pdf' } );
                const formData = new FormData();
                formData.append( 'pdf_file', blob, 'menu.pdf' );
                const res = await fetch( cfg.restUrl + '/archive', {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': cfg.nonce },
                    body: formData,
                    credentials: 'same-origin',
                } );
                const data = await parseJsonSafely( res );
                if ( ! res.ok ) throw new Error( data.message || 'Błąd zapisu pliku' );
                this.archive = data.archive;
            } catch ( e ) {
                alert( 'Wystąpił błąd z serwerem: ' + e.message );
            } finally {
                this.generating = false;
            }
        },

        toggleChecklistItem( item, checked ) {
            let lines = ( this.draft.rec || '' ).split( '\n' ).map( l => l.trim() ).filter( l => l !== '' );
            if ( checked ) {
                if ( ! lines.includes( item ) ) lines.push( item );
            } else {
                lines = lines.filter( l => l !== item );
            }
            this.draft.rec = lines.join( '\n' );
            this.onFieldInput();
        },

        isChecked( item ) {
            const lines = ( this.draft.rec || '' ).split( '\n' ).map( l => l.trim() );
            return lines.includes( item );
        },

        async addChecklistItem() {
            const val = this.newChecklistItem.trim();
            if ( ! val ) return;
            const fd = new URLSearchParams( { item: val } );
            const data = await apiFetch( '/checklist', { method: 'POST', body: fd, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } } );
            this.checklist = data.checklist;
            this.newChecklistItem = '';
        },

        async deleteChecklistItem( item ) {
            if ( ! confirm( 'Czy na pewno chcesz usunąć ten produkt z bazy?' ) ) return;
            const data = await apiFetch( '/checklist?item=' + encodeURIComponent( item ), { method: 'DELETE' } );
            this.checklist = data.checklist;
        },

        /**
         * Drukowanie zapisanego menu z archiwum. Wcześniej używaliśmy zwykłego
         * window.open(url) i liczyliśmy na przycisk drukowania w pasku
         * przeglądarki — ale w trybie PWA (strona dodana jako aplikacja) nie ma
         * żadnego paska przeglądarki, więc tego przycisku po prostu nie ma i
         * użytkownik nie miał jak wydrukować pliku. Rozwiązanie: wywołujemy
         * window.print() bezpośrednio z kodu — to działa niezależnie od tego,
         * czy widoczny jest pasek przeglądarki, bo korzysta z natywnego API
         * druku systemu, a nie z przycisku w interfejsie przeglądarki.
         */
        async printArchiveItem( url ) {
            try {
                const res = await fetch( url );
                if ( ! res.ok ) throw new Error( 'Nie udało się pobrać pliku PDF (status ' + res.status + ').' );
                const bytes = await res.arrayBuffer();
                // Kopia pliku do menu „Udostępnij → Drukuj” (PWA na iPhonie) — pdf.js
                // przejmuje oryginalny bufor, więc musi to być osobna kopia.
                const shareFile = new File( [ bytes.slice( 0 ) ], 'menu-lunch.pdf', { type: 'application/pdf' } );

                releasePrintUrls();
                const pdf = await getPdfJs().getDocument( { data: bytes } ).promise;

                // Każda strona → obrazek PNG w 300 DPI (wcześniej 144 DPI = rozmazany tekst).
                const pages = [];
                for ( let i = 1; i <= pdf.numPages; i++ ) {
                    const page = await pdf.getPage( i );
                    pages.push( await renderPdfPageToUrl( page ) );
                }
                pdf.destroy();
                await preloadPrintImages( pages );

                // Ta sama ścieżka co w pozostałych modułach: telefony i tablety →
                // główne okno z paskiem „Drukuj / Gotowe”; komputer (także PWA) →
                // ukryty iframe.
                if ( this.isMobilePrintDevice() ) {
                    await this.printViaMainWindow( pages, shareFile );
                } else {
                    await this.printViaHiddenIframe( pages );
                }
            } catch ( e ) {
                alert( 'Błąd przygotowania wydruku: ' + e.message );
            }
        },

        /**
         * Komputer / zwykła przeglądarka: sprawdzona metoda — piszemy obrazki do
         * ukrytego <iframe> (document.write) i drukujemy TEN iframe.
         */
        async printViaHiddenIframe( pages ) {
            let htmlPagesContent = '';
            pages.forEach( ( p ) => {
                htmlPagesContent += '<div class="print-page" style="padding:' + ( p.pad || 0 ) + '"><img src="' + escapeAttr( p.src ) + '" /></div>';
            } );

            let oldIframe = document.getElementById( 'weranda-lunch-print-iframe' );
            if ( oldIframe ) oldIframe.parentNode.removeChild( oldIframe );

            const iframe = document.createElement( 'iframe' );
            iframe.id = 'weranda-lunch-print-iframe';
            iframe.style.position = 'absolute';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = 'none';
            document.body.appendChild( iframe );

            const iframeHtml = '<!DOCTYPE html><html><head><title>Wydruk menu</title><style>' +
                '@page { margin: 0; size: auto; }' +
                'html, body { margin: 0; padding: 0; width: 100%; background: #fff; }' +
                '.print-page { width: 100vw; height: 100vh; page-break-after: always; display: flex; justify-content: center; align-items: center; box-sizing: border-box; overflow: hidden; }' +
                '.print-page:last-child { page-break-after: avoid; }' +
                'img { width: 100%; height: 100%; object-fit: contain; display: block; }' +
                '</style></head><body>' + htmlPagesContent + '</body></html>';

            const doc = iframe.contentWindow.document;
            doc.open();
            doc.write( iframeHtml );
            doc.close();

            const imgs = Array.from( doc.querySelectorAll( 'img' ) );
            await Promise.all( imgs.map( ( img ) => {
                if ( img.complete && img.naturalWidth > 0 ) return Promise.resolve();
                return new Promise( ( resolve ) => {
                    img.onload = resolve;
                    img.onerror = resolve;
                    setTimeout( resolve, 20000 );
                } );
            } ) );

            await new Promise( r => setTimeout( r, 200 ) );

            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        },

        /**
         * Rozpoznanie urządzenia do wyboru ścieżki druku (wspólne dla wszystkich
         * modułów): telefony oraz tablety (także iPad z iPadOS, który przedstawia
         * się jako Mac) drukują główne okno; komputery — także w trybie PWA —
         * drukują ukryty iframe.
         */
        isMobilePrintDevice() {
            return /Mobi|Android|iPhone|iPad|iPod/i.test( navigator.userAgent ) || ( navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1 );
        },

        /**
         * Telefony i tablety: drukujemy GŁÓWNE okno (window.print()). Reszta
         * strony jest odpinana z DOM na czas druku i wraca dopiero po kliknięciu
         * „Gotowe”.
         *
         * iOS: druk wywołany po dłuższym przygotowaniu (pobranie + renderowanie
         * PDF) traci „gest użytkownika” — w PWA system go wtedy blokuje, dlatego
         * w pasku jest przycisk „Drukuj”, który wywołuje druk bezpośrednio z
         * dotknięcia. Pasek jest przypięty (position:fixed), a przycisk „Gotowe”
         * reaguje na dotyk i klik — po anulowaniu okna druku Safari potrafi
         * zgubić zwykłe zdarzenie click. Gdyby przywracanie się nie powiodło,
         * strona jest przeładowywana, żeby nigdy nie zostać na ekranie wydruku.
         */
        async printViaMainWindow( pages, shareFile ) {
            const PREFIX = 'weranda-lunch';
            const barCss = 'position:fixed;top:0;left:0;right:0;z-index:2147483647;background:#111827;color:#fff;padding:12px 16px;padding-top:calc(12px + env(safe-area-inset-top, 0px));display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;box-sizing:border-box;';
            const btnCss = 'border:none;padding:10px 16px;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap;touch-action:manipulation;-webkit-tap-highlight-color:transparent;';

            const overlay = document.createElement( 'div' );
            overlay.id = PREFIX + '-print-overlay';
            overlay.style.cssText = 'background:#fff;min-height:100vh;';

            const returnBar = document.createElement( 'div' );
            returnBar.className = PREFIX + '-return-bar';
            returnBar.style.cssText = barCss;

            const msg = document.createElement( 'span' );
            msg.style.cssText = 'font-size:14px;font-weight:600;flex:1 1 180px;';
            msg.textContent = 'Jeśli okno drukowania nie otworzyło się samo, kliknij „Drukuj”.';

            const btns = document.createElement( 'div' );
            btns.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;';

            const printBtn = document.createElement( 'button' );
            printBtn.type = 'button';
            printBtn.textContent = '🖨 Drukuj';
            printBtn.style.cssText = btnCss + 'background:#fff;color:#111827;';

            const returnBtn = document.createElement( 'button' );
            returnBtn.type = 'button';
            returnBtn.textContent = '✓ Gotowe, wróć do listy';
            returnBtn.style.cssText = btnCss + 'background:#2563EB;color:#fff;';

            btns.appendChild( printBtn );
            btns.appendChild( returnBtn );
            returnBar.appendChild( msg );
            returnBar.appendChild( btns );

            // Odstęp pod przypiętym paskiem — ta sama klasa, więc znika na wydruku.
            const spacer = document.createElement( 'div' );
            spacer.className = PREFIX + '-return-bar';
            spacer.style.cssText = 'height:calc(76px + env(safe-area-inset-top, 0px));';

            // Strony jako zwykłe obrazki (WebKit na iOS bywa niestabilny przy druku <canvas>).
            const pagesWrap = document.createElement( 'div' );
            // Każda strona ma proporcje kartki (aspect-ratio), a obrazek jest w niej
            // wpasowany (object-fit: contain) — dzięki temu także oryginalne obrazy
            // kolorowanek o dowolnych proporcjach mieszczą się na jednej stronie.
            pages.forEach( ( p, idx ) => {
                const page = document.createElement( 'div' );
                page.style.cssText = 'width:100%;aspect-ratio:' + p.ratio + ';box-sizing:border-box;padding:' + ( p.pad || 0 ) + ';overflow:hidden;' +
                    ( idx < pages.length - 1 ? 'page-break-after:always;break-after:page;' : '' );
                const img = document.createElement( 'img' );
                img.src = p.src;
                img.alt = '';
                img.style.cssText = 'display:block;width:100%;height:100%;object-fit:contain;';
                page.appendChild( img );
                pagesWrap.appendChild( page );
            } );

            overlay.appendChild( returnBar );
            overlay.appendChild( spacer );
            overlay.appendChild( pagesWrap );

            const detachedNodes = Array.from( document.body.children ).map( ( el ) => ( {
                el, next: el.nextSibling,
            } ) );
            detachedNodes.forEach( ( { el } ) => el.remove() );
            document.body.appendChild( overlay );
            window.scrollTo( 0, 0 );

            const doPrint = () => {
                try { window.print(); } catch ( e ) { console.error( 'Wywołanie druku nie powiodło się:', e ); }
            };

            const onAfterPrint = () => {
                msg.textContent = 'Po zakończeniu kliknij „Gotowe, wróć do listy”.';
                // iOS po anulowaniu druku potrafi zostawić nieodświeżony układ — wymuszamy przemalowanie.
                overlay.style.display = 'none';
                void overlay.offsetHeight;
                overlay.style.display = '';
            };
            window.addEventListener( 'afterprint', onAfterPrint );

            let cleanupDone = false;
            const cleanup = ( e ) => {
                if ( e ) { e.preventDefault(); e.stopPropagation(); }
                if ( cleanupDone ) return;
                cleanupDone = true;
                window.removeEventListener( 'afterprint', onAfterPrint );
                try {
                    if ( overlay.parentNode ) overlay.parentNode.removeChild( overlay );
                    detachedNodes.forEach( ( { el, next } ) => {
                        if ( next && next.parentNode === document.body ) {
                            document.body.insertBefore( el, next );
                        } else {
                            document.body.appendChild( el );
                        }
                    } );
                } catch ( err ) {
                    console.error( 'Przywracanie strony po druku nie powiodło się:', err );
                    window.location.reload();
                }
            };
            [ 'touchend', 'pointerup', 'click' ].forEach( ( ev ) => returnBtn.addEventListener( ev, cleanup ) );

            // PWA na iPhonie/iPadzie: Apple wyłącza tam window.print() (wywołanie nic
            // nie robi). Jedyna działająca droga to systemowe menu „Udostępnij” z
            // gotowym plikiem PDF — ma ono opcję „Drukuj”. navigator.share musi być
            // wywołane wprost w kliknięciu, dlatego plik jest przygotowany wcześniej.
            const isIOSStandalone = window.navigator.standalone === true;
            const canShareFile = !! ( shareFile && navigator.canShare && navigator.share && navigator.canShare( { files: [ shareFile ] } ) );
            if ( isIOSStandalone ) {
                msg.textContent = canShareFile
                    ? 'Kliknij „Drukuj”, a potem w menu wybierz „Drukuj”.'
                    : 'Ta wersja iOS nie pozwala drukować z aplikacji — otwórz stronę w Safari.';
            }

            const onPrintTap = () => {
                if ( isIOSStandalone && canShareFile ) {
                    navigator.share( { files: [ shareFile ], title: shareFile.name } ).catch( ( err ) => {
                        if ( err && err.name === 'AbortError' ) return; // użytkownik zamknął menu
                        console.error( 'Udostępnianie do druku nie powiodło się:', err );
                        msg.textContent = 'Nie udało się otworzyć menu drukowania — spróbuj ponownie.';
                    } );
                    return;
                }
                doPrint();
            };
            printBtn.addEventListener( 'click', onPrintTap );

            await Promise.all( Array.from( overlay.querySelectorAll( 'img' ) ).map( ( img ) => {
                if ( img.complete && img.naturalWidth > 0 ) return Promise.resolve();
                return new Promise( ( resolve ) => { img.onload = resolve; img.onerror = resolve; setTimeout( resolve, 20000 ); } );
            } ) );
            for ( let i = 0; i < 3; i++ ) {
                await new Promise( r => requestAnimationFrame( r ) );
            }

            // Automatyczna próba druku — poza PWA na iOS (tam druk działa tylko
            // przez przycisk „Drukuj” → menu „Udostępnij”).
            if ( ! isIOSStandalone ) doPrint();
        },

        async downloadArchiveItem( item ) {
            const res = await fetch( item.url );
            const blob = await res.blob();
            const blobUrl = URL.createObjectURL( blob );
            const link = document.createElement( 'a' );
            link.href = blobUrl;
            link.download = item.name;
            document.body.appendChild( link );
            link.click();
            document.body.removeChild( link );
            URL.revokeObjectURL( blobUrl );
        },

        async deleteArchiveItem( item ) {
            if ( ! confirm( 'Czy na pewno chcesz na zawsze usunąć to menu?' ) ) return;
            const data = await apiFetch( '/archive/' + item.id, { method: 'DELETE' } );
            this.archive = data.archive;
        },

        // --- Zarządzanie szablonami (admin) ---

        /* ---------------- Ustawienia PDF (administrator) ---------------- */

        onLayoutInput() {
            this.layoutDirty = true;
            this.layoutMsg = '';
            clearTimeout( this.previewTimer );
            this.previewTimer = setTimeout( () => this.generatePreview(), 250 );
        },

        async saveLayout() {
            this.savingLayout = true;
            this.layoutMsg = '';
            try {
                const data = await apiFetch( '/layout', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( { layout: this.layoutEdit } ),
                } );
                this.layout = L.merge( data.layout );
                this.layoutEdit = clone( this.layout );
                this.layoutDirty = false;
                this.layoutMsgError = false;
                this.layoutMsg = 'Zapisano. Nowe ustawienia obowiązują od teraz przy każdym generowaniu.';
                this.generatePreview();
            } catch ( e ) {
                this.layoutMsgError = true;
                this.layoutMsg = 'Nie udało się zapisać: ' + e.message;
            } finally {
                this.savingLayout = false;
            }
        },

        cancelLayout() {
            this.layoutEdit = clone( this.layout );
            this.layoutDirty = false;
            this.layoutMsg = '';
            this.generatePreview();
        },

        /** Przywraca układ 1:1 z Canvy — tylko w formularzu; zapis dopiero przyciskiem „Zapisz”. */
        restoreDefaults() {
            if ( ! confirm( 'Przywrócić domyślne ustawienia (układ, teksty i ceny z szablonu Canvy)? Zmiany zobaczysz w podglądzie — zapiszą się dopiero po kliknięciu „Zapisz ustawienia”.' ) ) return;
            this.layoutEdit = L.defaults();
            this.onLayoutInput();
        },

        chooseLogo() {
            if ( ! window.wp || ! wp.media ) {
                alert( 'Biblioteka mediów nie jest dostępna na tej stronie.' );
                return;
            }
            const frame = wp.media( { title: 'Wybierz logo (PNG z przezroczystym tłem lub JPG)', button: { text: 'Użyj jako logo' }, library: { type: [ 'image/png', 'image/jpeg' ] }, multiple: false } );
            frame.on( 'select', () => {
                const a = frame.state().get( 'selection' ).first().toJSON();
                this.layoutEdit.logo.id = a.id;
                this.layoutEdit.logo.url = a.url;
                this.onLayoutInput();
            } );
            frame.open();
        },

        defaultLogo() {
            this.layoutEdit.logo.id = 0;
            this.layoutEdit.logo.url = '';
            this.onLayoutInput();
        },

        fieldName( b ) {
            return b.kind === 'field' ? 'z panelu Lunch' : ( b.price ? 'cena' : 'stały tekst' );
        },
    },

    template: `
    <div v-if="loading" style="display:flex;align-items:center;justify-content:center;padding:60px 0;color:var(--rs-text-faint,#9CA3AF);font-size:14px;">Ładowanie panelu…</div>
    <div v-else>
        <div v-if="initError" class="wl-msg wl-msg-error">{{ initError }}</div>

        <!-- SUB-NAWIGACJA LUNCHU -->
        <div class="wl-tabs">
            <button @click="switchTab('generator')" class="wl-tab" :class="{active: activeTab==='generator'}">
                <i class="fas fa-edit"></i> Generator Menu
            </button>
            <button v-if="isAdmin" @click="switchTab('settings')" class="wl-tab" :class="{active: activeTab==='settings'}">
                <i class="fas fa-cog"></i> Ustawienia PDF
            </button>
        </div>

        <!-- WIDOK: GENERATOR MENU -->
        <div v-show="activeTab==='generator'">
            <div style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;">

                <!-- LEWA KOLUMNA: Formularz -->
                <div class="wl-card" style="flex:1 1 420px;">
                    <h3 style="display:flex;align-items:center;gap:10px;">
                        Kreator Menu Weranda
                        <span style="font-size:12px;font-weight:600;color:var(--rs-success);transition:opacity .2s;" :style="{opacity: savingDraftLabel ? 1 : 0}">✓ Zapisano</span>
                    </h3>

                    <div style="display:flex;flex-direction:column;gap:22px;">
                        <div v-if="priceSummary" class="wl-price-note">
                            <i class="fas fa-tag"></i> Ceny na karcie: <strong>{{ priceSummary }}</strong>
                            <span v-if="isAdmin"> — zmienisz je w <a href="#" @click.prevent="switchTab('settings')">Ustawieniach PDF</a></span>
                        </div>

                        <div>
                            <label class="wl-label">Zupa dnia</label>
                            <textarea rows="3" v-model="draft.soup" @input="onFieldInput" class="wl-textarea" placeholder="np. Krem z buraka"></textarea>
                        </div>

                        <div>
                            <label class="wl-label">Gramatura lunchu (np. 360 g)</label>
                            <input type="text" v-model="draft.weight" @input="onFieldInput" class="wl-input" placeholder="np. 360 g">
                        </div>

                        <div>
                            <label class="wl-label">Opis lunchu (danie dnia)</label>
                            <textarea rows="6" v-model="draft.lunch" @input="onFieldInput" class="wl-textarea" placeholder="np. Makaron strozzapreti z salami piccante…"></textarea>
                        </div>

                        <div>
                            <label class="wl-label">Polecamy (jedna pozycja w linii — możesz wybrać z bazy poniżej)</label>
                            <textarea rows="6" v-model="draft.rec" @input="onFieldInput" class="wl-textarea" style="margin-bottom:12px;"></textarea>

                            <div class="wl-tagbox">
                                <span v-if="!checklist.length" style="font-size:14px;color:var(--rs-text-faint);">Brak produktów w bazie.</span>
                                <div v-for="opt in checklist" :key="opt" class="wl-tag" :class="{active: isChecked(opt)}">
                                    <label>
                                        <input type="checkbox" :checked="isChecked(opt)" @change="toggleChecklistItem(opt, $event.target.checked)">
                                        <span>{{ opt }}</span>
                                    </label>
                                    <button type="button" class="wl-tag-del" @click="deleteChecklistItem(opt)"><i class="fas fa-times"></i></button>
                                </div>
                            </div>

                            <div style="display:flex;gap:8px;">
                                <input type="text" v-model="newChecklistItem" @keyup.enter="addChecklistItem" placeholder="Wpisz nowy produkt..." class="wl-input" style="flex:1;">
                                <button type="button" @click="addChecklistItem" class="wl-btn wl-btn-secondary"><i class="fas fa-plus"></i> Dodaj</button>
                            </div>
                        </div>

                        <button type="button" :disabled="generating" @click="generateAndSave" class="wl-save">
                            <i class="fas" :class="generating ? 'fa-spinner fa-spin' : 'fa-check'"></i>
                            {{ generating ? 'Generowanie...' : 'Wygeneruj i Zapisz do Listy' }}
                        </button>
                    </div>
                </div>

                <!-- PRAWA KOLUMNA: Podgląd -->
                <div style="flex:1 1 380px;max-width:520px;">
                    <div class="wl-card" style="padding:0;overflow:hidden;">
                        <div style="padding:20px 22px;border-bottom:1px solid var(--rs-border);">
                            <h3 style="margin:0;font-size:18px;">Podgląd na żywo</h3>
                        </div>
                        <div class="wl-preview-body">
                            <div v-if="!previewReady && !previewError" class="wl-spinner"></div>
                            <div v-if="previewError" class="wl-msg wl-msg-error" style="margin:0;">{{ previewError }}</div>
                            <div v-if="fitNote && !previewError" class="wl-msg wl-msg-warn">{{ fitNote }}</div>
                            <canvas ref="previewCanvas" id="pdf-preview-canvas" :style="{opacity: previewReady ? 1 : 0, position: previewReady ? 'static' : 'absolute'}"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ARCHIWUM -->
            <div style="margin-top:36px;">
                <h3 style="font-size:18px;margin:0 0 16px;">Archiwum Wygenerowanych Menu</h3>
                <p v-if="!archive.length" class="wl-card" style="color:var(--rs-text-muted);">Brak zapisanych menu.</p>
                <div v-else style="display:flex;flex-direction:column;gap:12px;">
                    <div v-for="item in archive" :key="item.id" class="wl-archive-row">
                        <div class="wl-archive-info">
                            <div>
                                <span class="wl-archive-label">Data</span>
                                <span class="wl-archive-value">{{ item.date }}</span>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <span class="wl-archive-label">Plik</span>
                                <span class="wl-archive-value" style="font-weight:400;color:var(--rs-text-muted);font-size:14px;">{{ item.name }}</span>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;flex-shrink:0;">
                            <button @click="printArchiveItem(item.url)" class="wl-btn wl-btn-secondary"><i class="fas fa-print" style="color:var(--rs-accent);"></i> Wydrukuj</button>
                            <button @click="downloadArchiveItem(item)" class="wl-btn wl-btn-secondary"><i class="fas fa-save"></i> Pobierz</button>
                            <button @click="deleteArchiveItem(item)" class="wl-btn wl-btn-danger"><i class="fas fa-trash-alt"></i> Usuń</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- WIDOK: USTAWIENIA PDF (administrator) -->
        <div v-if="isAdmin && layoutEdit" v-show="activeTab==='settings'">
            <div style="margin-bottom:20px;">
                <h3 style="font-size:20px;margin:0 0 8px;">Ustawienia karty lunchowej</h3>
                <p style="font-size:14px;color:var(--rs-text-muted);margin:0;">Karta jest budowana z tych ustawień — bez szablonu PDF. Treść dań (zupa, gramatura, opis, polecamy) wpisuje się w zakładce Generator. Domyślne wartości odwzorowują szablon z Canvy 1:1. Podgląd zmienia się od razu; zmiany obowiązują wszystkich po kliknięciu „Zapisz ustawienia”.</p>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;">
                <div style="flex:1 1 520px;min-width:0;display:flex;flex-direction:column;gap:20px;">

                    <div class="wl-card">
                        <h3><i class="fas fa-tag"></i> Ceny</h3>
                        <div class="wl-grid2">
                            <div v-for="b in priceBlocks" :key="b.key">
                                <label class="wl-label">{{ b.label }}</label>
                                <input type="text" v-model="b.text" @input="onLayoutInput" class="wl-input">
                            </div>
                        </div>
                        <p class="wl-hint">Wpisz cenę dokładnie tak, jak ma wyglądać na karcie (np. „16 zł”, „45 ZŁ”).</p>
                    </div>

                    <div class="wl-card">
                        <h3><i class="fas fa-font"></i> Stałe teksty</h3>
                        <div style="display:flex;flex-direction:column;gap:14px;">
                            <div v-for="b in textBlocks" :key="b.key">
                                <label class="wl-label">{{ b.label }} <span class="wl-hint-inline">(nowa linia = Enter)</span></label>
                                <textarea rows="2" v-model="b.text" @input="onLayoutInput" class="wl-textarea"></textarea>
                            </div>
                            <div>
                                <label class="wl-label">Stopka</label>
                                <input type="text" v-model="layoutEdit.footer.text" @input="onLayoutInput" class="wl-input">
                            </div>
                        </div>
                    </div>

                    <div class="wl-card">
                        <h3><i class="fas fa-text-height"></i> Czcionki, rozmiary i odstępy</h3>
                        <div style="overflow-x:auto;">
                            <table class="wl-coord-table wl-layout-table">
                                <thead>
                                    <tr><th>Pokaż</th><th>Element</th><th>Czcionka</th><th>Rozmiar (pt)</th><th>Interlinia</th><th title="Odległość od poprzedniego elementu, w pt (może być ujemna)">Odstęp nad (pt)</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in layoutEdit.blocks" :key="b.key">
                                        <td><input type="checkbox" :checked="!!+b.enabled" @change="b.enabled = $event.target.checked ? 1 : 0; onLayoutInput()"></td>
                                        <td style="text-align:left;"><strong>{{ b.label }}</strong><br><span class="wl-hint-inline">{{ fieldName(b) }}</span></td>
                                        <td><select v-model="b.font" @change="onLayoutInput" class="wl-select wl-select-sm"><option v-for="f in fontOptions" :key="f.key" :value="f.key">{{ f.label }}</option></select></td>
                                        <td><input type="number" step="0.5" min="4" v-model.number="b.size" @input="onLayoutInput"></td>
                                        <td><input type="number" step="0.01" min="0.8" v-model.number="b.lh" @input="onLayoutInput"></td>
                                        <td><input type="number" step="0.5" v-model.number="b.gap" @input="onLayoutInput"></td>
                                    </tr>
                                    <tr>
                                        <td><input type="checkbox" :checked="!!+layoutEdit.footer.enabled" @change="layoutEdit.footer.enabled = $event.target.checked ? 1 : 0; onLayoutInput()"></td>
                                        <td style="text-align:left;"><strong>Stopka</strong><br><span class="wl-hint-inline">stały tekst</span></td>
                                        <td><select v-model="layoutEdit.footer.font" @change="onLayoutInput" class="wl-select wl-select-sm"><option v-for="f in fontOptions" :key="f.key" :value="f.key">{{ f.label }}</option></select></td>
                                        <td><input type="number" step="0.5" min="4" v-model.number="layoutEdit.footer.size" @input="onLayoutInput"></td>
                                        <td><input type="number" step="0.01" min="0.8" v-model.number="layoutEdit.footer.lh" @input="onLayoutInput"></td>
                                        <td><span class="wl-hint-inline">od góry:</span> <input type="number" step="0.5" v-model.number="layoutEdit.footer.top" @input="onLayoutInput"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="wl-hint">Pierwszy element zaczyna się na „Początku treści” (sekcja Strona). Każdy kolejny — pod poprzednim, z podanym odstępem; puste pola z panelu nie zajmują miejsca, więc dłuższa zupa sama przesuwa resztę w dół.</p>
                    </div>

                    <div class="wl-card">
                        <h3><i class="fas fa-image"></i> Logo</h3>
                        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
                            <img :src="logoPreviewUrl" alt="" style="max-width:220px;max-height:90px;background:#fff;border:1px solid var(--rs-border);border-radius:10px;padding:6px;">
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <label class="wl-check"><input type="checkbox" :checked="!!+layoutEdit.logo.enabled" @change="layoutEdit.logo.enabled = $event.target.checked ? 1 : 0; onLayoutInput()"> Pokaż logo</label>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="wl-btn wl-btn-secondary" @click="chooseLogo"><i class="fas fa-photo-video"></i> Wybierz z biblioteki</button>
                                    <button v-if="layoutEdit.logo.url" type="button" class="wl-btn wl-btn-secondary" @click="defaultLogo">Logo Weranda (domyślne)</button>
                                </div>
                            </div>
                        </div>
                        <div class="wl-grid4">
                            <div><label class="wl-label">Od góry (pt)</label><input type="number" step="0.5" v-model.number="layoutEdit.logo.top" @input="onLayoutInput" class="wl-input"></div>
                            <div><label class="wl-label">Szerokość (pt)</label><input type="number" step="0.5" v-model.number="layoutEdit.logo.width" @input="onLayoutInput" class="wl-input"></div>
                            <div><label class="wl-label">Lewe — od lewej (pt)</label><input type="number" step="0.5" v-model.number="layoutEdit.logo.left_x" @input="onLayoutInput" class="wl-input"></div>
                            <div><label class="wl-label">Prawe — od lewej (pt)</label><input type="number" step="0.5" v-model.number="layoutEdit.logo.right_x" @input="onLayoutInput" class="wl-input"></div>
                        </div>
                    </div>

                    <div class="wl-card">
                        <h3><i class="fas fa-ruler-combined"></i> Strona (A4 595,5 × 842,25 pt, dwie połówki do przecięcia)</h3>
                        <div class="wl-grid4">
                            <div><label class="wl-label">Początek treści (pt od góry)</label><input type="number" step="0.5" v-model.number="layoutEdit.page.content_top" @input="onLayoutInput" class="wl-input"></div>
                            <div><label class="wl-label">Koniec treści (pt od góry)</label><input type="number" step="0.5" v-model.number="layoutEdit.page.content_bottom" @input="onLayoutInput" class="wl-input"></div>
                            <div><label class="wl-label">Maks. szerokość linii (pt)</label><input type="number" step="1" v-model.number="layoutEdit.page.max_width" @input="onLayoutInput" class="wl-input"></div>
                            <div><label class="wl-label">Kolor tekstu</label><input type="color" v-model="layoutEdit.page.color" @input="onLayoutInput" class="wl-input wl-color"></div>
                            <div><label class="wl-label">Środek lewej połówki (pt)</label><input type="number" step="0.1" v-model.number="layoutEdit.page.center_left" @input="onLayoutInput" class="wl-input"></div>
                            <div><label class="wl-label">Środek prawej połówki (pt)</label><input type="number" step="0.1" v-model.number="layoutEdit.page.center_right" @input="onLayoutInput" class="wl-input"></div>
                            <div><label class="wl-label">Linia cięcia — pozycja (pt)</label><input type="number" step="0.1" v-model.number="layoutEdit.page.cut_x" @input="onLayoutInput" class="wl-input"></div>
                            <div><label class="wl-label">Linia cięcia — grubość (pt)</label><input type="number" step="0.1" min="0.1" v-model.number="layoutEdit.page.cut_width" @input="onLayoutInput" class="wl-input"></div>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:8px;margin-top:14px;">
                            <label class="wl-check"><input type="checkbox" :checked="!!+layoutEdit.page.cut_line" @change="layoutEdit.page.cut_line = $event.target.checked ? 1 : 0; onLayoutInput()"> Linia cięcia na środku kartki</label>
                            <label class="wl-check"><input type="checkbox" :checked="!!+layoutEdit.page.fit_page" @change="layoutEdit.page.fit_page = $event.target.checked ? 1 : 0; onLayoutInput()"> Zmniejsz treść, gdy nie mieści się nad stopką (maks. do <input type="number" step="5" min="30" max="100" :value="Math.round(layoutEdit.page.min_scale*100)" @input="layoutEdit.page.min_scale = ($event.target.value || 70) / 100; onLayoutInput()" style="width:64px;"> %)</label>
                            <label class="wl-check"><input type="checkbox" :checked="!!+layoutEdit.page.keep_short_words" @change="layoutEdit.page.keep_short_words = $event.target.checked ? 1 : 0; onLayoutInput()"> Nie zostawiaj krótkich słów („w”, „z”, „i”…) na końcu linii</label>
                        </div>
                    </div>

                    <div class="wl-card wl-settings-actions">
                        <button type="button" class="wl-btn wl-btn-primary" :disabled="savingLayout || !layoutDirty" @click="saveLayout"><i class="fas fa-save"></i> {{ savingLayout ? 'Zapisywanie…' : 'Zapisz ustawienia' }}</button>
                        <button type="button" class="wl-btn wl-btn-secondary" :disabled="!layoutDirty" @click="cancelLayout"><i class="fas fa-undo"></i> Anuluj zmiany</button>
                        <button type="button" class="wl-btn wl-btn-warning" @click="restoreDefaults"><i class="fas fa-magic"></i> Przywróć domyślne (Canva)</button>
                        <span v-if="layoutDirty && !layoutMsg" class="wl-hint-inline">Masz niezapisane zmiany.</span>
                        <span v-if="layoutMsg" :class="layoutMsgError ? 'wl-err-text' : 'wl-ok-text'">{{ layoutMsg }}</span>
                    </div>
                </div>

                <div style="flex:1 1 360px;max-width:520px;position:sticky;top:12px;">
                    <div class="wl-card" style="padding:0;overflow:hidden;">
                        <div style="padding:16px 20px;border-bottom:1px solid var(--rs-border);">
                            <h3 style="margin:0;font-size:17px;">Podgląd (z treścią z Generatora)</h3>
                        </div>
                        <div class="wl-preview-body">
                            <div v-if="previewError" class="wl-msg wl-msg-error" style="margin:0;">{{ previewError }}</div>
                            <div v-if="fitNote && !previewError" class="wl-msg wl-msg-warn">{{ fitNote }}</div>
                            <canvas ref="settingsCanvas" style="max-width:100%;height:auto;"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    `,
  } );
}

if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', weranda_lunch_boot );
} else {
    weranda_lunch_boot();
}

})();
