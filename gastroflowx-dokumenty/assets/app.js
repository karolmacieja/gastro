/*!
 * Dokumenty GastroFlowX — SPA (Vue 3, build globalny, bez kroku budowania).
 *
 * Cały proces tworzenia raportu dzieje się w przeglądarce:
 *   wybór zdjęć → kompresja (ukryty <canvas>, max szer., JPEG q≈0.7)
 *   → ustalenie kolejności (przeciągnij i upuść, także na dotyku)
 *   → jsPDF (A4, jedno zdjęcie na stronę, wyśrodkowane, z zachowaniem proporcji)
 *   → pobranie pliku.
 * Serwer dostaje pliki WYŁĄCZNIE przy włączonym Archiwum.
 */
(function () {
	'use strict';

	var MOUNT_ID = 'gfxdok-app';
	var CFG = window.gfxDokConfig || {};

	function bootError(msg) {
		var el = document.getElementById(MOUNT_ID);
		if (el) {
			el.innerHTML = '<div class="gd-boot-error"><strong>Nie udało się uruchomić modułu Dokumenty.</strong><br>' + msg + '</div>';
		}
	}

	function start() {
		var mountEl = document.getElementById(MOUNT_ID);
		if (!mountEl || mountEl.__gfxdokMounted) return;
		if (!window.Vue) {
			bootError('Nie załadowano biblioteki Vue. Sprawdź połączenie z internetem i odśwież stronę.');
			return;
		}
		mountEl.__gfxdokMounted = true;

		var Vue = window.Vue;
		var createApp = Vue.createApp, ref = Vue.ref, reactive = Vue.reactive, computed = Vue.computed,
			watch = Vue.watch, onMounted = Vue.onMounted, onBeforeUnmount = Vue.onBeforeUnmount, nextTick = Vue.nextTick;

		/* =============================================================
		 * Narzędzia: daty, formatowanie, REST
		 * =========================================================== */

		var MONTHS = ['Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'];
		var MONTHS_GEN = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
		var MONTHS_LOC = ['styczniu', 'lutym', 'marcu', 'kwietniu', 'maju', 'czerwcu', 'lipcu', 'sierpniu', 'wrześniu', 'październiku', 'listopadzie', 'grudniu'];
		var DOW_SHORT = ['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'Sb', 'Nd'];
		var DOW_LONG = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];

		function pad(n) { return (n < 10 ? '0' : '') + n; }
		function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
		function parseYmd(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
		function ym(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1); }
		function firstOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
		function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
		function dayLabel(s) {
			var d = parseYmd(s);
			return cap(DOW_LONG[d.getDay()]) + ', ' + d.getDate() + ' ' + MONTHS_GEN[d.getMonth()] + ' ' + d.getFullYear();
		}
		function shortDate(s) { var d = parseYmd(s); return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear(); }
		function fmtBytes(b) {
			b = +b || 0;
			if (b < 1024) return b + ' B';
			if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
			return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
		}
		function fmtDateTime(iso) {
			if (!iso) return '';
			var d = new Date(iso);
			if (isNaN(d)) return '';
			return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
		}
		function pagesLabel(n) {
			n = +n || 0;
			if (n === 1) return '1 strona';
			var l = n % 10, h = n % 100;
			return n + (l >= 2 && l <= 4 && (h < 12 || h > 14) ? ' strony' : ' stron');
		}
		function reportsLabel(n) {
			n = +n || 0;
			if (n === 1) return '1 raport';
			var l = n % 10, h = n % 100;
			return n + (l >= 2 && l <= 4 && (h < 12 || h > 14) ? ' raporty' : ' raportów');
		}
		function photosLabel(n) {
			n = +n || 0;
			if (n === 1) return '1 zdjęcie';
			var l = n % 10, h = n % 100;
			return n + (l >= 2 && l <= 4 && (h < 12 || h > 14) ? ' zdjęcia' : ' zdjęć');
		}

		/** Działa zarówno z "ładnymi" permalinkami (/wp-json/...), jak i ?rest_route=... */
		function buildUrl(path, params) {
			var base = String(CFG.restUrl || '').replace(/\/$/, '');
			var url = base + path;
			var qs = [];
			if (params) {
				Object.keys(params).forEach(function (k) {
					if (params[k] !== undefined && params[k] !== null) qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
				});
			}
			if (qs.length) url += (url.indexOf('?') === -1 ? '?' : '&') + qs.join('&');
			return url;
		}

		function api(path, opts) {
			opts = opts || {};
			var headers = { 'X-WP-Nonce': CFG.nonce };
			var body;
			if (opts.form) {
				body = opts.form;
			} else if (opts.body !== undefined) {
				headers['Content-Type'] = 'application/json';
				body = JSON.stringify(opts.body);
			}
			return fetch(buildUrl(path, opts.params), {
				method: opts.method || 'GET',
				headers: headers,
				body: body,
				credentials: 'same-origin'
			}).then(function (res) {
				return res.text().then(function (text) {
					var data = null;
					try { data = text ? JSON.parse(text) : null; } catch (e) {
						if (res.status === 413) throw new Error('Plik przekracza limit rozmiaru ustawiony na serwerze.');
						throw new Error('Serwer zwrócił nieprawidłową odpowiedź (HTTP ' + res.status + ').');
					}
					if (!res.ok) {
						throw new Error((data && data.message) || ('Błąd serwera (HTTP ' + res.status + ').'));
					}
					return data;
				});
			}, function () {
				throw new Error('Brak połączenia z serwerem. Sprawdź internet i spróbuj ponownie.');
			});
		}

		function fetchBlob(url) {
			return fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } }).then(function (res) {
				if (!res.ok) throw new Error('Nie udało się pobrać zdjęcia z archiwum (HTTP ' + res.status + ').');
				return res.blob();
			});
		}

		function buildFilename(pattern, date) {
			var d = parseYmd(date);
			var name = String(pattern || 'raport_dobowy_{data}')
				.replace(/\{data\}/g, date)
				.replace(/\{rok\}/g, String(d.getFullYear()))
				.replace(/\{miesiac\}/g, pad(d.getMonth() + 1))
				.replace(/\{dzien\}/g, pad(d.getDate()));
			try {
				name = name.replace(/[^\p{L}\p{N}_.\-]+/gu, '_');
			} catch (e) {
				name = name.replace(/[\\\/:*?"<>|\s]+/g, '_');
			}
			name = name.replace(/_+/g, '_').replace(/^[_.]+|[_.]+$/g, '') || ('raport_dobowy_' + date);
			if (!/\.pdf$/i.test(name)) name += '.pdf';
			return name;
		}

		function downloadBlob(blob, name) {
			// Wymuszenie właściwego typu MIME (WebKit bywa wrażliwy na pusty/ogólny typ blobu).
			if (blob && blob.type !== 'application/pdf' && /\.pdf$/i.test(name)) {
				blob = new Blob([blob], { type: 'application/pdf' });
			}
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			a.download = name;
			a.rel = 'noopener';
			a.style.display = 'none';
			document.body.appendChild(a);
			a.click();
			// Nie usuwamy linku ani URL-a od razu — część przeglądarek (zwł. WebKit
			// w trybie aplikacji) startuje zapis asynchronicznie.
			setTimeout(function () { a.remove(); }, 1000);
			setTimeout(function () { URL.revokeObjectURL(url); }, 120000);
		}

		/* -------------------------------------------------------------
		 * Zapis pliku w trybie zainstalowanej aplikacji (PWA).
		 * W oknie PWA na macOS pobieranie przez <a download> + blob: potrafi
		 * zgłosić „pobrano”, a plik nie trafia do folderu Pobrane. Tam, gdzie
		 * jest File System Access API (Chrome/Edge), pytamy o miejsce zapisu
		 * i zapisujemy plik bezpośrednio na dysk.
		 * ----------------------------------------------------------- */
		function isStandalone() {
			try {
				return window.matchMedia('(display-mode: standalone)').matches ||
					window.matchMedia('(display-mode: window-controls-overlay)').matches ||
					window.matchMedia('(display-mode: minimal-ui)').matches ||
					window.navigator.standalone === true;
			} catch (e) { return false; }
		}
		var CAN_PICK_SAVE = typeof window.showSaveFilePicker === 'function';

		/**
		 * UWAGA: wywoływać SYNCHRONICZNIE w obsłudze kliknięcia (przed fetch,
		 * generowaniem PDF itp.) — okno zapisu wymaga świeżej aktywacji użytkownika.
		 * Zwraca uchwyt pliku albo null (= użyj zwykłego pobierania).
		 * Odrzuca z AbortError, gdy użytkownik anulował okno.
		 */
		function pickSaveHandle(name) {
			if (!CAN_PICK_SAVE || !isStandalone()) return Promise.resolve(null);
			var p;
			try {
				p = window.showSaveFilePicker({
					suggestedName: name,
					types: [{ description: 'Dokument PDF', accept: { 'application/pdf': ['.pdf'] } }]
				});
			} catch (e) { return Promise.resolve(null); }
			return p.catch(function (e) {
				if (e && e.name === 'AbortError') throw e;
				return null; // np. SecurityError — wracamy do zwykłego pobierania
			});
		}

		function saveBlob(blob, name, handle) {
			if (!handle) {
				downloadBlob(blob, name);
				return Promise.resolve(false);
			}
			return handle.createWritable().then(function (w) {
				return w.write(blob).then(function () { return w.close(); });
			}).then(function () { return true; }, function () {
				downloadBlob(blob, name); // awaryjnie
				return false;
			});
		}

		function isAbort(e) { return e && e.name === 'AbortError'; }

		/* =============================================================
		 * Obróbka zdjęć (Canvas API)
		 * =========================================================== */

		function loadImg(url) {
			return new Promise(function (resolve, reject) {
				var img = new Image();
				img.decoding = 'async';
				img.onload = function () { resolve(img); };
				img.onerror = function () { reject(new Error('decode')); };
				img.src = url;
			});
		}

		/* ---- HEIC/HEIF (zdjęcia z iPhone'a) ----
		 * Safari odczytuje HEIC sam, ale Chrome, Edge i Firefox na komputerze
		 * nie. Gdy przeglądarka nie zdekoduje pliku HEIC, konwertujemy go do
		 * JPEG w przeglądarce biblioteką heic-to (libheif, dołączona lokalnie
		 * do wtyczki, ładowana tylko wtedy, gdy jest potrzebna). Nic nie trafia
		 * na serwer w formacie HEIC. */
		var heicLibPromise = null;

		/** Paczka IIFE wystawia `HeicTo` jako funkcję (starsze wersje: obiekt z `.heicTo`). */
		function heicFn() {
			var h = window.HeicTo;
			if (typeof h === 'function') return h;
			return h && typeof h.heicTo === 'function' ? h.heicTo : null;
		}

		function loadHeicLib() {
			if (heicFn()) return Promise.resolve(heicFn());
			if (heicLibPromise) return heicLibPromise;
			heicLibPromise = new Promise(function (resolve, reject) {
				if (!CFG.heicLib) { reject(new Error('Brak konwertera HEIC')); return; }
				var s = document.createElement('script');
				s.src = CFG.heicLib;
				s.async = true;
				s.onload = function () {
					if (heicFn()) resolve(heicFn());
					else reject(new Error('Nie udało się uruchomić konwertera HEIC'));
				};
				s.onerror = function () { reject(new Error('Nie udało się pobrać konwertera HEIC')); };
				document.head.appendChild(s);
			}).catch(function (e) { heicLibPromise = null; throw e; });
			return heicLibPromise;
		}

		/** Rozpoznaje HEIC/HEIF po nazwie, typie MIME lub nagłówku pliku (ftyp heic/heix/mif1…). */
		function isHeicBlob(blob) {
			if (/\.(heic|heif)$/i.test(blob.name || '') || /hei[cf]/i.test(blob.type || '')) return Promise.resolve(true);
			return blob.slice(0, 12).arrayBuffer().then(function (buf) {
				var b = new Uint8Array(buf);
				if (b.length < 12) return false;
				var box = String.fromCharCode(b[4], b[5], b[6], b[7]);
				var brand = String.fromCharCode(b[8], b[9], b[10], b[11]);
				return box === 'ftyp' && /^(heic|heix|hevc|hevx|heim|heis|mif1|msf1)$/.test(brand);
			}, function () { return false; });
		}

		function heicToJpeg(blob) {
			return loadHeicLib().then(function (convert) {
				return convert({ blob: blob, type: 'image/jpeg', quality: 0.92 });
			});
		}

		/** Dekoduje obraz z poprawną orientacją EXIF (zdjęcia z telefonów). */
		function decodeImage(blob) {
			return decodeNative(blob).catch(function (nativeErr) {
				return isHeicBlob(blob).then(function (heic) {
					if (!heic) throw nativeErr;
					return heicToJpeg(blob).then(decodeNative, function () {
						throw new Error('Nie udało się przekonwertować zdjęcia HEIC — spróbuj ponownie lub wybierz zdjęcie w formacie JPG');
					});
				});
			});
		}

		function decodeNative(blob) {
			var viaImg = function () {
				var url = URL.createObjectURL(blob);
				return loadImg(url).then(function (img) {
					return { src: img, w: img.naturalWidth, h: img.naturalHeight, close: function () { URL.revokeObjectURL(url); } };
				}, function () {
					URL.revokeObjectURL(url);
					throw new Error('Nieobsługiwany format pliku');
				});
			};
			if (window.createImageBitmap) {
				return createImageBitmap(blob, { imageOrientation: 'from-image' }).then(function (bmp) {
					return { src: bmp, w: bmp.width, h: bmp.height, close: function () { if (bmp.close) bmp.close(); } };
				}, viaImg);
			}
			return viaImg();
		}

		function canvasToBlob(canvas, quality) {
			return new Promise(function (resolve, reject) {
				canvas.toBlob(function (b) {
					if (b) resolve(b); else reject(new Error('Kompresja nie powiodła się (brak pamięci urządzenia?)'));
				}, 'image/jpeg', quality);
			});
		}

		/**
		 * Stopniowe zmniejszanie o połowę — przy dużej redukcji (np. 8000→1200 px)
		 * jednoprzebiegowe skalowanie daje "poszarpany" tekst na paragonie.
		 */
		function stepDown(src, sw, sh, targetW) {
			var cur = src, cw = sw, ch = sh;
			while (cw / 2 >= targetW) {
				var c = document.createElement('canvas');
				c.width = Math.round(cw / 2);
				c.height = Math.round(ch / 2);
				var cx = c.getContext('2d');
				cx.imageSmoothingEnabled = true;
				cx.imageSmoothingQuality = 'high';
				cx.drawImage(cur, 0, 0, c.width, c.height);
				cur = c; cw = c.width; ch = c.height;
			}
			return { src: cur, w: cw, h: ch };
		}

		/**
		 * @param {HTMLCanvasElement} canvas  ukryty canvas roboczy
		 * @param {Blob} blob                 oryginalny plik
		 * @param {{maxWidth:number, quality:number, rotate?:number}} o
		 */
		function processImage(canvas, thumbCanvas, blob, o) {
			return decodeImage(blob).then(function (dec) {
				try {
					var rot = (((o.rotate || 0) % 360) + 360) % 360;
					var swap = rot === 90 || rot === 270;
					var orientedW = swap ? dec.h : dec.w;
					var scale = Math.min(1, o.maxWidth / orientedW);
					var drawW = Math.max(1, Math.round(dec.w * scale));
					var drawH = Math.max(1, Math.round(dec.h * scale));
					var outW = swap ? drawH : drawW;
					var outH = swap ? drawW : drawH;

					var sd = stepDown(dec.src, dec.w, dec.h, drawW);

					canvas.width = outW;
					canvas.height = outH;
					var ctx = canvas.getContext('2d');
					ctx.save();
					ctx.fillStyle = '#ffffff';
					ctx.fillRect(0, 0, outW, outH);
					ctx.imageSmoothingEnabled = true;
					ctx.imageSmoothingQuality = 'high';
					ctx.translate(outW / 2, outH / 2);
					ctx.rotate(rot * Math.PI / 180);
					ctx.drawImage(sd.src, -drawW / 2, -drawH / 2, drawW, drawH);
					ctx.restore();
				} finally {
					dec.close();
				}
				return canvasToBlob(canvas, o.quality).then(function (full) {
					var ts = Math.min(1, 360 / canvas.width);
					thumbCanvas.width = Math.max(1, Math.round(canvas.width * ts));
					thumbCanvas.height = Math.max(1, Math.round(canvas.height * ts));
					var tctx = thumbCanvas.getContext('2d');
					tctx.imageSmoothingEnabled = true;
					tctx.imageSmoothingQuality = 'high';
					tctx.drawImage(canvas, 0, 0, thumbCanvas.width, thumbCanvas.height);
					return canvasToBlob(thumbCanvas, 0.6).then(function (thumb) {
						var res = { blob: full, thumb: thumb, width: canvas.width, height: canvas.height };
						canvas.width = canvas.height = 1; // zwolnienie pamięci (ważne na iOS)
						return res;
					});
				});
			});
		}

		var keySeq = 0;
		function nextKey() { keySeq += 1; return 'k' + Date.now().toString(36) + keySeq; }

		/* =============================================================
		 * Komponent: kalendarz miesiąca
		 * =========================================================== */

		var CalendarGrid = {
			props: {
				month: { type: Date, required: true },     // Date — 1. dzień miesiąca
				days: { type: Object, default: function () { return {}; } },
				today: { type: String, required: true },
				archiveOn: { type: Boolean, default: false },
				mode: { type: String, default: 'reports' }, // reports | archive
				loading: { type: Boolean, default: false }
			},
			emits: ['pick', 'prev', 'next'],
			setup: function (props) {
				var cells = computed(function () {
					var m = props.month;
					var first = new Date(m.getFullYear(), m.getMonth(), 1);
					var lead = (first.getDay() + 6) % 7;
					var count = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
					var out = [];
					for (var i = 0; i < lead; i++) out.push({ empty: true, key: 'e' + i });
					for (var d = 1; d <= count; d++) {
						var date = ymd(new Date(m.getFullYear(), m.getMonth(), d));
						var info = props.days[date] || null;
						var archived = !!(info && props.archiveOn && (info.hasPdf || info.imageCount));
						var generated = !!(info && !archived && info.generatedAt);
						var future = date > props.today;
						var disabled = future || (props.mode === 'archive' && !archived);
						out.push({ key: date, date: date, num: d, info: info, archived: archived, generated: generated, future: future, disabled: disabled, isToday: date === props.today });
					}
					return out;
				});
				var title = computed(function () { return MONTHS[props.month.getMonth()] + ' ' + props.month.getFullYear(); });
				var canNext = computed(function () { return ym(props.month) < props.today.slice(0, 7); });
				return { cells: cells, title: title, canNext: canNext, DOW_SHORT: DOW_SHORT, pagesLabel: pagesLabel };
			},
			template: '\
<div class="gd-cal">\
	<div class="gd-month-nav">\
		<button type="button" class="gd-round" @click="$emit(\'prev\')" aria-label="Poprzedni miesiąc"><i class="fa-solid fa-chevron-left"></i></button>\
		<h3>{{ title }}</h3>\
		<button type="button" class="gd-round" :disabled="!canNext" @click="$emit(\'next\')" aria-label="Następny miesiąc"><i class="fa-solid fa-chevron-right"></i></button>\
	</div>\
	<div class="gd-cal-card" :class="{ \'gd-is-loading\': loading }">\
		<div class="gd-cal-dow"><div v-for="d in DOW_SHORT" :key="d">{{ d }}</div></div>\
		<div class="gd-cal-grid">\
			<template v-for="c in cells" :key="c.key">\
				<div v-if="c.empty" class="gd-day gd-day-empty"></div>\
				<button v-else type="button" class="gd-day"\
					:class="{ \'gd-day-archived\': c.archived, \'gd-day-generated\': c.generated, \'gd-day-today\': c.isToday, \'gd-day-future\': c.future }"\
					:disabled="c.disabled" @click="$emit(\'pick\', c.date)"\
					:aria-label="c.date + (c.archived ? \', zarchiwizowany\' : c.generated ? \', wygenerowany\' : \'\')">\
					<span class="gd-day-num">{{ c.num }}</span>\
					<span v-if="c.archived" class="gd-day-chip gd-chip-archived"><i class="fa-solid fa-file-pdf"></i><span class="gd-chip-txt">{{ c.info.pages || c.info.imageCount }}</span></span>\
					<span v-else-if="c.generated" class="gd-day-chip gd-chip-generated"><i class="fa-solid fa-check"></i><span class="gd-chip-txt">{{ c.info.pages }}</span></span>\
				</button>\
			</template>\
		</div>\
	</div>\
</div>'
		};

		/* =============================================================
		 * Aplikacja główna
		 * =========================================================== */

		var App = {
			components: { CalendarGrid: CalendarGrid },
			setup: function () {
				var today = CFG.today || ymd(new Date());
				var canManage = !!CFG.canManage;
				var maxImages = +CFG.maxImages || 60;

				var settings = reactive(Object.assign({
					file_pattern: 'raport_dobowy_{data}', archive_enabled: false, max_width: 1200,
					quality: 0.7, margin_mm: 10, auto_orientation: false, page_footer: true
				}, CFG.settings || {}));

				var tab = ref('reports');
				var view = ref('calendar'); // calendar | day
				var toasts = ref([]);

				var canvasEl = ref(null);
				var thumbCanvasEl = ref(null);
				var fileInput = ref(null);
				var cameraInput = ref(null);
				var gridEl = ref(null);

				/* ---------------- Toasty ---------------- */
				function toast(msg, type) {
					var t = { id: nextKey(), msg: msg, type: type || 'ok' };
					toasts.value.push(t);
					setTimeout(function () { toasts.value = toasts.value.filter(function (x) { return x.id !== t.id; }); }, type === 'error' ? 6000 : 3500);
				}

				/* ---------------- Kalendarz (Raporty) ---------------- */
				var month = ref(firstOfMonth(parseYmd(today)));
				var monthDays = ref({});
				var monthLoading = ref(false);
				var monthError = ref('');

				function loadMonth() {
					monthLoading.value = true;
					monthError.value = '';
					var key = ym(month.value);
					return api('/month', { params: { month: key } }).then(function (res) {
						if (ym(month.value) === key) monthDays.value = res.days || {};
					}).catch(function (e) {
						monthError.value = e.message;
					}).then(function () { monthLoading.value = false; });
				}
				function shiftMonth(delta) {
					var m = month.value;
					month.value = new Date(m.getFullYear(), m.getMonth() + delta, 1);
					loadMonth();
				}
				var monthSummary = computed(function () {
					var list = Object.keys(monthDays.value).map(function (k) { return monthDays.value[k]; });
					var done = list.filter(function (d) { return d.generatedAt || d.hasPdf; }).length;
					return { done: done, label: MONTHS_LOC[month.value.getMonth()] };
				});

				/* ---------------- Edytor dnia ---------------- */
				var day = reactive({
					date: '',
					loading: false,
					report: null,
					items: [],
					removedServer: false,
					dirty: false,
					error: '',
					warnings: [],
					result: null
				});
				var busy = reactive({ active: false, phase: '', text: '', done: 0, total: 0 });
				var busyPct = computed(function () { return busy.total ? Math.round(busy.done / busy.total * 100) : 0; });

				function setBusy(phase, text, total) {
					busy.active = true; busy.phase = phase; busy.text = text; busy.done = 0; busy.total = total || 0;
				}
				function clearBusy() { busy.active = false; busy.phase = ''; busy.text = ''; busy.done = 0; busy.total = 0; }

				function revokeItem(it) {
					if (it && it.localThumb) { URL.revokeObjectURL(it.thumbUrl); it.localThumb = false; }
				}
				function clearItems() {
					day.items.forEach(revokeItem);
					day.items.splice(0, day.items.length);
				}
				function clearResult() {
					day.result = null;
				}

				var hasLocalWork = computed(function () {
					return day.dirty && day.items.length > 0 && !busy.active;
				});

				function openDay(date) {
					if (date > today) return;
					if (view.value === 'day' && hasLocalWork.value && date !== day.date) {
						if (!window.confirm('Masz niezapisane zdjęcia w bieżącym dniu. Porzucić je?')) return;
					}
					clearItems();
					clearResult();
					day.date = date;
					day.report = null;
					day.error = '';
					day.warnings = [];
					day.dirty = false;
					view.value = 'day';
					tab.value = 'reports';
					var m = parseYmd(date);
					if (ym(m) !== ym(month.value)) { month.value = firstOfMonth(m); loadMonth(); }
					scrollTop();

					day.loading = true;
					return api('/day/' + date).then(function (res) {
						if (day.date !== date) return;
						day.report = res.report;
						if (res.archive && res.images && res.images.length) {
							res.images.forEach(function (img) {
								day.items.push({
									key: 'srv' + img.id, source: 'server', id: img.id,
									width: img.width, height: img.height, bytes: img.bytes,
									thumbUrl: img.thumbUrl, fullUrl: img.fullUrl, blob: null, localThumb: false
								});
							});
						}
					}).catch(function (e) {
						day.error = e.message;
					}).then(function () { day.loading = false; });
				}

				function backToCalendar() {
					if (hasLocalWork.value && !window.confirm('Masz niezapisane zdjęcia. Wrócić do kalendarza i je porzucić?')) return;
					clearItems();
					clearResult();
					day.dirty = false;
					view.value = 'calendar';
					scrollTop();
				}

				function scrollTop() {
					nextTick(function () {
						var root = document.getElementById(MOUNT_ID);
						if (root && root.getBoundingClientRect().top < 0) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
					});
				}

				/* ---------------- Dodawanie zdjęć ---------------- */
				function pickFiles() { if (fileInput.value) fileInput.value.click(); }
				function pickCamera() { if (cameraInput.value) cameraInput.value.click(); }

				function onFileInput(e) {
					var files = Array.prototype.slice.call(e.target.files || []);
					e.target.value = '';
					addFiles(files);
				}

				var dropActive = ref(false);
				function onDragOver(e) {
					if (busy.active) return;
					if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) {
						e.preventDefault();
						dropActive.value = true;
					}
				}
				function onDragLeave(e) {
					if (!e.currentTarget.contains(e.relatedTarget)) dropActive.value = false;
				}
				function onDrop(e) {
					dropActive.value = false;
					if (busy.active || !e.dataTransfer) return;
					e.preventDefault();
					addFiles(Array.prototype.slice.call(e.dataTransfer.files || []));
				}

				function isImageFile(f) {
					return (f.type && f.type.indexOf('image/') === 0) || /\.(jpe?g|png|webp|gif|bmp|heic|heif|avif)$/i.test(f.name || '');
				}

				function addFiles(files) {
					if (!files.length || busy.active) return;
					clearResult();
					day.error = '';
					day.warnings = [];
					var skipped = [];
					var images = files.filter(function (f) {
						if (isImageFile(f)) return true;
						skipped.push((f.name || 'plik') + ' — to nie jest zdjęcie');
						return false;
					});
					var room = maxImages - day.items.length;
					if (images.length > room) {
						images.slice(Math.max(0, room)).forEach(function (f) { skipped.push((f.name || 'plik') + ' — przekroczono limit ' + maxImages + ' zdjęć'); });
						images = images.slice(0, Math.max(0, room));
					}
					if (!images.length) { day.warnings = skipped; return; }

					setBusy('compress', 'Optymalizacja zdjęć', images.length);
					var batch = { added: 0, orig: 0, out: 0 };
					var chain = Promise.resolve();
					images.forEach(function (file) {
						chain = chain.then(function () {
							busy.text = 'Optymalizacja zdjęć';
							return processImage(canvasEl.value, thumbCanvasEl.value, file, {
								maxWidth: +settings.max_width || 1200,
								quality: +settings.quality || 0.7
							}).then(function (r) {
								day.items.push({
									key: nextKey(), source: 'local', id: null,
									width: r.width, height: r.height, bytes: r.blob.size, originalBytes: file.size,
									blob: r.blob, thumbBlob: r.thumb, thumbUrl: URL.createObjectURL(r.thumb), localThumb: true,
									fullUrl: '', name: file.name
								});
								day.dirty = true;
								batch.added += 1; batch.orig += file.size; batch.out += r.blob.size;
							}, function (err) {
								skipped.push((file.name || 'zdjęcie') + ' — ' + (err && err.message ? err.message : 'nie udało się odczytać pliku'));
							}).then(function () { busy.done += 1; });
						});
					});
					chain.then(function () {
						clearBusy();
						day.warnings = skipped;
						if (batch.added) {
							toast('Dodano ' + photosLabel(batch.added) + ': ' + fmtBytes(batch.orig) + ' → ' + fmtBytes(batch.out) + ' po kompresji.');
						}
					});
				}

				/* ---------------- Operacje na miniaturach ---------------- */
				function removeItem(index) {
					var it = day.items[index];
					if (!it) return;
					if (it.source === 'server' && !window.confirm('Usunąć to zdjęcie z raportu? Zmiana zostanie zapisana w archiwum po ponownym wygenerowaniu PDF.')) return;
					revokeItem(it);
					day.items.splice(index, 1);
					day.dirty = true;
					clearResult();
					if (preview.key === it.key) closePreview();
				}
				function moveItem(index, delta) {
					var to = index + delta;
					if (to < 0 || to >= day.items.length) return;
					var it = day.items.splice(index, 1)[0];
					day.items.splice(to, 0, it);
					day.dirty = true;
					clearResult();
				}
				function rotateItem(index) {
					var it = day.items[index];
					if (!it || busy.active) return;
					setBusy('rotate', 'Obracanie zdjęcia', 1);
					var src = it.blob ? Promise.resolve(it.blob) : fetchBlob(it.fullUrl);
					src.then(function (blob) {
						return processImage(canvasEl.value, thumbCanvasEl.value, blob, {
							maxWidth: Math.max(it.width, it.height, +settings.max_width || 1200),
							quality: Math.min(0.92, (+settings.quality || 0.7) + 0.1),
							rotate: 90
						});
					}).then(function (r) {
						var idx = day.items.indexOf(it);
						if (idx === -1) return;
						revokeItem(it);
						var fresh = {
							key: nextKey(), source: 'local', id: null,
							width: r.width, height: r.height, bytes: r.blob.size, originalBytes: 0,
							blob: r.blob, thumbBlob: r.thumb, thumbUrl: URL.createObjectURL(r.thumb), localThumb: true,
							fullUrl: '', name: it.name || ''
						};
						day.items.splice(idx, 1, fresh);
						day.dirty = true;
						clearResult();
						if (preview.open && preview.key === it.key) openPreview(idx);
					}).catch(function (e) {
						day.error = 'Nie udało się obrócić zdjęcia: ' + e.message;
					}).then(clearBusy);
				}

				/* ---------------- Podgląd (lightbox) ---------------- */
				var preview = reactive({ open: false, key: null, url: '', local: false, index: 0 });
				function openPreview(index) {
					var it = day.items[index];
					if (!it) return;
					if (preview.local && preview.url) URL.revokeObjectURL(preview.url);
					preview.key = it.key;
					preview.index = index;
					preview.local = !!it.blob;
					preview.url = it.blob ? URL.createObjectURL(it.blob) : it.fullUrl;
					preview.open = true;
				}
				function closePreview() {
					if (preview.local && preview.url) URL.revokeObjectURL(preview.url);
					preview.open = false; preview.url = ''; preview.key = null; preview.local = false;
				}
				var previewIndex = computed(function () {
					return day.items.findIndex(function (i) { return i.key === preview.key; });
				});
				function previewStep(delta) {
					var i = previewIndex.value + delta;
					if (i >= 0 && i < day.items.length) openPreview(i);
				}
				function onKey(e) {
					if (!preview.open) return;
					if (e.key === 'Escape') closePreview();
					if (e.key === 'ArrowRight') previewStep(1);
					if (e.key === 'ArrowLeft') previewStep(-1);
				}

				/* ---------------- Przeciągnij i upuść (pointer events) ----------------
				 * Natywne HTML5 drag&drop nie działa na ekranach dotykowych, dlatego
				 * sortowanie jest zrobione na zdarzeniach wskaźnika:
				 *  - mysz: chwyć dowolne miejsce miniatury (po przesunięciu > 6 px),
				 *  - dotyk: chwyć uchwyt z numerem strony (touch-action:none), reszta
				 *    miniatury normalnie przewija stronę.
				 */
				var drag = reactive({ pending: false, active: false, key: null, startX: 0, startY: 0, x: 0, y: 0, offX: 0, offY: 0, w: 0, h: 0, thumb: '' });
				var suppressClick = false;
				var scrollParent = null;
				var rafId = 0;

				function findScrollParent(el) {
					var p = el && el.parentElement;
					while (p && p !== document.body) {
						var s = getComputedStyle(p);
						if (/(auto|scroll)/.test(s.overflowY) && p.scrollHeight > p.clientHeight + 4) return p;
						p = p.parentElement;
					}
					return null;
				}

				function onThumbPointerDown(e, index, fromHandle) {
					if (busy.active) return;
					if (e.pointerType === 'mouse' && e.button !== 0) return;
					if (!fromHandle && e.pointerType !== 'mouse') return; // dotyk: tylko uchwyt
					var card = e.currentTarget.closest('.gd-thumb');
					if (!card) return;
					var rect = card.getBoundingClientRect();
					var it = day.items[index];
					drag.pending = true;
					drag.active = false;
					drag.key = it.key;
					drag.startX = drag.x = e.clientX;
					drag.startY = drag.y = e.clientY;
					drag.offX = e.clientX - rect.left;
					drag.offY = e.clientY - rect.top;
					drag.w = rect.width;
					drag.h = rect.height;
					drag.thumb = it.thumbUrl;
					if (fromHandle) { e.preventDefault(); e.stopPropagation(); }
					window.addEventListener('pointermove', onPointerMove, { passive: false });
					window.addEventListener('pointerup', onPointerUp);
					window.addEventListener('pointercancel', onPointerUp);
					if (fromHandle) activateDrag();
				}

				function activateDrag() {
					drag.active = true;
					scrollParent = findScrollParent(gridEl.value);
					document.documentElement.classList.add('gd-no-select');
					cancelAnimationFrame(rafId);
					rafId = requestAnimationFrame(autoScroll);
				}

				function hitTest() {
					var el = document.elementFromPoint(drag.x, drag.y);
					var target = el && el.closest ? el.closest('[data-gd-index]') : null;
					if (!target || !gridEl.value || !gridEl.value.contains(target)) return;
					var to = +target.getAttribute('data-gd-index');
					var from = day.items.findIndex(function (i) { return i.key === drag.key; });
					if (from === -1 || to === from || isNaN(to)) return;
					var it = day.items.splice(from, 1)[0];
					day.items.splice(to, 0, it);
					day.dirty = true;
					clearResult();
				}

				function onPointerMove(e) {
					if (!drag.pending) return;
					drag.x = e.clientX;
					drag.y = e.clientY;
					if (!drag.active) {
						if (Math.abs(drag.x - drag.startX) + Math.abs(drag.y - drag.startY) < 6) return;
						activateDrag();
					}
					e.preventDefault();
					hitTest();
				}

				function autoScroll() {
					if (!drag.active) return;
					var edge = 70, speed = 0, top, bottom;
					if (scrollParent) {
						var r = scrollParent.getBoundingClientRect();
						top = r.top; bottom = r.bottom;
					} else {
						top = 0; bottom = window.innerHeight;
					}
					if (drag.y < top + edge) speed = -Math.ceil((top + edge - drag.y) / 5);
					else if (drag.y > bottom - edge) speed = Math.ceil((drag.y - (bottom - edge)) / 5);
					if (speed) {
						if (scrollParent) scrollParent.scrollTop += speed; else window.scrollBy(0, speed);
						hitTest();
					}
					rafId = requestAnimationFrame(autoScroll);
				}

				function onPointerUp() {
					window.removeEventListener('pointermove', onPointerMove);
					window.removeEventListener('pointerup', onPointerUp);
					window.removeEventListener('pointercancel', onPointerUp);
					cancelAnimationFrame(rafId);
					document.documentElement.classList.remove('gd-no-select');
					if (drag.active) {
						suppressClick = true;
						setTimeout(function () { suppressClick = false; }, 60);
					}
					drag.pending = false;
					drag.active = false;
					drag.key = null;
				}

				function onThumbClick(index) {
					if (suppressClick) return;
					openPreview(index);
				}

				var ghostStyle = computed(function () {
					return {
						width: drag.w + 'px',
						height: drag.h + 'px',
						transform: 'translate3d(' + (drag.x - drag.offX) + 'px,' + (drag.y - drag.offY) + 'px,0) rotate(2deg) scale(1.04)'
					};
				});

				/* ---------------- Generowanie PDF ---------------- */
				var fileName = computed(function () { return day.date ? buildFilename(settings.file_pattern, day.date) : ''; });

				function buildPdf() {
					var lib = window.jspdf && window.jspdf.jsPDF;
					if (!lib) return Promise.reject(new Error('Nie załadowano biblioteki PDF (jsPDF). Sprawdź połączenie z internetem i odśwież stronę.'));
					var items = day.items.slice();
					var n = items.length;
					var margin = Math.max(0, Math.min(30, +settings.margin_mm || 0));
					var footer = !!settings.page_footer;
					var footerH = footer ? 8 : 0;
					var doc = null;
					var label = shortDate(day.date);

					setBusy('pdf', 'Składanie pliku PDF', n);
					var chain = Promise.resolve();
					items.forEach(function (it, i) {
						chain = chain.then(function () {
							return (it.blob ? Promise.resolve(it.blob) : fetchBlob(it.fullUrl)).then(function (b) {
								return b.arrayBuffer();
							}).then(function (buf) {
								var landscape = settings.auto_orientation && it.width > it.height;
								var orient = landscape ? 'l' : 'p';
								if (!doc) {
									doc = new lib({ orientation: orient, unit: 'mm', format: 'a4', compress: true });
								} else {
									doc.addPage('a4', orient);
								}
								var pw = doc.internal.pageSize.getWidth();
								var ph = doc.internal.pageSize.getHeight();
								var aw = pw - margin * 2;
								var ah = ph - margin * 2 - footerH;
								var r = Math.min(aw / it.width, ah / it.height);
								var w = it.width * r;
								var h = it.height * r;
								var x = (pw - w) / 2;
								var y = margin + (ah - h) / 2;
								doc.addImage(new Uint8Array(buf), 'JPEG', x, y, w, h, 'p' + i, 'NONE');
								if (footer) {
									doc.setFont('helvetica', 'normal');
									doc.setFontSize(8);
									doc.setTextColor(120, 120, 120);
									// Standardowa czcionka PDF nie ma polskich znaków — stopka celowo bez diakrytyków.
									doc.text('Raport dobowy ' + label + '  -  strona ' + (i + 1) + ' z ' + n, pw / 2, ph - margin - 2, { align: 'center' });
								}
								busy.done = i + 1;
							});
						});
					});
					return chain.then(function () {
						doc.setProperties({ title: 'Raport dobowy ' + label, subject: 'Raport dobowy z utargu', creator: 'Dokumenty GastroFlowX' });
						return doc.output('blob');
					});
				}

				function generate() {
					if (!day.items.length || busy.active) return;
					day.error = '';
					day.warnings = [];
					clearResult();
					var name = fileName.value;
					var pages = day.items.length;

					// Okno zapisu (PWA + Chrome/Edge) musi się otworzyć od razu po kliknięciu.
					pickSaveHandle(name).then(function (handle) {
						return buildPdf().then(function (pdfBlob) {
							return saveBlob(pdfBlob, name, handle).then(function (saved) {
								if (saved) toast('Zapisano plik ' + name + '.');
								return pdfBlob;
							});
						});
					}).then(function (pdfBlob) {
						var file = null;
						try { file = new File([pdfBlob], name, { type: 'application/pdf' }); } catch (e) { file = null; }
						day.result = {
							blob: pdfBlob, name: name, pages: pages, size: pdfBlob.size,
							canShare: !!(file && navigator.canShare && navigator.canShare({ files: [file] })),
							file: file, archived: false, archiveError: '', metaWarning: ''
						};
						if (settings.archive_enabled) {
							return saveToArchive();
						}
						// Archiwum wyłączone: zdjęcia natychmiast usuwane z pamięci, na serwer idą tylko metadane.
						clearItems();
						day.dirty = false;
						clearBusy();
						return api('/day/' + day.date + '/finalize', { method: 'POST', form: metaForm(pages, name) })
							.then(function (res) { day.report = res.report; })
							.catch(function () { if (day.result) day.result.metaWarning = 'Nie udało się oznaczyć dnia w kalendarzu (plik PDF został pobrany poprawnie).'; })
							.then(loadMonth);
					}).catch(function (e) {
						clearBusy();
						if (isAbort(e)) return; // anulowano okno zapisu
						day.error = 'Nie udało się wygenerować raportu: ' + e.message;
					});
				}

				function metaForm(pages, name) {
					var f = new FormData();
					f.append('pages', String(pages));
					f.append('filename', name);
					return f;
				}

				/** Wysyła nowe zdjęcia, a potem PDF + kolejność. Można ponowić po błędzie. */
				function saveToArchive() {
					var res = day.result;
					if (!res) return Promise.resolve();
					res.archiveError = '';
					var maxUp = +CFG.maxUpload || 0;
					if (maxUp && res.size > maxUp) {
						clearBusy();
						res.archiveError = 'Plik PDF (' + fmtBytes(res.size) + ') przekracza limit przesyłania na serwerze (' + fmtBytes(maxUp) + '). Zmniejsz jakość lub szerokość zdjęć w Ustawieniach.';
						return Promise.resolve();
					}
					var locals = day.items.filter(function (i) { return i.source === 'local'; });
					setBusy('upload', 'Zapisywanie w archiwum', locals.length + 1);
					var date = day.date;
					var chain = Promise.resolve();
					locals.forEach(function (it) {
						chain = chain.then(function () {
							var f = new FormData();
							f.append('file', it.blob, 'zdjecie.jpg');
							if (it.thumbBlob) f.append('thumb', it.thumbBlob, 'miniatura.jpg');
							return api('/day/' + date + '/images', { method: 'POST', form: f }).then(function (r) {
								it.source = 'server';
								it.id = r.image.id;
								it.fullUrl = r.image.fullUrl;
								busy.done += 1;
							});
						});
					});
					return chain.then(function () {
						var f = new FormData();
						f.append('order', JSON.stringify(day.items.map(function (i) { return i.id; })));
						f.append('filename', res.name);
						f.append('pdf', res.blob, res.name);
						return api('/day/' + date + '/finalize', { method: 'POST', form: f });
					}).then(function (r) {
						busy.done = busy.total;
						day.report = r.report;
						res.archived = true;
						day.dirty = false;
						toast('Raport zapisany w archiwum.');
						loadMonth();
						if (archive.loaded) loadArchive(archive.year);
					}).catch(function (e) {
						res.archiveError = 'PDF został pobrany, ale zapis w archiwum nie powiódł się: ' + e.message;
					}).then(clearBusy);
				}

				function redownload() {
					var r = day.result;
					if (!r) return;
					pickSaveHandle(r.name).then(function (h) {
						return saveBlob(r.blob, r.name, h);
					}).then(function (saved) {
						if (saved) toast('Zapisano plik ' + r.name + '.');
					}).catch(function (e) {
						if (!isAbort(e)) toast('Nie udało się zapisać pliku.', 'error');
					});
				}

				/** Pobranie PDF z archiwum — w PWA przez okno zapisu, w przeglądarce zwykły link. */
				function onPdfLink(ev, url, name, date) {
					if (!CAN_PICK_SAVE || !isStandalone()) return; // domyślne zachowanie <a href>
					ev.preventDefault();
					name = name || ('raport_dobowy_' + date + '.pdf');
					pickSaveHandle(name).then(function (h) {
						if (!h) { window.location.href = url; return false; }
						return fetchBlob(url).then(function (b) { return saveBlob(b, name, h); });
					}).then(function (saved) {
						if (saved) toast('Zapisano plik ' + name + '.');
					}).catch(function (e) {
						if (!isAbort(e)) toast('Nie udało się pobrać pliku PDF z archiwum.', 'error');
					});
				}
				function share() {
					var r = day.result;
					if (!r || !r.file) return;
					navigator.share({ files: [r.file], title: r.name }).catch(function (e) {
						if (e && e.name !== 'AbortError') toast('Nie udało się udostępnić pliku.', 'error');
					});
				}

				function deleteDay(date) {
					date = date || day.date;
					if (!window.confirm('Usunąć wszystkie zapisane dane dnia ' + shortDate(date) + ' (zdjęcia i PDF)? Tej operacji nie można cofnąć.')) return Promise.resolve();
					return api('/day/' + date, { method: 'DELETE' }).then(function () {
						toast('Usunięto dane dnia ' + shortDate(date) + '.');
						if (day.date === date) {
							clearItems(); clearResult(); day.report = null; day.dirty = false;
						}
						loadMonth();
						if (archive.loaded) loadArchive(archive.year);
					}).catch(function (e) { toast(e.message, 'error'); });
				}

				/* ---------------- Archiwum ---------------- */
				var archive = reactive({ loaded: false, loading: false, error: '', year: parseYmd(today).getFullYear(), years: [], days: [], mode: 'list', calMonth: firstOfMonth(parseYmd(today)), open: {} });

				function loadArchive(year) {
					if (!settings.archive_enabled) return Promise.resolve();
					archive.loading = true;
					archive.error = '';
					return api('/archive', { params: { year: year || archive.year } }).then(function (res) {
						archive.year = res.year || year || archive.year;
						archive.years = res.years || [];
						archive.days = res.days || [];
						archive.loaded = true;
						// Domyślnie rozwinięty najnowszy miesiąc.
						if (archive.days.length && !Object.keys(archive.open).length) {
							archive.open[archive.days[0].date.slice(0, 7)] = true;
						}
					}).catch(function (e) { archive.error = e.message; }).then(function () { archive.loading = false; });
				}

				var archiveGroups = computed(function () {
					var groups = [];
					var map = {};
					archive.days.forEach(function (d) {
						var k = d.date.slice(0, 7);
						if (!map[k]) {
							map[k] = { key: k, label: MONTHS[+k.slice(5, 7) - 1] + ' ' + k.slice(0, 4), days: [], size: 0 };
							groups.push(map[k]);
						}
						map[k].days.push(d);
						map[k].size += d.pdfSize || 0;
					});
					return groups;
				});
				var archiveDayMap = computed(function () {
					var m = {};
					archive.days.forEach(function (d) { m[d.date] = d; });
					return m;
				});
				var archiveYearOptions = computed(function () {
					var ys = archive.years.slice();
					var cur = parseYmd(today).getFullYear();
					if (ys.indexOf(cur) === -1) ys.unshift(cur);
					if (ys.indexOf(archive.year) === -1) ys.push(archive.year);
					return ys.sort(function (a, b) { return b - a; });
				});
				function shiftArchiveMonth(delta) {
					var m = archive.calMonth;
					archive.calMonth = new Date(m.getFullYear(), m.getMonth() + delta, 1);
					if (archive.calMonth.getFullYear() !== archive.year) loadArchive(archive.calMonth.getFullYear());
				}
				function toggleGroup(k) { archive.open[k] = !archive.open[k]; }
				function changeArchiveYear(y) {
					archive.open = {};
					archive.calMonth = new Date(+y, y == parseYmd(today).getFullYear() ? parseYmd(today).getMonth() : 11, 1);
					loadArchive(+y);
				}

				/* ---------------- Ustawienia ---------------- */
				var form = reactive(Object.assign({}, settings));
				var savingSettings = ref(false);
				var settingsError = ref('');
				var purging = ref(false);
				var namePreview = computed(function () { return buildFilename(form.file_pattern, today); });
				var patternInput = ref(null);

				function resetForm() { Object.assign(form, settings); settingsError.value = ''; }
				function insertToken(tok) {
					var el = patternInput.value;
					var v = form.file_pattern || '';
					if (el && typeof el.selectionStart === 'number') {
						var s = el.selectionStart, e = el.selectionEnd;
						form.file_pattern = v.slice(0, s) + tok + v.slice(e);
						nextTick(function () { el.focus(); el.setSelectionRange(s + tok.length, s + tok.length); });
					} else {
						form.file_pattern = v + tok;
					}
				}
				function saveSettings() {
					savingSettings.value = true;
					settingsError.value = '';
					var wasArchive = settings.archive_enabled;
					return api('/settings', { method: 'POST', body: {
						file_pattern: form.file_pattern, archive_enabled: !!form.archive_enabled,
						max_width: +form.max_width, quality: +form.quality, margin_mm: +form.margin_mm,
						auto_orientation: !!form.auto_orientation, page_footer: !!form.page_footer
					} }).then(function (res) {
						Object.assign(settings, res.settings);
						Object.assign(form, res.settings);
						toast('Ustawienia zapisane.');
						if (wasArchive !== settings.archive_enabled) {
							archive.loaded = false;
							loadMonth();
						}
					}).catch(function (e) {
						settingsError.value = e.message;
					}).then(function () { savingSettings.value = false; });
				}
				function purgeArchive() {
					var typed = window.prompt('To usunie z serwera WSZYSTKIE zdjęcia i pliki PDF z archiwum. Informacja o wygenerowanych dniach w kalendarzu zostanie.\n\nWpisz USUN, aby potwierdzić.');
					if (typed === null) return;
					if (String(typed).trim().toUpperCase() !== 'USUN') { toast('Nie usunięto — niepoprawne potwierdzenie.', 'error'); return; }
					purging.value = true;
					api('/archive/purge', { method: 'POST', body: { confirm: 'USUN' } }).then(function () {
						toast('Archiwum zostało wyczyszczone.');
						archive.loaded = false;
						archive.days = [];
						loadMonth();
					}).catch(function (e) { toast(e.message, 'error'); }).then(function () { purging.value = false; });
				}

				/* ---------------- Zakładki ---------------- */
				function setTab(t) {
					tab.value = t;
					if (t === 'archive' && settings.archive_enabled && !archive.loaded) loadArchive(archive.year);
					if (t === 'settings') resetForm();
				}
				function editFromArchive(date) { openDay(date); }

				/* ---------------- Cykl życia ---------------- */
				function beforeUnload(e) {
					if (hasLocalWork.value || busy.active) { e.preventDefault(); e.returnValue = ''; }
				}
				onMounted(function () {
					loadMonth();
					window.addEventListener('keydown', onKey);
					window.addEventListener('beforeunload', beforeUnload);
				});
				onBeforeUnmount(function () {
					window.removeEventListener('keydown', onKey);
					window.removeEventListener('beforeunload', beforeUnload);
					clearItems();
				});

				var qualityPct = computed(function () { return Math.round((+form.quality || 0) * 100); });
				var totalBytes = computed(function () { return day.items.reduce(function (a, i) { return a + (i.bytes || 0); }, 0); });

				return {
					today: today, canManage: canManage, maxImages: maxImages, settings: settings, tab: tab, view: view, toasts: toasts,
					canvasEl: canvasEl, thumbCanvasEl: thumbCanvasEl, fileInput: fileInput, cameraInput: cameraInput, gridEl: gridEl,
					month: month, monthDays: monthDays, monthLoading: monthLoading, monthError: monthError, monthSummary: monthSummary,
					loadMonth: loadMonth, shiftMonth: shiftMonth,
					day: day, busy: busy, busyPct: busyPct, openDay: openDay, backToCalendar: backToCalendar,
					pickFiles: pickFiles, pickCamera: pickCamera, onFileInput: onFileInput,
					dropActive: dropActive, onDragOver: onDragOver, onDragLeave: onDragLeave, onDrop: onDrop,
					removeItem: removeItem, moveItem: moveItem, rotateItem: rotateItem,
					preview: preview, previewIndex: previewIndex, openPreview: openPreview, closePreview: closePreview, previewStep: previewStep,
					drag: drag, ghostStyle: ghostStyle, onThumbPointerDown: onThumbPointerDown, onThumbClick: onThumbClick,
					fileName: fileName, generate: generate, saveToArchive: saveToArchive, redownload: redownload, onPdfLink: onPdfLink, share: share, deleteDay: deleteDay,
					archive: archive, archiveGroups: archiveGroups, archiveDayMap: archiveDayMap, archiveYearOptions: archiveYearOptions,
					loadArchive: loadArchive, shiftArchiveMonth: shiftArchiveMonth, toggleGroup: toggleGroup, changeArchiveYear: changeArchiveYear,
					editFromArchive: editFromArchive,
					form: form, savingSettings: savingSettings, settingsError: settingsError, purging: purging, namePreview: namePreview,
					patternInput: patternInput, insertToken: insertToken, saveSettings: saveSettings, resetForm: resetForm, purgeArchive: purgeArchive,
					setTab: setTab, qualityPct: qualityPct, totalBytes: totalBytes,
					dayLabel: dayLabel, shortDate: shortDate, fmtBytes: fmtBytes, fmtDateTime: fmtDateTime, pagesLabel: pagesLabel, photosLabel: photosLabel, reportsLabel: reportsLabel
				};
			},
			template: '\
<div class="gd-app">\
	<canvas ref="canvasEl" class="gd-hidden-canvas" aria-hidden="true"></canvas>\
	<canvas ref="thumbCanvasEl" class="gd-hidden-canvas" aria-hidden="true"></canvas>\
	<input ref="fileInput" type="file" multiple accept="image/*,.heic,.heif" class="gd-hidden-input" @change="onFileInput">\
	<input ref="cameraInput" type="file" accept="image/*" capture="environment" class="gd-hidden-input" @change="onFileInput">\
\
	<nav class="gd-tabs" role="tablist">\
		<button type="button" role="tab" class="gd-tab" :class="{ \'gd-active\': tab === \'reports\' }" :aria-selected="tab === \'reports\'" @click="setTab(\'reports\')"><i class="fa-solid fa-calendar-days"></i> Raporty</button>\
		<button type="button" role="tab" class="gd-tab" :class="{ \'gd-active\': tab === \'archive\' }" :aria-selected="tab === \'archive\'" @click="setTab(\'archive\')"><i class="fa-solid fa-box-archive"></i> Archiwum</button>\
		<button v-if="canManage" type="button" role="tab" class="gd-tab" :class="{ \'gd-active\': tab === \'settings\' }" :aria-selected="tab === \'settings\'" @click="setTab(\'settings\')"><i class="fa-solid fa-gear"></i> Ustawienia</button>\
	</nav>\
\
	<div class="gd-body">\
\
	<!-- ===================== RAPORTY: KALENDARZ ===================== -->\
	<section v-if="tab === \'reports\' && view === \'calendar\'" class="gd-section">\
		<div class="gd-intro">\
			<h2 class="gd-h2">Raport dobowy z utargu</h2>\
			<p class="gd-muted">Wybierz dzień, dodaj zdjęcia raportów z kasy fiskalnej i terminala, a złożymy je w jeden plik PDF.</p>\
		</div>\
		<div v-if="monthError" class="gd-alert gd-alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>{{ monthError }}</span><button type="button" class="gd-link" @click="loadMonth">Spróbuj ponownie</button></div>\
		<calendar-grid :month="month" :days="monthDays" :today="today" :archive-on="settings.archive_enabled" :loading="monthLoading" mode="reports"\
			@pick="openDay" @prev="shiftMonth(-1)" @next="shiftMonth(1)"></calendar-grid>\
		<div class="gd-legend">\
			<span><i class="gd-dot gd-dot-generated"></i> Raport wygenerowany</span>\
			<span v-if="settings.archive_enabled"><i class="gd-dot gd-dot-archived"></i> Zapisany w archiwum</span>\
			<span><i class="gd-dot gd-dot-today"></i> Dziś</span>\
			<span class="gd-legend-sum">W {{ monthSummary.label }}: {{ reportsLabel(monthSummary.done) }}</span>\
		</div>\
		<button type="button" class="gd-btn gd-btn-primary gd-btn-block gd-today-btn" @click="openDay(today)"><i class="fa-solid fa-plus"></i> Raport za dziś ({{ shortDate(today) }})</button>\
	</section>\
\
	<!-- ===================== RAPORTY: EDYTOR DNIA ===================== -->\
	<section v-if="tab === \'reports\' && view === \'day\'" class="gd-section">\
		<div class="gd-dayhead">\
			<button type="button" class="gd-round" @click="backToCalendar" aria-label="Wróć do kalendarza"><i class="fa-solid fa-arrow-left"></i></button>\
			<div class="gd-dayhead-txt">\
				<h2 class="gd-h2">{{ dayLabel(day.date) }}</h2>\
				<p class="gd-muted gd-small" v-if="settings.archive_enabled">Archiwum włączone — zdjęcia i PDF zostaną zapisane na serwerze.</p>\
				<p class="gd-muted gd-small" v-else>Archiwum wyłączone — nic nie trafia na serwer, zdjęcia są usuwane zaraz po wygenerowaniu pliku.</p>\
			</div>\
		</div>\
\
		<div v-if="day.report && (day.report.hasPdf || day.report.generatedAt)" class="gd-saved">\
			<i class="fa-solid" :class="day.report.hasPdf ? \'fa-file-pdf\' : \'fa-circle-check\'"></i>\
			<div class="gd-saved-txt">\
				<strong>{{ day.report.hasPdf ? \'Raport zapisany w archiwum\' : \'Raport za ten dzień był już generowany\' }}</strong>\
				<span>{{ pagesLabel(day.report.pages) }}<template v-if="day.report.generatedAt">, {{ fmtDateTime(day.report.generatedAt) }}</template><template v-if="day.report.generatedBy">, {{ day.report.generatedBy }}</template></span>\
			</div>\
			<a v-if="day.report.hasPdf" class="gd-btn gd-btn-secondary gd-btn-sm" :href="day.report.pdfDownload" @click="onPdfLink($event, day.report.pdfDownload, day.report.pdfName, day.report.date)"><i class="fa-solid fa-download"></i> Pobierz</a>\
		</div>\
\
		<div class="gd-card gd-editor" :class="{ \'gd-drop-active\': dropActive }" @dragover="onDragOver" @dragleave="onDragLeave" @drop="onDrop">\
			<div v-if="day.loading" class="gd-loading-row"><span class="gd-spinner"></span> Wczytywanie dnia…</div>\
\
			<template v-else>\
				<div v-if="!day.items.length" class="gd-empty">\
					<div class="gd-empty-ico"><i class="fa-solid fa-receipt"></i></div>\
					<h3>Dodaj zdjęcia raportów</h3>\
					<p class="gd-muted">Raport fiskalny dobowy, zestawienie z terminala, inne wydruki z dnia. Każde zdjęcie będzie osobną stroną A4.</p>\
					<div class="gd-empty-actions">\
						<button type="button" class="gd-btn gd-btn-primary" @click="pickFiles"><i class="fa-solid fa-images"></i> Wybierz zdjęcia</button>\
						<button type="button" class="gd-btn gd-btn-secondary" @click="pickCamera"><i class="fa-solid fa-camera"></i> Zrób zdjęcie</button>\
					</div>\
					<p class="gd-hint gd-desktop-only">Możesz też przeciągnąć pliki z komputera w to miejsce.</p>\
				</div>\
\
				<template v-else>\
					<div class="gd-editor-bar">\
						<div>\
							<strong>{{ pagesLabel(day.items.length) }}</strong>\
							<span class="gd-muted gd-small"> · {{ fmtBytes(totalBytes) }}</span>\
						</div>\
						<p class="gd-muted gd-small gd-hint-sort"><i class="fa-solid fa-grip-vertical"></i> Przeciągnij za numer, aby zmienić kolejność stron</p>\
					</div>\
\
					<div ref="gridEl" class="gd-grid">\
						<div v-for="(it, i) in day.items" :key="it.key" class="gd-thumb" :data-gd-index="i"\
							:class="{ \'gd-thumb-dragging\': drag.active && drag.key === it.key, \'gd-thumb-local\': it.source === \'local\' }"\
							tabindex="0" role="button" :aria-label="\'Podgląd strony \' + (i + 1)"\
							@pointerdown="onThumbPointerDown($event, i, false)" @click="onThumbClick(i)" @keydown.enter.prevent="openPreview(i)">\
							<img :src="it.thumbUrl" alt="" draggable="false" loading="lazy">\
							<button type="button" class="gd-handle" :aria-label="\'Strona \' + (i + 1) + \' — przeciągnij, aby zmienić kolejność\'"\
								@pointerdown.stop="onThumbPointerDown($event, i, true)" @click.stop>\
								<i class="fa-solid fa-grip-vertical"></i><span>{{ i + 1 }}</span>\
							</button>\
							<button type="button" class="gd-thumb-btn gd-thumb-del" aria-label="Usuń zdjęcie" @pointerdown.stop @click.stop="removeItem(i)"><i class="fa-solid fa-xmark"></i></button>\
							<button type="button" class="gd-thumb-btn gd-thumb-rot" aria-label="Obróć o 90°" @pointerdown.stop @click.stop="rotateItem(i)"><i class="fa-solid fa-rotate-right"></i></button>\
						</div>\
						<button v-if="day.items.length < maxImages" type="button" class="gd-thumb gd-thumb-add" @click="pickFiles">\
							<i class="fa-solid fa-plus"></i><span>Dodaj</span>\
						</button>\
					</div>\
\
					<div class="gd-gen">\
						<div class="gd-gen-name"><i class="fa-regular fa-file-pdf"></i><span>{{ fileName }}</span></div>\
						<div class="gd-gen-actions">\
							<button type="button" class="gd-btn gd-btn-secondary gd-mobile-only" @click="pickCamera"><i class="fa-solid fa-camera"></i><span class="gd-sr">Zrób zdjęcie</span></button>\
							<button type="button" class="gd-btn gd-btn-primary gd-btn-big" :disabled="busy.active || !day.items.length" @click="generate">\
								<i class="fa-solid fa-file-arrow-down"></i> Generuj raport\
							</button>\
						</div>\
					</div>\
				</template>\
			</template>\
\
			<div v-if="busy.active" class="gd-busy" role="status" aria-live="polite">\
				<span class="gd-spinner gd-spinner-lg"></span>\
				<strong>{{ busy.text }}</strong>\
				<span v-if="busy.total > 1" class="gd-muted gd-small">{{ busy.done }} / {{ busy.total }}</span>\
				<div v-if="busy.total > 1" class="gd-progress"><div :style="{ width: busyPct + \'%\' }"></div></div>\
			</div>\
		</div>\
\
		<div v-if="day.error" class="gd-alert gd-alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>{{ day.error }}</span><button type="button" class="gd-x" @click="day.error = \'\'" aria-label="Zamknij"><i class="fa-solid fa-xmark"></i></button></div>\
		<div v-if="day.warnings.length" class="gd-alert gd-alert-warn">\
			<i class="fa-solid fa-triangle-exclamation"></i>\
			<div><strong>Pominięte pliki ({{ day.warnings.length }}):</strong><ul><li v-for="w in day.warnings" :key="w">{{ w }}</li></ul></div>\
			<button type="button" class="gd-x" @click="day.warnings = []" aria-label="Zamknij"><i class="fa-solid fa-xmark"></i></button>\
		</div>\
\
		<div v-if="day.result" class="gd-card gd-result">\
			<div class="gd-result-ico"><i class="fa-solid fa-check"></i></div>\
			<div class="gd-result-txt">\
				<h3>Raport gotowy</h3>\
				<p><strong>{{ day.result.name }}</strong> — {{ pagesLabel(day.result.pages) }}, {{ fmtBytes(day.result.size) }}. Pobieranie powinno rozpocząć się automatycznie.</p>\
				<p v-if="!settings.archive_enabled" class="gd-muted gd-small">Zdjęcia zostały usunięte — nic nie zostało zapisane na serwerze.</p>\
				<p v-if="day.result.archived" class="gd-ok gd-small"><i class="fa-solid fa-box-archive"></i> Zapisano w archiwum. Dzień możesz edytować w dowolnym momencie.</p>\
				<p v-if="day.result.metaWarning" class="gd-muted gd-small">{{ day.result.metaWarning }}</p>\
				<div v-if="day.result.archiveError" class="gd-alert gd-alert-error gd-alert-inline"><i class="fa-solid fa-circle-exclamation"></i><span>{{ day.result.archiveError }}</span></div>\
				<div class="gd-result-actions">\
					<button type="button" class="gd-btn gd-btn-secondary" @click="redownload"><i class="fa-solid fa-download"></i> Pobierz ponownie</button>\
					<button v-if="day.result.canShare" type="button" class="gd-btn gd-btn-secondary" @click="share"><i class="fa-solid fa-share-nodes"></i> Udostępnij</button>\
					<button v-if="day.result.archiveError" type="button" class="gd-btn gd-btn-primary" :disabled="busy.active" @click="saveToArchive"><i class="fa-solid fa-rotate"></i> Ponów zapis</button>\
					<button type="button" class="gd-btn gd-btn-ghost" @click="backToCalendar"><i class="fa-solid fa-calendar-days"></i> Kalendarz</button>\
				</div>\
			</div>\
		</div>\
\
		<div v-if="canManage && day.report && (day.report.hasPdf || day.report.generatedAt || day.report.imageCount)" class="gd-danger-row">\
			<button type="button" class="gd-link gd-link-danger" @click="deleteDay(day.date)"><i class="fa-regular fa-trash-can"></i> Usuń zapisane dane tego dnia</button>\
		</div>\
	</section>\
\
	<!-- ===================== ARCHIWUM ===================== -->\
	<section v-if="tab === \'archive\'" class="gd-section">\
		<div v-if="!settings.archive_enabled" class="gd-card gd-archive-off">\
			<div class="gd-empty-ico gd-ico-muted"><i class="fa-solid fa-box-archive"></i></div>\
			<h3>Archiwum jest wyłączone</h3>\
			<p class="gd-muted">Zdjęcia i pliki PDF nie są zapisywane na serwerze — są usuwane od razu po wygenerowaniu raportu. Pobrany plik zostaje tylko na Twoim urządzeniu.</p>\
			<button v-if="canManage" type="button" class="gd-btn gd-btn-secondary" @click="setTab(\'settings\')"><i class="fa-solid fa-gear"></i> Włącz w ustawieniach</button>\
			<p v-else class="gd-muted gd-small">Archiwum może włączyć kierownik lub administrator.</p>\
		</div>\
\
		<template v-else>\
			<div class="gd-archive-bar">\
				<select class="gd-select gd-select-sm" :value="archive.year" @change="changeArchiveYear($event.target.value)" aria-label="Rok">\
					<option v-for="y in archiveYearOptions" :key="y" :value="y">{{ y }}</option>\
				</select>\
				<div class="gd-seg" role="group" aria-label="Widok">\
					<button type="button" :class="{ \'gd-active\': archive.mode === \'list\' }" @click="archive.mode = \'list\'"><i class="fa-solid fa-list"></i> Lista</button>\
					<button type="button" :class="{ \'gd-active\': archive.mode === \'calendar\' }" @click="archive.mode = \'calendar\'"><i class="fa-regular fa-calendar"></i> Kalendarz</button>\
				</div>\
			</div>\
\
			<div v-if="archive.error" class="gd-alert gd-alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>{{ archive.error }}</span><button type="button" class="gd-link" @click="loadArchive(archive.year)">Spróbuj ponownie</button></div>\
			<div v-if="archive.loading && !archive.days.length" class="gd-loading-row"><span class="gd-spinner"></span> Wczytywanie archiwum…</div>\
\
			<template v-else-if="archive.mode === \'list\'">\
				<div v-if="!archive.days.length && !archive.loading" class="gd-card gd-empty">\
					<div class="gd-empty-ico gd-ico-muted"><i class="fa-regular fa-folder-open"></i></div>\
					<h3>Brak raportów w {{ archive.year }}</h3>\
					<p class="gd-muted">Wygenerowane raporty pojawią się tutaj automatycznie.</p>\
					<button type="button" class="gd-btn gd-btn-primary" @click="openDay(today)"><i class="fa-solid fa-plus"></i> Raport za dziś</button>\
				</div>\
				<div v-for="g in archiveGroups" :key="g.key" class="gd-month-group">\
					<button type="button" class="gd-month-head" :aria-expanded="!!archive.open[g.key]" @click="toggleGroup(g.key)">\
						<span class="gd-month-title">{{ g.label }}</span>\
						<span class="gd-muted gd-small">{{ g.days.length }} {{ g.days.length === 1 ? \'dzień\' : \'dni\' }} · {{ fmtBytes(g.size) }}</span>\
						<i class="fa-solid fa-chevron-down gd-chev" :class="{ \'gd-chev-open\': archive.open[g.key] }"></i>\
					</button>\
					<div v-show="archive.open[g.key]" class="gd-month-list">\
						<div v-for="d in g.days" :key="d.date" class="gd-arch-row">\
							<button type="button" class="gd-arch-date" @click="editFromArchive(d.date)">\
								<span class="gd-arch-daynum">{{ +d.date.slice(8, 10) }}</span>\
								<span class="gd-arch-dow">{{ dayLabel(d.date).split(\',\')[0] }}</span>\
							</button>\
							<div class="gd-arch-main" @click="editFromArchive(d.date)">\
								<div class="gd-arch-thumbs">\
									<img v-for="(t, ti) in d.thumbs" :key="ti" :src="t" alt="" loading="lazy">\
									<span v-if="d.imageCount > d.thumbs.length" class="gd-arch-more">+{{ d.imageCount - d.thumbs.length }}</span>\
								</div>\
								<div class="gd-arch-meta gd-small gd-muted">{{ pagesLabel(d.pages || d.imageCount) }}<template v-if="d.pdfSize"> · {{ fmtBytes(d.pdfSize) }}</template><template v-if="d.generatedBy"> · {{ d.generatedBy }}</template></div>\
							</div>\
							<div class="gd-arch-actions">\
								<a v-if="d.hasPdf" class="gd-icon-btn" :href="d.pdfDownload" @click="onPdfLink($event, d.pdfDownload, d.pdfName, d.date)" aria-label="Pobierz PDF" title="Pobierz PDF"><i class="fa-solid fa-download"></i></a>\
								<a v-if="d.hasPdf" class="gd-icon-btn gd-desktop-only" :href="d.pdfUrl" target="_blank" rel="noopener" aria-label="Otwórz PDF" title="Otwórz PDF"><i class="fa-regular fa-eye"></i></a>\
								<button type="button" class="gd-icon-btn" @click="editFromArchive(d.date)" aria-label="Edytuj dzień" title="Edytuj dzień"><i class="fa-solid fa-pen"></i></button>\
								<button v-if="canManage" type="button" class="gd-icon-btn gd-icon-danger" @click="deleteDay(d.date)" aria-label="Usuń dzień" title="Usuń dzień"><i class="fa-regular fa-trash-can"></i></button>\
							</div>\
						</div>\
					</div>\
				</div>\
			</template>\
\
			<template v-else>\
				<calendar-grid :month="archive.calMonth" :days="archiveDayMap" :today="today" :archive-on="true" :loading="archive.loading" mode="archive"\
					@pick="editFromArchive" @prev="shiftArchiveMonth(-1)" @next="shiftArchiveMonth(1)"></calendar-grid>\
				<p class="gd-muted gd-small gd-center">Kliknij oznaczony dzień, aby zobaczyć zdjęcia, pobrać PDF lub edytować raport.</p>\
			</template>\
		</template>\
	</section>\
\
	<!-- ===================== USTAWIENIA ===================== -->\
	<section v-if="tab === \'settings\' && canManage" class="gd-section">\
		<form class="gd-settings" @submit.prevent="saveSettings">\
			<div class="gd-card">\
				<h3 class="gd-h3">Nazwa pliku</h3>\
				<label class="gd-label" for="gd-pattern">Wzorzec nazwy</label>\
				<input id="gd-pattern" ref="patternInput" class="gd-input" type="text" v-model="form.file_pattern" maxlength="120" autocomplete="off" spellcheck="false">\
				<div class="gd-tokens">\
					<button type="button" class="gd-token" @click="insertToken(\'{data}\')">{data}</button>\
					<button type="button" class="gd-token" @click="insertToken(\'{rok}\')">{rok}</button>\
					<button type="button" class="gd-token" @click="insertToken(\'{miesiac}\')">{miesiac}</button>\
					<button type="button" class="gd-token" @click="insertToken(\'{dzien}\')">{dzien}</button>\
				</div>\
				<p class="gd-preview"><i class="fa-regular fa-file-pdf"></i> {{ namePreview }}</p>\
			</div>\
\
			<div class="gd-card">\
				<h3 class="gd-h3">Archiwum</h3>\
				<label class="gd-switch-row">\
					<span class="gd-switch-txt"><strong>Zapisuj raporty na serwerze</strong><span class="gd-muted gd-small">Zdjęcia i PDF każdego dnia trafiają do zakładki Archiwum — można je przeglądać, pobierać i edytować.</span></span>\
					<input type="checkbox" class="gd-switch" v-model="form.archive_enabled">\
				</label>\
				<p v-if="settings.archive_enabled && !form.archive_enabled" class="gd-alert gd-alert-warn gd-alert-inline"><i class="fa-solid fa-triangle-exclamation"></i><span>Po wyłączeniu nowe raporty nie będą zapisywane. Już zapisane pliki zostaną na serwerze (ukryte) — możesz je usunąć przyciskiem poniżej.</span></p>\
			</div>\
\
			<div class="gd-card">\
				<h3 class="gd-h3">Kompresja zdjęć</h3>\
				<div class="gd-field-row">\
					<div class="gd-field">\
						<label class="gd-label" for="gd-maxw">Maksymalna szerokość</label>\
						<select id="gd-maxw" class="gd-select" v-model.number="form.max_width">\
							<option :value="1000">1000 px — najmniejszy plik</option>\
							<option :value="1200">1200 px — zalecane</option>\
							<option :value="1600">1600 px — lepsza czytelność</option>\
							<option :value="2000">2000 px — najwyższa jakość</option>\
						</select>\
					</div>\
					<div class="gd-field">\
						<label class="gd-label" for="gd-q">Jakość JPEG: {{ qualityPct }}%</label>\
						<input id="gd-q" class="gd-range" type="range" min="0.4" max="0.95" step="0.05" v-model.number="form.quality">\
					</div>\
				</div>\
			</div>\
\
			<div class="gd-card">\
				<h3 class="gd-h3">Strona PDF</h3>\
				<div class="gd-field gd-field-narrow">\
					<label class="gd-label" for="gd-margin">Margines (mm)</label>\
					<input id="gd-margin" class="gd-input" type="number" min="0" max="30" step="1" v-model.number="form.margin_mm">\
				</div>\
				<label class="gd-switch-row">\
					<span class="gd-switch-txt"><strong>Stopka z datą i numerem strony</strong><span class="gd-muted gd-small">Np. „Raport dobowy 22.09.2026 - strona 1 z 4”.</span></span>\
					<input type="checkbox" class="gd-switch" v-model="form.page_footer">\
				</label>\
				<label class="gd-switch-row">\
					<span class="gd-switch-txt"><strong>Poziome zdjęcia na poziomej stronie</strong><span class="gd-muted gd-small">Szerokie zdjęcie trafi na stronę A4 w orientacji poziomej zamiast pionowej.</span></span>\
					<input type="checkbox" class="gd-switch" v-model="form.auto_orientation">\
				</label>\
			</div>\
\
			<div v-if="settingsError" class="gd-alert gd-alert-error"><i class="fa-solid fa-circle-exclamation"></i><span>{{ settingsError }}</span></div>\
			<button type="submit" class="gd-btn gd-btn-primary gd-btn-block gd-btn-big" :disabled="savingSettings">\
				<span v-if="savingSettings" class="gd-spinner gd-spinner-light"></span><i v-else class="fa-solid fa-floppy-disk"></i> Zapisz ustawienia\
			</button>\
\
			<div class="gd-card gd-danger-card">\
				<h3 class="gd-h3">Usuwanie archiwum</h3>\
				<p class="gd-muted gd-small">Kasuje z serwera wszystkie zdjęcia i pliki PDF. Oznaczenia wygenerowanych dni w kalendarzu zostają.</p>\
				<button type="button" class="gd-btn gd-btn-danger" :disabled="purging" @click="purgeArchive"><span v-if="purging" class="gd-spinner"></span><i v-else class="fa-regular fa-trash-can"></i> Usuń całe archiwum</button>\
			</div>\
		</form>\
	</section>\
\
	</div>\
\
	<teleport to="body"><div class="gd-portal">\
	<!-- Duch przeciąganej miniatury -->\
	<div v-if="drag.active" class="gd-ghost" :style="ghostStyle"><img :src="drag.thumb" alt=""></div>\
\
	<!-- Podgląd zdjęcia -->\
	<div v-if="preview.open" class="gd-lightbox" role="dialog" aria-modal="true" aria-label="Podgląd zdjęcia" @click.self="closePreview">\
		<div class="gd-lb-top">\
			<span class="gd-lb-count">Strona {{ previewIndex + 1 }} z {{ day.items.length }}</span>\
			<button type="button" class="gd-lb-btn" @click="closePreview" aria-label="Zamknij"><i class="fa-solid fa-xmark"></i></button>\
		</div>\
		<div class="gd-lb-stage" @click.self="closePreview">\
			<button type="button" class="gd-lb-nav gd-lb-prev" :disabled="previewIndex <= 0" @click="previewStep(-1)" aria-label="Poprzednie"><i class="fa-solid fa-chevron-left"></i></button>\
			<img :src="preview.url" alt="Podgląd strony raportu">\
			<button type="button" class="gd-lb-nav gd-lb-next" :disabled="previewIndex >= day.items.length - 1" @click="previewStep(1)" aria-label="Następne"><i class="fa-solid fa-chevron-right"></i></button>\
		</div>\
		<div class="gd-lb-bar">\
			<button type="button" class="gd-lb-action" :disabled="previewIndex <= 0" @click="moveItem(previewIndex, -1)"><i class="fa-solid fa-arrow-left"></i><span>Wcześniej</span></button>\
			<button type="button" class="gd-lb-action" :disabled="busy.active" @click="rotateItem(previewIndex)"><i class="fa-solid fa-rotate-right"></i><span>Obróć</span></button>\
			<button type="button" class="gd-lb-action" :disabled="previewIndex >= day.items.length - 1" @click="moveItem(previewIndex, 1)"><i class="fa-solid fa-arrow-right"></i><span>Później</span></button>\
			<button type="button" class="gd-lb-action gd-lb-danger" @click="removeItem(previewIndex)"><i class="fa-regular fa-trash-can"></i><span>Usuń</span></button>\
		</div>\
	</div>\
\
	<div class="gd-toasts" aria-live="polite">\
		<div v-for="t in toasts" :key="t.id" class="gd-toast" :class="\'gd-toast-\' + t.type"><i class="fa-solid" :class="t.type === \'error\' ? \'fa-circle-exclamation\' : \'fa-circle-check\'"></i> {{ t.msg }}</div>\
	</div>\
	</div></teleport>\
</div>'
		};

		try {
			createApp(App).mount(mountEl);
		} catch (err) {
			mountEl.__gfxdokMounted = false;
			bootError(String(err && err.message ? err.message : err));
			if (window.console) console.error('[Dokumenty GastroFlowX]', err);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
