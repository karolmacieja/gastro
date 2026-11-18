<?php
if (!defined('ABSPATH')) exit;

add_shortcode('napiwki_app', 'snspa_render_shortcode');

function snspa_render_shortcode() {
    if (!is_user_logged_in()) {
        return '<div style="padding:30px;text-align:center;font-family:sans-serif;">Zaloguj się, aby zobaczyć system napiwków.</div>';
    }

    $rest_url = esc_url_raw(rest_url(SNSPA_REST_NS));
    $nonce = wp_create_nonce('wp_rest');

    ob_start();
    ?>
    <div id="snspa-root" data-rest="<?php echo $rest_url; ?>" data-nonce="<?php echo $nonce; ?>"></div>
    <?php echo snspa_styles(); ?>
    <?php echo snspa_script(); ?>
    <?php
    return ob_get_clean();
}

function snspa_styles() {
    ob_start();
    ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
    #snspa-root{
        --rs-bg:#F9FAFB; --rs-surface:#FFFFFF; --rs-surface-alt:#F9FAFB; --rs-border:#E5E7EB;
        --rs-border-light:#DBEAFE; --rs-border-mid:#BFDBFE; --rs-border-strong:#60A5FA;
        --rs-text:#1F2937; --rs-text-muted:#6B7280; --rs-text-faint:#9CA3AF;
        --rs-accent:#2563EB; --rs-accent-dark:#1D4ED8; --rs-accent-soft:#EFF6FF; --rs-accent-text:#1D4ED8;
        --rs-success:#15803D; --rs-success-soft:#F0FDF4; --rs-success-border:#BBF7D0;
        --rs-danger:#EF4444; --rs-danger-soft:#FEF2F2; --rs-warning:#F59E0B; --rs-warning-soft:#FFFBEB;
        --rs-radius:12px; --rs-radius-lg:24px; --rs-radius-md:8px;
        font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
        font-size:16px; line-height:1.5; color:var(--rs-text); background:var(--rs-bg);
        border-radius:var(--rs-radius-lg); overflow:hidden; max-width:1400px; width:100%; margin:0 auto;
        box-shadow:0 1px 3px rgba(0,0,0,.06); box-sizing:border-box; }
    /* Krytyczne dla telefonów: bez min-width:0 elementy grid/flex (karty, wiersze, kontenery tabel)
       domyślnie NIE chcą się zmniejszyć poniżej naturalnej szerokości swojej treści (np. szerokiej
       tabeli), co rozpycha całą stronę w bok i uniemożliwia normalne przewijanie. */
    #snspa-root, #snspa-root *{ box-sizing:border-box; min-width:0; }
    #snspa-root img, #snspa-root svg{ max-width:100%; }
    #snspa-root h1, #snspa-root h2, #snspa-root h3, #snspa-root h4{ font-weight:700; }
    @media (max-width:639px){ #snspa-root .snspa-body{ padding-bottom:90px; } }

    /* Sekcje główne (Dzień/Raporty/Więcej) — pełne pigułki, sticky */
    .snspa-tabs{ display:flex; gap:8px; padding:12px 16px; flex-wrap:wrap; position:sticky; top:0; z-index:21;
        background:rgba(255,255,255,.97); border-bottom:1px solid var(--rs-border); }
    .snspa-tab{ border:1px solid var(--rs-border); background:var(--rs-surface); color:var(--rs-text-muted);
        padding:9px 18px; border-radius:999px; font-size:14px; font-weight:600; cursor:pointer;
        display:inline-flex; align-items:center; gap:8px; transition:transform .15s ease, background .15s ease, color .15s ease; }
    .snspa-tab:active{ transform:scale(.97); }
    .snspa-tab.active{ background:var(--rs-accent); border-color:var(--rs-accent); color:#fff; box-shadow:0 1px 2px rgba(37,99,235,.3); }

    /* Dolna nawigacja — telefon, do 3 głównych widoków */
    .snspa-bottom-nav{ display:none; }
    @media (max-width:639px){
        .snspa-bottom-nav{
            display:flex; position:fixed; left:0; right:0; bottom:0; z-index:40; max-width:1400px; margin:0 auto;
            padding:8px 4px calc(8px + env(safe-area-inset-bottom, 0px));
            background:#fff; border-top:1px solid var(--rs-border); box-shadow:0 -4px 10px -1px rgba(0,0,0,.08);
        }
        .snspa-nav-item{ flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; padding:6px 2px;
            border:none; background:transparent; color:var(--rs-text-faint); font-size:10px; font-weight:600; cursor:pointer; border-radius:12px; }
        .snspa-nav-item .ic{ font-size:18px; line-height:1; }
        .snspa-nav-item.active{ color:var(--rs-accent); }
        .snspa-nav-item.active .ic{ transform:scale(1.1); }
        .snspa-tabs.snspa-tabs-primary{ display:none; }
    }

    /* Podzakładki wewnątrz sekcji — mniejsze, "miękkie" pigułki, sticky.
       Zamiast zawijać się w kilka wierszy (co potrafiło zjeść pół ekranu i zasłonić
       treść pod spodem), przewijają się w JEDNEJ linii w bok. Odstęp od góry (--subtabs-top)
       jest mierzony w JS na podstawie realnej wysokości paska sekcji głównych, żeby nigdy
       na niego nie nachodzić — na telefonie, gdzie ten pasek jest ukryty, offset wynosi 0. */
    .snspa-subtabs{ position:sticky; top:var(--subtabs-top, 0px); z-index:20; display:flex; gap:6px; padding:10px 16px;
        flex-wrap:nowrap; background:#fff; border-bottom:1px solid var(--rs-border); overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .snspa-subtab{ border:1px solid transparent; background:transparent; color:var(--rs-text-muted); flex:0 0 auto;
        padding:7px 14px; border-radius:var(--rs-radius-md); font-size:12px; font-weight:600; cursor:pointer;
        transition:transform .15s ease; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; }
    .snspa-subtab:hover{ background:var(--rs-surface-alt); }
    .snspa-subtab:active{ transform:scale(.97); }
    .snspa-subtab.active{ background:var(--rs-accent-soft); border-color:var(--rs-border-mid); color:var(--rs-accent-text); box-shadow:0 1px 2px rgba(0,0,0,.04); }

    .snspa-datebar{ display:flex; align-items:center; justify-content:center; gap:14px; padding:20px 16px 8px; }
    .snspa-datebar button{ width:40px; height:40px; border-radius:999px; background:var(--rs-surface); border:1px solid var(--rs-border);
        color:var(--rs-text-muted); cursor:pointer; font-size:14px; box-shadow:0 1px 2px rgba(0,0,0,.04); transition:transform .15s ease; }
    .snspa-datebar button:hover{ background:var(--rs-surface-alt); }
    .snspa-datebar button:active{ transform:scale(.93); }
    .snspa-datebar input[type=date]{ border:1px solid var(--rs-border); border-radius:var(--rs-radius); padding:9px 14px; font-size:15px;
        font-weight:700; background:var(--rs-surface); color:var(--rs-text); box-shadow:0 1px 2px rgba(0,0,0,.04); }

    .snspa-body{ padding:16px 16px 26px; max-width:1400px; margin:0 auto; }
    .snspa-grid{ display:grid; grid-template-columns:1fr; gap:20px; }
    @media (min-width:768px){ .snspa-grid{ grid-template-columns:1fr 1fr; } }

    .snspa-card{ background:var(--rs-surface); border-radius:var(--rs-radius-lg); padding:22px;
        box-shadow:0 1px 2px rgba(0,0,0,.04); border:1px solid var(--rs-border); }
    /* Karty wprowadzania danych (Manager/Bar/Kuchnia/Twoja gotówka) — lekko podkreślone niebieskim */
    .snspa-card.role-manager, .snspa-card.role-bar, .snspa-card.role-kitchen, .snspa-card.role-waiter{
        border:2px solid var(--rs-border-light); }
    /* Karty z wynikiem/rozliczeniem — neutralne z odrobiną koloru */
    .snspa-card.role-summary{ border:1px solid var(--rs-border-mid); }
    /* Karta z konkretną, wyszukaną odpowiedzią (np. Sprawdź pracownika) — mocniej zaakcentowana */
    .snspa-card.role-result{ border:2px solid var(--rs-border-strong); box-shadow:0 4px 10px rgba(37,99,235,.08); }
    .snspa-card h3{ margin:0 0 18px; font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:var(--rs-text); font-weight:700; }

    .snspa-row{ display:flex; justify-content:space-between; align-items:center; gap:8px; padding:0 0 16px; margin-bottom:16px;
        border-bottom:1px solid var(--rs-border); font-size:14px; flex-wrap:wrap; }
    .snspa-row:last-child{ border-bottom:none; margin-bottom:0; padding-bottom:0; }
    .snspa-row .name{ flex:1 1 auto; min-width:60px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:500; color:var(--rs-text); }
    .snspa-row > span:not(.name){ display:flex; align-items:center; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
    .snspa-row input[type=number]{ width:64px; padding:7px; border:1px solid var(--rs-border); border-radius:var(--rs-radius-md);
        font-size:14px; font-weight:600; text-align:center; background:var(--rs-surface-alt); color:var(--rs-text); }
    .snspa-row input[type=number]:focus{ outline:none; border-color:var(--rs-accent); background:#fff; }

    .snspa-tag{ font-size:10px; background:var(--rs-accent-soft); color:var(--rs-accent-text); padding:3px 8px; border-radius:999px; margin-left:6px; font-weight:600; }
    .snspa-total{ display:flex; justify-content:space-between; align-items:flex-end; margin-top:14px; padding-top:14px; border-top:1px solid var(--rs-border); font-weight:700; font-size:15px; }

    .snspa-save{ width:100%; margin-top:14px; background:var(--rs-accent); color:#fff; border:none; padding:13px; border-radius:var(--rs-radius);
        font-weight:700; cursor:pointer; font-size:14px; box-shadow:0 1px 2px rgba(0,0,0,.05); transition:transform .1s ease, background .15s ease;
        display:flex; align-items:center; justify-content:center; gap:8px; }
    .snspa-save:active{ transform:scale(.98); }
    .snspa-save:hover{ background:var(--rs-accent-dark); }
    .snspa-save.secondary{ background:var(--rs-surface-alt); color:var(--rs-text); border:1px solid var(--rs-border); }
    .snspa-save.secondary:hover{ background:var(--rs-border-light); }

    .snspa-payout{ font-size:32px; font-weight:800; color:var(--rs-success); text-align:right; letter-spacing:-0.02em; }
    .snspa-neg{ color:var(--rs-danger); }

    .snspa-table{ width:auto; max-width:100%; border-collapse:collapse; font-size:13px; text-align:right; }
    .snspa-table th{ text-align:right; padding:10px 8px; background:var(--rs-surface-alt); font-size:10px; text-transform:uppercase;
        letter-spacing:.05em; color:var(--rs-text-faint); font-weight:700; white-space:nowrap; }
    .snspa-table th:first-child, .snspa-table td:first-child{ text-align:left; }
    .snspa-table td{ padding:10px 8px; border-bottom:1px solid var(--rs-border-light); color:var(--rs-text-muted); font-weight:500; white-space:nowrap; }
    .snspa-table tbody tr:hover{ background:var(--rs-surface-alt); }

    /* Kontener przewijanej w bok tabeli — kluczowe dla telefonów: scrollowanie ZOSTAJE
       wewnątrz tego pudełka (nie rozpycha całej strony), a delikatny gradient na prawym
       krańcu podpowiada, że jest więcej treści do przesunięcia. */
    .snspa-table-wrap{ position:relative; width:100%; max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .snspa-table-wrap.is-scrollable::after{
        content:''; position:absolute; top:0; right:0; bottom:0; width:22px; pointer-events:none;
        background:linear-gradient(to right, rgba(255,255,255,0), rgba(255,255,255,.9)); transition:opacity .15s ease;
    }
    .snspa-table-wrap.is-scrollable.scrolled-end::after{ opacity:0; }
    .snspa-scroll-hint{ display:flex; align-items:center; gap:6px; justify-content:center; color:var(--rs-text-faint);
        font-size:11px; font-weight:600; margin:0 0 6px; }
    @media (min-width:768px){ .snspa-scroll-hint{ display:none !important; } }

    .snspa-muted{ color:var(--rs-text-muted); font-size:12px; }
    .snspa-msg{ background:var(--rs-success-soft); color:var(--rs-success); padding:10px 14px; border-radius:var(--rs-radius); font-size:13px; margin-bottom:10px; text-align:center; font-weight:600; }
    .snspa-msg.err{ background:var(--rs-danger-soft); color:var(--rs-danger); }

    /* Wszystkie <select> w aplikacji — własny wygląd (bez natywnego "chrome" przeglądarki),
       żeby wysokość i padding były w 100% pod naszą kontrolą. Natywne selecty w różnych
       przeglądarkach różnie liczą wysokość względem line-height/font-size, co przy niestandardowym
       paddingu potrafiło obcinać tekst od górnej i dolnej krawędzi. */
    #snspa-root select{
        -webkit-appearance:none; -moz-appearance:none; appearance:none;
        line-height:1.3; height:36px; box-sizing:border-box;
        background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='%236B7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
        background-repeat:no-repeat; background-position:right 8px center; background-size:14px;
        padding:0 26px 0 10px;
    }

    .snspa-helper-row{ background:var(--rs-accent-soft); border:1px dashed var(--rs-border-mid); border-radius:var(--rs-radius); padding:12px; margin-top:8px; }
    .snspa-helper-row select{ font-size:13px; border-radius:var(--rs-radius-md); border:1px solid var(--rs-border-mid); max-width:130px; background-color:#fff; color:var(--rs-accent-text); font-weight:500; }

    .snspa-loading{ text-align:center; padding:40px; color:var(--rs-text-muted); }

    .snspa-settings label{ display:flex; align-items:center; gap:10px; font-size:14px; margin-bottom:14px; font-weight:500; color:var(--rs-text); cursor:pointer; }
    .snspa-settings input[type=checkbox]{ width:20px; height:20px; accent-color:var(--rs-accent); border-radius:4px; }
    .snspa-settings input[type=number]{ width:80px; padding:8px; border:1px solid var(--rs-border); border-radius:var(--rs-radius-md); font-weight:700; text-align:center; }

    .snspa-month-select{ display:flex; gap:8px; align-items:center; justify-content:center; padding:14px 16px 0; flex-wrap:wrap; }
    .snspa-month-select select,.snspa-month-select input{ border:1px solid var(--rs-border); border-radius:var(--rs-radius-md);
        font-size:13px; font-weight:600; background-color:var(--rs-surface); color:var(--rs-text); box-shadow:0 1px 2px rgba(0,0,0,.03); }
    .snspa-month-select input{ padding:8px 12px; height:36px; box-sizing:border-box; }

    /* Segmented control (Ogólny/Szczegółowy, itp.) — pigułkowy kontener w stylu mockupu */
    .snspa-view-toggle{ display:flex; gap:4px; padding:10px 16px 0; justify-content:flex-end; }
    .snspa-view-toggle-inner{ display:flex; gap:2px; background:var(--rs-surface-alt); border-radius:999px; padding:4px; }
    .snspa-view-btn{ border:none; background:transparent; color:var(--rs-text-muted); padding:7px 16px; border-radius:999px; font-size:12px; font-weight:600; cursor:pointer; transition:transform .15s ease; }
    .snspa-view-btn:active{ transform:scale(.97); }
    .snspa-view-btn.active{ background:var(--rs-accent); color:#fff; box-shadow:0 1px 2px rgba(0,0,0,.1); }

    .snspa-summary-box{ border-radius:var(--rs-radius-lg); padding:22px; background:var(--rs-surface); border:2px solid var(--rs-border);
        box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .snspa-summary-box.box-blue{ border-color:var(--rs-border-light); }
    .snspa-summary-box.box-green{ border-color:#BBF7D0; }
    .snspa-summary-box.box-green h3{ color:var(--rs-success); }
    .snspa-card.box-green{ border:1px solid var(--rs-success-border); }
    .snspa-summary-meta{ display:flex; flex-direction:column; gap:4px; font-size:13px; color:var(--rs-text-muted); margin:6px 0 14px; }
    @media (min-width:480px){ .snspa-summary-meta{ flex-direction:row; justify-content:space-between; gap:6px; } }

    .snspa-day-card{ background:var(--rs-surface); border-radius:var(--rs-radius); padding:14px; border:1px solid var(--rs-border);
        box-shadow:0 1px 2px rgba(0,0,0,.03); }
    .snspa-day-card h4{ margin:0 0 8px; font-size:13px; }

    .snspa-stat-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-bottom:16px; }
    @media (min-width:640px){ .snspa-stat-grid{ grid-template-columns:repeat(4,1fr); } }
    .snspa-stat-card{ background:var(--rs-surface); border-radius:var(--rs-radius); padding:16px; border:1px solid var(--rs-border);
        box-shadow:0 1px 2px rgba(0,0,0,.03); }
    .snspa-stat-card .lbl{ font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--rs-text-faint); font-weight:700; }
    .snspa-stat-card .val{ font-size:22px; font-weight:800; margin-top:6px; letter-spacing:-0.01em; }

    .snspa-badge{ display:inline-block; padding:4px 10px; border-radius:999px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; }
    .snspa-badge.ok{ background:var(--rs-success-soft); color:var(--rs-success); }
    .snspa-badge.debt{ background:var(--rs-danger-soft); color:var(--rs-danger); }
    .snspa-badge.surplus{ background:var(--rs-warning-soft); color:var(--rs-warning); }
    .snspa-badge.none{ background:var(--rs-surface-alt); color:var(--rs-text-faint); }

    .snspa-dept-toggle{ display:flex; gap:2px; margin-top:10px; background:var(--rs-surface-alt); border-radius:999px; padding:4px; width:fit-content; }
    .snspa-dept-btn{ border:none; background:transparent; color:var(--rs-text-muted); padding:8px 16px; border-radius:999px; font-weight:600; font-size:12px; cursor:pointer; transition:transform .15s ease; }
    .snspa-dept-btn:active{ transform:scale(.97); }
    .snspa-dept-btn.active{ background:var(--rs-accent); color:#fff; box-shadow:0 1px 2px rgba(0,0,0,.1); }

    /* Zakres dat / meta — nigdy brzydkiego łamania w losowym miejscu */
    .snspa-daterange{ display:flex; flex-direction:column; align-items:center; gap:2px; }
    @media (min-width:480px){ .snspa-daterange{ flex-direction:row; gap:8px; } }
    </style>
    <?php
    return ob_get_clean();
}

function snspa_script() {
    return snspa_font_globals_script() . snspa_js_body();
}

// Wstrzykuje osadzone czcionki (DejaVu Sans, wycięty podzbiór ~30KB z polskimi znakami)
// jako zmienne globalne JS, żeby generator PDF mógł je zarejestrować w jsPDF.
function snspa_font_globals_script() {
    $dir = plugin_dir_path(__FILE__) . '../assets/';
    $regular = @file_get_contents($dir . 'dejavu-regular.b64');
    $bold = @file_get_contents($dir . 'dejavu-bold.b64');
    $regular = $regular ? trim($regular) : '';
    $bold = $bold ? trim($bold) : '';
    return '<script>window.__snspaFontRegular=' . wp_json_encode($regular) . ';window.__snspaFontBold=' . wp_json_encode($bold) . ';</script>';
}

function snspa_js_body() {
    // Nowdoc-safe JS (no PHP variable interpolation needed inside)
    return <<<'JS'
    <script>
    (function(){
    var root = document.getElementById('snspa-root');
    if (!root) return;
    var REST = root.dataset.rest;
    var NONCE = root.dataset.nonce;
    var state = { date: new Date().toISOString().slice(0,10), tab: 'day', sectionTab: { day: 'day' }, flags: null, day: null, month: null, settings: null,
                  monthM: (new Date().getMonth()+1), monthY: new Date().getFullYear(), monthView: 'general', monthSummary: null, msg: null,
                  staffList: null, checkUserId: '', checkM: (new Date().getMonth()+1), checkY: new Date().getFullYear(), checkData: null,
                  poolDate: new Date().toISOString().slice(0,10), poolData: null, poolMode: 'hours',
                  wsM: (new Date().getMonth()+1), wsY: new Date().getFullYear(), wsData: null, wsDept: null,
                  emailM: (new Date().getMonth()+1), emailY: new Date().getFullYear(), emailTemplates: {}, emailMsg: {},
                  pushM: (new Date().getMonth()+1), pushY: new Date().getFullYear(), pushTemplates: {}, pushMsg: {},
                  onesignalSettings: null, percentOverrides: null,
                  srM: (new Date().getMonth()+1), srY: new Date().getFullYear(), srDept: 'kuchnia', srData: null };

    function fmt(n){ n = parseFloat(n||0); return n.toLocaleString('pl-PL', {minimumFractionDigits:2, maximumFractionDigits:2}); }
    function fmtDate(iso){ var p = iso.split('-'); return p[2] + '.' + p[1] + '.' + p[0]; }
    function isValidIsoDate(str){
        if (!str || !/^\d{4}-\d{2}-\d{2}$/.test(str)) return false;
        var dt = new Date(str + 'T00:00:00');
        return !isNaN(dt.getTime());
    }
    function todayIso(){ return new Date().toISOString().slice(0,10); }
    function esc(s){ return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function ensureJsPdfLibs(cb){
        if (window.jspdf && window.__snspaAutoTableReady) { cb(); return; }
        if (window.__snspaPdfLoading){ window.__snspaPdfQueue = window.__snspaPdfQueue || []; window.__snspaPdfQueue.push(cb); return; }
        window.__snspaPdfLoading = true;
        var s1 = document.createElement('script');
        s1.src = 'https://unpkg.com/jspdf@2.5.2/dist/jspdf.umd.min.js';
        s1.onload = function(){
            var s2 = document.createElement('script');
            s2.src = 'https://unpkg.com/jspdf-autotable@5.0.8/dist/jspdf.plugin.autotable.min.js';
            s2.onload = function(){
                window.__snspaAutoTableReady = true;
                cb();
                (window.__snspaPdfQueue || []).forEach(function(fn){ fn(); });
                window.__snspaPdfQueue = [];
            };
            document.head.appendChild(s2);
        };
        document.head.appendChild(s1);
    }

    // Zwraca tekst komórki tabeli z <br> zamienionym na spację (żeby słowa się nie zlepiały)
    // i bez nadmiarowych białych znaków.
    function snspaCellText(el){
        var clone = el.cloneNode(true);
        clone.querySelectorAll('br').forEach(function(br){ br.replaceWith(' '); });
        return clone.textContent.replace(/\s+/g, ' ').trim();
    }

    // Dla kontenerów z kilkoma rodzeństwami (np. <span>DATA: ..</span><span>SUMA: ..</span>) —
    // łączy tekst każdego dziecka z separatorem, żeby się nie zlepiały w jedno słowo.
    function snspaMetaText(el){
        if (el.children.length > 1){
            return Array.prototype.map.call(el.children, snspaCellText).join('   ');
        }
        return snspaCellText(el);
    }

    // Rozwija colspan tak, żeby liczba komórek w wierszu zgadzała się z liczbą kolumn nagłówka
    // (inaczej autoTable źle dopasuje kolumny np. w wierszu SUMA).
    function snspaRowCells(tr){
        var out = [];
        Array.prototype.forEach.call(tr.children, function(td){
            var span = parseInt(td.getAttribute('colspan') || '1', 10);
            out.push(snspaCellText(td));
            for (var i = 1; i < span; i++) out.push('');
        });
        return out;
    }

    function snspaExtractTable(table){
        var head = Array.prototype.map.call(table.querySelectorAll('thead tr'), snspaRowCells);
        var body = Array.prototype.map.call(table.querySelectorAll('tbody tr'), snspaRowCells);
        var foot = Array.prototype.map.call(table.querySelectorAll('tfoot tr'), snspaRowCells);
        return { head: head, body: body, foot: foot.length ? foot : undefined };
    }

    function snspaSetupDoc(orientation){
        var jsPDF = window.jspdf.jsPDF;
        var doc = new jsPDF({ orientation: orientation || 'landscape', unit: 'mm', format: 'a4' });
        if (window.__snspaFontRegular){
            doc.addFileToVFS('DejaVuSans.ttf', window.__snspaFontRegular);
            doc.addFont('DejaVuSans.ttf', 'DejaVuSans', 'normal');
        }
        if (window.__snspaFontBold){
            doc.addFileToVFS('DejaVuSans-Bold.ttf', window.__snspaFontBold);
            doc.addFont('DejaVuSans-Bold.ttf', 'DejaVuSans', 'bold');
        }
        doc.setFont('DejaVuSans', 'normal');
        return doc;
    }

    // Generuje PDF z RZECZYWISTYCH danych (tekst + tabele czytane z DOM), a nie ze zrzutu
    // ekranu — zrzuty ekranu (html2canvas -> jsPDF) w niektórych środowiskach dawały
    // uszkodzone, puste strony. Ten sposób jest niezawodny i dodatkowo daje przeszukiwalny,
    // kopiowalny tekst w PDF (łącznie z polskimi znakami dzięki osadzonej czcionce DejaVu Sans).
    function exportPdf(elementId, filename, orientation){
        var el = document.getElementById(elementId);
        if (!el) return;
        ensureJsPdfLibs(function(){
            try {
                var doc = snspaSetupDoc(orientation);
                var margin = 10;
                var pageWidth = doc.internal.pageSize.getWidth();
                var pageHeight = doc.internal.pageSize.getHeight();
                var y = margin + 5;

                var dayCards = el.querySelectorAll(':scope > .snspa-day-card, :scope .snspa-day-card');
                if (dayCards.length){
                    dayCards.forEach(function(card){
                        if (y > pageHeight - 40){ doc.addPage(); y = margin + 5; }
                        var h = card.querySelector('h4');
                        if (h){
                            doc.setFontSize(11);
                            doc.setFont('DejaVuSans', 'bold');
                            doc.text(snspaCellText(h), margin, y);
                            doc.setFont('DejaVuSans', 'normal');
                            y += 6;
                        }
                        var table = card.querySelector('table');
                        if (table){
                            var data = snspaExtractTable(table);
                            doc.autoTable({ head: data.head, body: data.body, foot: data.foot, startY: y,
                                margin: { left: margin, right: margin },
                                styles: { font: 'DejaVuSans', fontSize: 8 },
                                headStyles: { font: 'DejaVuSans', fontStyle: 'bold', fillColor: [37,99,235] },
                                footStyles: { font: 'DejaVuSans', fontStyle: 'bold', fillColor: [241,245,249], textColor: [31,41,55] } });
                            y = doc.lastAutoTable.finalY + 10;
                        }
                    });
                } else {
                    var heading = el.querySelector('h3, h4');
                    if (heading){
                        doc.setFontSize(14);
                        doc.setFont('DejaVuSans', 'bold');
                        doc.text(snspaCellText(heading), margin, y);
                        doc.setFont('DejaVuSans', 'normal');
                        y += 8;
                    }
                    var metaNodes = el.querySelectorAll(':scope > .snspa-summary-meta, :scope > .snspa-muted');
                    metaNodes.forEach(function(m){
                        doc.setFontSize(9);
                        doc.setTextColor(107,114,128);
                        doc.text(snspaMetaText(m), margin, y);
                        doc.setTextColor(0,0,0);
                        y += 6;
                    });
                    y += 2;

                    var tables = el.querySelectorAll('table');
                    tables.forEach(function(table){
                        if (y > pageHeight - 40){ doc.addPage(); y = margin + 5; }
                        var data = snspaExtractTable(table);
                        doc.autoTable({ head: data.head, body: data.body, foot: data.foot, startY: y,
                            margin: { left: margin, right: margin },
                            styles: { font: 'DejaVuSans', fontSize: 9 },
                            headStyles: { font: 'DejaVuSans', fontStyle: 'bold', fillColor: [37,99,235] },
                            footStyles: { font: 'DejaVuSans', fontStyle: 'bold', fillColor: [241,245,249], textColor: [31,41,55] } });
                        y = doc.lastAutoTable.finalY + 10;
                    });
                }

                doc.save(filename);
            } catch (err) {
                alert('Błąd generowania PDF: ' + (err && err.message ? err.message : err));
            }
        });
    }

    function api(path, method, body){
        return fetch(REST + path, {
            method: method || 'GET',
            headers: Object.assign({'Content-Type':'application/json'}, {'X-WP-Nonce': NONCE}),
            body: body ? JSON.stringify(body) : undefined
        }).then(function(r){
            if(!r.ok) return r.json().then(function(e){ throw new Error(e.message || 'Błąd serwera'); });
            return r.json();
        });
    }

    var TAB_META = {
        day: { section:'day', label:'Dzień', icon:'fa-calendar-day' },
        month: { section:'reports', label:'Miesiąc', icon:'fa-calendar-alt' },
        check: { section:'reports', label:'Sprawdź pracownika', icon:'fa-search' },
        servicePool: { section:'reports', label:'Rozliczenie serwisu', icon:'fa-balance-scale' },
        waiterSettlement: { section:'reports', label:'Rozliczenie z kelnerami', icon:'fa-handshake' },
        sectionReport: { section:'reports', label:'Raport dla sekcji', icon:'fa-file-signature' },
        email: { section:'more', label:'E-mail', icon:'fa-envelope' },
        push: { section:'more', label:'Powiadomienia', icon:'fa-bell' },
        settings: { section:'more', label:'Ustawienia', icon:'fa-cog' }
    };
    var SECTION_META = {
        day: { label:'Dzień', icon:'fa-calendar-day' },
        reports: { label:'Raporty', icon:'fa-chart-bar' },
        more: { label:'Więcej', icon:'fa-cog' }
    };
    var SECTION_ORDER = ['day', 'reports', 'more'];
    function ic(cls){ return '<i class="fas ' + cls + '"></i>'; }

    function visibleTabs(){
        var f = state.flags || {};
        var ids = ['day', 'month'];
        if (f.isManager) ids.push('check');
        if (f.isAdminBar || f.isAdminKtc) ids.push('servicePool', 'waiterSettlement');
        // Raport miesięczny dla kuchni/baru — wyłącznie administrator strony.
        if (f.canSectionReport) ids.push('sectionReport');
        if (f.isManager || f.isAdminBar || f.isAdminKtc) ids.push('email', 'push');
        if (f.isAdmin) ids.push('settings');
        return ids;
    }

    function tabsBySection(tabs){
        var out = {};
        tabs.forEach(function(id){
            var sec = TAB_META[id].section;
            (out[sec] = out[sec] || []).push(id);
        });
        return out;
    }

    function switchTab(id){
        state.tab = id;
        state.msg = null;
        var sec = TAB_META[id].section;
        state.sectionTab[sec] = id;
        if (id === 'day' && !state.day) loadDay();
        if (id === 'month' && !state.month) loadMonth();
        if (id === 'settings' && !state.settings) loadSettings();
        if (id === 'settings' && !state.onesignalSettings) { api('/onesignal-settings').then(function(d){ state.onesignalSettings = d; render(); }); }
        if (id === 'check' && !state.staffList) { loadStaffList().then(render); }
        if (id === 'servicePool' && !state.poolData) loadServicePool();
        if (id === 'waiterSettlement' && !state.wsData) loadWaiterSettlement();
        if (id === 'sectionReport' && !state.srData) loadSectionReport();
        if (id === 'email' && !Object.keys(state.emailTemplates).length) { loadEmailTemplates(); }
        if (id === 'push' && !Object.keys(state.pushTemplates).length) { loadPushTemplates(); }
        render();
    }

    function render(){
        var f = state.flags || {};
        var tabs = visibleTabs();
        var bySection = tabsBySection(tabs);
        var sections = SECTION_ORDER.filter(function(s){ return bySection[s] && bySection[s].length; });
        var currentSection = TAB_META[state.tab] ? TAB_META[state.tab].section : 'day';

        var html = '';

        // Segmented control głównych sekcji (desktop) — ukryty na telefonie na rzecz dolnej nawigacji
        html += '<div class="snspa-tabs snspa-tabs-primary">';
        sections.forEach(function(sec){
            html += '<button class="snspa-tab' + (sec===currentSection?' active':'') + '" data-section="' + sec + '">' +
                ic(SECTION_META[sec].icon) + ' ' + SECTION_META[sec].label + '</button>';
        });
        html += '</div>';

        // Podzakładki sekcji (jeśli >1 widok w danej sekcji) — sticky przy przewijaniu
        if (bySection[currentSection] && bySection[currentSection].length > 1){
            html += '<div class="snspa-subtabs">';
            bySection[currentSection].forEach(function(id){
                html += '<button class="snspa-subtab' + (state.tab===id?' active':'') + '" data-subtab="' + id + '">' +
                    ic(TAB_META[id].icon) + ' ' + TAB_META[id].label + '</button>';
            });
            html += '</div>';
        }

        if (state.tab === 'day') html += renderDay();
        else if (state.tab === 'month') html += renderMonth();
        else if (state.tab === 'check') html += renderCheckStaff();
        else if (state.tab === 'servicePool') html += renderServicePool();
        else if (state.tab === 'waiterSettlement') html += renderWaiterSettlement();
        else if (state.tab === 'sectionReport') html += renderSectionReport();
        else if (state.tab === 'email') html += renderEmail();
        else if (state.tab === 'push') html += renderPush();
        else if (state.tab === 'settings') html += renderSettings();

        // Dolna nawigacja (telefon) — te same sekcje co segmented control powyżej
        html += '<div class="snspa-bottom-nav">';
        sections.forEach(function(sec){
            html += '<button class="snspa-nav-item' + (sec===currentSection?' active':'') + '" data-section="' + sec + '">' +
                '<span class="ic">' + ic(SECTION_META[sec].icon) + '</span><span>' + SECTION_META[sec].label + '</span></button>';
        });
        html += '</div>';

        root.innerHTML = html;
        bindEvents();
        enhanceScrollableTables();
        updateStickyOffsets();
    }

    // Mierzy REALNĄ wysokość paska sekcji głównych (Dzień/Raporty/Więcej) i ustawia ją jako
    // offset dla podzakładek poniżej, żeby nigdy się nie nakładały. Na telefonie ten pasek
    // jest ukryty (display:none), więc jego wysokość to naturalnie 0 — podzakładki przyklejają
    // się od samej góry, bez żadnego magicznego, "zgadywanego" numeru pikseli.
    function updateStickyOffsets(){
        var primary = root.querySelector('.snspa-tabs-primary');
        var h = primary ? primary.offsetHeight : 0;
        root.style.setProperty('--subtabs-top', h + 'px');
    }

    // Wykrywa tabele, które FAKTYCZNIE nie mieszczą się na ekranie (scrollWidth > clientWidth)
    // i dopiero wtedy dodaje delikatny gradient + podpowiedź "przesuń w bok" — nie na siłę,
    // tylko tam, gdzie przewijanie jest naprawdę potrzebne i możliwe.
    function enhanceScrollableTables(){
        if (window.innerWidth >= 768) return;
        root.querySelectorAll('.snspa-table-wrap').forEach(function(wrap){
            if (wrap.scrollWidth <= wrap.clientWidth + 2) return;
            wrap.classList.add('is-scrollable');
            var hint = document.createElement('div');
            hint.className = 'snspa-scroll-hint';
            hint.innerHTML = '<i class="fas fa-arrows-left-right"></i> Przesuń w bok, aby zobaczyć więcej';
            wrap.parentNode.insertBefore(hint, wrap);
            wrap.addEventListener('scroll', function(){
                var atEnd = wrap.scrollLeft + wrap.clientWidth >= wrap.scrollWidth - 2;
                wrap.classList.toggle('scrolled-end', atEnd);
            });
        });
    }

    function renderDay(){
        var d = state.day;
        var f = state.flags || {};
        var html = '<div class="snspa-datebar">';
        html += '<button data-nav="-1">←</button>';
        html += '<input type="date" id="snspa-date" value="' + state.date + '">';
        html += '<button data-nav="1">→</button>';
        html += '</div><div class="snspa-body">';

        if (state.msg) html += '<div class="snspa-msg' + (state.msg.err?' err':'') + '">' + state.msg.text + '</div>';

        if (!d) { html += '<div class="snspa-loading">Ładowanie…</div>'; html += '</div>'; return html; }

        html += '<div class="snspa-grid">';

        if (d.reservations && d.reservations.length){
            html += '<div class="snspa-card role-summary" style="grid-column:1/-1;"><h3>Rezerwacje tego dnia</h3>';
            d.reservations.forEach(function(r){
                html += '<div class="snspa-row"><span class="name">' + r.name + ' <span class="snspa-tag">' + r.paymentType.toUpperCase() + '</span></span><span>' + fmt(r.amount) + ' zł</span></div>';
            });
            html += '</div>';
        }

        if (d.manager){
            html += '<div class="snspa-card role-manager"><h3>Manager — karta / serwis kelnerów</h3>';
            d.manager.waiters.forEach(function(w){
                html += '<div class="snspa-row"><span class="name">' + w.name + '</span><span>' +
                    'K:<input type="number" step="0.01" data-mgr-card="' + w.id + '" value="' + w.card + '">' +
                    ' S:<input type="number" step="0.01" data-mgr-service="' + w.id + '" value="' + w.service + '">' +
                    '</span></div>';
            });
            html += '<div class="snspa-total"><span>Suma</span><span>Karta ' + fmt(d.manager.sumCard) + ' / Serwis ' + fmt(d.manager.sumService) + '</span></div>';
            html += '<button class="snspa-save" data-save="manager">Zapisz i przelicz</button></div>';
        }

        if (f.canSetPercentOverrides && d.manager){
            html += '<div class="snspa-card role-manager"><h3><i class="fas fa-percent"></i> Wyjątki procentowe (ten dzień)</h3>';
            html += '<div class="snspa-muted" style="margin-bottom:10px;">Domyślnie 10% dla baru i 20% dla kuchni, osobno dla karty i osobno dla serwisu. Zmień poniżej dla wybranych osób TYLKO na ' + fmtDate(state.date) + ' — kolejne dni wracają do wartości domyślnych.</div>';
            if (!state.percentOverrides || !state.percentOverrides.waiters){
                html += '<div class="snspa-loading">Ładowanie…</div>';
            } else if (!state.percentOverrides.waiters.length){
                html += '<div class="snspa-muted">Brak aktywnych kelnerów tego dnia.</div>';
            } else {
                state.percentOverrides.waiters.forEach(function(w){
                    html += '<div style="padding:12px 0;border-bottom:1px solid var(--rs-border);">';
                    html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">' +
                        '<span style="font-weight:600;">' + w.name + (w.isOverride?' <span class="snspa-tag">wyjątek</span>':'') + '</span>' +
                        (w.isOverride ? '<button class="snspa-save secondary" style="width:auto;margin:0;padding:5px 10px;font-size:11px;" data-po-reset="' + w.id + '">Reset</button>' : '') +
                        '</div>';
                    html += '<div class="snspa-row" style="border:none;padding:0;margin-bottom:6px;"><span class="name" style="flex:0 0 56px;color:var(--rs-text-muted);font-size:12px;">Karta</span><span>' +
                        'Bar:<input type="number" step="0.01" min="0" max="100" data-po-card-bar="' + w.id + '" value="' + w.cardBarPercent + '" style="width:56px;">% ' +
                        'Kuchnia:<input type="number" step="0.01" min="0" max="100" data-po-card-kitchen="' + w.id + '" value="' + w.cardKitchenPercent + '" style="width:56px;">% ' +
                        '</span></div>';
                    html += '<div class="snspa-row" style="border:none;padding:0;"><span class="name" style="flex:0 0 56px;color:var(--rs-text-muted);font-size:12px;">Serwis</span><span>' +
                        'Bar:<input type="number" step="0.01" min="0" max="100" data-po-service-bar="' + w.id + '" value="' + w.serviceBarPercent + '" style="width:56px;">% ' +
                        'Kuchnia:<input type="number" step="0.01" min="0" max="100" data-po-service-kitchen="' + w.id + '" value="' + w.serviceKitchenPercent + '" style="width:56px;">% ' +
                        '</span></div>';
                    html += '</div>';
                });
                html += '<button class="snspa-save" data-save="percentOverrides">Zapisz wyjątki dla tego dnia</button>';
            }
            html += '</div>';
        }

        if (d.bar){
            html += '<div class="snspa-card role-bar"><h3>Bar — godziny pracy</h3>';
            d.bar.staff.forEach(function(s){
                html += '<div class="snspa-row"><span class="name">' + s.name + (s.helper?' <span class="snspa-tag">pomoc</span>':'') + '</span><span>' +
                    'H:<input type="number" step="0.01" data-bar-hours="' + s.id + '" data-orig="' + s.hours + '" value="' + s.hours + '">' +
                    ' +K:<input type="number" step="0.01" data-bar-extra="' + s.id + '" data-orig="' + s.extraCard + '" value="' + s.extraCard + '">' +
                    '</span></div>';
            });
            if (d.bar.helperOptions && d.bar.helperOptions.length){
                html += '<div class="snspa-helper-row"><select id="snspa-bar-helper-select"><option value="">+ dodaj pomoc (kelner)</option>';
                d.bar.helperOptions.forEach(function(o){ html += '<option value="' + o.id + '">' + o.name + '</option>'; });
                html += '</select> H:<input type="number" step="0.01" id="snspa-bar-helper-hours" style="width:40px;"> +K:<input type="number" step="0.01" id="snspa-bar-helper-card" style="width:45px;"></div>';
            }
            html += '<div class="snspa-muted" style="margin-top:8px;">Pula gotówki baru dzisiaj: ' + fmt(d.bar.cashPool) + ' zł</div>';
            html += '<button class="snspa-save" data-save="bar">Zapisz i przelicz</button>';
            if (d.bar.waiterCash && d.bar.waiterCash.length){
                html += '<h3 style="margin-top:16px;">Gotówka od kelnerów (10% dla baru)</h3>';
                html += '<div class="snspa-muted" style="margin-bottom:6px;">Wpisz kwotę oddaną do baru — kuchnia (20%) uzupełni się sama.</div>';
                d.bar.waiterCash.forEach(function(w){
                    html += '<div class="snspa-row"><span class="name">' + w.name + '</span><span><input type="number" step="0.01" data-bar-cash="' + w.id + '" value="' + w.amount + '"></span></div>';
                });
                html += '<button class="snspa-save" data-save="barCash">Zapisz gotówkę</button>';
            }
            html += '</div>';
        }

        if (d.kitchen){
            html += '<div class="snspa-card role-kitchen"><h3>Kuchnia — godziny pracy</h3>';
            d.kitchen.staff.forEach(function(s){
                html += '<div class="snspa-row"><span class="name">' + s.name + '</span><span>' +
                    'H:<input type="number" step="0.01" data-ktc-hours="' + s.id + '" data-orig="' + s.hours + '" value="' + s.hours + '">' +
                    ' +K:<input type="number" step="0.01" data-ktc-extra="' + s.id + '" data-orig="' + s.extraCard + '" value="' + s.extraCard + '">' +
                    '</span></div>';
            });
            html += '<div class="snspa-muted" style="margin-top:8px;">Pula gotówki kuchni dzisiaj: ' + fmt(d.kitchen.cashPool) + ' zł</div>';
            html += '<button class="snspa-save" data-save="kitchen">Zapisz i przelicz</button>';
            if (d.kitchen.waiterCash && d.kitchen.waiterCash.length){
                html += '<h3 style="margin-top:16px;">Gotówka od kelnerów (20% dla kuchni)</h3>';
                html += '<div class="snspa-muted" style="margin-bottom:6px;">Wpisz kwotę oddaną do kuchni — bar (10%) uzupełni się sam.</div>';
                d.kitchen.waiterCash.forEach(function(w){
                    html += '<div class="snspa-row"><span class="name">' + w.name + '</span><span><input type="number" step="0.01" data-ktc-cash="' + w.id + '" value="' + w.amount + '"></span></div>';
                });
                html += '<button class="snspa-save" data-save="kitchenCash">Zapisz gotówkę</button>';
            }
            html += '</div>';
        }

        if (d.kelnerSelf){
            var k = d.kelnerSelf;
            html += '<div class="snspa-card role-waiter"><h3>Twoja gotówka (oddana do baru/kuchni)</h3>';
            html += '<div class="snspa-muted" style="margin-bottom:6px;">Wpisz jedną z kwot — druga uzupełni się automatycznie (10% bar / 20% kuchnia z tej samej gotówki).</div>';
            html += '<div class="snspa-row"><span class="name">Dla baru (10%)</span><span><input type="number" step="0.01" id="snspa-cash-bar" value="' + k.cashGivenBar + '"></span></div>';
            html += '<div class="snspa-row"><span class="name">Dla kuchni (20%)</span><span><input type="number" step="0.01" id="snspa-cash-ktc" value="' + k.cashGivenKtc + '"></span></div>';
            html += '<button class="snspa-save" data-save="kelnerCash">Zapisz</button></div>';

            html += '<div class="snspa-card role-waiter"><h3>Twoje rozliczenie' + (k.age!=null?(' (wiek: ' + k.age + ' lat)'):'') + '</h3>';
            html += '<div class="snspa-row"><span>Napiwki z karty</span><span>' + fmt(k.cardRaw) + ' zł</span></div>';
            html += '<div class="snspa-row"><span>Napiwki z serwisu</span><span>' + fmt(k.serviceRaw) + ' zł</span></div>';
            html += '<div class="snspa-row"><span>Gotówka (100%)</span><span>' + fmt(k.cash100) + ' zł</span></div>';
            if (k.taxCut > 0.001) html += '<div class="snspa-row snspa-neg"><span>Korekta wiekowa (podatek 26+)</span><span>-' + fmt(k.taxCut) + ' zł</span></div>';
            html += '<div class="snspa-row snspa-neg"><span>- 10% dla baru</span><span>-' + fmt(k.poolBar) + ' zł</span></div>';
            html += '<div class="snspa-row snspa-neg"><span>- 20% dla kuchni</span><span>-' + fmt(k.poolKtc) + ' zł</span></div>';
            html += '<div class="snspa-total"><span>Do wypłaty (70%)</span></div>';
            html += '<div class="snspa-payout">' + fmt(k.payout) + ' zł</div>';
            html += '</div>';
        }

        if (d.staffSelf){
            var s = d.staffSelf;
            html += '<div class="snspa-card role-' + (s.type==='barman'?'bar':'kitchen') + '"><h3>Twoje godziny (' + s.type + ')</h3>';
            if (s.canEditHours){
                html += '<div class="snspa-row"><span>Godziny</span><span><input type="number" step="0.01" id="snspa-my-hours" value="' + s.hours + '"></span></div>';
                html += '<button class="snspa-save" data-save="myHours">Zapisz i przelicz</button></div>';
            } else {
                html += '<div class="snspa-row"><span>Godziny</span><span>' + fmt(s.hours) + ' h</span></div>';
                html += '<div class="snspa-muted" style="margin-top:8px;">Godziny wpisuje admin kuchni.</div></div>';
            }

            html += '<div class="snspa-card role-' + (s.type==='barman'?'bar':'kitchen') + '"><h3>Twoja wypłata</h3>';
            html += '<div class="snspa-row"><span>Z gotówki</span><span>' + fmt(s.calcCash) + ' zł</span></div>';
            html += '<div class="snspa-row"><span>Z karty' + (s.extraCard>0?(' <span class="snspa-tag">+' + fmt(s.extraCard) + ' rez.</span>'):'') + '</span><span>' + fmt(s.calcCard) + ' zł</span></div>';
            html += '<div class="snspa-row"><span>Serwis</span><span>' + fmt(s.calcService) + ' zł</span></div>';
            html += '<div class="snspa-total"><span>Razem</span></div>';
            html += '<div class="snspa-payout">' + fmt(s.calcCash + s.calcCard + s.calcService) + ' zł</div>';
            html += '</div>';
        }

        // "Podsumowanie dnia dla menagera" — pełny kolorowy box (screen 1, niebieski)
        if (d.manager){
            html += '<div class="snspa-summary-box box-blue" style="grid-column:1/-1;" id="snspa-pdf-mgr-summary">';
            html += '<h3 style="text-transform:none;font-size:16px;margin:0;">Podsumowanie dnia dla menagera</h3>';
            html += '<div class="snspa-summary-meta"><span>DATA: ' + fmtDate(state.date) + '</span><span>SUMA KARTA: ' + fmt(d.manager.sumCard) + ' zł | SUMA SERWIS: ' + fmt(d.manager.sumService) + ' zł</span></div>';
            html += '<div class="snspa-table-wrap"><table class="snspa-table"><thead><tr><th>Pracownik</th><th style="text-align:left;">Rola</th><th>Karta</th><th>Serwis</th></tr></thead><tbody>';
            d.manager.waiters.forEach(function(w){
                html += '<tr><td>' + w.name + '</td><td style="text-align:left;">kelner</td><td style="font-weight:700;">' + fmt(w.card) + '</td><td style="font-weight:700;">' + fmt(w.service) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            html += '<button class="snspa-save" data-pdf="snspa-pdf-mgr-summary|Podsumowanie_Managera_' + state.date + '.pdf|landscape"><i class="fas fa-file-download"></i> Pobierz PDF</button>';
            html += '</div>';
        }

        // "Podsumowanie dnia dla admina baru/kuchni" — pełny kolorowy box (screen 1, zielony) — tylko dla adminów działów/administratora
        if (d.staffSummary && (f.isAdminBar || f.isAdminKtc || f.isAdmin)){
            html += '<div class="snspa-summary-box box-green" style="grid-column:1/-1;" id="snspa-pdf-admin-summary">';
            html += '<h3 style="text-transform:none;font-size:16px;margin:0;">Podsumowanie dnia dla admina baru/kuchni</h3>';
            html += '<div class="snspa-summary-meta"><span>DATA: ' + fmtDate(state.date) + '</span></div>';
            html += '<div class="snspa-table-wrap"><table class="snspa-table"><thead><tr><th>Pracownik</th><th style="text-align:left;">H / Serwis</th><th style="text-align:left;">Rola</th><th>Z gotówki</th><th>Z karty</th><th>Serwis</th></tr></thead><tbody>';
            var t2c=0,t2k=0,t2s=0;
            d.staffSummary.forEach(function(r){
                t2c+=r.calcCash; t2k+=r.calcCard; t2s+=r.calcService;
                html += '<tr><td>' + r.name + '</td><td style="text-align:left;">' + r.hours + 'h / ' + r.serviceHours + 'h</td><td style="text-align:left;"><small>' + r.type + '</small></td>' +
                    '<td style="color:var(--rs-success);font-weight:700;">' + fmt(r.calcCash) + ' zł</td><td>' + fmt(r.calcCard) + '</td><td>' + fmt(r.calcService) + '</td></tr>';
            });
            html += '</tbody><tfoot><tr><td colspan="3" style="text-align:right;font-weight:700;">SUMA:</td><td style="color:var(--rs-success);font-weight:700;">' + fmt(t2c) + ' zł</td><td style="font-weight:700;">' + fmt(t2k) + ' zł</td><td style="font-weight:700;">' + fmt(t2s) + ' zł</td></tr></tfoot></table></div>';
            html += '<button class="snspa-save" data-pdf="snspa-pdf-admin-summary|Podsumowanie_Admina_' + state.date + '.pdf|landscape"><i class="fas fa-file-download"></i> Pobierz PDF</button>';
            html += '</div>';
        }

        html += '</div></div>';
        return html;
    }

    function renderMonth(){
        var months = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
        var html = '<div class="snspa-month-select">';
        html += '<select id="snspa-month-m">';
        for (var i=1;i<=12;i++) html += '<option value="' + i + '"' + (i===state.monthM?' selected':'') + '>' + months[i-1] + '</option>';
        html += '</select>';
        html += '<input type="number" id="snspa-month-y" value="' + state.monthY + '" style="width:80px;">';
        html += '</div>';
        html += '<div class="snspa-view-toggle">';
        html += '<span style="font-size:12px;color:var(--rs-text-muted);align-self:center;">Typ raportu:</span>';
        html += '<button class="snspa-view-btn' + (state.monthView==='general'?' active':'') + '" data-monthview="general">Ogólny</button>';
        html += '<button class="snspa-view-btn' + (state.monthView==='daily'?' active':'') + '" data-monthview="daily">Szczegółowy (dni)</button>';
        html += '</div>';
        html += '<div class="snspa-body">';

        var mo = state.month;
        if (!mo) { html += '<div class="snspa-loading">Ładowanie…</div></div>'; return html; }

        html += '<div class="snspa-grid">';

        if (mo.kelnerReport){
            html += '<div class="snspa-card role-summary" style="grid-column:1/-1;" id="snspa-pdf-kelner-month"><h3>Raport Kelnera: ' + months[state.monthM-1] + ' ' + state.monthY + '</h3>';
            html += '<div class="snspa-table-wrap"><table class="snspa-table"><thead><tr><th>' + (state.monthView==='general'?'Kategoria':'Data') + '</th>' +
                '<th>Karta brutto</th><th>Karta netto</th><th>Serwis brutto</th><th>Serwis netto</th><th>Gotówka (100%)</th><th>10% bar</th><th>20% kuchnia</th><th style="text-align:right;">70% dla Ciebie</th></tr></thead><tbody>';
            if (state.monthView === 'general'){
                mo.kelnerReport.categories.forEach(function(c){
                    var isKarty = c.label==='KARTY', isSerwis = c.label==='SERWIS', isGotowka = c.label==='GOTÓWKA';
                    html += '<tr><td><b>' + c.label + '</b></td>' +
                        '<td>' + (isKarty?fmt(c.raw):'-') + '</td><td>' + (isKarty?fmt(c.net):'-') + '</td>' +
                        '<td>' + (isSerwis?fmt(c.raw):'-') + '</td><td>' + (isSerwis?fmt(c.net):'-') + '</td>' +
                        '<td>' + (isGotowka?fmt(c.net):'-') + '</td>' +
                        '<td class="snspa-neg">' + fmt(c.pool10) + '</td><td class="snspa-neg">' + fmt(c.pool20) + '</td>' +
                        '<td style="text-align:right;font-weight:700;">' + fmt(c.pay70) + '</td></tr>';
                });
            } else {
                mo.kelnerReport.days.forEach(function(r){
                    html += '<tr><td><b>' + r.date + '</b></td><td>' + fmt(r.cardRaw) + '</td><td>' + fmt(r.cardNet) + '</td>' +
                        '<td>' + fmt(r.servRaw) + '</td><td>' + fmt(r.servNet) + '</td><td>' + fmt(r.cash100) + '</td>' +
                        '<td class="snspa-neg">' + fmt(r.pool10) + '</td><td class="snspa-neg">' + fmt(r.pool20) + '</td>' +
                        '<td style="text-align:right;font-weight:700;">' + fmt(r.pay70) + '</td></tr>';
                });
            }
            html += '</tbody></table></div>';
            html += '<button class="snspa-save" data-pdf="snspa-pdf-kelner-month|Raport_Kelnera_' + state.monthM + '_' + state.monthY + '.pdf|landscape"><i class="fas fa-file-download"></i> Pobierz PDF</button>';
            html += '</div>';
        }

        if (mo.managerReport){
            html += '<div class="snspa-card role-summary" style="grid-column:1/-1;" id="snspa-pdf-manager-month"><h3>Raport Managera: ' + months[state.monthM-1] + ' ' + state.monthY + '</h3>';
            html += '<div class="snspa-table-wrap"><table class="snspa-table"><thead><tr><th>' + (state.monthView==='general'?'Kelner':'Data | Kelner') + '</th>' +
                '<th style="text-align:left;">Wiek' + (state.monthView==='general'?' (aktualny)':'') + '</th><th>Karta brutto</th><th>Karta netto</th><th>Serwis brutto</th><th>Serwis netto</th><th>Pula bar (10%)</th><th>Pula kuchnia (20%)</th></tr></thead><tbody>';
            mo.managerReport.forEach(function(r){
                var label = state.monthView==='general' ? ('<b>' + r.name + '</b>') : (fmtDate(r.date) + ' | <b>' + r.name + '</b>');
                html += '<tr><td>' + label + '</td><td style="text-align:left;">' + (r.age!=null?r.age+' l.':'?') + '</td>' +
                    '<td>' + fmt(r.cardRaw) + '</td><td>' + fmt(r.cardNet) + '</td><td>' + fmt(r.servRaw) + '</td><td>' + fmt(r.servNet) + '</td>' +
                    '<td style="color:var(--rs-accent);font-weight:700;">' + fmt(r.poolBar) + '</td><td style="color:var(--rs-success);font-weight:700;">' + fmt(r.poolKtc) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            html += '<button class="snspa-save" data-pdf="snspa-pdf-manager-month|Raport_Managera_' + state.monthM + '_' + state.monthY + '.pdf|landscape"><i class="fas fa-file-download"></i> Pobierz PDF</button>';
            html += '</div>';
        }

        if (mo.staffReport){
            html += '<div class="snspa-card role-summary" style="grid-column:1/-1;" id="snspa-pdf-staff-month"><h3>Raport Wypłat Personelu: ' + months[state.monthM-1] + ' ' + state.monthY + '</h3>';
            html += '<div class="snspa-table-wrap"><table class="snspa-table"><thead><tr><th>' + (state.monthView==='general'?'Pracownik':'Data | Pracownik') + '</th>' +
                '<th style="text-align:left;">Godziny (H / S)</th><th>Gotówka</th><th>Karta</th><th>Serwis</th><th style="text-align:right;">Suma (Karta+Serwis)</th></tr></thead><tbody>';
            mo.staffReport.forEach(function(r){
                var label = state.monthView==='general' ? ('<b>' + r.name + '</b>') : (fmtDate(r.date) + ' | <b>' + r.name + '</b>');
                html += '<tr><td>' + label + '</td><td style="text-align:left;">' + r.hours + 'h / ' + r.serviceHours + 'h</td><td style="color:var(--rs-success);">' + fmt(r.cash) + '</td>' +
                    '<td>' + fmt(r.card) + (r.extra>0?('<br><span class="snspa-tag">w tym ' + fmt(r.extra) + ' rez.</span>'):'') + '</td>' +
                    '<td>' + fmt(r.service) + '</td><td style="text-align:right;font-weight:700;">' + fmt(r.total) + ' zł</td></tr>';
            });
            html += '</tbody></table></div>';
            html += '<button class="snspa-save" data-pdf="snspa-pdf-staff-month|Raport_Wyplat_Personelu_' + state.monthM + '_' + state.monthY + '.pdf|landscape"><i class="fas fa-file-download"></i> Pobierz PDF</button>';
            html += '</div>';
        }

        if (state.flags && state.flags.isManager){
            html += renderMonthSummary();
        }

        html += '</div></div>';
        return html;
    }

    function renderMonthSummary(){
        var months = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
        var ms = state.monthSummary;
        var html = '<div style="grid-column:1/-1;"><h3 style="margin:0 0 14px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:var(--rs-text-muted);">Karty dzienne</h3>';
        if (!ms) { html += '<div class="snspa-loading">Ładowanie…</div></div>'; return html; }

        html += '<div id="snspa-pdf-days-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:16px;">';
        ms.days.forEach(function(dc){
            html += '<div class="snspa-day-card"><h4><i class="fas fa-calendar-day"></i> ' + fmtDate(dc.date) + '</h4>';
            html += '<div class="snspa-table-wrap"><table class="snspa-table"><thead><tr><th>Kategoria</th><th>Całość (netto)</th><th>10%</th><th>20%</th></tr></thead><tbody>';
            html += '<tr><td>Karty</td><td>' + fmt(dc.card.net) + '</td><td>' + fmt(dc.card.p10) + '</td><td>' + fmt(dc.card.p20) + '</td></tr>';
            html += '<tr><td>Serwis</td><td>' + fmt(dc.service.net) + '</td><td>' + fmt(dc.service.p10) + '</td><td>' + fmt(dc.service.p20) + '</td></tr>';
            html += '<tr><td>Gotówka</td><td>' + fmt(dc.cash.net) + '</td><td>' + fmt(dc.cash.p10) + '</td><td>' + fmt(dc.cash.p20) + '</td></tr>';
            if (dc.serviceDetails && dc.serviceDetails.length){
                html += '<tr style="background:var(--rs-surface-alt);"><td colspan="2"><b>Serwis — szczegóły</b></td><td><b>10%</b></td><td><b>20%</b></td></tr>';
                dc.serviceDetails.forEach(function(sd){
                    html += '<tr><td>' + sd.name + (sd.taxed?' <small>(26+)</small>':'') + '</td><td>' + fmt(sd.net) + '</td><td>' + fmt(sd.net*0.1) + '</td><td>' + fmt(sd.net*0.2) + '</td></tr>';
                });
            }
            html += '</tbody></table></div></div>';
        });
        html += '</div>';
        html += '<button class="snspa-save secondary" data-pdf="snspa-pdf-days-grid|Karty_Dniowe_' + state.monthM + '_' + state.monthY + '.pdf|portrait" style="margin-bottom:24px;"><i class="fas fa-file-download"></i> PDF: Karty Dni</button>';

        html += '<div class="snspa-card box-green" id="snspa-pdf-month-summary">';
        html += '<h3 style="color:var(--rs-success);"><i class="fas fa-chart-bar"></i> Podsumowanie Miesiąca: ' + months[state.monthM-1] + ' ' + state.monthY + '</h3>';
        html += '<div class="snspa-table-wrap"><table class="snspa-table"><thead><tr><th>Pracownik</th><th>Karty (net)</th><th>Serwis (net)</th><th>Gotówka</th>' +
            '<th>10% K</th><th>10% S</th><th>20% K</th><th>20% S</th><th style="background:var(--rs-accent-soft);">Suma 10%</th><th style="background:var(--rs-success-soft);">Suma 20%</th></tr></thead><tbody>';
        ms.summary.forEach(function(r){
            html += '<tr><td><b>' + r.name + '</b></td><td>' + fmt(r.cardNet) + '</td><td>' + fmt(r.servNet) + '</td><td>' + fmt(r.cash100) + '</td>' +
                '<td>' + fmt(r.k10) + '</td><td>' + fmt(r.s10) + '</td><td>' + fmt(r.k20) + '</td><td>' + fmt(r.s20) + '</td>' +
                '<td style="background:var(--rs-accent-soft);font-weight:700;">' + fmt(r.sum10) + '</td><td style="background:var(--rs-success-soft);font-weight:700;color:var(--rs-success);">' + fmt(r.sum20) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        html += '<div style="display:flex;justify-content:center;margin-top:16px;"><button class="snspa-save" style="width:auto;padding:12px 36px;" data-pdf="snspa-pdf-month-summary|Podsumowanie_Miesieczne_' + state.monthM + '_' + state.monthY + '.pdf|landscape"><i class="fas fa-file-download"></i> PDF: Podsumowanie</button></div>';
        html += '</div></div>';
        return html;
    }

    function renderCheckStaff(){
        var html = '<div class="snspa-body">';
        html += '<div class="snspa-card role-summary">';
        html += '<h3>Sprawdź pracownika</h3>';
        html += '<div class="snspa-row"><span class="name">Pracownik</span><select id="snspa-check-user" style="flex:1;max-width:220px;">';
        html += '<option value="">— wybierz —</option>';
        (state.staffList || []).forEach(function(u){
            var label = u.name + (u.terminated?' [zwolniony]':'') + ' (' + u.role + ')';
            html += '<option value="' + u.id + '"' + (String(state.checkUserId)===String(u.id)?' selected':'') + '>' + label + '</option>';
        });
        html += '</select></div>';
        html += '<div class="snspa-row"><span class="name">Miesiąc / rok</span><span>';
        html += '<select id="snspa-check-m">';
        var months = ['Sty','Lut','Mar','Kwi','Maj','Cze','Lip','Sie','Wrz','Paź','Lis','Gru'];
        for (var i=1;i<=12;i++) html += '<option value="' + i + '"' + (i===state.checkM?' selected':'') + '>' + months[i-1] + '</option>';
        html += '</select> <input type="number" id="snspa-check-y" value="' + state.checkY + '" style="width:70px;">';
        html += '</span></div>';
        html += '<button class="snspa-save" data-save="checkStaff">Pokaż</button>';
        html += '</div>';

        var c = state.checkData;
        if (c){
            var title = c.name + (c.age!=null?(' — ' + c.age + ' lat'):'');
            if (c.isKelner && c.kelner){
                var t = c.kelner.totals;
                html += '<div class="snspa-card role-result" style="grid-column:1/-1;"><h3>' + title + ' (kelner)</h3>';
                html += '<div class="snspa-row"><span>Karta netto</span><span>' + fmt(t.cardNet) + ' zł</span></div>';
                html += '<div class="snspa-row"><span>Serwis netto</span><span>' + fmt(t.servNet) + ' zł</span></div>';
                html += '<div class="snspa-row"><span>Gotówka (100%)</span><span>' + fmt(t.cash100) + ' zł</span></div>';
                html += '<div class="snspa-row snspa-neg"><span>10% dla baru</span><span>-' + fmt(t.poolBar) + ' zł</span></div>';
                html += '<div class="snspa-row snspa-neg"><span>20% dla kuchni</span><span>-' + fmt(t.poolKtc) + ' zł</span></div>';
                html += '<div class="snspa-total"><span>Do wypłaty (70%)</span></div><div class="snspa-payout">' + fmt(t.payout) + ' zł</div>';
                html += '<div class="snspa-table-wrap" style="margin-top:10px;"><table class="snspa-table"><thead><tr><th>Data</th><th>Karta</th><th>Serwis</th><th>Gotówka</th><th>70%</th></tr></thead><tbody>';
                c.kelner.days.forEach(function(r){ html += '<tr><td>' + r.date + '</td><td>' + fmt(r.cardNet) + '</td><td>' + fmt(r.servNet) + '</td><td>' + fmt(r.cash100) + '</td><td>' + fmt(r.payout) + '</td></tr>'; });
                html += '</tbody></table></div></div>';
            } else if (c.staff){
                var s = c.staff.totals;
                html += '<div class="snspa-card role-result" style="grid-column:1/-1;"><h3>' + title + '</h3>';
                html += '<div class="snspa-row"><span>Godziny (H / S)</span><span>' + s.hours + 'h / ' + s.serviceHours + 'h</span></div>';
                html += '<div class="snspa-row"><span>Gotówka łącznie</span><span>' + fmt(s.cash) + ' zł</span></div>';
                html += '<div class="snspa-row"><span>Karta łącznie' + (s.extra>0?(' <span class="snspa-tag">w tym ' + fmt(s.extra) + ' rez.</span>'):'') + '</span><span>' + fmt(s.card) + ' zł</span></div>';
                html += '<div class="snspa-row"><span>Serwis łącznie</span><span>' + fmt(s.service) + ' zł</span></div>';
                html += '<div class="snspa-total"><span>Suma (karta+serwis)</span></div><div class="snspa-payout">' + fmt(s.card+s.service) + ' zł</div>';
                html += '<div class="snspa-table-wrap" style="margin-top:10px;"><table class="snspa-table"><thead><tr><th>Data</th><th>H/S</th><th>Gotówka</th><th>Karta</th><th>Serwis</th></tr></thead><tbody>';
                c.staff.days.forEach(function(r){ html += '<tr><td>' + r.date + '</td><td>' + r.hours + 'h/' + r.serviceHours + 'h</td><td>' + fmt(r.calcCash) + '</td><td>' + fmt(r.calcCard) + '</td><td>' + fmt(r.calcService) + '</td></tr>'; });
                html += '</tbody></table></div></div>';
            }
        }

        html += '</div>';
        return html;
    }

    function renderServicePool(){
        var f = state.flags || {};
        var deptLabel = f.isAdminBar ? 'BAR (10%)' : 'KUCHNIA (20%)';
        var html = '<div class="snspa-datebar">';
        html += '<input type="date" id="snspa-pool-date" value="' + state.poolDate + '">';
        html += '</div><div class="snspa-body">';
        if (state.msg) html += '<div class="snspa-msg' + (state.msg.err?' err':'') + '">' + state.msg.text + '</div>';

        var p = state.poolData;
        html += '<div class="snspa-grid">';
        html += '<div class="snspa-card role-summary" style="grid-column:1/-1;"><h3>Rozliczenie serwisu — ' + deptLabel + '</h3>';
        if (!p){
            html += '<div class="snspa-loading">Ładowanie…</div></div></div></div>';
            return html;
        }
        if (p.poolRaw <= 0){
            html += '<div class="snspa-muted">Brak wpisanego serwisu przez managera dla tego dnia.</div></div></div></div>';
            return html;
        }
        html += '<div class="snspa-row"><span>Suma serwisu kelnerów</span><span>' + fmt(p.poolRaw) + ' zł</span></div>';
        html += '<div class="snspa-row"><span>Pula dla działu</span><span style="font-weight:800;color:var(--rs-success);">' + fmt(p.available) + ' zł</span></div>';
        p.contributors.forEach(function(c){ html += '<div class="snspa-row"><span class="name" style="font-size:11px;">' + c.name + (c.taxed?' <span class="snspa-tag">26+</span>':'') + '</span><span style="font-size:11px;">' + fmt(c.share) + ' zł</span></div>'; });

        html += '<div class="snspa-row" style="margin-top:10px;"><label><input type="radio" name="snspa-pool-mode" value="hours" ' + (state.poolMode==='hours'?'checked':'') + '> Godzinowo</label>' +
            '&nbsp;&nbsp;<label><input type="radio" name="snspa-pool-mode" value="manual" ' + (state.poolMode==='manual'?'checked':'') + '> Ręcznie (kwotowo)</label></div>';

        html += '<div class="snspa-table-wrap"><table class="snspa-table"><thead><tr><th>Pracownik</th><th>Godziny serwisu</th><th>Kwota serwisu</th></tr></thead><tbody>';
        p.staff.forEach(function(s){
            html += '<tr><td>' + s.name + '</td><td><input type="number" step="0.01" data-pool-hours="' + s.id + '" value="' + s.serviceHours + '" ' + (state.poolMode!=='hours'?'disabled':'') + '></td>' +
                '<td><input type="number" step="0.01" data-pool-amount="' + s.id + '" value="' + s.calcService + '" ' + (state.poolMode!=='manual'?'disabled':'') + '></td></tr>';
        });
        html += '</tbody></table></div>';
        if (!p.staff.length) html += '<div class="snspa-muted">Brak pracowników z wpisanymi godzinami w tym dniu.</div>';
        html += '<button class="snspa-save" data-save="servicePool">Zapisz rozliczenie</button>';
        html += '</div></div></div>';
        return html;
    }

    /* ---------------------------------------------------------
     * RAPORT DLA SEKCJI (kuchnia / bar) — tylko administrator strony.
     * Jeden wiersz na kelnera za cały miesiąc: kwota dla sekcji z karty
     * (10% bar / 20% kuchnia), z serwisu, suma i pusta rubryka „Kwota
     * otrzymana” do wpisania długopisem. Bez pełnych napiwków kelnera
     * i bez udziału drugiej sekcji.
     * --------------------------------------------------------- */
    var SR_MONTHS = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
    function srAmt(v){ return (v > 0) ? fmt(v) + ' zł' : '—'; }
    function srDeptLabel(d){ return d === 'bar' ? 'Bar' : 'Kuchnia'; }

    function loadSectionReport(){
        state.srData = null; render();
        api('/section-report?m=' + state.srM + '&y=' + state.srY + '&dept=' + state.srDept)
            .then(function(d){ state.srData = d; render(); })
            .catch(function(e){ state.msg = { text: e.message, err: true }; state.srData = { rows: [], error: true }; render(); });
    }

    function renderSectionReport(){
        var d = state.srData;
        var html = '<div class="snspa-body">';
        if (state.msg) html += '<div class="snspa-msg' + (state.msg.err?' err':'') + '">' + state.msg.text + '</div>';
        html += '<div class="snspa-card role-summary" style="margin-bottom:14px;">';
        html += '<div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:flex-start;">';
        html += '<div><h3 style="margin:0;"><i class="fas fa-file-signature"></i> Raport miesięczny dla sekcji</h3>';
        html += '<div class="snspa-dept-toggle">';
        html += '<button class="snspa-dept-btn' + (state.srDept==='kuchnia'?' active':'') + '" data-sr-dept="kuchnia">Kuchnia</button>';
        html += '<button class="snspa-dept-btn' + (state.srDept==='bar'?' active':'') + '" data-sr-dept="bar">Bar</button>';
        html += '</div></div>';
        html += '<div class="snspa-month-select" style="padding:0;"><select id="snspa-sr-m">';
        for (var i=1;i<=12;i++) html += '<option value="' + i + '"' + (i===state.srM?' selected':'') + '>' + SR_MONTHS[i-1] + '</option>';
        html += '</select><input type="number" id="snspa-sr-y" value="' + state.srY + '" style="width:80px;"></div>';
        html += '</div>';
        html += '<div class="snspa-muted" style="margin-top:8px;">Raport pokazuje kwoty przypadające sekcji od każdego kelnera: ' + (state.srDept==='bar' ? '10%' : '20%') + ' z karty i ' + (state.srDept==='bar' ? '10%' : '20%') + ' z serwisu (netto, z wyjątkami procentowymi) oraz ich sumę — bez pełnych napiwków kelnerów i bez udziału drugiej sekcji. Kwotę otrzymaną sekcja wpisuje długopisem na wydruku. Gotówka nie jest uwzględniana.</div>';
        html += '</div>';

        if (!d){ html += '<div class="snspa-loading">Ładowanie…</div></div>'; return html; }

        html += '<div class="snspa-card" style="grid-column:1/-1;">';
        if (!d.rows.length){
            html += '<div class="snspa-muted">Brak napiwków z karty ani serwisu w tym miesiącu.</div></div></div>';
            return html;
        }
        html += '<div class="snspa-table-wrap"><table class="snspa-table"><thead><tr><th>Lp.</th><th style="text-align:left;">Kelner</th><th>Z karty (' + d.pct + '%)</th><th>Z serwisu (' + d.pct + '%)</th><th>Razem</th><th>Kwota otrzymana</th></tr></thead><tbody>';
        d.rows.forEach(function(r, i){
            html += '<tr><td>' + (i+1) + '</td><td><b>' + esc(r.name) + '</b></td><td style="text-align:right;">' + srAmt(r.card) + '</td><td style="text-align:right;">' + srAmt(r.service) + '</td><td style="text-align:right;"><b>' + srAmt(r.total) + '</b></td><td style="color:#9CA3AF;text-align:right;">(wpisuje sekcja)</td></tr>';
        });
        html += '</tbody><tfoot><tr><td></td><td><b>RAZEM</b></td><td style="text-align:right;"><b>' + srAmt(d.totals.card) + '</b></td><td style="text-align:right;"><b>' + srAmt(d.totals.service) + '</b></td><td style="text-align:right;"><b>' + srAmt(d.totals.total) + '</b></td><td></td></tr></tfoot></table></div>';
        html += '<button class="snspa-save" data-sr-pdf="1"><i class="fas fa-file-download"></i> Pobierz PDF — ' + srDeptLabel(d.dept) + ', ' + SR_MONTHS[d.month-1].toLowerCase() + ' ' + d.year + '</button>';
        html += '</div></div>';
        return html;
    }

    // PDF do druku (A4 pionowo): tytuł, sekcja, miesiąc, tabela z wysokimi wierszami
    // i pustą rubryką na kwotę, suma do wpisania ręcznie, miejsca na podpisy.
    function sectionReportPdf(){
        var d = state.srData;
        if (!d || !d.rows || !d.rows.length) return;
        ensureJsPdfLibs(function(){
            try {
                var doc = snspaSetupDoc('portrait');
                var W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight(), M = 15;
                var dept = srDeptLabel(d.dept).toUpperCase();
                var period = SR_MONTHS[d.month-1].toLowerCase() + ' ' + d.year;
                doc.setFont('DejaVuSans', 'bold'); doc.setFontSize(16);
                doc.text('Raport napiwków — ' + dept, M, M + 6);
                doc.setFont('DejaVuSans', 'normal'); doc.setFontSize(10); doc.setTextColor(75,85,99);
                doc.text('Miesiąc: ' + period, M, M + 13);
                doc.text('Wygenerowano: ' + d.generated.split(' ')[0].split('-').reverse().join('.') + ' ' + (d.generated.split(' ')[1] || ''), W - M, M + 13, { align: 'right' });
                doc.setFontSize(8.5);
                doc.text(doc.splitTextToSize('Kwoty przypadające ' + (d.dept === 'bar' ? 'barowi' : 'kuchni') + ' od każdego kelnera: ' + d.pct + '% napiwków z karty i ' + d.pct + '% z serwisu (netto, z uwzględnieniem wyjątków procentowych) oraz ich suma. Rubrykę „Kwota otrzymana” wypełnia sekcja długopisem po otrzymaniu przelewu. Gotówka rozliczana jest na bieżąco i nie jest ujęta w raporcie.', W - 2*M), M, M + 20);
                doc.setTextColor(0,0,0);

                var body = d.rows.map(function(r, i){ return [String(i+1), r.name, srAmt(r.card), srAmt(r.service), srAmt(r.total), '']; });
                doc.autoTable({
                    head: [['Lp.', 'Kelner', 'Z karty (' + d.pct + '%)', 'Z serwisu (' + d.pct + '%)', 'Razem', 'Kwota otrzymana (zł)']],
                    body: body,
                    foot: [['', 'RAZEM', srAmt(d.totals.card), srAmt(d.totals.service), srAmt(d.totals.total), '']],
                    startY: M + 32,
                    margin: { left: M, right: M },
                    theme: 'grid',
                    styles: { font: 'DejaVuSans', fontSize: 10, minCellHeight: 11, valign: 'middle', lineColor: [156,163,175], lineWidth: 0.2, textColor: [17,24,39] },
                    headStyles: { font: 'DejaVuSans', fontStyle: 'bold', fillColor: [37,99,235], textColor: 255, minCellHeight: 9 },
                    footStyles: { font: 'DejaVuSans', fontStyle: 'bold', fillColor: [241,245,249], textColor: [31,41,55] },
                    columnStyles: {
                        0: { cellWidth: 10, halign: 'center' },
                        2: { cellWidth: 27, halign: 'right' },
                        3: { cellWidth: 27, halign: 'right' },
                        4: { cellWidth: 27, halign: 'right', fontStyle: 'bold' },
                        5: { cellWidth: 38 }
                    },
                    didParseCell: function(c){ if (c.section === 'foot' && c.column.index >= 2 && c.column.index <= 4) c.cell.styles.halign = 'right'; }
                });
                var y = doc.lastAutoTable.finalY + 22;
                if (y > H - 30){ doc.addPage(); y = M + 20; }
                doc.setFontSize(9); doc.setTextColor(75,85,99);
                var colW = (W - 2*M - 20) / 2;
                [[M, 'Data i podpis osoby odbierającej (' + srDeptLabel(d.dept).toLowerCase() + ')'], [M + colW + 20, 'Podpis administratora']].forEach(function(c){
                    doc.setDrawColor(107,114,128); doc.setLineWidth(0.3);
                    doc.line(c[0], y, c[0] + colW, y);
                    doc.text(c[1], c[0], y + 5);
                });
                var pages = doc.internal.getNumberOfPages();
                for (var p = 1; p <= pages; p++){
                    doc.setPage(p); doc.setFontSize(8); doc.setTextColor(156,163,175);
                    doc.text('Raport napiwków — ' + dept + ' — ' + period + ' — strona ' + p + ' z ' + pages, W / 2, H - 8, { align: 'center' });
                }
                doc.save('Raport_napiwkow_' + srDeptLabel(d.dept) + '_' + d.year + '-' + (d.month < 10 ? '0' : '') + d.month + '.pdf');
            } catch (err) {
                alert('Błąd generowania PDF: ' + (err && err.message ? err.message : err));
            }
        });
    }

    function renderWaiterSettlement(){
        var f = state.flags || {};
        var months = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
        var w = state.wsData;
        var dept = w ? w.dept : (state.wsDept || (f.isAdminBar ? 'bar' : 'kuchnia'));
        var pct = dept === 'bar' ? 10 : 20;

        var html = '<div class="snspa-body">';
        if (state.msg) html += '<div class="snspa-msg' + (state.msg.err?' err':'') + '">' + state.msg.text + '</div>';

        html += '<div class="snspa-card role-summary" style="margin-bottom:14px;">';
        html += '<div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;align-items:flex-start;">';
        html += '<div><h3 style="margin:0;"><i class="fas fa-handshake"></i> Rozliczenie z Kelnerami</h3>';
        if (f.isAdmin){
            html += '<div class="snspa-dept-toggle">';
            html += '<button class="snspa-dept-btn' + (dept==='bar'?' active':'') + '" data-ws-dept="bar">Rozliczam Bar</button>';
            html += '<button class="snspa-dept-btn' + (dept==='kuchnia'?' active':'') + '" data-ws-dept="kuchnia">Rozliczam Kuchnię</button>';
            html += '</div>';
        } else {
            html += '<div class="snspa-muted" style="margin-top:6px;">Dział: <b>' + (dept==='bar'?'Bar (10%)':'Kuchnia (20%)') + '</b></div>';
        }
        html += '</div>';
        html += '<div class="snspa-month-select" style="padding:0;">';
        html += '<select id="snspa-ws-m">';
        for (var i=1;i<=12;i++) html += '<option value="' + i + '"' + (i===state.wsM?' selected':'') + '>' + months[i-1] + '</option>';
        html += '</select><input type="number" id="snspa-ws-y" value="' + state.wsY + '" style="width:80px;">';
        html += '</div></div></div>';

        if (!w){ html += '<div class="snspa-loading">Ładowanie…</div></div>'; return html; }

        html += '<div class="snspa-stat-grid">';
        html += '<div class="snspa-stat-card" style="border-left-color:var(--rs-accent);"><div class="lbl">Zadeklarowane w systemie</div><div class="val" style="color:var(--rs-accent);" id="snspa-ws-sum-owed">0.00 zł</div></div>';
        html += '<div class="snspa-stat-card" style="border-left-color:var(--rs-success);"><div class="lbl">Fizycznie wpłacono</div><div class="val" style="color:var(--rs-success);" id="snspa-ws-sum-rec">0.00 zł</div></div>';
        html += '<div class="snspa-stat-card" style="border-left-color:var(--rs-warning);"><div class="lbl">Nadwyżki łącznie</div><div class="val" style="color:var(--rs-warning);" id="snspa-ws-sum-surplus">+0.00 zł</div></div>';
        html += '<div class="snspa-stat-card" style="border-left-color:var(--rs-danger);"><div class="lbl">Brakuje łącznie</div><div class="val" style="color:var(--rs-danger);" id="snspa-ws-sum-debt">0.00 zł</div></div>';
        html += '</div>';

        html += '<div class="snspa-card" style="grid-column:1/-1;">';
        html += '<div class="snspa-table-wrap"><table class="snspa-table" id="snspa-ws-table"><thead><tr>' +
            '<th>Pracownik</th><th>Karta (' + pct + '%)</th><th>Serwis (' + pct + '%)</th><th>Suma do oddania</th>' +
            '<th>Fizyczna wpłata</th><th>Różnica (nadwyżka/brak)</th><th>Status</th></tr></thead><tbody>';
        w.rows.forEach(function(r){
            var received = (r.received!=null) ? r.received : '';
            html += '<tr data-ws-owed="' + r.owedTotal + '">' +
                '<td><b>' + r.name + '</b></td>' +
                '<td>' + fmt(r.owedCard) + '</td><td>' + fmt(r.owedService) + '</td>' +
                '<td style="font-weight:700;">' + fmt(r.owedTotal) + '</td>' +
                '<td><input type="number" step="0.01" data-ws-received="' + r.id + '" value="' + received + '" placeholder="0.00"></td>' +
                '<td class="snspa-ws-diff"></td><td class="snspa-ws-status"></td></tr>';
        });
        html += '</tbody></table></div>';
        html += '<button class="snspa-save" data-save="waiterSettlement"><i class="fas fa-save"></i> Zapisz rozliczenia miesiąca</button>';
        html += '</div>';
        html += '</div>';
        return html;
    }

    function updateWsDiffs(){
        var totalOwed=0, totalRec=0, totalSurplus=0, totalDebt=0;
        root.querySelectorAll('[data-ws-owed]').forEach(function(row){
            var owed = parseFloat(row.getAttribute('data-ws-owed')) || 0;
            var input = row.querySelector('[data-ws-received]');
            var diffCell = row.querySelector('.snspa-ws-diff');
            var statusCell = row.querySelector('.snspa-ws-status');
            if (!input || !diffCell) return;
            totalOwed += owed;
            var hasInput = input.value !== '';
            var received = hasInput ? (parseFloat(input.value) || 0) : 0;
            if (hasInput) totalRec += received;
            if (!hasInput){
                diffCell.innerHTML = '<span class="snspa-muted">-</span>';
                statusCell.innerHTML = '<span class="snspa-badge none">Brak wpłaty</span>';
                totalDebt += owed;
                return;
            }
            var diff = received - owed;
            if (diff > 0.01){
                diffCell.innerHTML = '<span style="color:var(--rs-warning);">+' + diff.toFixed(2) + ' zł</span>';
                statusCell.innerHTML = '<span class="snspa-badge surplus">Nadwyżka</span>';
                totalSurplus += diff;
            } else if (diff < -0.01){
                diffCell.innerHTML = '<span class="snspa-neg">' + diff.toFixed(2) + ' zł</span>';
                statusCell.innerHTML = '<span class="snspa-badge debt">Do dopłaty</span>';
                totalDebt += Math.abs(diff);
            } else {
                diffCell.innerHTML = '<span style="color:var(--rs-success);">0.00 zł</span>';
                statusCell.innerHTML = '<span class="snspa-badge ok">Rozliczony</span>';
            }
        });
        var sumOwedEl = root.querySelector('#snspa-ws-sum-owed');
        if (sumOwedEl){
            sumOwedEl.innerText = fmt(totalOwed) + ' zł';
            root.querySelector('#snspa-ws-sum-rec').innerText = fmt(totalRec) + ' zł';
            root.querySelector('#snspa-ws-sum-surplus').innerText = '+' + fmt(totalSurplus) + ' zł';
            root.querySelector('#snspa-ws-sum-debt').innerText = fmt(totalDebt) + ' zł';
        }
    }

    function renderEmail(){
        var f = state.flags || {};
        var months = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
        var groups = [];
        if (f.isManager) groups.push({ id:'kelner', label:'Kelnerzy', hint:'Wysyła manager — podsumowanie napiwków i kwoty do oddania barowi/kuchni.',
            placeholders:'{imie}, {miesiac_rok}, {karta_netto}, {serwis_netto}, {gotowka}, {kwota_bar}, {kwota_kuchnia}, {wyplata}' });
        if (f.isAdminBar) groups.push({ id:'bar', label:'Bar', hint:'Wysyła admin baru — zarobek barmana z napiwków.',
            placeholders:'{imie}, {miesiac_rok}, {gotowka}, {karta}, {serwis}, {razem}' });
        if (f.isAdminKtc) groups.push({ id:'kuchnia', label:'Kuchnia', hint:'Wysyła admin kuchni — zarobek kucharza z napiwków.',
            placeholders:'{imie}, {miesiac_rok}, {gotowka}, {karta}, {serwis}, {razem}' });

        var html = '<div class="snspa-body"><div class="snspa-grid">';
        if (state.msg) html += '<div class="snspa-msg' + (state.msg.err?' err':'') + '" style="grid-column:1/-1;">' + state.msg.text + '</div>';

        groups.forEach(function(g){
            var tpl = state.emailTemplates[g.id];
            html += '<div class="snspa-card role-summary" style="grid-column:1/-1;"><h3><i class="fas fa-envelope"></i> E-mail do: ' + g.label + '</h3>';
            if (!tpl){
                html += '<div class="snspa-loading">Ładowanie szablonu…</div></div>';
                return;
            }
            html += '<div class="snspa-muted" style="margin-bottom:8px;">' + g.hint + '</div>';
            html += '<label class="snspa-muted" style="display:block;margin-bottom:4px;">Temat wiadomości</label>';
            html += '<input type="text" data-email-subject="' + g.id + '" value="' + esc(tpl.subject) + '" style="width:100%;padding:9px;border:1px solid var(--rs-border);border-radius:var(--rs-radius-md);margin-bottom:10px;font-size:14px;">';
            html += '<label class="snspa-muted" style="display:block;margin-bottom:4px;">Treść wiadomości</label>';
            html += '<textarea data-email-body="' + g.id + '" rows="8" style="width:100%;padding:10px;border:1px solid var(--rs-border);border-radius:var(--rs-radius-md);font-family:inherit;font-size:13px;">' + esc(tpl.body) + '</textarea>';
            html += '<div class="snspa-muted" style="margin:6px 0 10px;">Dostępne pola: ' + g.placeholders + '</div>';
            html += '<button class="snspa-save" data-save-email="' + g.id + '"><i class="fas fa-save"></i> Zapisz szablon</button>';

            html += '<div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--rs-border);">';
            html += '<label class="snspa-muted" style="display:block;margin-bottom:4px;">Wyślij podsumowanie za miesiąc</label>';
            html += '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
            html += '<select data-email-m="' + g.id + '">';
            for (var i=1;i<=12;i++) html += '<option value="' + i + '"' + (i===state.emailM?' selected':'') + '>' + months[i-1] + '</option>';
            html += '</select>';
            html += '<input type="number" data-email-y="' + g.id + '" value="' + state.emailY + '" style="width:80px;padding:7px;border:1px solid var(--rs-border);border-radius:var(--rs-radius-md);">';
            html += '<button class="snspa-save" style="width:auto;margin-top:0;" data-send-email="' + g.id + '"><i class="fas fa-paper-plane"></i> Wyślij teraz</button>';
            html += '</div>';
            if (state.emailMsg[g.id]) html += '<div class="snspa-msg' + (state.emailMsg[g.id].err?' err':'') + '" style="margin-top:10px;">' + state.emailMsg[g.id].text + '</div>';
            html += '</div></div>';
        });

        if (!groups.length) html += '<div class="snspa-card"><div class="snspa-muted">Brak dostępnych grup do wysyłki dla Twojej roli.</div></div>';
        html += '</div></div>';
        return html;
    }

    function renderPush(){
        var f = state.flags || {};
        var months = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
        var groups = [];
        if (f.isManager) groups.push({ id:'kelner', label:'Kelnerzy', hint:'Wysyła manager — podsumowanie napiwków i kwoty do oddania barowi/kuchni.',
            placeholders:'{imie}, {miesiac_rok}, {karta_netto}, {serwis_netto}, {gotowka}, {kwota_bar}, {kwota_kuchnia}, {wyplata}' });
        if (f.isAdminBar) groups.push({ id:'bar', label:'Bar', hint:'Wysyła admin baru — zarobek barmana z napiwków.',
            placeholders:'{imie}, {miesiac_rok}, {gotowka}, {karta}, {serwis}, {razem}' });
        if (f.isAdminKtc) groups.push({ id:'kuchnia', label:'Kuchnia', hint:'Wysyła admin kuchni — zarobek kucharza z napiwków.',
            placeholders:'{imie}, {miesiac_rok}, {gotowka}, {karta}, {serwis}, {razem}' });

        var html = '<div class="snspa-body"><div class="snspa-grid">';
        if (state.msg) html += '<div class="snspa-msg' + (state.msg.err?' err':'') + '" style="grid-column:1/-1;">' + state.msg.text + '</div>';

        if (f.isAdmin && state.onesignalSettings && !state.onesignalSettings.hubManaged){
            html += '<div class="snspa-card" style="grid-column:1/-1;border:1px solid var(--rs-warning);background:var(--rs-warning-soft);">' +
                '<div style="display:flex;align-items:center;gap:10px;color:var(--rs-warning);font-weight:600;font-size:13px;">' +
                '<i class="fas fa-triangle-exclamation"></i> Powiadomienia push są nieaktywne — skonfiguruj Firebase Cloud Messaging centralnie w panelu GastroFlowx → Integracje.</div></div>';
        }

        groups.forEach(function(g){
            var tpl = state.pushTemplates[g.id];
            html += '<div class="snspa-card role-summary" style="grid-column:1/-1;"><h3><i class="fas fa-bell"></i> Powiadomienie push do: ' + g.label + '</h3>';
            if (!tpl){
                html += '<div class="snspa-loading">Ładowanie szablonu…</div></div>';
                return;
            }
            html += '<div class="snspa-muted" style="margin-bottom:8px;">' + g.hint + '</div>';
            html += '<label class="snspa-muted" style="display:block;margin-bottom:4px;">Tytuł powiadomienia</label>';
            html += '<input type="text" data-push-title="' + g.id + '" value="' + esc(tpl.title) + '" style="width:100%;padding:9px;border:1px solid var(--rs-border);border-radius:var(--rs-radius-md);margin-bottom:10px;font-size:14px;">';
            html += '<label class="snspa-muted" style="display:block;margin-bottom:4px;">Treść powiadomienia</label>';
            html += '<textarea data-push-message="' + g.id + '" rows="4" style="width:100%;padding:10px;border:1px solid var(--rs-border);border-radius:var(--rs-radius-md);font-family:inherit;font-size:13px;">' + esc(tpl.message) + '</textarea>';
            html += '<div class="snspa-muted" style="margin:6px 0 10px;">Dostępne pola: ' + g.placeholders + '</div>';

            // Ikona powiadomienia
            var iconSrc = tpl.icon_url || tpl.fallback_icon_url || '';
            html += '<label class="snspa-muted" style="display:block;margin:4px 0 6px;">Ikona powiadomienia</label>';
            html += '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px;">';
            html += '<span style="width:52px;height:52px;border:1px solid var(--rs-border);border-radius:var(--rs-radius-md);display:inline-flex;align-items:center;justify-content:center;overflow:hidden;background:var(--rs-bg, #f6f7f7);">' +
                (iconSrc ? '<img src="' + esc(iconSrc) + '" alt="" style="max-width:100%;max-height:100%;' + (tpl.icon_url ? '' : 'opacity:.45;') + '">' : '<i class="fas fa-bell" style="opacity:.4;"></i>') + '</span>';
            html += '<label class="snspa-save" style="width:auto;margin-top:0;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-upload"></i> ' + (tpl.icon_url ? 'Zmień ikonę' : 'Wgraj ikonę') +
                '<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" data-push-icon-file="' + g.id + '" style="display:none;"></label>';
            if (tpl.icon_url) html += '<button type="button" class="snspa-save" style="width:auto;margin-top:0;background:transparent;color:var(--rs-danger, #b32d2e);border:1px solid currentColor;" data-push-icon-remove="' + g.id + '"><i class="fas fa-trash"></i> Usuń</button>';
            html += '</div>';
            html += '<div class="snspa-muted" style="margin-bottom:10px;">' + (tpl.icon_url
                ? 'Własna ikona tego powiadomienia.'
                : 'Brak własnej ikony — użyta zostanie ikona ustawiona w panelu GastroFlowx (podgląd wyszarzony).') +
                ' Zalecany kwadratowy PNG min. 192×192 px, maks. 2 MB. Na iPhone system zawsze pokazuje ikonę aplikacji.</div>';

            html += '<button class="snspa-save" data-save-push="' + g.id + '"><i class="fas fa-save"></i> Zapisz szablon</button>';

            html += '<div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--rs-border);">';
            html += '<label class="snspa-muted" style="display:block;margin-bottom:4px;">Wyślij powiadomienie za miesiąc</label>';
            html += '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
            html += '<select data-push-m="' + g.id + '">';
            for (var i=1;i<=12;i++) html += '<option value="' + i + '"' + (i===state.pushM?' selected':'') + '>' + months[i-1] + '</option>';
            html += '</select>';
            html += '<input type="number" data-push-y="' + g.id + '" value="' + state.pushY + '" style="width:80px;padding:7px;border:1px solid var(--rs-border);border-radius:var(--rs-radius-md);">';
            html += '<button class="snspa-save" style="width:auto;margin-top:0;" data-send-push="' + g.id + '"><i class="fas fa-paper-plane"></i> Wyślij teraz</button>';
            html += '</div>';
            if (state.pushMsg[g.id]) html += '<div class="snspa-msg' + (state.pushMsg[g.id].err?' err':'') + '" style="margin-top:10px;">' + state.pushMsg[g.id].text + '</div>';
            html += '</div></div>';
        });

        if (!groups.length) html += '<div class="snspa-card"><div class="snspa-muted">Brak dostępnych grup do wysyłki dla Twojej roli.</div></div>';
        html += '</div></div>';
        return html;
    }

    function renderSettings(){
        var s = state.settings || {cardActive:false, servActive:false, rate:0};
        var html = '<div class="snspa-body"><div class="snspa-grid">';
        html += '<div class="snspa-card role-summary snspa-settings"><h3>Reguły podatkowe (ulga do 26 lat)</h3>';
        html += '<label><input type="checkbox" id="snspa-set-card" ' + (s.cardActive?'checked':'') + '> Potrącaj podatek od karty dla osób 26+</label>';
        html += '<label><input type="checkbox" id="snspa-set-serv" ' + (s.servActive?'checked':'') + '> Potrącaj podatek od serwisu dla osób 26+</label>';
        html += '<label>Stawka podatku (%): <input type="number" step="0.01" id="snspa-set-rate" value="' + (s.rate*100) + '"></label>';
        html += '<button class="snspa-save" data-save="settings">Zapisz ustawienia</button></div>';

        var os = state.onesignalSettings;
        html += '<div class="snspa-card role-summary snspa-settings"><h3><i class="fas fa-bell"></i> Powiadomienia push</h3>';
        if (!os){
            html += '<div class="snspa-loading">Ładowanie…</div></div>';
        } else if (os.hubManaged){
            html += '<div class="snspa-muted" style="padding:10px;border:1px solid var(--rs-border);border-radius:var(--rs-radius-md);background:var(--rs-surface-alt,rgba(0,0,0,0.03));">' +
                '<i class="fas fa-circle-info"></i> Powiadomienia push (Firebase Cloud Messaging) są aktywne i zarządzane centralnie w panelu GastroFlowx (Integracje).</div></div>';
        } else {
            html += '<div class="snspa-muted" style="padding:10px;border:1px solid var(--rs-border);border-radius:var(--rs-radius-md);background:var(--rs-surface-alt,rgba(0,0,0,0.03));">' +
                '<i class="fas fa-circle-info"></i> Powiadomienia push są nieaktywne — skonfiguruj Firebase Cloud Messaging centralnie w panelu GastroFlowx (Integracje), żeby włączyć je tu i we wszystkich innych modułach.</div></div>';
        }

        html += '</div></div>';
        return html;
    }

    function loadDay(){
        // Druga linia obrony: gdyby state.date z jakiegokolwiek powodu było puste/uszkodzone,
        // wracamy do dzisiejszej daty zamiast wysyłać złe zapytanie do serwera.
        if (!isValidIsoDate(state.date)) state.date = todayIso();
        state.day = null; state.percentOverrides = null; render();
        api('/day?date=' + state.date).then(function(d){ state.day = d; render(); })
            .catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
        if (state.flags && state.flags.canSetPercentOverrides){
            api('/percent-overrides?date=' + state.date).then(function(d){ state.percentOverrides = d; render(); })
                .catch(function(){ /* ciche niepowodzenie — karta po prostu nie pokaże wyjątków */ });
        }
    }
    function loadMonth(){
        state.month = null; render();
        api('/month?m=' + state.monthM + '&y=' + state.monthY + '&view=' + state.monthView).then(function(d){ state.month = d; render(); });
        if (state.flags && state.flags.isManager){
            state.monthSummary = null;
            api('/month-summary?m=' + state.monthM + '&y=' + state.monthY).then(function(d){ state.monthSummary = d; render(); });
        }
    }
    function loadSettings(){
        api('/settings').then(function(d){ state.settings = d; render(); });
    }
    function loadStaffList(){
        return api('/staff-list').then(function(d){ state.staffList = d; });
    }
    function loadCheckStaff(){
        if (!state.checkUserId) { render(); return; }
        state.checkData = null; render();
        api('/staff-check?user_id=' + state.checkUserId + '&m=' + state.checkM + '&y=' + state.checkY).then(function(d){ state.checkData = d; render(); })
            .catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
    }
    function loadServicePool(){
        state.poolData = null; render();
        api('/service-pool?date=' + state.poolDate).then(function(d){ state.poolData = d; render(); })
            .catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
    }
    function loadWaiterSettlement(){
        state.wsData = null; render();
        var deptParam = state.wsDept ? ('&dept=' + state.wsDept) : '';
        api('/waiter-settlement?m=' + state.wsM + '&y=' + state.wsY + deptParam).then(function(d){ state.wsData = d; state.wsDept = d.dept; render(); })
            .catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
    }
    function loadEmailTemplates(){
        var f = state.flags || {};
        var groups = [];
        if (f.isManager) groups.push('kelner');
        if (f.isAdminBar) groups.push('bar');
        if (f.isAdminKtc) groups.push('kuchnia');
        var promises = groups.map(function(g){
            return api('/email-template?group=' + g).then(function(d){ state.emailTemplates[g] = d; });
        });
        return Promise.all(promises).then(render);
    }

    function loadPushTemplates(){
        var f = state.flags || {};
        var groups = [];
        if (f.isManager) groups.push('kelner');
        if (f.isAdminBar) groups.push('bar');
        if (f.isAdminKtc) groups.push('kuchnia');
        var promises = groups.map(function(g){
            return api('/push-template?group=' + g).then(function(d){ state.pushTemplates[g] = d; });
        });
        if (f.isAdmin){
            promises.push(api('/onesignal-settings').then(function(d){ state.onesignalSettings = d; }));
        }
        return Promise.all(promises).then(render);
    }

    function collect(prefixAttr){
        var out = {};
        root.querySelectorAll('[' + prefixAttr + ']').forEach(function(el){
            out[el.getAttribute(prefixAttr)] = el.value;
        });
        return out;
    }

    // Wartości widziane przy otwarciu formularza — serwer zapisuje tylko pola zmienione przez admina
    function collectOrig(prefixAttr){
        var out = {};
        root.querySelectorAll('[' + prefixAttr + '][data-orig]').forEach(function(el){
            out[el.getAttribute(prefixAttr)] = el.getAttribute('data-orig');
        });
        return out;
    }

    function bindEvents(){
        root.querySelectorAll('[data-section]').forEach(function(el){
            el.addEventListener('click', function(){
                var sec = el.dataset.section;
                var target = state.sectionTab[sec] || (tabsBySection(visibleTabs())[sec] || [])[0];
                if (target) switchTab(target);
            });
        });
        root.querySelectorAll('[data-subtab]').forEach(function(el){
            el.addEventListener('click', function(){ switchTab(el.dataset.subtab); });
        });

        root.querySelectorAll('[data-po-reset]').forEach(function(el){
            el.addEventListener('click', function(){
                var wid = el.dataset.poReset;
                if (!window.confirm('Przywrócić domyślne wartości (10% / 20%) dla tej osoby na ' + state.date + '?')) return;
                api('/percent-overrides', 'DELETE', { date: state.date, waiterId: wid }).then(function(d){
                    state.percentOverrides = d;
                    state.msg = { text: 'Przywrócono wartości domyślne.' };
                    loadDay();
                }).catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
            });
        });

        var checkUserSel = root.querySelector('#snspa-check-user');
        if (checkUserSel) checkUserSel.addEventListener('change', function(){ state.checkUserId = this.value; loadCheckStaff(); });
        var checkMSel = root.querySelector('#snspa-check-m'), checkYSel = root.querySelector('#snspa-check-y');
        if (checkMSel) checkMSel.addEventListener('change', function(){ state.checkM = parseInt(this.value,10); if (state.checkUserId) loadCheckStaff(); });
        if (checkYSel) checkYSel.addEventListener('change', function(){ state.checkY = parseInt(this.value,10); if (state.checkUserId) loadCheckStaff(); });

        var poolDateInput = root.querySelector('#snspa-pool-date');
        if (poolDateInput) poolDateInput.addEventListener('change', function(){ state.poolDate = this.value; loadServicePool(); });
        root.querySelectorAll('input[name="snspa-pool-mode"]').forEach(function(el){
            el.addEventListener('change', function(){ state.poolMode = this.value; render(); });
        });

        var wsMSel = root.querySelector('#snspa-ws-m'), wsYSel = root.querySelector('#snspa-ws-y');
        if (wsMSel) wsMSel.addEventListener('change', function(){ state.wsM = parseInt(this.value,10); loadWaiterSettlement(); });
        if (wsYSel) wsYSel.addEventListener('change', function(){ state.wsY = parseInt(this.value,10); loadWaiterSettlement(); });
        root.querySelectorAll('[data-ws-dept]').forEach(function(el){
            el.addEventListener('click', function(){ state.wsDept = el.dataset.wsDept; loadWaiterSettlement(); });
        });
        root.querySelectorAll('[data-ws-received]').forEach(function(el){ el.addEventListener('input', updateWsDiffs); });
        var srMSel = root.querySelector('#snspa-sr-m'), srYSel = root.querySelector('#snspa-sr-y');
        if (srMSel) srMSel.addEventListener('change', function(){ state.srM = parseInt(this.value,10); state.msg = null; loadSectionReport(); });
        if (srYSel) srYSel.addEventListener('change', function(){ state.srY = parseInt(this.value,10); state.msg = null; loadSectionReport(); });
        root.querySelectorAll('[data-sr-dept]').forEach(function(el){
            el.addEventListener('click', function(){ state.srDept = el.dataset.srDept; state.msg = null; loadSectionReport(); });
        });
        root.querySelectorAll('[data-sr-pdf]').forEach(function(el){ el.addEventListener('click', sectionReportPdf); });
        if (state.tab === 'waiterSettlement' && state.wsData) updateWsDiffs();

        var dateInput = root.querySelector('#snspa-date');
        if (dateInput) dateInput.addEventListener('change', function(){
            // Zabezpieczenie przed pustą/niepełną datą (np. gdy ktoś skasuje pole na telefonie) —
            // bez tego wysyłalibyśmy do serwera puste "date=" i dostawali "Nieprawidłowa data".
            if (!isValidIsoDate(this.value)) { this.value = state.date; return; }
            state.date = this.value;
            loadDay();
        });

        root.querySelectorAll('[data-nav]').forEach(function(el){
            el.addEventListener('click', function(){
                var dt = new Date(state.date + 'T00:00:00');
                if (isNaN(dt.getTime())) { state.date = todayIso(); loadDay(); return; }
                dt.setDate(dt.getDate() + parseInt(el.dataset.nav,10));
                state.date = dt.toISOString().slice(0,10);
                loadDay();
            });
        });

        var mSel = root.querySelector('#snspa-month-m'), ySel = root.querySelector('#snspa-month-y');
        if (mSel) mSel.addEventListener('change', function(){ state.monthM = parseInt(this.value,10); loadMonth(); });
        if (ySel) ySel.addEventListener('change', function(){ state.monthY = parseInt(this.value,10); loadMonth(); });

        root.querySelectorAll('[data-monthview]').forEach(function(el){
            el.addEventListener('click', function(){ state.monthView = el.dataset.monthview; loadMonth(); });
        });

        root.querySelectorAll('[data-save]').forEach(function(el){
            el.addEventListener('click', function(){ save(el.dataset.save); });
        });

        root.querySelectorAll('[data-pdf]').forEach(function(el){
            el.addEventListener('click', function(){
                var parts = el.dataset.pdf.split('|');
                exportPdf(parts[0], parts[1], parts[2]);
            });
        });

        root.querySelectorAll('[data-save-email]').forEach(function(el){
            el.addEventListener('click', function(){ saveEmailTemplate(el.dataset.saveEmail); });
        });
        root.querySelectorAll('[data-send-email]').forEach(function(el){
            el.addEventListener('click', function(){ sendEmail(el.dataset.sendEmail); });
        });
        root.querySelectorAll('[data-email-m]').forEach(function(el){
            el.addEventListener('change', function(){ state.emailM = parseInt(this.value,10); });
        });
        root.querySelectorAll('[data-email-y]').forEach(function(el){
            el.addEventListener('change', function(){ state.emailY = parseInt(this.value,10); });
        });

        root.querySelectorAll('[data-save-push]').forEach(function(el){
            el.addEventListener('click', function(){ savePushTemplate(el.dataset.savePush); });
        });
        root.querySelectorAll('[data-push-icon-file]').forEach(function(el){
            el.addEventListener('change', function(){ if (el.files && el.files[0]) uploadPushIcon(el.dataset.pushIconFile, el.files[0]); });
        });
        root.querySelectorAll('[data-push-icon-remove]').forEach(function(el){
            el.addEventListener('click', function(){ removePushIcon(el.dataset.pushIconRemove); });
        });
        root.querySelectorAll('[data-send-push]').forEach(function(el){
            el.addEventListener('click', function(){ sendPush(el.dataset.sendPush); });
        });
        root.querySelectorAll('[data-push-m]').forEach(function(el){
            el.addEventListener('change', function(){ state.pushM = parseInt(this.value,10); });
        });
        root.querySelectorAll('[data-push-y]').forEach(function(el){
            el.addEventListener('change', function(){ state.pushY = parseInt(this.value,10); });
        });
    }

    function save(section){
        var payload = { date: state.date };
        if (section === 'manager'){
            payload.manager = { cards: collect('data-mgr-card'), service: collect('data-mgr-service') };
        } else if (section === 'bar'){
            var helperSel = root.querySelector('#snspa-bar-helper-select');
            payload.bar = { hours: collect('data-bar-hours'), extraCard: collect('data-bar-extra'),
                orig: { hours: collectOrig('data-bar-hours'), extraCard: collectOrig('data-bar-extra') } };
            if (helperSel && helperSel.value){
                payload.bar.helper = { id: helperSel.value,
                    hours: root.querySelector('#snspa-bar-helper-hours').value,
                    card: root.querySelector('#snspa-bar-helper-card').value };
            }
        } else if (section === 'barCash'){
            payload.bar = { cash: collect('data-bar-cash') };
        } else if (section === 'kitchen'){
            payload.kitchen = { hours: collect('data-ktc-hours'), extraCard: collect('data-ktc-extra'),
                orig: { hours: collectOrig('data-ktc-hours'), extraCard: collectOrig('data-ktc-extra') } };
        } else if (section === 'kitchenCash'){
            payload.kitchen = { cash: collect('data-ktc-cash') };
        } else if (section === 'kelnerCash'){
            payload.kelnerCash = { bar: root.querySelector('#snspa-cash-bar').value, ktc: root.querySelector('#snspa-cash-ktc').value };
        } else if (section === 'myHours'){
            payload.myHours = root.querySelector('#snspa-my-hours').value;
        } else if (section === 'settings'){
            var body = {
                cardActive: root.querySelector('#snspa-set-card').checked,
                servActive: root.querySelector('#snspa-set-serv').checked,
                rate: parseFloat(root.querySelector('#snspa-set-rate').value || 0) / 100
            };
            api('/settings','POST', body).then(function(d){ state.settings = d; state.msg = {text:'Zapisano ustawienia.'}; render(); });
            return;
        } else if (section === 'percentOverrides'){
            var cardBars = collect('data-po-card-bar');
            var cardKitchens = collect('data-po-card-kitchen');
            var serviceBars = collect('data-po-service-bar');
            var serviceKitchens = collect('data-po-service-kitchen');
            var ids = Object.keys(cardBars);
            var calls = ids.map(function(wid){
                return api('/percent-overrides', 'POST', {
                    date: state.date, waiterId: wid,
                    cardBarPercent: parseFloat(cardBars[wid] || 10),
                    cardKitchenPercent: parseFloat(cardKitchens[wid] || 20),
                    serviceBarPercent: parseFloat(serviceBars[wid] || 10),
                    serviceKitchenPercent: parseFloat(serviceKitchens[wid] || 20),
                });
            });
            Promise.all(calls).then(function(results){
                state.percentOverrides = results[results.length - 1] || state.percentOverrides;
                state.msg = { text: 'Zapisano wyjątki procentowe dla ' + state.date + '.' };
                loadDay();
            }).catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
            return;
        } else if (section === 'checkStaff'){
            var sel = root.querySelector('#snspa-check-user');
            state.checkUserId = sel ? sel.value : '';
            var mSel = root.querySelector('#snspa-check-m'), ySel = root.querySelector('#snspa-check-y');
            if (mSel) state.checkM = parseInt(mSel.value,10);
            if (ySel) state.checkY = parseInt(ySel.value,10);
            loadCheckStaff();
            return;
        } else if (section === 'servicePool'){
            var poolBody = { date: state.poolDate, mode: state.poolMode };
            if (state.poolMode === 'hours') poolBody.hours = collect('data-pool-hours');
            else poolBody.amounts = collect('data-pool-amount');
            api('/service-pool','POST', poolBody).then(function(d){ state.poolData = d; state.msg = {text:'Zapisano rozliczenie serwisu.'}; render(); })
                .catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
            return;
        } else if (section === 'waiterSettlement'){
            var wsBody = { m: state.wsM, y: state.wsY, dept: state.wsDept || (state.wsData ? state.wsData.dept : undefined), received: collect('data-ws-received') };
            api('/waiter-settlement','POST', wsBody).then(function(d){ state.wsData = d; state.wsDept = d.dept; state.msg = {text:'Zapisano rozliczenia miesiąca.'}; render(); })
                .catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
            return;
        }

        api('/day','POST', payload).then(function(d){
            state.day = d;
            var c = d.saveConflicts, txt = 'Zapisano i przeliczono.';
            if (c && c.kept && c.kept.length) txt += ' W międzyczasie zmieniono wpis: ' + c.kept.join(', ') + ' — zachowano nowszą wartość.';
            if (c && c.overwritten && c.overwritten.length) txt += ' Uwaga: nadpisano Twoją wartością wpis zmieniony w międzyczasie: ' + c.overwritten.join(', ') + '.';
            state.msg = {text: txt, err: !!(c && c.overwritten && c.overwritten.length)}; render();
        }).catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
    }

    function saveEmailTemplate(group){
        var subjectEl = root.querySelector('[data-email-subject="' + group + '"]');
        var bodyEl = root.querySelector('[data-email-body="' + group + '"]');
        if (!subjectEl || !bodyEl) return;
        api('/email-template', 'POST', { group: group, subject: subjectEl.value, body: bodyEl.value }).then(function(d){
            state.emailTemplates[group] = d;
            state.msg = { text: 'Zapisano szablon wiadomości.' };
            render();
        }).catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
    }

    function sendEmail(group){
        var mEl = root.querySelector('[data-email-m="' + group + '"]');
        var yEl = root.querySelector('[data-email-y="' + group + '"]');
        var m = mEl ? parseInt(mEl.value,10) : state.emailM;
        var y = yEl ? parseInt(yEl.value,10) : state.emailY;
        state.emailM = m; state.emailY = y;
        var labels = { kelner: 'kelnerów', bar: 'barmanów', kuchnia: 'kucharzy' };
        if (!window.confirm('Wysłać e-maile z podsumowaniem do ' + (labels[group]||group) + ' za wybrany miesiąc?')) return;

        var btn = root.querySelector('[data-send-email="' + group + '"]');
        if (btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Wysyłanie…'; }

        api('/send-emails', 'POST', { group: group, m: m, y: y }).then(function(d){
            state.emailMsg[group] = { text: 'Wysłano: ' + d.sent + '. Pominięto (brak danych/e-maila): ' + d.skipped + (d.errors && d.errors.length ? ('. Błędy: ' + d.errors.join(', ')) : '') };
            render();
        }).catch(function(e){
            state.emailMsg[group] = { err: true, text: e.message };
            render();
        });
    }

    function savePushTemplate(group, iconId, okText){
        var titleEl = root.querySelector('[data-push-title="' + group + '"]');
        var msgEl = root.querySelector('[data-push-message="' + group + '"]');
        if (!titleEl || !msgEl) return;
        var payload = { group: group, title: titleEl.value, message: msgEl.value };
        if (typeof iconId !== 'undefined') payload.icon_id = iconId;
        api('/push-template', 'POST', payload).then(function(d){
            state.pushTemplates[group] = d;
            state.msg = { text: okText || 'Zapisano szablon powiadomienia.' };
            render();
        }).catch(function(e){ state.msg = {err:true, text:e.message}; render(); });
    }

    // Wgranie ikony -> od razu zapis szablonu z nową ikoną (tytuł/treść
    // bierzemy z pól formularza, więc niezapisane zmiany tekstu też trafią).
    function uploadPushIcon(group, file){
        if (file.size > 2 * 1024 * 1024){ state.msg = { err:true, text:'Plik jest za duży (maks. 2 MB).' }; render(); return; }
        var fd = new FormData();
        fd.append('group', group);
        fd.append('file', file);
        state.msg = { text: 'Wgrywanie ikony…' };
        fetch(REST + '/push-icon', { method:'POST', headers:{ 'X-WP-Nonce': NONCE }, body: fd })
            .then(function(r){ return r.json().then(function(d){ if (!r.ok) throw new Error(d.message || 'Błąd serwera'); return d; }); })
            .then(function(d){ savePushTemplate(group, d.icon_id, 'Ikona została zapisana.'); })
            .catch(function(e){ state.msg = { err:true, text:e.message }; render(); });
    }

    function removePushIcon(group){
        if (!window.confirm('Usunąć własną ikonę tego powiadomienia? Użyta zostanie ikona z panelu GastroFlowx.')) return;
        savePushTemplate(group, 0, 'Usunięto własną ikonę.');
    }

    function sendPush(group){
        var mEl = root.querySelector('[data-push-m="' + group + '"]');
        var yEl = root.querySelector('[data-push-y="' + group + '"]');
        var m = mEl ? parseInt(mEl.value,10) : state.pushM;
        var y = yEl ? parseInt(yEl.value,10) : state.pushY;
        state.pushM = m; state.pushY = y;
        var labels = { kelner: 'kelnerów', bar: 'barmanów', kuchnia: 'kucharzy' };
        if (!window.confirm('Wysłać powiadomienia push z podsumowaniem do ' + (labels[group]||group) + ' za wybrany miesiąc?')) return;

        var btn = root.querySelector('[data-send-push="' + group + '"]');
        if (btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Wysyłanie…'; }

        api('/send-push', 'POST', { group: group, m: m, y: y }).then(function(d){
            if (d.configError){
                state.pushMsg[group] = { err: true, text: d.configError };
            } else {
                state.pushMsg[group] = { text: 'Wysłano: ' + d.sent + (d.errors && d.errors.length ? ('. Błędy: ' + d.errors.join(', ')) : '') +
                    (d.recipients && d.recipients.length ? ('. Odbiorcy: ' + d.recipients.join(', ')) : '') };
            }
            render();
        }).catch(function(e){
            state.pushMsg[group] = { err: true, text: e.message };
            render();
        });
    }

    root.innerHTML = '<div class="snspa-loading">Ładowanie aplikacji…</div>';
    api('/bootstrap').then(function(b){
        state.flags = b.flags;
        state.flags.canSetPercentOverrides = b.canSetPercentOverrides;
        state.flags.canSectionReport = !!b.canSectionReport;
        if (b.today) state.date = b.today;
        render();
        loadDay();
    }).catch(function(e){
        root.innerHTML = '<div class="snspa-loading">Błąd ładowania: ' + e.message + '</div>';
    });

    var __resizeTimer = null;
    window.addEventListener('resize', function(){
        clearTimeout(__resizeTimer);
        __resizeTimer = setTimeout(updateStickyOffsets, 150);
    });
    })();
    </script>
JS;
}
