=== Employee Timesheet & Tips ===
Contributors: —
Tags: godziny pracy, ewidencja czasu, napiwki, grafik, SPA, vue
Requires at least: 5.9
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Ewidencja godzin pracy pracowników jako aplikacja SPA (Vue 3) osadzona w WordPressie.

== Wygląd interfejsu ==

Interfejs SPA jest stylistycznie spójny z siostrzaną wtyczką "system-napiwkow-spa"
(ten sam panel): identyczne zmienne kolorów, promienie zaokrągleń, typografia,
pigułkowe taby, karty, statystyki i komunikaty. Nagłówek i menu dostarcza system
nadrzędny (wtyczka nie renderuje własnego nagłówka) — od razu pod nim wchodzą
sub-taby "Dzień" / "Kalendarz i podsumowanie" / "Ustawienia". Cały styl to
samodzielny, zwykły CSS przypięty do `#ehtt-app` (bez Tailwind ani innego
frameworka) — zero kroku budowania (build/webpack/npm).

Wszystkie nazwy klas CSS w tej wtyczce są w pełni zaprefiksowane (`ehtt-*`),
żeby nigdy nie kolidowały z globalnym motywem/CSS panelu, w którym wtyczka jest
osadzona (np. generyczna klasa `active` bez prefiksu potrafi zderzyć się z
globalnym stylowaniem "aktywnego" elementu w hoście i "wygasić" tekst/kolor).

Vue 3 i Font Awesome są hostowane LOKALNIE w plikach wtyczki
(`assets/vendor/vue/`, `assets/vendor/fontawesome/`), a NIE ładowane z
zewnętrznych CDN (unpkg.com / cdnjs.cloudflare.com). Dzięki temu wygląd i
działanie aplikacji nie zależą od dostępności serwerów trzecich — to najczęstsza
przyczyna sporadycznego "gubienia się" stylu przy osadzaniu bibliotek z CDN
(blokada przez ad-block/firewall, przerwa w działaniu CDN, wolne połączenie).

Jeśli styl mimo to czasem nie wczytuje się:
1. Otwórz konsolę przeglądarki (F12) → zakładka Network, odśwież stronę i
   sprawdź, czy `app.css` / `app.js` / `vue.global.prod.js` / `all.min.css`
   wracają ze statusem 200 (nie 403/404/timeout).
2. Jeśli w systemie działa wtyczka cache'ująca/optymalizująca zasoby (WP Rocket,
   Autoptimize, LiteSpeed Cache, W3 Total Cache itp.), wyłącz dla uchwytów:
   `vue3`, `ehtt-fontawesome`, `ehtt-app`, `ehtt-app-style` łączenie/minifikację/
   opóźnione ładowanie (defer/async) — łączenie wielu plików CSS/JS w jeden
   bywa źródłem sporadycznych błędów przy inwalidacji cache.
3. Sprawdź, czy problem występuje na tej samej podstronie/zakładce zawsze, czy
   losowo — to pomoże odróżnić błąd cache'a od błędu sieci.

== Opis ==

Wtyczka pozwala pracownikom rejestrować swoje godziny pracy dzień po dniu:

* Stawka godzinowa: ogólna (globalna) + możliwość zmiany na konkretny dzień lub dla konkretnego pracownika.
* Godzina rozpoczęcia jest sugerowana automatycznie na podstawie integracji z wtyczką grafiku (filtr `ehtt_suggested_start_time`) — pracownik może ją zmienić; jeśli nie zmieni, system użyje sugestii z grafiku.
* Czas pracy liczony jest i zaokrąglany do 15 minut, wyświetlany w formacie "7,5 h".
* Integracja z wtyczką napiwków (filtr `ehtt_daily_tips_amount`) — pokazuje napiwki danego dnia, z automatycznym podziałem na kuchnię i bar (konfigurowalne %).
* Podsumowanie: zarobek z godzin, napiwki brutto/netto, wysokość przelewu na konto oraz kwota gotówki po odliczeniu podziału dla kuchni/baru.
* Widok kalendarza (siatka miesiąca) z godzinami, zarobkiem i napiwkami dla każdego dnia.
* W podsumowaniu dnia widoczne jest dodatkowo pole "Łączny zarobek" (godziny + napiwki netto).
* Godziny rozpoczęcia i zakończenia są zawsze zaokrąglane do pełnych 15 minut (np. 7:30,
  7:45, 18:30, 19:15) — nigdy do wartości pośrednich typu 19:12 czy 7:32. Zaokrąglenie
  działa zarówno w przeglądarce (natychmiast po zmianie pola), jak i na serwerze
  (dodatkowe zabezpieczenie przy zapisie).

== Instalacja ==

1. Wgraj katalog `employee-timesheet` do `/wp-content/plugins/`.
2. Aktywuj wtyczkę w Wtyczki → Zainstalowane wtyczki. Zostaną utworzone własne tabele bazy danych.
3. Przejdź do menu **Ewidencja godzin** w panelu WP-Admin, albo umieść shortcode `[ehtt_timesheet]` na dowolnej stronie frontowej (np. w intranecie).
4. W zakładce **Ustawienia** (widoczna dla administratorów / osób z uprawnieniem `ehtt_manage_timesheets`) skonfiguruj stawkę ogólną, zaokrąglanie, % dla kuchni/baru oraz sposób wypłaty napiwków.

== Połączenie z Grafikiem i Napiwkami (od 1.1.0, wbudowane) ==

Ewidencja sama korzysta z wtyczek Grafik Pracy (restaurant-scheduler) i System
Napiwków (system-napiwkow-spa), jeśli są aktywne — łącznik nie jest potrzebny.

Godziny (podpowiedź rozpoczęcia i zakończenia):
1. ręczny wpis kierownika (sekcja „Zarządzanie” → „Ręczne godziny podpowiedzi”),
2. Grafik: opublikowane zmiany z danego dnia — najwcześniejszy początek
   i najpóźniejszy koniec (bez wersji roboczych i znaczników „Nieobecny” / „Dostępny”),
3. brak podpowiedzi.
Bez zapisanego wpisu pola są od razu wypełnione podpowiedzią; pracownik może je zmienić.

Napiwki (od 1.2.0):
* Karta i serwis — kwoty BRUTTO z modułu Napiwków (kelner: wpisane kwoty;
  barman / kucharz / pomoc: udział z puli). Trafiają do PRZELEWU. Rozliczenie
  na netto po otrzymaniu przelewu (podatek z ulgą do 26 lat, udział baru i kuchni
  wg Napiwków, z wyjątkami procentowymi) jest pokazywane informacyjnie.
  Ręczny wpis kierownika = karta + serwis brutto; odliczenia % z ustawień.
* Gotówka — z modułu Napiwków (100% i kwota oddana do baru i kuchni), a gdy
  w Napiwkach nie ma gotówki z tego dnia — informacyjny wpis kelnera.
  Wpis kelnera jest pomijany, jeśli Napiwki mają już gotówkę z tego dnia.
  Gotówka NIE trafia do przelewu.
* Premia — informacyjny wpis kelnera, bez odliczeń, wchodzi do przelewu.
* Gotówkę i premię wpisuje tylko kelner (rola „kelner”) w zakładce „Dzień”.
* Przelew = zarobek z godzin + karta + serwis + premia (brutto).
* Łączny zarobek (netto) = godziny + karta i serwis netto + gotówka, która
  zostaje + premia.
* Kalendarz: godziny, zarobek i suma napiwków dnia; tabela „Dzień po dniu”
  (godziny, zarobek, karta, serwis, gotówka, premia) i podsumowanie miesiąca.
Puste pole ręcznego wpisu usuwa go — wracają dane z Grafiku / Napiwków.

Filtry dla programistów: ehtt_suggested_start_time, ehtt_suggested_end_time,
ehtt_tips_breakdown (pełne rozbicie: gross, tax, bar_cut, kitchen_cut, net, source).

== Uprawnienia ==

* Każdy zalogowany użytkownik widzi i edytuje wyłącznie własne wpisy.
* Rola `administrator` otrzymuje automatycznie capability `ehtt_manage_timesheets` (dostęp do wszystkich pracowników, ustawień, stawek i nadpisań). Aby dać dostęp innej roli (np. "kierownik zmiany"), dodaj tę capability programowo:

	$role = get_role( 'kierownik_zmiany' );
	$role->add_cap( 'ehtt_manage_timesheets' );

== Uwaga dotycząca danych przy odinstalowaniu ==

Domyślnie dane (wpisy godzin, stawki, napiwki) NIE są usuwane przy odinstalowaniu wtyczki.
Aby wymusić usunięcie, zdefiniuj w wp-config.php: `define( 'EHTT_REMOVE_DATA_ON_UNINSTALL', true );`

== Changelog ==

= 1.2.0 =
* Karta i serwis brutto do przelewu, rozliczenie netto po przelewie (informacyjnie).
* Informacyjne pola kelnera: napiwki z gotówki i premia (tabela wp_ehtt_extras).
* Tabela „Dzień po dniu” i nowe podsumowanie miesiąca (przelew, gotówka, rozliczenie).
* Usunięte ustawienie „Sposób wypłaty napiwków” (zasady przelewu są stałe).
* Automatyczne tworzenie nowych tabel po aktualizacji bez ponownej aktywacji.

= 1.1.0 =
* Wbudowane połączenie z Grafikiem (podpowiedź godziny rozpoczęcia i zakończenia) i Napiwkami (napiwki brutto i netto z rozbiciem na podatek, bar i kuchnię).
* Ręczne godziny podpowiedzi w sekcji „Zarządzanie”; puste pole usuwa ręczny wpis.
* Podsumowanie miesiąca z wierszem podatku.

= 1.0.0 =
* Pierwsze wydanie.
