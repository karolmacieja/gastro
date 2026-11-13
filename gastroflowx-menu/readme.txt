=== Menu GastroFlowX ===
Contributors: gastroflowx
Tags: restaurant, menu, pdf, dompdf, gastroflowx
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Moduł systemu GastroFlowx: baza dań PL/EN, kreator menu, „Wersje Standardowe” i generowanie PDF (Dompdf) — w całości z frontu.

== Opis ==

Moduł pracowniczy osadzany w panelu [gastroflowx_app] (Hub). Cała praca odbywa
się na froncie, w czterech zakładkach:

* Kreator menu — wybór dań z bazy dla każdej kategorii (checkboxy + kolejność
  strzałkami), język menu PL/EN, dodatkowy tekst (nad lub pod daniami),
  podgląd arkusza A4 na żywo, pobranie PDF bez zapisywania.
* Wersje — zapisane „Wersje Standardowe”: Edytuj, Duplikuj, Pobierz PDF, Usuń.
  Zaznaczenie kilku wersji generuje JEDEN plik PDF (w kolejności zaznaczania,
  każda wersja od nowej strony).
* Baza dań — dodawanie/edycja/usuwanie dań (nazwa i opis PL + EN, kategoria),
  wyszukiwarka, zarządzanie kategoriami (PL/EN, kolejność).
* Ustawienia PDF — logo nagłówka i stopki z natywnego Media Uploadera WP,
  szerokość/wysokość (px, % lub auto), marginesy i odstępy, opcja
  „Dopasuj do jednej strony” (zmniejsza treść maks. do 70%).

Przy pierwszej aktywacji baza jest wypełniana 24 daniami z menu „kwiecień”
(3 wersje, z tłumaczeniami EN) oraz trzema gotowymi Wersjami Standardowymi.

== Instalacja ==

1. Wgraj folder gastroflowx-menu do /wp-content/plugins/ i aktywuj wtyczkę.
   Aktywacja tworzy tabele wp_gfxm_dishes i wp_gfxm_versions (dbDelta) oraz
   rolę „Menedżer menu” (menu_manager).
2. W wp-admin → GastroFlowx → Moduły dodaj moduł:
   Nazwa: Menu
   Ikona: fa-book-open
   Shortcode: gastroflowx_menu_app   (w nawiasach: [gastroflowx_menu_app])
3. W GastroFlowx → Dostęp ról zaznacz moduł dla ról, które mają go widzieć.
4. W GastroFlowx → Menu (dostęp) wybierz role, które mają dostęp do DANYCH
   modułu (REST). Domyślnie: menu_manager oraz manager (jeśli istnieje).
   Administrator ma dostęp zawsze.
5. Czcionki Poppins, Adorn Garland i Myriad Pro są dołączone
   w assets/fonts/ (szczegóły w assets/fonts/README.txt).

== Integracja z Hubem ==

* Shortcode: [gastroflowx_menu_app] — renderuje się tylko dla
  is_user_logged_in() z dostępem do modułu.
* Assety (Vue 3 z unpkg, app.js, style.css, Font Awesome 6.5.1,
  wp_enqueue_media) są kolejkowane WEWNĄTRZ callbacku shortcode'a — moduł
  działa osadzony przez do_shortcode() w [gastroflowx_app] oraz samodzielnie.
  Uchwyty 'vue3' i 'font-awesome-gfx' są wspólne z Hubem/Lunchem, więc nic
  nie ładuje się podwójnie.
* Kolor przewodni #2563EB, design system --rs-* jak w Weranda Lunch.

== REST API ==

Namespace: gastroflowx-menu/v1. Każdy endpoint wymaga zalogowania, ważnego
nonce wp_rest (nagłówek X-WP-Nonce) i dostępu do modułu (rola/uprawnienie).

* GET    /bootstrap            — kategorie, dania, wersje, ustawienia, status czcionek
* POST   /dishes               — nowe danie
* POST   /dishes/{id}          — edycja dania
* DELETE /dishes/{id}          — usunięcie (także z zapisanych wersji)
* POST   /categories           — zapis listy kategorii (kolejność)
* POST   /versions             — nowa wersja standardowa
* POST   /versions/{id}        — edycja wersji
* DELETE /versions/{id}        — usunięcie wersji
* POST   /settings             — ustawienia PDF
* POST   /pdf                  — { "ids": [1,2,3] } lub { "draft": {...} } → application/pdf

== Bezpieczeństwo ==

* wp-admin zablokowany dla wszystkich poza administratorem (robi to Hub;
  gdy Hub jest wyłączony, moduł włącza identyczną blokadę sam).
* Uprawnienie upload_files (wymagane przez Media Uploader) jest nadawane
  dynamicznie wyłącznie rolom z dostępem do modułu — bez trwałej zmiany ról.
* Wszystkie dane wejściowe sanityzowane po stronie serwera; błędy PHP
  w naszych endpointach zawsze wracają jako JSON (shutdown handler).

== PDF (Dompdf) ==

* Dompdf 3.1.4 dołączony w vendor/dompdf (bez Composera).
* Układ wg wzoru Weranda: logo nagłówka wyśrodkowane u góry, kategorie
  w dwóch kolumnach, logo stopki wyśrodkowane na dole. Każda wersja = 1 strona A4.
* Prowadnice (Ustawienia PDF → Układ strony) to te same liczby co w Canvie.
  Kategorie „do górnej prowadnicy” układają się od góry w dół, „do dolnej”
  kończą się na dolnej prowadnicy — ostatnie linie obu kolumn są równe.
  Wysokości mierzone są pierwszym przebiegiem Dompdf, więc skład jest dokładny.
* Typografia (domyślnie, zmienna w Ustawieniach PDF):
  kategoria — Poppins ExtraBold 20 pt, małe litery;
  podtytuł — Adorn Garland 14 pt, nachodzi na nagłówek, wyśrodkowany pod nim;
  nazwa dania — Fabrikat Mono Bold 9 pt, WIELKIE LITERY;
  opis — Fabrikat Mono Regular 9 pt; interlinia 1.2; światło regulowane
  osobno dla kategorii, podtytułów i dań (Ustawienia PDF → Typografia).
* „Dopasuj do strony”: gdy treść nie mieści się nad stopką, pismo i odstępy
  zmniejszają się krokami do 70%.
* Logo osadzane jako data-URI — Dompdf nie pobiera niczego z sieci.

== Changelog ==

= 1.6.1 =
* Układ 1:1 z szablonem InDesign (listopad): kolumny 47,37 / 306,04 pt (42 i 46 znaków w wierszu), dania Fabrikat Mono 10 pt z interlinią 13 pt, 2 mm między daniami, nazwa kategorii → pierwsze danie 30,9 pt (prawa kolumna 28,4 pt), linia bazowa „zupy” 210,3 pt, ostatnie linie obu kolumn na 663,9 pt. Zgodność linii bazowych z szablonem ±0,05 pt.
* Nowe logo nagłówka i stopki wycięte z szablonu (logo-*-v2.png, 1200 dpi, przezroczyste tło) — importowane do biblioteki mediów i ustawiane automatycznie.
* Nowe ustawienia: interlinia dań (Typografia), przy kategorii — przesunięcie w poziomie i własny odstęp nazwa → pierwsze danie. Prowadnice i rozmiary logo przyjmują ułamki px.
* Nazwa dania: „|” łamie wiersz (np. „Sałata Lazurowa | z gruszką i serem pleśniowym”); w opisie działa zwykły Enter.
* Migracja ustawia prowadnice, typografię, logo i układ czterech kategorii domyślnych (przystawki i słodkie — do dolnej prowadnicy); własne kategorie, nazwy i dania bez zmian. Ponowna aktywacja wtyczki nie pomija już migracji.

= 1.6.0 =
* Nowy wygląd podtytułów wg szablonu z listopada: czcionka Sweet Belly Sans (dołączona jako TTF z dorysowanymi polskimi znakami Ą Ę Ć Ł Ń Ó Ś Ź Ż), wersaliki, światło 0.14 em, 10,3 pt (DESERY 11,1 pt).
* Podtytuł stawiany względem nazwy kategorii: przesunięcie w prawo i od linii bazowej (minus = nad nazwą) — osobno dla każdej kategorii (Kategorie). „NA POCZĄTEK” nad „zupy”, „AUTORSKIE”, „ORYGINALNE”, „DESERY” pod nazwami. Podtytuł nie zmienia wysokości bloku, więc nazwy kategorii zostają na swoich liniach.
* Ustawienia PDF → Typografia: wybór czcionki podtytułów (Sweet Belly Sans / Script / Adorn Garland) i przełącznik „wersalikami”.
* Nazwy kategorii domyślnie Poppins Black 22 pt, światło 0.05 em (jak w szablonie). Podtytuł kategorii „Główne”: „autorskie”.
* Migracja: przesunięcia podtytułów i nowa typografia nagłówków/podtytułów ustawiane automatycznie; prowadnice, logo i rozmiary dań bez zmian.

= 1.5.2 =
* Domyślne „Światło — dania” = 0 (łamanie wierszy jak we wzorze). Dotyczy świeżych instalacji i przycisku „Przywróć domyślne”; zapisane ustawienia się nie zmieniają.

= 1.5.1 =
* Przyciski „Przywróć domyślne” w: Kategoriach, Logo nagłówka, Logo stopki, Prowadnicach (osobno dla każdego szablonu) i Typografii (wspólna).
* Przywracanie zmienia tylko formularz — zmiany trafiają do PDF dopiero po „Zapisz”, więc można się wycofać bez zapisywania.
* Kategorie: domyślne wracają do fabrycznych nazw, podtytułów, kolumn, wyrównania i kolejności; własne kategorie zostają na końcu listy.

= 1.5.0 =
* Drugi szablon PDF „Podzielona strona”: jedna kolumna w lewej połówce A4 i ta sama treść powielona w prawej (do przecięcia na pół), z opcjonalną linią cięcia.
* Szablon wybierany w Kreatorze osobno dla każdej wersji; w połączonym PDF można mieszać szablony.
* Ustawienia PDF: przełącznik „Edytowany szablon” — każdy szablon ma własne prowadnice i rozmiary logo; obrazy logo, czcionki, rozmiary pisma i światło są wspólne.
* Prowadnice szablonu „Podzielona strona” z Canvy: logo 35–165, połówki 38–368 / 437–767, treść od 210 (dania od 252), stopka 995–1080.
* Wspólna baza dań i kategorii dla obu szablonów.

= 1.4.1 =
* Ustawienia PDF → Typografia: regulacja światła (letter-spacing, em) osobno dla kategorii, podtytułów oraz nazw i opisów dań. Działa w PDF i w podglądzie.

= 1.4.0 =
* Dołączone czcionki Fabrikat Mono (Regular, Medium, Bold).
* Ustawienia PDF → Typografia: wybór grubości nazwy dania — Fabrikat Mono Bold lub Medium (podgląd zmienia się od razu).

= 1.3.0 =
* Układ 1:1 z prowadnic Canvy (A4 794 × 1123 px): logo 56–186, treść 300–835, kolumny 63–373 i 429–718, stopka od 934 (szer. 285).
* Kategorie „do górnej” / „do dolnej prowadnicy” — dolne kategorie kończą się dokładnie na 835 px, więc ostatnie pozycje obu kolumn są na równej linii.
* Nazwy i opisy dań: Fabrikat Mono Bold / Regular, 9 pt (plik wykrywany automatycznie po nazwie).
* Odstęp między daniami liczony tylko między pozycjami (bez „wiszącego” marginesu na końcu).

= 1.2.0 =
* Dołączone czcionki Myriad Pro (Regular, Bold) i Adorn Garland.
* Spójniki i krótkie przyimki (z, w, i, a, o, u, na, do, oraz, lub… oraz EN: a, the, and, with…) są łączone twardą spacją ze słowem następnym — nigdy nie zostają same na końcu linii (PDF i podgląd).
* Poprawna interlinia 1.2 w PDF niezależnie od metryk kroju (Dompdf mnożył line-height przez wysokość kroju — teraz jest to automatycznie kompensowane).
* Podgląd: korekta linii bazowej, tak by tekst leżał jak w PDF.
* Pola select w module bez paddingu (!important).

= 1.1.1 =
* Naprawa: błąd krytyczny witryny przy aktualizacji z 1.0.0 (import logo uruchamiany przed inicjalizacją WordPressa). Migracja działa teraz na 'init' i nie może zablokować strony.

= 1.1.0 =
* Nowy układ PDF 1:1 ze wzorem: dwie kolumny, podtytuły kategorii, stałe pozycje.
* Czcionki: Poppins / Adorn Garland / Myriad Pro (z zamiennikami OFL).
* Kategorie: podtytuł PL/EN, kolumna, pozycja od góry.
* Ustawienia: typografia (grubość Poppins, rozmiary w pt), nowe parametry układu.
* Logo Weranda (nagłówek i stopka) importowane do biblioteki mediów przy aktualizacji.


= 1.0.0 =
* Pierwsza wersja.
