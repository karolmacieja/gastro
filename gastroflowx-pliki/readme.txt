=== GastroFlowX Pliki ===
Contributors: gastroflowx
Tags: gastronomia, dokumenty, druk, restauracja, gastroflowx
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Moduł ekosystemu GastroFlowX: biblioteka dokumentów do druku dla zespołu restauracji — wzory raportów utargu, listy obowiązków, checklisty HACCP.

== Description ==

GastroFlowX Pliki to jedno miejsce na wszystkie druki, których zespół potrzebuje na zmianie. Cała obsługa odbywa się na froncie, w panelu GastroFlowX — bez wchodzenia do wp-admin.

Funkcje:

* Dokumenty tworzone w edytorze na froncie: nagłówki, pogrubienie, listy, lista zadań z polami do odhaczenia, tabele (dodawanie/usuwanie wierszy i kolumn, Tab — następna komórka), pola do wpisania, pełne linie, miejsce na podpisy, wyrównanie.
* Wgrywanie plików PDF, JPG, PNG i WEBP (wiele naraz). Typ pliku jest sprawdzany po zawartości, nie tylko po rozszerzeniu.
* Kategorie (np. Raporty, Listy obowiązków, Checklisty / HACCP), wyszukiwarka i sortowanie.
* Podgląd każdego dokumentu przed drukiem (PDF renderowany w przeglądarce).
* Druk grupowy: zaznacz kilka dokumentów, ustaw liczbę kopii — wszystko drukuje się jednym zadaniem, z zachowaniem orientacji pionowej/poziomej każdej strony.
* Tryb druku przystosowany do telefonów i tabletów.
* Ograniczenie widoczności pojedynczego dokumentu do wybranych ról (np. tylko kuchnia).
* Cztery gotowe wzory po aktywacji: Raport utargu dziennego, Lista obowiązków — otwarcie, Lista obowiązków — zamknięcie zmiany, Rejestr temperatur urządzeń chłodniczych.

== Installation ==

1. Wgraj katalog `gastroflowx-pliki` do `/wp-content/plugins/` albo zainstaluj plik ZIP w Wtyczki → Dodaj nową.
2. Włącz wtyczkę.
3. W GastroFlowX → Moduły dodaj moduł:
   * Shortcode: `[gastroflowx_pliki_app]`
   * Sugerowana kategoria: Dokumenty (lub Narzędzia)
   * Sugerowana ikona: `fa-folder-open`
4. Opcjonalnie: GastroFlowX → Pliki (dokumenty) (lub Ustawienia → Pliki (dokumenty), jeśli Hub nie ma własnego menu) — wybierz role, które widzą moduł i które mogą zarządzać dokumentami. Te same ustawienia administrator ma na froncie (ikona koła zębatego).

Shortcode działa samodzielnie na dowolnej stronie oraz osadzony przez `do_shortcode()` wewnątrz `[gastroflowx_app]` — skrypty i style są ładowane w momencie renderowania shortcode'u, więc nie trzeba nic zmieniać w Hubie.

== Uprawnienia ==

Administrator ma zawsze wszystkie uprawnienia i jako jedyny zmienia ustawienia. Dla pozostałych ról każde uprawnienie ustawia się osobno (wp-admin → Pliki (dokumenty) albo ikona koła zębatego na froncie):

* Widzi moduł i drukuje (domyślnie: wszystkie role GastroFlowX)
* Widzi dokumenty innych ról — także te ograniczone np. do kuchni (domyślnie: rs_restaurant_manager)
* Pobiera oryginalne pliki (domyślnie: wszystkie role GastroFlowX)
* Tworzy dokumenty w edytorze (domyślnie: rs_restaurant_manager)
* Wgrywa pliki PDF i zdjęcia (domyślnie: rs_restaurant_manager)
* Edytuje własne / wszystkie dokumenty (domyślnie: rs_restaurant_manager)
* Usuwa własne / wszystkie dokumenty (domyślnie: rs_restaurant_manager)
* Zarządza kategoriami (domyślnie: rs_restaurant_manager)

Każde uprawnienie wymaga dostępu do modułu; „wszystkie dokumenty” obejmuje „własne”. Każdy endpoint REST (`gastroflowx-pliki/v1`) sprawdza właściwe uprawnienie, a każdy zapis wymaga nonce (X-WP-Nonce). Dokument niewidoczny dla użytkownika jest dla niego traktowany jak nieistniejący. Filtr dla programistów: `gfx_pliki_can( $ok, $action, $user )`.

Użytkownicy bez roli administratora są przekierowywani z wp-admin na stronę główną, a pasek administracyjny jest dla nich ukryty. Można to wyłączyć filtrem: `add_filter( 'gfx_pliki_block_wp_admin', '__return_false' );`

== Bezpieczeństwo plików ==

Wgrane pliki trafiają do `wp-content/uploads/gastroflowx-pliki/` pod losowymi nazwami i są udostępniane wyłącznie przez REST API po sprawdzeniu uprawnień. Na Apache bezpośredni dostęp blokuje dołączony plik `.htaccess`. Na serwerze nginx dodaj regułę:

    location ^~ /wp-content/uploads/gastroflowx-pliki/ { deny all; }

== Frequently Asked Questions ==

= Jak wgrać dokument z Worda lub Excela? =

Zapisz go jako PDF (Plik → Zapisz jako → PDF) i wgraj PDF. Zachowa dokładnie ten sam wygląd na wydruku.

= Jak zapisać dokument jako PDF? =

Kliknij Drukuj i w oknie drukowania wybierz „Zapisz jako PDF”.

= Czy usunięcie wtyczki kasuje dokumenty? =

Nie. Dezaktywacja ani usunięcie wtyczki nie kasuje dokumentów ani plików.

== Changelog ==

= 1.1.1 =
* Rola managera to teraz rs_restaurant_manager (zamiast manager). Zapisane uprawnienia i ograniczenia dokumentów są przenoszone automatycznie.

= 1.1.0 =
* Osobne uprawnienia dla każdej czynności (widok, dokumenty innych ról, pobieranie, tworzenie, wgrywanie, edycja i usuwanie własnych/wszystkich, kategorie). Ustawienia z wersji 1.0.0 są przenoszone automatycznie.

= 1.0.0 =
* Pierwsze wydanie.
