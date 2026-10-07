=== GastroFlowx — Panel Pracownika (SPA Hub) ===
Contributors: senior-fullstack-dev
Tags: restaurant, spa, roles, scheduler, tips
Requires at least: 6.0
Requires PHP: 7.4
Version: 1.0.0

== Opis ==
Ta wtyczka NIE zastępuje istniejących wtyczek — spina je w jeden spójny
panel SPA (jak w dostarczonej makiecie Untitled-3.html i zrzutach ekranu),
z jednym logowaniem, jednym menu (sidebar / dolna nawigacja) i dostępem
do kategorii nadawanym per rola WordPress.

Wymagane, aktywne wtyczki (zainstaluj wraz z tą):
- restaurant-scheduler  → shortcode [restaurant_scheduler_app] → kategoria "Grafik"
- system-napiwkow-spa   → shortcode [napiwki_app]              → kategoria "Napiwki"
- weranda-lunch         → shortcode [weranda_lunch_panel]      → kategoria "Lunch"
- weranda-kolorowanki   → shortcode [weranda_kolorowanki_panel]→ kategoria "Kolorowanki"
- employee-timesheet    → shortcode [ehtt_timesheet]           → kategoria "Godziny"

Kategorie "Strona domowa" i "Moje konto" są wbudowane w tego huba i zawsze
widoczne dla każdego zalogowanego użytkownika.

== Instalacja ==
1. Zainstaluj i aktywuj wszystkie 5 wtyczek modułowych wymienionych powyżej
   (bez zmian — hub korzysta z ich shortcode'ów, tabel i REST API 1:1).
2. Zainstaluj i aktywuj tę wtyczkę (gastroflowx-hub).
3. W panelu WP przejdź do "GastroFlowx → Ustawienia ogólne" i ustaw nazwę
   restauracji oraz logo.
4. W "GastroFlowx → Dostęp ról" zaznacz, które role mają dostęp do których
   kategorii. Administrator ma zawsze pełny dostęp.
5. Utwórz stronę (np. "Panel Pracownika") i wstaw na niej shortcode:
   [gastroflowx_app]
6. Ustaw tę stronę np. jako stronę główną dla pracowników. Niezalogowani
   użytkownicy zobaczą ekran logowania z opcją "Zapamiętaj mnie", zalogowani —
   pełny panel z menu bocznym (desktop) / dolną nawigacją (mobile), zgodnie
   z przyznanymi uprawnieniami roli. Próba wejścia do kategorii bez
   uprawnień (np. przez bezpośredni link) pokaże komunikat "Brak dostępu".

== Zarządzanie rolami i dostępem ==
Role personelu (kelner, barman, kucharz, lunch, rs_chef_manager,
rs_restaurant_manager, rs_schedule_manager, itd.) pochodzą z istniejących
wtyczek/WordPressa — ten hub ich nie duplikuje, tylko odczytuje pełną listę
ról systemowych i pozwala nadać/edytować/usunąć dostęp do każdej kategorii
z poziomu "GastroFlowx → Dostęp ról" (macierz z checkboxami + przycisk
"Wyczyść" per rola).

== Tytuły pracowników i zdjęcia profilowe (od 1.0.4) ==
- "GastroFlowx → Tytuły pracowników" pozwala ręcznie ustawić tekst wyświetlany
  w nagłówku panelu (np. "Kelner", "Szef kuchni") dla każdego użytkownika,
  niezależnie od jego faktycznej roli WordPress. Puste pole = pokazywana jest
  domyślna nazwa roli WP.
- Każdy zalogowany pracownik może ustawić własne zdjęcie profilowe w zakładce
  "Moje konto" (ikona aparatu na awatarze) — działa nawet bez uprawnienia
  "upload_files" w WordPressie.

== Dostęp do WordPressa (od 1.1.0) ==
- Pasek administracyjny WP jest ukryty dla wszystkich poza administratorem.
- Wejście do /wp-admin/ jest zablokowane dla wszystkich poza administratorem
  (przekierowanie na stronę główną). Dotyczy to również linków w mailach WP itp.
- Po zalogowaniu (także awaryjnie przez natywny /wp-login.php) osoby inne niż
  administrator zawsze trafiają na stronę główną, nigdy do /wp-admin/.

== Moduł Urodziny (od 1.1.0) ==
Nowa kategoria "Urodziny" — widok kalendarza miesięcznego (z nawigacją
miesiąc wprzód/wstecz) oraz lista nadchodzących urodzin pogrupowana wg
miesięcy. Dane pochodzą wyłącznie z pola `user_birth_date` — tego samego,
które wypełnia się w "Moje konto" i którego używa wtyczka Napiwków (jedno
źródło prawdy, zero duplikacji). Każdy z dostępem do tej kategorii widzi
urodziny całego zespołu — nic nie jest ukrywane per osoba. Przycisk
"+ Dodaj datę" pozwala szybko ustawić własną datę urodzenia, jeśli nie jest
jeszcze uzupełniona. Kategoria podlega tej samej macierzy uprawnień ról co
pozostałe (GastroFlowx → Dostęp ról).

== Powiadomienia push (Firebase Cloud Messaging) — od 2.0.0 ==
"GastroFlowx → Integracje" pozwala scentralizowanie skonfigurować Firebase
Cloud Messaging (konfiguracja Web SDK + klucz VAPID + konto serwisowe do
wysyłki po stronie serwera). Po włączeniu, ten panel jest JEDYNYM miejscem
inicjalizującym SDK Firebase na froncie (dla każdego zalogowanego
pracownika, na każdej stronie) i JEDYNYM kanałem wysyłki push — zarówno dla
własnych powiadomień urodzinowych (codziennie o 9:00), jak i dla Grafiku i
Napiwków, przez wspólny filtr `gfx_dispatch_push`. Odbiorcy są filtrowani po
realnej zgodzie (ekran "GastroFlowx → Powiadomienia push → Zgody
użytkowników"), a każda wysyłka jest logowana w "Historii wysyłki" tego
samego ekranu. Jeśli integracja jest nieaktywna/nieskonfigurowana, push jest
po prostu pomijany — inne kanały (np. e-mail w Grafiku) działają nadal.
Instrukcja krok po kroku (konto Firebase, klucze, konto serwisowe) znajduje
się bezpośrednio na stronie "GastroFlowx → Integracje" w panelu WP.

Wcześniejsze wersje (do 1.2.x) korzystały z OneSignal — od 2.0.0 integracja
została w całości zastąpiona przez Firebase Cloud Messaging.

== Szablony treści i ikony powiadomień push (od 2.3.0) ==

GastroFlowx → Powiadomienia push → zakładka „Szablony i ikony”.

* Edycja tytułu i treści KAŻDEGO powiadomienia push: urodziny (życzenia i ogłoszenie dla zespołu), powitalne, testowe oraz powiadomienia z innych modułów.
* Pola w szablonach: {tytul}, {tresc} (oryginał z modułu), {odbiorca}, {imie_odbiorcy}, {nazwa_restauracji} + pola typu (np. {solenizant}).
* Typy z modułów, które nie rejestrują się same (np. Grafik), pojawiają się automatycznie po pierwszej wysyłce — domyślnie z oryginalną treścią.
* Ikona per typ powiadomienia + ikona domyślna. Kolejność: ikona z modułu > ikona typu > ikona domyślna > ikona aplikacji (PWA) > logo.
* Przycisk „Wyślij test do mnie” przy każdym typie.
* Treść testowego i powitalnego powiadomienia przeniesiona z Integracji (dotychczasowe teksty zostają zachowane).
* Dla programistów: filtr `gfx_push_notification_types` oraz nowe argumenty `type`, `vars`, `icon` w `gfx_dispatch_push`.
* 2.3.1: ikonka paska stanu Androida (badge) — domyślna w panelu; moduły mogą podać `fallback_badge`. Nowy argument `fallback_icon` (ikona modułu używana, gdy typ nie ma własnej, a w panelu nie wybrano ikony domyślnej).
* 2.3.1: Grafik (restaurant-scheduler 1.5.0+) wysyła push przez panel — wszystkie jego wiadomości widoczne w „Szablony i ikony”.
* iOS/iPadOS zawsze pokazuje ikonę zainstalowanej aplikacji — własna ikona działa na Androidzie, Windows, macOS i ChromeOS.

== Spójność wizualna ==
Każdy moduł zachowuje własną, samodzielną logikę frontendową (Vue/REST),
ale jego kolorystyka akcentowa została zharmonizowana z niebieskim motywem
GastroFlowx poprzez assets/css/brand-override.css (nadpisanie zmiennych CSS
i klas Tailwind modułów, bez modyfikacji ich kodu źródłowego).
