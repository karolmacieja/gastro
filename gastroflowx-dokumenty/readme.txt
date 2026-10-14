=== Dokumenty GastroFlowX ===
Contributors: gastroflowx
Tags: raport dobowy, utarg, kasa fiskalna, pdf, gastroflowx, vue
Requires at least: 5.9
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Moduł systemu GastroFlowx: zbiorcze raporty dobowe z utargu (zdjęcia raportów z kasy fiskalnej i terminala) składane w jeden plik PDF — w całości w przeglądarce.

== Description ==

Pracownik wybiera dzień w kalendarzu, dodaje zdjęcia wydruków (z galerii lub aparatem), ustala kolejność stron przeciągając miniatury i klika „Generuj raport”. Plik PDF pobiera się od razu na urządzenie.

Cały proces odbywa się na froncie:

* Wybór wielu zdjęć (`<input type="file" multiple accept="image/*">`), osobny przycisk „Zrób zdjęcie” (aparat telefonu), na komputerze także przeciąganie plików.
* Kompresja przez ukryty `<canvas>`: zmniejszenie do maks. szerokości (domyślnie 1200 px, stopniowo, żeby tekst paragonu był czytelny), zapis jako JPEG (domyślnie jakość 0,7), poprawna orientacja EXIF.
* Siatka miniatur z sortowaniem „przeciągnij i upuść”: myszą za dowolne miejsce miniatury, na dotyku za uchwyt z numerem strony (reszta miniatury normalnie przewija stronę). Obracanie o 90°, usuwanie, podgląd pełnoekranowy z przesuwaniem „wcześniej/później”.
* jsPDF: każde zdjęcie na osobnej stronie A4, wyśrodkowane i przeskalowane z zachowaniem proporcji w obrębie marginesów. Opcjonalna stopka „Raport dobowy DD.MM.RRRR - strona X z Y” i poziome strony dla szerokich zdjęć.
* Pobranie pliku jako `raport_dobowy_RRRR-MM-DD.pdf` (wzorzec nazwy ustawiany na froncie), przyciski „Pobierz ponownie” i „Udostępnij” (Web Share na telefonach).
* Stany ładowania (spinner + pasek postępu przy kompresji, składaniu PDF i zapisie) i czytelne komunikaty o błędach (np. nieobsługiwany format HEIC, limit rozmiaru na serwerze, brak połączenia).

= Archiwum (opcja) =

* **Wyłączone (domyślnie):** żadne zdjęcie ani PDF nie trafia na serwer. Zdjęcia są usuwane z pamięci przeglądarki zaraz po wygenerowaniu pliku. Serwer zapisuje wyłącznie metadane („dzień X: raport wygenerowany, N stron, kto, kiedy”), żeby dzień był oznaczony w kalendarzu. Zakładka Archiwum wyświetla informację, że pliki nie są zapisywane.
* **Włączone:** po wygenerowaniu PDF skompresowane zdjęcia (+ miniatury) i sam PDF są zapisywane na serwerze. Zakładka Archiwum pokazuje raporty w formie listy z podziałem na miesiące lub kalendarza. Każdy dzień można otworzyć, pobrać PDF i edytować (dodać/usunąć/obrócić zdjęcia, zmienić kolejność) — ponowne wygenerowanie nadpisuje PDF, a usunięte zdjęcia są kasowane z serwera.

== Installation ==

1. Wgraj katalog `gastroflowx-dokumenty` do `wp-content/plugins/` i aktywuj wtyczkę.
2. wp-admin → GastroFlowx → **Dokumenty**: zaznacz role, które „Korzystają” z modułu i które nim „Zarządzają”.
3. wp-admin → GastroFlowx → **Moduły**: dodaj moduł:
   * Nazwa: `Dokumenty`
   * Shortcode: `gastroflowx_dokumenty_app`  (pełna postać: `[gastroflowx_dokumenty_app]`)
   * Ikona: `fa-file-invoice-dollar` (albo `fa-receipt`)
   * Kategoria: wg własnej struktury menu
4. wp-admin → GastroFlowx → **Dostęp ról**: zaznacz nowy moduł tym samym rolom co w kroku 2.

Moduł można też wkleić samodzielnie na dowolną stronę: `[gastroflowx_dokumenty_app]`.

= Integracja z Hubem =

Zgodnie z konwencją GastroFlowx wszystkie `wp_enqueue_script/style` są wywoływane **wewnątrz callbacku shortcode'u** (a nie na `wp_enqueue_scripts` z bramką `has_shortcode()`), więc moduł działa osadzony dynamicznie przez `do_shortcode()` wewnątrz `[gastroflowx_app]` bez żadnych zmian w kodzie Huba (`GFX_Shell::ensure_child_module_assets()` nie wymaga dopisywania).

Uchwyty zasobów: `vue3` (wspólny z innymi modułami, Vue 3.4.21), `gfxdok-jspdf` (jsPDF 2.5.2 z unpkg), `gfxdok-fontawesome` (ładowany tylko, jeśli Hub nie załadował już `font-awesome-gfx`), `gfxdok-app`, `gfxdok-style`. Jeśli używasz wtyczki łączącej/minifikującej JS, wyklucz te uchwyty.

== Uprawnienia i bezpieczeństwo ==

* Administrator ma zawsze pełny dostęp. Pozostałe role — wg ustawień w wp-admin → Dokumenty. Przy aktywacji domyślnie zaznaczane są role `manager` / `kierownik`, jeśli istnieją.
* „Korzysta”: tworzenie raportów, przeglądanie i edycja dni w Archiwum.
* „Zarządza”: dodatkowo zakładka Ustawienia na froncie (wzorzec nazwy, Archiwum, kompresja, układ strony), usuwanie dni i czyszczenie całego Archiwum.
* Każdy endpoint REST sprawdza uprawnienie modułu (nie tylko zalogowanie). Każdy zapis jawnie weryfikuje nonce `wp_rest` (nagłówek `X-WP-Nonce`).
* Pliki Archiwum leżą w `wp-content/uploads/gfx-dokumenty/RRRR/MM/DD/` pod losowymi nazwami, katalog ma `.htaccess` blokujący dostęp. Pliki są wydawane wyłącznie przez REST po sprawdzeniu uprawnień.
* Serwer akceptuje tylko JPEG (sprawdzany `getimagesize`) i PDF (sprawdzany nagłówek `%PDF-`), limit 60 zdjęć na dzień.
* Blokada wp-admin dla wszystkich poza administratorem realizuje Hub (`GFX_Access`). Gdyby Hub był nieaktywny, tę samą blokadę włącza ta wtyczka.

**Serwery nginx** ignorują `.htaccess`. Dodaj regułę:

    location ^~ /wp-content/uploads/gfx-dokumenty/ { deny all; }

== REST API ==

Namespace `gfx-dokumenty/v1`:

* `GET  /bootstrap` — ustawienia i uprawnienia bieżącego użytkownika
* `POST /settings` — zapis ustawień (zarządza)
* `GET  /month?month=RRRR-MM` — dni miesiąca do kalendarza
* `GET  /archive?year=RRRR` — lista dni w Archiwum
* `POST /archive/purge` — wyczyszczenie Archiwum, body `{"confirm":"USUN"}` (zarządza)
* `GET  /day/{data}` — dane dnia (+ zdjęcia, gdy Archiwum włączone)
* `DELETE /day/{data}` — usunięcie danych dnia (zarządza)
* `POST /day/{data}/images` — jedno skompresowane zdjęcie `file` + miniatura `thumb` (tylko Archiwum)
* `POST /day/{data}/finalize` — zamknięcie dnia: przy Archiwum `order` (JSON z ID) + `pdf`; bez Archiwum tylko `pages` i `filename`
* `GET  /day/{data}/pdf` — zapisany PDF (`?download=1` wymusza pobranie)
* `GET  /images/{id}/file?size=thumb|full` — zdjęcie z Archiwum

== Frequently Asked Questions ==

= Zdjęcia HEIC z iPhone'a =
Od 1.0.2 obsługiwane w każdej przeglądarce. Safari odczytuje HEIC samo; w Chrome, Edge i Firefox (np. zdjęcia przeciągnięte z Maca lub przesłane AirDropem) wtyczka konwertuje HEIC do JPEG w przeglądarce biblioteką heic-to (libheif, LGPL-3.0), dołączoną w assets/vendor/heic-to/ i ładowaną tylko przy pierwszym zdjęciu HEIC (ok. 3 MB, jednorazowo). Konwersja trwa ok. 1–3 s na zdjęcie. Na serwer trafia wyłącznie skompresowany JPEG.

= „Plik PDF przekracza limit przesyłania na serwerze” =
Dotyczy tylko Archiwum. Zwiększ `upload_max_filesize` i `post_max_size` w PHP albo zmniejsz szerokość/jakość zdjęć w Ustawieniach. Aktualny limit widać w wp-admin → Dokumenty. Plik i tak zostaje pobrany na urządzenie.

= Polskie znaki w stopce PDF =
Stopka używa standardowej czcionki PDF, która nie ma polskich znaków — dlatego jest celowo bez diakrytyków. Zdjęcia raportów nie są w żaden sposób zmieniane.

= Co się dzieje po odinstalowaniu? =
Domyślnie nic nie jest kasowane. Jeśli w wp-admin → Dokumenty zaznaczysz „Przy usuwaniu wtyczki skasuj…”, usunięte zostaną tabele, ustawienia i wszystkie pliki Archiwum.

== Changelog ==

= 1.0.2 =
* Zdjęcia HEIC/HEIF z iPhone'a są automatycznie konwertowane do JPEG w przeglądarkach, które nie obsługują HEIC (Chrome, Edge, Firefox). Wcześniej były pomijane z komunikatem „format HEIC nie jest obsługiwany”.
* Wybór plików akceptuje jawnie .heic/.heif.

= 1.0.0 =
* Pierwsza wersja: kalendarz, kompresja Canvas, sortowanie przeciągnij-i-upuść (mysz i dotyk), jsPDF A4, pobieranie/udostępnianie, opcjonalne Archiwum (lista miesięcy / kalendarz, edycja dnia), ustawienia na froncie, uprawnienia ról w wp-admin.
