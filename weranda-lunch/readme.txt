=== Weranda Lunch Menu Generator ===
Stable tag: 2.1.0
Requires PHP: 7.4

Generator karty lunchowej (A4, dwie identyczne połówki do przecięcia) w panelu GastroFlowx.
Shortcode: [weranda_lunch_panel] (osadzany też w [gastroflowx_app]).

== Jak działa (od 2.0.0) ==
Karta jest budowana w całości z danych — bez szablonu PDF z Canvy:
* Zakładka „Generator Menu” (rola lunch / administrator): zupa dnia, gramatura,
  opis lunchu, polecamy (z bazą produktów). Podgląd na żywo, „Wygeneruj i Zapisz
  do Listy”, archiwum z drukiem i pobieraniem — bez zmian.
* Zakładka „Ustawienia PDF” (administrator): ceny, stałe teksty (nagłówki, stopka),
  czcionka / rozmiar / interlinia / odstęp każdego elementu, logo (z biblioteki
  mediów albo dołączone logo Weranda), pozycje na stronie, linia cięcia,
  automatyczne zmniejszanie treści, gdy nie mieści się nad stopką.
  Zmiany widać od razu w podglądzie; obowiązują wszystkich po „Zapisz ustawienia”.
  „Przywróć domyślne (Canva)” wraca do układu 1:1 z dawnych szablonów.

Domyślny układ jest zmierzony z szablonów Canvy (szablon-1/2/3): pozycje linii bazowych
zgadzają się z oryginałem z dokładnością 0,01 pt. Bloki „płyną” w dół — dłuższa zupa
sama przesuwa resztę, więc jeden układ zastępuje trzy dawne szablony.

Pliki: assets/lunch-layout.js (silnik układu), assets/app.js (panel), assets/fonts/
(Poppins i Barlow Condensed, OFL), assets/img/logo-weranda.png, assets/vendor/
(pdf-lib 1.17.1, @pdf-lib/fontkit 1.1.1 — dołączone lokalnie).

== Changelog ==

= 2.1.0 =
* Szablony karty: w Ustawieniach PDF można dodać kolejne szablony (kopia istniejącego), zmienić nazwę, usunąć i ustawić domyślny. Każdy szablon ma własną kolejność, położenie, czcionki, logo, stopkę i ustawienia strony.
* Nowa tabela „Kolejność i położenie”: strzałki zmieniają kolejność elementów; położenie „pod poprzednim” / „w linii poprzedniego” (np. cena obok nagłówka) / „stałe miejsce” (pt od góry); wyrównanie środek / do lewej / do prawej i przesunięcie w poziomie.
* Generator: wybór szablonu karty (zapamiętywany w szkicu); bez wyboru — szablon domyślny.
* Ceny są wspólne dla wszystkich szablonów — zmiana w jednym zmienia je we wszystkich.
* Dotychczasowy układ staje się „Szablonem podstawowym” — wydruk po aktualizacji wygląda tak samo.
= 2.0.0 =
* Karta generowana z danych zamiast nakładania tekstu na szablon PDF.
* Ceny, nagłówki, stopka, czcionki, rozmiary, odstępy, logo i układ edytowane z frontu (Ustawienia PDF).
* Czcionki, logo, pdf-lib i fontkit dołączone lokalnie (bez jsDelivr/unpkg dla generowania).
* Opcja „nie zostawiaj krótkich słów na końcu linii” (domyślnie wyłączona — jak w Canvie).
* Automatyczne zmniejszanie treści (do 70%), gdy nie mieści się nad stopką.
* Stare szablony PDF nie są już używane; ich dane i trasy REST zostają (zgodność).
