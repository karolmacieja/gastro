<?php
/**
 * Starter content for the six GastroFlowx business documents, inserted by
 * GFXDoc_Seed (see class-gfxdoc-seed.php). 'since' is the seed version that
 * introduced the document: on upgrade only documents newer than the stored
 * seed version are added, so nothing the admin edited or deleted is touched.
 * The tip regulations and the PWA guide are kept verbatim from seed version 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function gfxdoc_seed_documents() {
	return array(
		array(
			'title'   => 'Opis systemu i funkcji GastroFlowx',
			'since'   => 2,
			'content' => <<<'GFXDOC_SEED'
<p class="subtitle">Weranda Lunch and Wine — opis systemu pracowniczego i wszystkich jego funkcji</p>
[gfxbox color="blue"]
Dokument opisuje, z czego składa się system GastroFlowx, jak działają jego moduły, kto ma do nich dostęp i jakie dane każdy moduł przechowuje. Opis przygotowano na podstawie analizy kodu wszystkich dwunastu wtyczek wchodzących w skład systemu.<br/><br/>
<strong>Wersja dokumentu:</strong> 2.0 &nbsp;|&nbsp; <strong>Stan na:</strong> październik 2026 &nbsp;|&nbsp; <strong>Przygotowano dla:</strong> Weranda Lunch and Wine (werandalunchwine.pl)
[/gfxbox]
<h2>Spis treści</h2>
<ol>
<li>Czym jest GastroFlowx — opis ogólny</li>
<li>Architektura — jak moduły łączą się w jeden system</li>
<li>Panel Pracownika (Hub)</li>
<li>Grafik Pracy</li>
<li>System Napiwków</li>
<li>Ewidencja Godzin Pracy</li>
<li>Generator Menu Lunchowego</li>
<li>Menu (karta dań PL/EN)</li>
<li>Kolorowanki</li>
<li>Pliki — biblioteka druków</li>
<li>Dokumenty — raporty dobowe z utargu</li>
<li>Karty Dostępu i Listy Pracowników</li>
<li>Dokumenty firmowe (regulaminy, polityki, porozumienia)</li>
<li>Rejestr Aktywności Użytkowników</li>
<li>Powiadomienia e-mail i push</li>
<li>Bezpieczeństwo systemu</li>
<li>Role systemowe — zestawienie</li>
<li>Jakie dane przechowuje każdy moduł</li>
<li>Przepływ danych i raport miesięczny</li>
</ol>
[gfxpagebreak]
<h2>1. Czym jest GastroFlowx — opis ogólny</h2>
<p><strong>GastroFlowx</strong> to wewnętrzny system pracowniczy restauracji Weranda Lunch and Wine. Działa jako aplikacja internetowa (z możliwością instalacji na telefonie jako aplikacja PWA) zbudowana na WordPressie. Zastępuje kartki, arkusze i wiadomości w komunikatorach w codziennych sprawach zespołu:</p>
<ul>
<li>układanie <strong>grafiku pracy</strong>, zgłaszanie dyspozycyjności, dni wolnych i zamian zmian,</li>
<li>przejrzyste <strong>rozliczanie napiwków</strong> (karta, serwis, gotówka) między salą, barem i kuchnią,</li>
<li>prowadzenie własnej <strong>ewidencji godzin</strong> i podgląd zarobku,</li>
<li>przygotowanie <strong>menu lunchowego</strong> i <strong>karty dań</strong> w PDF gotowych do druku,</li>
<li>biblioteki <strong>druków</strong> (listy obowiązków, HACCP), <strong>kolorowanek</strong> dla dzieci i <strong>raportów dobowych z kasy</strong>,</li>
<li><strong>powiadomienia</strong> e-mail i push o grafiku, napiwkach i urodzinach w zespole.</li>
</ul>
[gfxbox color="yellow"]
<strong>Charakter systemu.</strong> System został stworzony i jest utrzymywany przez Administratora systemu na serwerze wynajmowanym u dostawcy hostingu SeoHost.pl. Zasady przetwarzania danych opisuje <em>„Polityka prywatności systemu GastroFlowx”</em>, a relację z pracodawcą — <em>„Porozumienie z pracodawcą w sprawie korzystania z systemu GastroFlowx i przekazywania danych”</em>.
[/gfxbox]
<h3>Najważniejsze cechy</h3>
<table class="gfxdoc-table">
<tr><th style="width:32%;">Cecha</th><th>Co to oznacza w praktyce</th></tr>
<tr><td>Jedno logowanie</td><td>Pracownik loguje się raz i ma wszystkie moduły w jednym panelu (menu boczne na komputerze, dolna nawigacja na telefonie).</td></tr>
<tr><td>Dostęp według ról</td><td>Każdy widzi tylko te zakładki i dane, które są potrzebne na jego stanowisku. Role nadaje administrator.</td></tr>
<tr><td>Zgoda przed użyciem</td><td>Przy pierwszym logowaniu pracownik widzi politykę prywatności i decyduje, czy wyraża zgodę. Bez zgody panel się nie otwiera; zgodę można w każdej chwili wycofać w „Moje konto”.</td></tr>
<tr><td>Praca na telefonie</td><td>Wszystkie moduły są responsywne, a panel można zainstalować jako aplikację z ikoną na ekranie głównym.</td></tr>
<tr><td>Bez dostępu do zaplecza</td><td>Pracownicy nie mają dostępu do panelu technicznego WordPress (<code>/wp-admin/</code>). Cała praca odbywa się w panelu pracownika.</td></tr>
<tr><td>Wydruki i PDF</td><td>Grafik, raporty napiwków, menu, lunch, druki i raporty dobowe można pobrać jako PDF lub wydrukować.</td></tr>
</table>
[gfxpagebreak]
<h2>2. Architektura — jak moduły łączą się w jeden system</h2>
<p>System składa się z <strong>dwunastu wtyczek WordPress</strong>. Każda odpowiada za jeden obszar pracy i ma własne tabele bazy danych oraz własne, zabezpieczone REST API. Wtyczka centralna — <strong>GastroFlowx Hub</strong> — spina je w jeden panel <code>[gastroflowx_app]</code> z jednym logowaniem, wspólnym menu i wspólną macierzą uprawnień.</p>
<table class="gfxdoc-table">
<tr><th style="width:26%;">Moduł (wtyczka)</th><th>Do czego służy</th><th style="width:22%;">Gdzie widoczny</th></tr>
<tr><td><strong>GastroFlowx Hub</strong><br/><span class="small">gastroflowx-hub</span></td><td>Logowanie, menu, uprawnienia ról, Moje konto, Urodziny, zgody, aplikacja PWA, centralne powiadomienia push.</td><td>Strona domowa, Moje konto, Urodziny</td></tr>
<tr><td><strong>Grafik Pracy</strong><br/><span class="small">restaurant-scheduler</span></td><td>Grafik zmian, dyspozycyjność, dni wolne (offy), zamiany, automatyczne generowanie, kalendarz iCal, PDF.</td><td>Grafik</td></tr>
<tr><td><strong>System Napiwków</strong><br/><span class="small">system-napiwkow-spa</span></td><td>Rozliczanie napiwków z karty, serwisu i gotówki między kelnerami, barem i kuchnią; raporty miesięczne.</td><td>Napiwki</td></tr>
<tr><td><strong>Ewidencja Godzin</strong><br/><span class="small">employee-timesheet</span></td><td>Własne godziny pracy dzień po dniu (podpowiedź z Grafiku), stawka godzinowa, napiwki brutto i netto z Napiwków, zarobek, kalendarz miesiąca.</td><td>Godziny</td></tr>
<tr><td><strong>Weranda Lunch</strong><br/><span class="small">weranda-lunch</span></td><td>Generator PDF codziennego menu lunchowego na szablonach graficznych.</td><td>Lunch</td></tr>
<tr><td><strong>Menu GastroFlowX</strong><br/><span class="small">gastroflowx-menu</span></td><td>Baza dań PL/EN, kreator karty menu, „Wersje Standardowe”, PDF A4.</td><td>Menu</td></tr>
<tr><td><strong>Weranda Kolorowanki</strong><br/><span class="small">weranda-kolorowanki</span></td><td>Biblioteka kolorowanek dla dzieci z drukiem grupowym.</td><td>Kolorowanki</td></tr>
<tr><td><strong>GastroFlowX Pliki</strong><br/><span class="small">gastroflowx-pliki</span></td><td>Biblioteka druków: raporty utargu, listy obowiązków, checklisty HACCP; edytor i druk grupowy.</td><td>Pliki / Dokumenty</td></tr>
<tr><td><strong>Dokumenty GastroFlowX</strong><br/><span class="small">gastroflowx-dokumenty</span></td><td>Raporty dobowe: zdjęcia wydruków z kasy fiskalnej i terminala składane w jeden PDF.</td><td>Raporty dobowe</td></tr>
<tr><td><strong>Access Cards</strong><br/><span class="small">gastroflowx-access-cards</span></td><td>Dwustronne karty dostępu z kodami QR oraz listy pracowników do druku.</td><td class="small">tylko administrator (wp-admin)</td></tr>
<tr><td><strong>WP User Activity Tracker</strong><br/><span class="small">wp-user-activity-tracker</span></td><td>Rejestr logowań, sesji, odwiedzanych stron i edycji — bezpieczeństwo i rozliczalność.</td><td class="small">tylko administrator (wp-admin)</td></tr>
<tr><td><strong>GastroFlowx Documents</strong><br/><span class="small">gastroflowx-documents</span></td><td>Edytor dokumentów firmowych (ten dokument, polityka, regulamin, porozumienie) z eksportem do PDF.</td><td class="small">tylko administrator (wp-admin)</td></tr>
</table>
<p class="note">Moduły Menu, Pliki i Dokumenty (raporty dobowe) dodaje się do panelu w <code>GastroFlowx → Moduły</code> (nazwa, ikona, shortcode). Ich dokładne nazwy w menu ustala administrator.</p>
<h3>Wspólne elementy</h3>
<ul>
<li><strong>Konta i role</strong> — wszystkie moduły korzystają z tych samych kont WordPress. Jedna osoba może mieć kilka ról naraz (np. kelner + manager napiwków); każdy moduł bierze pod uwagę wszystkie role konta.</li>
<li><strong>Wspólne pola profilu</strong> — data urodzenia jest jedna dla całego systemu (moduł Urodziny w Hubie i ulga podatkowa „do 26 lat” w Napiwkach). Flaga „uprawnienie do zamykania lokalu” i „pracownik nieaktywny” pochodzą z Grafiku.</li>
<li><strong>Wspólne powiadomienia push</strong> — Hub jest jedynym kanałem push dla całego systemu (Firebase Cloud Messaging). Grafik i Napiwki wysyłają push przez Hub, który sprawdza zgodę odbiorcy i zapisuje historię wysyłki.</li>
<li><strong>Wspólny wygląd</strong> — wszystkie moduły używają tego samego niebieskiego motywu GastroFlowx (kolor przewodni #2563EB) i ikon Font Awesome.</li>
</ul>
[gfxbox color="muted"]
<strong>Ewidencja Godzin a Grafik i Napiwki.</strong> Ewidencja Godzin (od wersji 1.1.0) ma <u>wbudowane</u> połączenie z obydwoma modułami — bez dodatkowych wtyczek. Z Grafiku <strong>podpowiada godzinę rozpoczęcia i zakończenia</strong> pracy (najwcześniejszy początek i najpóźniejszy koniec opublikowanych zmian danego dnia; wersje robocze i znaczniki „Nieobecny/Dostępny” są pomijane). Z modułu Napiwków pobiera <strong>napiwki brutto i netto</strong> z rozbiciem na podatek oraz udziały baru i kuchni — liczone tymi samymi wzorami co Napiwki, więc netto w Ewidencji równa się wypłacie dnia w Napiwkach. Ręczny wpis osoby zarządzającej Ewidencją (godziny lub napiwki brutto) ma pierwszeństwo; od ręcznej kwoty napiwków Ewidencja odejmuje procenty kuchni i baru z własnych ustawień.
[/gfxbox]
[gfxpagebreak]
<h2>3. Panel Pracownika (Hub) [gfxbadge]wszyscy pracownicy[/gfxbadge]</h2>
<p>Wtyczka centralna. Nie powiela funkcji innych modułów, tylko je spina i dokłada funkcje wspólne.</p>
<h3>Funkcje dla każdego zalogowanego pracownika</h3>
<ul>
<li><strong>Logowanie</strong> — własny ekran logowania z opcją „Zapamiętaj mnie” albo logowanie kodem QR z karty dostępu.</li>
<li><strong>Ekran zgody</strong> — przy pierwszym logowaniu (i po wycofaniu zgody) panel pokazuje link do polityki prywatności oraz przyciski „Wyrażam zgodę” / „Nie wyrażam zgody”. System zapisuje każdą decyzję w historii: datę, wersję polityki i adres IP.</li>
<li><strong>Menu</strong> — menu boczne (komputer) lub dolna nawigacja (telefon). Widoczne kategorie zależą od ról; wejście bez uprawnień kończy się komunikatem „Brak dostępu”.</li>
<li><strong>Strona domowa</strong> — ekran startowy panelu po zalogowaniu.</li>
<li><strong>Moje konto</strong> — zmiana imienia, nazwiska i adresu e-mail, własne zdjęcie profilowe (ikona aparatu przy awatarze), data urodzenia, podgląd daty zgody i przycisk jej wycofania.</li>
<li><strong>Urodziny</strong> — kalendarz miesiąca i lista nadchodzących urodzin zespołu (imię i nazwisko, data, kończony wiek). Osoby oznaczone jako nieaktywne są domyślnie ukryte. Codziennie o 9:00 system może wysłać push z życzeniami do solenizanta i ogłoszenie dla zespołu.</li>
<li><strong>Aplikacja PWA</strong> — instalacja panelu na telefonie/komputerze jako aplikacji z ikoną; przycisk „Włącz powiadomienia” do wyrażenia zgody na push na danym urządzeniu.</li>
</ul>
<h3>Funkcje administracyjne (wp-admin, tylko administrator)</h3>
<table class="gfxdoc-table">
<tr><th style="width:36%;">Ekran</th><th>Funkcja</th></tr>
<tr><td>GastroFlowx → Ustawienia ogólne</td><td>Nazwa restauracji, logo, link do polityki prywatności (pokazywany na ekranie zgody), ukrywanie nieaktywnych w Urodzinach.</td></tr>
<tr><td>GastroFlowx → Moduły</td><td>Dodawanie modułów do panelu (nazwa, ikona, shortcode, kategoria).</td></tr>
<tr><td>GastroFlowx → Dostęp ról</td><td>Macierz: które role widzą które kategorie panelu. Administrator ma zawsze pełny dostęp.</td></tr>
<tr><td>GastroFlowx → Tytuły pracowników</td><td>Indywidualny tytuł wyświetlany w nagłówku (np. „Szef kuchni”).</td></tr>
<tr><td>GastroFlowx → Lista zgód</td><td>Bieżący status zgody każdego konta (wyrażona / wycofana / brak) z eksportem do PDF.</td></tr>
<tr><td>GastroFlowx → Integracje</td><td>Konfiguracja Firebase Cloud Messaging dla całego systemu.</td></tr>
<tr><td>GastroFlowx → Powiadomienia push</td><td>Zgody na push per urządzenie, historia wysyłki (ostatnie 5000 wpisów), szablony treści i ikony każdego typu powiadomienia, test „Wyślij do mnie”.</td></tr>
</table>
[gfxpagebreak]
<h2>4. Grafik Pracy [gfxbadge]Kucharz / Kelner / Barman / Menadżerowie[/gfxbadge]</h2>
<p>Aplikacja SPA (Vue 3) do planowania zmian. Restauracja jest podzielona na dwie niezależnie zarządzane sekcje:</p>
<table class="gfxdoc-table">
<tr><th>Sekcja</th><th>Kto do niej należy</th><th>Kto nią zarządza</th></tr>
<tr><td>Kuchnia</td><td>Kucharze</td><td>Szef Kuchni (<code>rs_chef_manager</code>)</td></tr>
<tr><td>Sala i bar</td><td>Kelnerzy, barmani</td><td>Menadżer Restauracji (<code>rs_restaurant_manager</code>)</td></tr>
<tr><td>Cała restauracja</td><td>—</td><td>Menadżer Grafiku — pełny dostęp (<code>rs_schedule_manager</code>) i administrator</td></tr>
</table>
<p class="note">Rozdział jest egzekwowany po stronie serwera: Szef Kuchni nie odczyta ani nie zmieni danych sali i baru, a Menadżer Restauracji — danych kuchni. Obie role widzą podgląd całości w zakładce „Gotowy grafik” (tylko do odczytu).</p>
<h3>Zakładki pracownika</h3>
<ul>
<li><strong>Mój grafik / Moje zmiany</strong> — własne zmiany tydzień po tygodniu, z oznaczeniem zmian zamykających.</li>
<li><strong>Dyspozycyjność</strong> — zgłaszanie dni i godzin, w których możesz pracować. Gdy menadżer zamknie okres zgłaszania, zmiany na te dni są zablokowane.</li>
<li><strong>Offy</strong> — wniosek o dzień wolny / urlop z wyborem powodu z listy i opcjonalnym komentarzem; decyzję podejmuje menadżer sekcji.</li>
<li><strong>Zamiany</strong> — prośba o zamianę zmiany z kolegą: najpierw akceptuje kolega, potem menadżer.</li>
<li><strong>Gotowy grafik</strong> — opublikowany grafik całej restauracji.</li>
<li><strong>Kalendarz</strong> — widok miesiąca z wnioskami o dni wolne (offy) osób z tej samej sekcji.</li>
<li><strong>Synchronizacja</strong> — prywatny link iCal do subskrypcji grafiku w Kalendarzu Google / iOS i szybkie „Dodaj do Kalendarza Google”; eksport własnego grafiku do PDF.</li>
</ul>
<h3>Funkcje menadżerów</h3>
<ul>
<li><strong>Siatka grafiku</strong> (pracownicy × dni) z kolorowaniem: zmiana / zgłoszona dyspozycja / niedyspozycyjny / off; suma godzin tygodnia; kolejność pracowników.</li>
<li><strong>Szablony zmian</strong> — gotowe wzorce godzin do szybkiego przypisywania.</li>
<li><strong>Okresy zgłaszania dyspozycji</strong> — otwarcie zgłaszania na zakres dat (z terminem i wiadomością), statystyka „zgłosiło X z Y”, przypomnienia do osób bez dyspozycji, zamknięcie i ponowne otwarcie. Opcjonalnie zgłaszanie tylko w otwartych okresach.</li>
<li><strong>Auto-generowanie</strong> — grafik układany automatycznie z dyspozycyjności i zatwierdzonych offów. Zmianę zamykającą dostaje wyłącznie osoba z uprawnieniem do zamykania lokalu. Wynik to <u>wersja robocza</u>, którą menadżer sprawdza i zatwierdza (z opcją powiadomienia zespołu); system pokazuje zestawienie zmian, godzin i zamknięć.</li>
<li><strong>Wymuszone przypisanie</strong> zmiany innej osobie oraz znaczniki „Nieobecny” / „Dostępny”.</li>
<li><strong>Decyzje</strong> w sprawie offów i zamian, z notatką menadżera.</li>
<li><strong>Pracownik nieaktywny</strong> — ukrywa osobę w siatce, kalendarzu, PDF i generowaniu, bez kasowania historii.</li>
<li><strong>Wyślij e-mail</strong> — wiadomość do wybranej grupy lub całej załogi; własne szablony wiadomości.</li>
<li><strong>Eksport PDF</strong> grafiku sekcji i widoku zbiorczego.</li>
</ul>
<h3>Powiadomienia automatyczne Grafiku</h3>
<p>Nowa zmiana, zmiana przypisania, decyzja o offie, prośba o zamianę i decyzja o zamianie (do pracownika); zmieniona dyspozycyjność, nowy wniosek o off i zamiana do akceptacji (do menadżerów); otwarcie, przypomnienie i zamknięcie zgłaszania dyspozycji. Każdą wiadomość administrator może edytować lub wyłączyć w <code>Ustawienia → Grafik: e-maile i push</code>.</p>
[gfxpagebreak]
<h2>5. System Napiwków [gfxbadge]Kelner / Barman / Kucharz / Managerowie[/gfxbadge]</h2>
<p>Moduł rozlicza napiwki z <strong>karty</strong>, <strong>serwisu</strong> i <strong>gotówki</strong> według „Regulaminu podziału i rozliczania napiwków”. Domyślnie kelner oddaje 10% do puli baru i 20% do puli kuchni, a pule dzieli się między personel działu proporcjonalnie do godzin.</p>
<h3>Zakładka „Dzień” — kto co wpisuje</h3>
<table class="gfxdoc-table">
<tr><th style="width:24%;">Rola</th><th>Wpisuje</th><th>Widzi</th></tr>
<tr><td>Kelner</td><td>Gotówkę oddaną do baru <u>lub</u> kuchni (druga kwota liczy się sama).</td><td>Własne rozliczenie dnia: karta netto, serwis netto, gotówka 100%, wypłata.</td></tr>
<tr><td>Barman / Kucharz</td><td>Własne godziny (barman — jeśli nie ma osoby admin_bar; godziny kucharzy wpisuje admin_kuchnia).</td><td>Własną wypłatę dnia.</td></tr>
<tr><td>Manager napiwków</td><td>Kartę i serwis każdego kelnera.</td><td>„Kelnerzy (Rozliczenie Napiwków)” i podsumowanie dnia.</td></tr>
<tr><td>Admin Bar / Admin Kuchnia</td><td>Godziny personelu działu oraz gotówkę od kelnerów.</td><td>„Rozliczenie Personelu” i podsumowanie dnia działu.</td></tr>
<tr><td>Administrator</td><td>Wszystko powyżej.</td><td>Wszystko + ustawienia.</td></tr>
</table>
<h3>Pozostałe zakładki</h3>
<ul>
<li><strong>Miesiąc</strong> — Raport Kelnera, Raport Managera, Raport Wypłat Personelu (ogólny lub dzień po dniu) oraz Podsumowanie Miesiąca (manager/administrator).</li>
<li><strong>Sprawdź pracownika</strong> (manager/administrator) — pełny raport dowolnej osoby za wybrany miesiąc, także zwolnionej.</li>
<li><strong>Rozliczenie serwisu</strong> (admin_bar/admin_kuchnia) — podział puli serwisu godzinowo albo ręcznie kwotowo (np. przy większej rezerwacji).</li>
<li><strong>Rozliczenie z kelnerami</strong> (admin_bar/admin_kuchnia) — zadeklarowane / fizycznie wpłacone / różnica, ze statusami: Rozliczony, Nadwyżka, Do dopłaty, Brak wpłaty.</li>
<li><strong>Wyjątki procentowe</strong> — inny procent dla konkretnego kelnera na jeden dzień, osobno dla karty i serwisu (administrator zawsze; manager napiwków — jeśli administrator to włączy).</li>
<li><strong>E-mail</strong> i <strong>Powiadomienia</strong> — ręczna (nigdy automatyczna) wysyłka miesięcznego rozliczenia do grupy, z potwierdzeniem przed wysyłką; treść z polami <code>{imie}</code>, <code>{wyplata}</code>, <code>{razem}</code> itd.</li>
<li><strong>Ustawienia</strong> (administrator) — stawka podatku, ulga PIT-0 do 26. urodzin (z możliwością ręcznego nadpisania daty w profilu), zgoda na wyjątki dla managera.</li>
<li><strong>PDF</strong> — każda karta i raport ma przycisk „Pobierz PDF” (prawdziwy tekst z polskimi znakami).</li>
</ul>
[gfxbox color="green"]<strong>Wzór wypłaty kelnera:</strong> (karta netto + serwis netto + gotówka „100%”) × (100% − procent bar − procent kuchnia) — przy wartościach domyślnych × 70%. <strong>Stawka godzinowa działu</strong> = pula działu ÷ suma godzin personelu działu.[/gfxbox]
[gfxpagebreak]
<h2>6. Ewidencja Godzin Pracy [gfxbadge]wszyscy pracownicy[/gfxbadge]</h2>
<ul>
<li>Zakładka <strong>Dzień</strong> — godzina rozpoczęcia i zakończenia pracy, zawsze zaokrąglana do 15 minut; czas pokazywany jako np. „7,5 h”; notatka.</li>
<li><strong>Podpowiedź z Grafiku</strong> — godzina rozpoczęcia i zakończenia są wstępnie ustawiane według Twoich zmian z Grafiku; można je zmienić.</li>
<li><strong>Stawka godzinowa</strong> — ogólna, z możliwością nadpisania dla konkretnej osoby lub dnia.</li>
<li><strong>Napiwki dnia brutto i netto</strong> — pobierane z modułu Napiwków: brutto (karta + serwis + gotówka 100% albo udział z puli), podatek, udział baru i kuchni, netto do wypłaty. Kwotę może też wpisać ręcznie kierownik (brutto, odliczenia % z ustawień).</li>
<li><strong>Podsumowanie dnia</strong> — zarobek z godzin, napiwki brutto/netto, kwota przelewu, gotówka po odliczeniu podziału, łączny zarobek.</li>
<li><strong>Kalendarz i podsumowanie</strong> — siatka miesiąca z godzinami, zarobkiem i napiwkami.</li>
<li>Każdy widzi i edytuje <u>wyłącznie własne</u> wpisy. Osoba z uprawnieniem <code>ehtt_manage_timesheets</code> (domyślnie administrator) widzi wszystkich, ustawia stawki, ręcznie nadpisuje podpowiedzi godzin i napiwków dnia oraz zmienia ustawienia.</li>
</ul>
<h2>7. Generator Menu Lunchowego [gfxbadge]rola „lunch” / administrator[/gfxbadge]</h2>
<ul>
<li><strong>Szkic</strong> — zupa, waga, danie lunchowe i rekomendacja; szkic zapisuje się na serwerze, więc można wrócić do niego później.</li>
<li><strong>Baza produktów (checklista)</strong> — pozycje do szybkiego wybierania; dodawanie i usuwanie.</li>
<li><strong>Generowanie PDF</strong> na wybranym szablonie graficznym (tekst nanoszony na wzór PDF), z podglądem.</li>
<li><strong>Archiwum</strong> — wygenerowane pliki PDF zapisane na serwerze do ponownego pobrania.</li>
<li><strong>Szablony PDF</strong> (administrator) — pozycja i rozmiar każdego pola, wybór szablonu domyślnego, wgrywanie nowych wzorów — z frontu, bez wp-admin.</li>
</ul>
<h2>8. Menu (karta dań PL/EN) [gfxbadge]Menedżer menu / manager[/gfxbadge]</h2>
<ul>
<li><strong>Kreator menu</strong> — wybór dań z bazy w każdej kategorii (kolejność strzałkami), język PL lub EN, dodatkowy tekst, podgląd arkusza A4 na żywo, pobranie PDF bez zapisywania.</li>
<li><strong>Wersje</strong> — zapisane „Wersje Standardowe”: edycja, duplikowanie, PDF, usuwanie; zaznaczenie kilku wersji daje jeden wspólny PDF. Dwa szablony: „Dwie kolumny” i „Podzielona strona” (do przecięcia na pół).</li>
<li><strong>Baza dań</strong> — nazwy i opisy PL/EN, kategorie z podtytułami, wyszukiwarka.</li>
<li><strong>Ustawienia PDF</strong> — logo nagłówka i stopki, prowadnice 1:1 z Canvy, typografia (Poppins, Adorn Garland, Fabrikat Mono), „Dopasuj do jednej strony”.</li>
<li>Dostęp do danych modułu mają role wskazane w <code>GastroFlowx → Menu (dostęp)</code>; domyślnie Menedżer menu (<code>menu_manager</code>) i manager.</li>
</ul>
[gfxpagebreak]
<h2>9. Kolorowanki [gfxbadge]rola „lunch” / administrator[/gfxbadge]</h2>
<ul>
<li>Kategorie (tworzenie, usuwanie — pliki wracają do „Bez kategorii”).</li>
<li>Wgrywanie wielu plików naraz (JPG, PNG, WEBP, PDF), zmiana kategorii pliku.</li>
<li>Druk pojedynczy i <strong>druk grupowy</strong> z liczbą kopii — wszystko w jednym zadaniu drukarki.</li>
</ul>
<h2>10. Pliki — biblioteka druków [gfxbadge]role wybrane przez administratora[/gfxbadge]</h2>
<ul>
<li>Dokumenty tworzone w edytorze na froncie: nagłówki, listy, lista zadań do odhaczenia, tabele, pola do wpisania, miejsce na podpisy.</li>
<li>Wgrywanie PDF, JPG, PNG, WEBP (typ sprawdzany po zawartości pliku).</li>
<li>Kategorie (np. Raporty, Listy obowiązków, Checklisty / HACCP), wyszukiwarka, sortowanie, podgląd przed drukiem.</li>
<li>Druk grupowy z liczbą kopii, tryb druku dla telefonów i tabletów.</li>
<li>Ograniczenie widoczności dokumentu do wybranych ról (np. tylko kuchnia).</li>
<li>Osobne uprawnienia: widok i druk, dokumenty innych ról, pobieranie oryginałów, tworzenie, wgrywanie, edycja i usuwanie (własnych / wszystkich), kategorie. Domyślnie zarządza Menadżer Restauracji.</li>
<li>Gotowe wzory: Raport utargu dziennego, Lista obowiązków — otwarcie, Lista obowiązków — zamknięcie zmiany, Rejestr temperatur urządzeń chłodniczych.</li>
</ul>
<h2>11. Dokumenty — raporty dobowe z utargu [gfxbadge]role „Korzysta” / „Zarządza”[/gfxbadge]</h2>
<ul>
<li>Wybór dnia w kalendarzu, dodanie zdjęć wydruków z kasy fiskalnej i terminala (z galerii lub aparatem), ustawienie kolejności przeciąganiem, obracanie, podgląd.</li>
<li>Kompresja zdjęć w przeglądarce i złożenie jednego PDF A4 (np. <code>raport_dobowy_2026-10-06.pdf</code>), pobranie lub udostępnienie z telefonu.</li>
<li><strong>Archiwum wyłączone (domyślnie)</strong> — zdjęcia ani PDF nie trafiają na serwer; zapisywane są tylko metadane: dzień, liczba stron, kto i kiedy wygenerował raport.</li>
<li><strong>Archiwum włączone</strong> — zdjęcia, miniatury i PDF są przechowywane na serwerze w chronionym katalogu; dzień można otworzyć, pobrać, edytować. Usuwanie dni i czyszczenie archiwum — tylko rola „Zarządza”.</li>
</ul>
<h2>12. Karty Dostępu i Listy Pracowników [gfxbadge]tylko administrator[/gfxbadge]</h2>
<ul>
<li>Wzór karty: nazwa lokalu, logo, adres aplikacji PWA i strony WWW.</li>
<li>Dwustronne karty A4 (druk duplex) z wybranymi kodami QR: logowanie, aplikacja PWA, strona WWW.</li>
<li>Hasło na karcie: zamaskowane (gwiazdki) albo nowe, wygenerowane hasło, które <u>nadpisuje dotychczasowe</u> i jest drukowane na karcie.</li>
<li>Kod QR logowania zawiera indywidualny, losowy klucz konta — zeskanowanie loguje do panelu bez hasła. Klucz działa do czasu jego zmiany przez administratora.</li>
<li>Listy pracowników do druku (imię i nazwisko, login, rola) z własnymi pustymi kolumnami, np. „Podpis”, „Data odebrania”.</li>
</ul>
<h2>13. Dokumenty firmowe [gfxbadge]tylko administrator[/gfxbadge]</h2>
<p>Edytor w wp-admin do prowadzenia dokumentów takich jak ten: gotowe bloki w stylu GastroFlowx (ramki, plakietki, pola do wypełnienia, tabela podpisów, podział strony), eksport do PDF jednym kliknięciem, historia wersji. Można też wgrać gotowy PDF i edytować go w panelu (dopisywanie tekstu, zaczernianie fragmentów).</p>
[gfxpagebreak]
<h2>14. Rejestr Aktywności Użytkowników [gfxbadge]tylko administrator[/gfxbadge]</h2>
<p>Wtyczka bezpieczeństwa i rozliczalności (wp-admin → „Aktywność użytkowników”), niewidoczna dla pracowników. Rejestruje wyłącznie zalogowanych użytkowników.</p>
<table class="gfxdoc-table">
<tr><th style="width:30%;">Co rejestruje</th><th>Szczegóły</th></tr>
<tr><td>Logowania i wylogowania</td><td>Data i godzina, czas trwania sesji. Sesja bez wylogowania jest domykana automatycznie po 12 h bez aktywności. Logowanie kodem QR z karty dostępu nie jest odnotowywane jako logowanie.</td></tr>
<tr><td>Odwiedzane strony</td><td>Adres strony przy każdym pełnym wczytaniu strony panelu (przełączanie zakładek wewnątrz modułów nie jest rejestrowane); znacznik ostatniej aktywności.</td></tr>
<tr><td>Edycje treści</td><td>Zapis wpisów, stron i innych treści WordPress (np. dokumentów firmowych).</td></tr>
<tr><td>Zamówienia WooCommerce</td><td>Zmiany statusów zamówień — tylko jeśli WooCommerce jest aktywne (w GastroFlowx nie jest używane).</td></tr>
<tr><td>Adres IP i lokalizacja</td><td>Adres IP oraz przybliżony kraj i miasto ustalane przez zewnętrzny serwis ip-api.com (wynik zapamiętywany 30 dni dla danego IP).</td></tr>
</table>
<ul>
<li>Widok „Online teraz” (aktywni w ostatnich 5 minutach), filtrowanie dziennika, eksport do CSV.</li>
<li>Ustawienia: włączanie i wyłączanie każdej kategorii, okres przechowywania (domyślnie <strong>90 dni</strong>, czyszczenie codzienne), próg „online”, usuwanie danych przy odinstalowaniu.</li>
<li>Dostęp wyłącznie z uprawnieniem <code>manage_options</code> (administrator).</li>
</ul>
<h2>15. Powiadomienia e-mail i push</h2>
<table class="gfxdoc-table">
<tr><th style="width:22%;">Kanał</th><th>Jak działa</th></tr>
<tr><td>E-mail</td><td>Wysyłany z serwera systemu (funkcja <code>wp_mail</code>) na adres e-mail z konta. Grafik wysyła wiadomości automatycznie (patrz pkt 4); Napiwki — wyłącznie ręcznie, po potwierdzeniu.</td></tr>
<tr><td>Push</td><td>Firebase Cloud Messaging (Google), konfigurowany w Hubie. Trafia tylko na urządzenia, na których pracownik kliknął „Włącz powiadomienia” i zezwolił w przeglądarce. Każda wysyłka jest zapisywana w historii (ostatnie 5000 wpisów).</td></tr>
<tr><td>Kalendarz</td><td>Prywatny kanał iCal grafiku, który pracownik sam może dodać do Kalendarza Google lub iOS.</td></tr>
</table>
<h2>16. Bezpieczeństwo systemu</h2>
<ul>
<li>Połączenie szyfrowane (HTTPS); każde zapytanie do danych wymaga zalogowania i jednorazowego tokenu bezpieczeństwa WordPress (nonce).</li>
<li>Uprawnienia sprawdzane po stronie serwera w każdym module — nie tylko ukrywanie przycisków.</li>
<li>Blokada <code>/wp-admin/</code> i paska administracyjnego dla wszystkich poza administratorem.</li>
<li>Pliki z modułów Pliki i Dokumenty zapisywane pod losowymi nazwami w katalogach zablokowanych przed bezpośrednim dostępem; wydawane tylko po sprawdzeniu uprawnień.</li>
<li>Sprawdzanie typu wgrywanych plików po zawartości, limity rozmiaru.</li>
<li>Rejestr zgód (kto, kiedy, jaka wersja polityki, z jakiego IP) i rejestr aktywności — rozliczalność wymagana przez RODO.</li>
<li>Usunięcie lub wyłączenie wtyczek domyślnie <u>nie kasuje</u> danych (chroni przed przypadkową utratą rozliczeń).</li>
</ul>
[gfxpagebreak]
<h2>17. Role systemowe — zestawienie</h2>
<table class="gfxdoc-table">
<tr><th style="width:26%;">Rola</th><th style="width:30%;">Moduły</th><th>Opis</th></tr>
<tr><td><code>administrator</code></td><td>wszystkie</td><td>Pełny dostęp do panelu i do wp-admin; konfiguracja systemu.</td></tr>
<tr><td><code>kelner</code></td><td>Grafik, Napiwki, Godziny</td><td>Personel sali (sekcja „Sala i bar”).</td></tr>
<tr><td><code>barman</code></td><td>Grafik, Napiwki, Godziny</td><td>Personel baru (sekcja „Sala i bar”).</td></tr>
<tr><td><code>kucharz</code></td><td>Grafik, Napiwki, Godziny</td><td>Personel kuchni (sekcja „Kuchnia”).</td></tr>
<tr><td><code>rs_chef_manager</code></td><td>Grafik</td><td>Szef Kuchni — zarządza grafikiem kuchni.</td></tr>
<tr><td><code>rs_restaurant_manager</code></td><td>Grafik, Pliki</td><td>Menadżer Restauracji — grafik sali i baru; domyślnie zarządza Plikami.</td></tr>
<tr><td><code>rs_schedule_manager</code></td><td>Grafik</td><td>Menadżer Grafiku — pełny dostęp do obu sekcji.</td></tr>
<tr><td><code>manager</code></td><td>Napiwki (+ Menu)</td><td>Manager napiwków — rozlicza kelnerów.</td></tr>
<tr><td><code>admin_bar</code></td><td>Napiwki</td><td>Rozlicza dział baru.</td></tr>
<tr><td><code>admin_kuchnia</code></td><td>Napiwki</td><td>Rozlicza dział kuchni.</td></tr>
<tr><td><code>lunch</code></td><td>Lunch, Kolorowanki</td><td>Menu lunchowe i kolorowanki.</td></tr>
<tr><td><code>menu_manager</code></td><td>Menu</td><td>Menedżer menu — baza dań i karta menu.</td></tr>
</table>
<p class="note">Dostęp do kategorii panelu ustawia się w <code>GastroFlowx → Dostęp ról</code>; dostęp do Plików, Dokumentów i Menu — dodatkowo w ustawieniach tych modułów.</p>
<h2>18. Jakie dane przechowuje każdy moduł</h2>
<table class="gfxdoc-table">
<tr><th style="width:22%;">Moduł</th><th>Dane osobowe pracowników</th></tr>
<tr><td>Hub</td><td>Login, imię i nazwisko, e-mail, hasło (zaszyfrowane), role, tytuł, zdjęcie profilowe, data urodzenia; historia zgód (data, wersja polityki, IP); urządzenia z włączonym push (identyfikator urządzenia, token FCM, ostatnia aktywność); historia wysyłki push (odbiorca, tytuł, status).</td></tr>
<tr><td>Grafik</td><td>Zmiany (dzień, godziny, zamykająca, komentarz), dyspozycyjność z notatką, offy (zakres dat, powód, komentarz, decyzja), zamiany z wiadomością, uprawnienie do zamykania, status nieaktywny, kolejność, klucz kanału iCal.</td></tr>
<tr><td>Napiwki</td><td>Dzienne kwoty z karty, serwisu i gotówki, godziny, wyliczone wypłaty, wyjątki procentowe, rezerwacje (nazwa, przypisany personel, kwota), data urodzenia, data zakończenia pracy, ręczna data wygaśnięcia ulgi.</td></tr>
<tr><td>Godziny</td><td>Godziny rozpoczęcia i zakończenia, liczba godzin, stawka godzinowa i jej nadpisania, notatka, napiwki dnia.</td></tr>
<tr><td>Dokumenty (raporty)</td><td>Kto i kiedy wygenerował raport lub dodał zdjęcie; przy włączonym Archiwum — zdjęcia wydruków kasowych.</td></tr>
<tr><td>Pliki</td><td>Autor dokumentu; treść druków (bez danych osobowych, chyba że ktoś je wpisze).</td></tr>
<tr><td>Karty Dostępu</td><td>Klucz logowania QR; dane drukowane na kartach i listach (imię i nazwisko, login, rola, ewentualnie hasło).</td></tr>
<tr><td>Rejestr Aktywności</td><td>Login, rodzaj zdarzenia, odwiedzony adres lub edytowana treść, czas sesji, adres IP, kraj i miasto, data i godzina; znacznik ostatniej aktywności.</td></tr>
<tr><td>Lunch, Menu, Kolorowanki</td><td>Brak danych osobowych (treści menu, szablony, pliki graficzne).</td></tr>
</table>
<h2>19. Przepływ danych i raport miesięczny</h2>
<ul>
<li><strong>Źródła danych:</strong> pracownicy (własne godziny, dyspozycyjność, wnioski, gotówka) oraz kierownicy zmian, menadżerowie i osoby rozliczające (karta i serwis kelnerów, godziny personelu, grafik), którzy przekazują informacje Administratorowi systemu lub wprowadzają je w ramach przyznanych uprawnień.</li>
<li><strong>Raport miesięczny:</strong> po zakończeniu miesiąca dla każdej sekcji przygotowywany jest raport rozliczenia napiwków. Kwoty w raporcie są <strong>jawne dla wszystkich pracowników danej sekcji</strong> uczestniczących w podziale — zgodnie z §3 „Regulaminu podziału i rozliczania napiwków”.</li>
<li><strong>Pracodawca nie otrzymuje danych z Systemu</strong> i nie ma do niego dostępu. Prowadzi własną, odrębną dokumentację wynagrodzeń i rozliczeń.</li>
</ul>
[gfxnote]Dokument przygotowany na podstawie analizy kodu wtyczek systemu GastroFlowx (wersje: Hub 1.2.1, Activity Tracker 1.0.0, Grafik 1.5.0, Napiwki 1.2.3, Ewidencja Godzin 1.1.0, Lunch 1.0.12, Menu 1.5.3, Kolorowanki 1.0.20, Pliki 1.1.3, Dokumenty 1.0.1, Access Cards 1.4.0, Documents 1.3.0). Instrukcje krok po kroku zawiera dokument „Instrukcje dla pracowników”.[/gfxnote]
GFXDOC_SEED,
		),
		array(
			'title'   => 'Instrukcje dla pracowników',
			'since'   => 2,
			'content' => <<<'GFXDOC_SEED'
<p class="subtitle">System GastroFlowx — Weranda Lunch and Wine — instrukcje krok po kroku według ról</p>
[gfxbox color="blue"]
Przeczytaj rozdział 1 (wszyscy), rozdział 2 (zasady bezpieczeństwa danych) oraz rozdziały dotyczące Twoich ról. Jeśli masz kilka ról (np. Kelner + Manager napiwków), przeczytaj każdą z nich.<br/><br/>
<strong>Wersja dokumentu:</strong> 2.0 &nbsp;|&nbsp; <strong>Stan na:</strong> październik 2026
[/gfxbox]
<h2>Spis treści</h2>
<ol>
<li>Pierwsze kroki — dla wszystkich</li>
<li>Zasady bezpieczeństwa i ochrony danych — dla wszystkich</li>
<li>Kelner</li>
<li>Barman</li>
<li>Kucharz</li>
<li>Szef Kuchni</li>
<li>Menadżer Restauracji / Menadżer Grafiku</li>
<li>Manager napiwków</li>
<li>Admin Bar / Admin Kuchnia</li>
<li>Pracownik Lunchu (Lunch i Kolorowanki)</li>
<li>Menedżer menu (karta dań)</li>
<li>Pliki i Raporty dobowe</li>
<li>Administrator</li>
<li>Najczęstsze problemy</li>
</ol>
[gfxpagebreak]
<h2>1. Pierwsze kroki — dla wszystkich</h2>
<h3>1.1 Logowanie</h3>
<ol class="steps">
<li>Otwórz panel pracownika (adres podany przez administratora lub ikona aplikacji na telefonie) albo zeskanuj kod QR „Logowanie” z Twojej karty dostępu.</li>
<li>Wpisz login i hasło. Opcję „Zapamiętaj mnie” zaznaczaj tylko na <u>własnym</u> telefonie lub komputerze.</li>
<li>Przy pierwszym logowaniu zobaczysz <strong>ekran zgody</strong>. Kliknij „Przeczytaj politykę prywatności”, zapoznaj się z nią i wybierz „Wyrażam zgodę” albo „Nie wyrażam zgody”.</li>
</ol>
[gfxbox color="yellow"]
<strong>Zgoda jest dobrowolna.</strong> Bez niej panel się nie otworzy, ale nie ponosisz żadnych konsekwencji — rozliczenia prowadzone są wtedy tradycyjnie, poza systemem. Zgodę możesz wycofać w każdej chwili w zakładce „Moje konto”.
[/gfxbox]
<h3>1.2 Poruszanie się po panelu</h3>
<ul>
<li>Na komputerze menu jest po lewej stronie, na telefonie — na dole ekranu.</li>
<li>Widzisz tylko zakładki przypisane do Twojej roli. Komunikat „Brak dostępu” oznacza, że rola nie ma uprawnień — to nie błąd. Jeśli potrzebujesz dostępu, napisz do administratora.</li>
<li>Panel możesz zainstalować na telefonie jako aplikację i włączyć powiadomienia — szczegóły w dokumencie „Instalacja aplikacji (PWA) i powiadomienia push”.</li>
</ul>
<h3>1.3 Moje konto</h3>
<ol class="steps">
<li>Sprawdź imię, nazwisko i adres e-mail — na ten adres przychodzą wiadomości o grafiku i rozliczeniach. Popraw je, jeśli są nieaktualne.</li>
<li>Opcjonalnie ustaw zdjęcie profilowe (ikona aparatu przy awatarze).</li>
<li>Opcjonalnie uzupełnij datę urodzenia. Jest potrzebna do poprawnego naliczenia ulgi podatkowej „do 26 lat” w Napiwkach i pojawia się w zakładce „Urodziny” (data i wiek widoczne dla zespołu).</li>
<li>Tu też widzisz datę wyrażenia zgody i przycisk jej wycofania.</li>
</ol>
<h3>1.4 Urodziny</h3>
<p>W zakładce „Urodziny” zobaczysz kalendarz i listę nadchodzących urodzin zespołu. Przyciskiem „+ Dodaj datę” uzupełnisz własną datę, jeśli jej jeszcze nie ma. Jeśli nie chcesz, aby Twoje urodziny były widoczne, nie wpisuj daty lub poproś administratora o jej usunięcie (pamiętaj wtedy o wpływie na ulgę podatkową w Napiwkach).</p>
[gfxpagebreak]
<h2>2. Zasady bezpieczeństwa i ochrony danych — dla wszystkich</h2>
[gfxbox color="red"]
W systemie są dane o zarobkach, godzinach pracy i nieobecnościach Twoich i Twoich współpracowników. Korzystając z systemu, zobowiązujesz się przestrzegać poniższych zasad.
[/gfxbox]
<ol>
<li><strong>Hasło i karta dostępu są osobiste.</strong> Nie udostępniaj ich nikomu i nie loguj się na cudze konto. Kod QR „Logowanie” na karcie działa jak klucz — kto go zeskanuje, wchodzi na Twoje konto bez hasła.</li>
<li><strong>Zgubiona karta lub podejrzenie, że ktoś zna Twoje hasło</strong> — zgłoś to od razu administratorowi (lub na <strong>iod@gastroflowx.pl</strong>). Administrator zmieni hasło i klucz QR.</li>
<li><strong>Wyloguj się</strong> na wspólnych urządzeniach (np. tablet w barze, komputer w biurze) i nie zaznaczaj tam „Zapamiętaj mnie”.</li>
<li><strong>Nie wpisuj informacji o zdrowiu</strong> w powody offów, komentarze, notatki i wiadomości. Wystarczy np. „off”, „urlop”, „sprawy prywatne”. Zwolnienia lekarskie składasz pracodawcy tradycyjnie, poza systemem.</li>
<li><strong>Dane innych osób</strong> (kwoty, godziny, urodziny, grafik) wykorzystuj wyłącznie do pracy. Nie rób zrzutów ekranu z cudzymi danymi i nie przesyłaj ich poza zespół (np. na grupy w komunikatorach).</li>
<li><strong>Wydruki</strong> z danymi (raporty napiwków, listy, grafik) trzymaj tylko w miejscu dostępnym dla pracowników — nie zostawiaj ich na sali — a niepotrzebne niszcz zgodnie z regulaminem napiwków.</li>
<li><strong>Pobrane pliki PDF</strong> z rozliczeniami przechowuj na własnym urządzeniu tylko tak długo, jak są potrzebne.</li>
<li><strong>Błąd w danych</strong> (np. zła kwota, cudze godziny) zgłoś osobie rozliczającej lub administratorowi — nie poprawiaj danych, do których nie masz uprawnień.</li>
<li><strong>Raporty dobowe</strong> — fotografuj tylko wydruki z kasy i terminala, bez osób i bez danych kart płatniczych gości.</li>
</ol>
[gfxpagebreak]
[gfxbox color="party"]
<h2>3. Kelner</h2>
<div class="role-meta">Zakładki: <strong>Strona domowa, Moje konto, Urodziny, Grafik, Napiwki, Godziny</strong> &nbsp;|&nbsp; Twój menadżer grafiku: <strong>Menadżer Restauracji</strong></div>
[/gfxbox]
<h3>3.1 Grafik</h3>
<ol class="steps">
<li><strong>Mój grafik</strong> — sprawdzaj swoje zmiany. Zmiana zamykająca jest oznaczona.</li>
<li><strong>Dyspozycyjność</strong> — gdy menadżer otworzy zgłaszanie (dostaniesz e-mail/push), zaznacz dni i godziny, w których możesz pracować, przed podanym terminem. Po zamknięciu zgłaszania zmiany są zablokowane — w razie potrzeby poproś menadżera o korektę.</li>
<li><strong>Offy</strong> — złóż wniosek o dzień wolny: zakres dat, powód z listy, opcjonalny komentarz. O decyzji dostaniesz powiadomienie.</li>
<li><strong>Zamiana</strong> — wybierz swoją zmianę i osobę, z którą chcesz się zamienić. Najpierw akceptuje kolega, potem menadżer. Do tego czasu obowiązuje dotychczasowy grafik.</li>
<li><strong>Gotowy grafik</strong> — podgląd całej restauracji.</li>
<li><strong>Kalendarz</strong> — dni wolne osób z Twojej sekcji. Twoje wnioski (z powodem i komentarzem) też są tam widoczne dla sekcji — pisz w nich tylko ogólnie.</li>
<li><strong>Synchronizacja</strong> — skopiuj prywatny link i dodaj go do Kalendarza Google/iOS, a grafik będzie się aktualizował w telefonie. Nie udostępniaj tego linku innym osobom. Tu pobierzesz też swój grafik w PDF.</li>
</ol>
<h3>3.2 Napiwki</h3>
<ol class="steps">
<li>Zakładka <strong>Dzień</strong> → wybierz datę → wpisz kwotę gotówki oddanej do baru <u>albo</u> do kuchni. Druga kwota uzupełni się sama.</li>
<li>Kwoty z karty i serwisu wpisuje manager napiwków — Ty widzisz poniżej swoje rozliczenie: karta netto, serwis netto, gotówka 100% i wypłata.</li>
<li>Zakładka <strong>Miesiąc</strong> → „Raport Kelnera” (ogólny lub dzień po dniu) → „Pobierz PDF”.</li>
<li>Gotówkę dla baru i kuchni przekaż fizycznie osobom przyjmującym, zgodnie z regulaminem napiwków.</li>
</ol>
<h3>3.3 Godziny</h3>
<ol class="steps">
<li>Zakładka <strong>Dzień</strong> → godzina rozpoczęcia i zakończenia są już wpisane według Twojej zmiany w Grafiku (oznaczenie „Grafik: …”). Sprawdź je, popraw, jeśli pracowałeś/pracowałaś w innych godzinach (zaokrąglają się do 15 minut), i kliknij „Zapisz dzień”.</li>
<li>Sprawdź podsumowanie: zarobek z godzin, napiwki <strong>brutto</strong> (z rozbiciem na podatek, bar i kuchnię) i <strong>netto</strong> — netto to ta sama kwota, co Twoja wypłata dnia w Napiwkach — oraz łączny zarobek. Jeśli napiwki się nie zgadzają, zgłoś to osobie rozliczającej w Napiwkach.</li>
<li><strong>Kalendarz i podsumowanie</strong> — cały miesiąc w jednym widoku.</li>
</ol>
[gfxbox color="party"]
<h2>4. Barman</h2>
<div class="role-meta">Zakładki: <strong>Strona domowa, Moje konto, Urodziny, Grafik, Napiwki, Godziny</strong> &nbsp;|&nbsp; Twój menadżer grafiku: <strong>Menadżer Restauracji</strong></div>
[/gfxbox]
<ul>
<li><strong>Grafik i Godziny</strong> — tak samo jak Kelner (punkty 3.1 i 3.3).</li>
<li><strong>Napiwki → Dzień</strong> — jeśli w zespole jest osoba z rolą Admin Bar, to ona wpisuje Twoje godziny; Ty widzisz swoją wypłatę dnia. Jeśli nie ma takiej osoby, wpisujesz godziny sam(a).</li>
<li><strong>Napiwki → Miesiąc</strong> — „Raport Wypłat Personelu”: godziny, gotówka, karta, serwis, suma; przycisk PDF.</li>
</ul>
[gfxbox color="party"]
<h2>5. Kucharz</h2>
<div class="role-meta">Zakładki: <strong>Strona domowa, Moje konto, Urodziny, Grafik, Napiwki, Godziny</strong> &nbsp;|&nbsp; Twój menadżer grafiku: <strong>Szef Kuchni</strong></div>
[/gfxbox]
<ul>
<li><strong>Grafik i Godziny</strong> — tak samo jak Kelner (punkty 3.1 i 3.3); wnioski i zamiany akceptuje Szef Kuchni.</li>
<li><strong>Napiwki → Dzień</strong> — godziny kucharzy wpisuje Admin Kuchnia; Ty widzisz godziny (tylko do odczytu) i wypłatę dnia.</li>
<li><strong>Napiwki → Miesiąc</strong> — „Raport Wypłat Personelu” z przyciskiem PDF.</li>
</ul>
[gfxpagebreak]
[gfxbox color="party"]
<h2>6. Szef Kuchni [gfxbadge]rs_chef_manager[/gfxbadge]</h2>
<div class="role-meta">Zarządzasz grafikiem <strong>wyłącznie kuchni</strong>. Sala i bar są dla Ciebie dostępne tylko do podglądu w „Gotowym grafiku”.</div>
[/gfxbox]
<h3>6.1 Zbieranie dyspozycji</h3>
<ol class="steps">
<li>Zakładka <strong>Dyspozycje</strong> → „Otwórz zgłaszanie” → zakres dat, termin i opcjonalna wiadomość. Kucharze dostaną e-mail i push.</li>
<li>Śledź licznik „zgłosiło X z Y” i listę osób bez dyspozycji; użyj „Przypomnij osobom bez dyspozycji”.</li>
<li>Po terminie kliknij „Zamknij zgłaszanie”. W razie potrzeby możesz nadal wpisać dyspozycję w imieniu pracownika lub otworzyć okres ponownie.</li>
</ol>
<h3>6.2 Układanie grafiku</h3>
<ol class="steps">
<li><strong>Ręcznie</strong> — w siatce (kucharze × dni) kliknij komórkę i przypisz zmianę albo użyj „Szablonów zmian”.</li>
<li><strong>Automatycznie</strong> — zakładka „Auto-generowanie” → zakres dat → generuj. Powstaje <u>wersja robocza</u>; system pokaże zestawienie zmian, godzin i zamknięć.</li>
<li>Sprawdź i popraw wersję roboczą. „Wyczyść wygenerowane zmiany” usuwa tylko wersje robocze.</li>
<li>Kliknij „Zatwierdź auto-generowanie” i zdecyduj, czy od razu powiadomić zespół.</li>
<li>Gdy trzeba, użyj wymuszonego przypisania albo znaczników „Nieobecny” / „Dostępny”.</li>
</ol>
[gfxbox color="blue"]Zmianę zamykającą może dostać tylko osoba z „Uprawnieniem do zamykania lokalu” — system tego pilnuje także przy generowaniu automatycznym. Pamiętaj, że to Ty odpowiadasz za ostateczny kształt grafiku: automat tylko proponuje.[/gfxbox]
<h3>6.3 Wnioski, komunikacja, PDF</h3>
<ul>
<li>Akceptuj lub odrzucaj <strong>offy</strong> i <strong>zamiany</strong>; możesz dodać notatkę.</li>
<li>Osobę, która już nie pracuje, oznacz jako <strong>nieaktywną</strong> — zniknie z grafiku, ale historia zostanie zachowana.</li>
<li>„Wyślij e-mail” — wiadomość do zespołu kuchni (lub całej załogi przy uprawnieniu do widoku zbiorczego).</li>
<li>Eksportuj grafik do PDF; wydruk wieszaj tylko na zapleczu.</li>
</ul>
<p class="note">Jeśli masz też rolę Admin Kuchnia — patrz rozdział 9.</p>
[gfxbox color="party"]
<h2>7. Menadżer Restauracji / Menadżer Grafiku [gfxbadge]rs_restaurant_manager / rs_schedule_manager[/gfxbadge]</h2>
<div class="role-meta">Menadżer Restauracji zarządza grafikiem <strong>sali i baru</strong>; Menadżer Grafiku — <strong>całej restauracji</strong>.</div>
[/gfxbox]
<ul>
<li>Wszystkie czynności jak w rozdziale 6, dla kelnerów i barmanów (Menadżer Grafiku — dla wszystkich, łącznie z okresami „cała restauracja”).</li>
<li>Menadżer Restauracji domyślnie zarządza też modułem <strong>Pliki</strong> — patrz rozdział 12.</li>
<li>Jeśli masz rolę manager lub admin_bar — patrz rozdziały 8 i 9.</li>
</ul>
[gfxpagebreak]
[gfxbox color="party"]
<h2>8. Manager napiwków [gfxbadge]manager[/gfxbadge]</h2>
<div class="role-meta">Rozliczasz kelnerów: wprowadzasz karty i serwis, przygotowujesz raporty i wysyłasz rozliczenia.</div>
[/gfxbox]
<h3>8.1 Codziennie — zakładka „Dzień”</h3>
<ol class="steps">
<li>Wybierz datę i dla każdego pracującego kelnera wpisz kwotę z karty i serwisu (na podstawie informacji od kierownika zmiany / wydruków).</li>
<li>Sprawdź kartę „Kelnerzy (Rozliczenie Napiwków)”: karta netto, serwis netto, gotówka 100%, wypłata.</li>
<li>Sprawdź „Podsumowanie dnia dla menagera”; w razie potrzeby pobierz PDF.</li>
<li>Jeśli administrator to włączył — ustaw <strong>wyjątek procentowy</strong> dla kelnera na ten dzień (karta i serwis osobno) zgodnie z §2.2 regulaminu; „Reset” przywraca 10%/20%.</li>
</ol>
<h3>8.2 Co miesiąc</h3>
<ol class="steps">
<li>Zakładka <strong>Miesiąc</strong> → „Raport Managera” i „Podsumowanie Miesiąca”; „Sprawdź pracownika” dla pojedynczej osoby.</li>
<li>Zakładka <strong>E-mail</strong> → wybierz miesiąc → sprawdź szablon → „Wyślij teraz” → potwierdź. Każdy kelner dostanie tylko własne kwoty.</li>
<li>Zakładka <strong>Powiadomienia</strong> — to samo jako push.</li>
<li>Przekaż dane do raportu miesięcznego swojej sekcji — raport z jawnymi kwotami udostępniany jest pracownikom sekcji zgodnie z §3 regulaminu napiwków. Raportu ani innych danych z Systemu nie przekazuje się pracodawcy.</li>
</ol>
[gfxbox color="yellow"]Dane, które wprowadzasz, są poufne. Wykorzystuj je wyłącznie do rozliczeń, nie przekazuj osobom spoza podziału napiwków i nie wysyłaj zrzutów ekranu.[/gfxbox]
[gfxbox color="party"]
<h2>9. Admin Bar / Admin Kuchnia [gfxbadge]admin_bar / admin_kuchnia[/gfxbadge]</h2>
<div class="role-meta">Rozliczasz personel swojego działu. Instrukcja jest wspólna dla obu ról.</div>
[/gfxbox]
<ol class="steps">
<li><strong>Dzień</strong> — wpisz godziny barmanów lub kucharzy (także pomoc kelnera) i gotówkę otrzymaną od kelnerów dla Twojego działu (kwota dla drugiego działu uzupełni się sama).</li>
<li>Sprawdź „Rozliczenie Personelu” i podsumowanie dnia; pobierz PDF, jeśli potrzebujesz.</li>
<li><strong>Rozliczenie serwisu</strong> — podziel pulę serwisu godzinowo albo ręcznie kwotowo. Przy większej rezerwacji przypisz kwotę tylko osobom, które ją obsługiwały (§2.5 regulaminu).</li>
<li><strong>Rozliczenie z kelnerami</strong> — wpisz fizycznie otrzymaną kwotę od każdego kelnera; system pokaże różnicę i status. Rozbieżności wyjaśnij od razu (§4 regulaminu).</li>
<li><strong>Miesiąc</strong> — „Raport Wypłat Personelu”; zakładki <strong>E-mail</strong> i <strong>Powiadomienia</strong> — wysyłka rozliczeń do personelu działu.</li>
</ol>
[gfxpagebreak]
[gfxbox color="party"]
<h2>10. Pracownik Lunchu [gfxbadge]lunch[/gfxbadge]</h2>
<div class="role-meta">Zakładki: <strong>Strona domowa, Moje konto, Urodziny, Lunch, Kolorowanki</strong></div>
[/gfxbox]
<h3>10.1 Menu lunchowe</h3>
<ol class="steps">
<li>Zakładka <strong>Lunch</strong> → uzupełnij szkic: zupa, waga, danie lunchowe, rekomendacja. Pozycje możesz wybierać z bazy produktów (checklisty) i dodawać nowe.</li>
<li>Wybierz szablon i sprawdź podgląd.</li>
<li>Wygeneruj PDF i wydrukuj. Plik trafia do <strong>Archiwum</strong>, skąd można go pobrać ponownie lub usunąć.</li>
<li>Szablony graficzne (pozycje i rozmiary tekstu) zmienia administrator.</li>
</ol>
<h3>10.2 Kolorowanki</h3>
<ol class="steps">
<li>Zakładka <strong>Kolorowanki</strong> → wybierz kategorię lub „Wszystkie”.</li>
<li>Jeden plik: przycisk druku przy pliku. Kilka plików: zaznacz je, ustaw liczbę kopii i użyj druku grupowego — pójdą jednym zadaniem do drukarki.</li>
<li>Nowe pliki wgrywasz przyciskiem wgrywania (wiele naraz) do wybranej kategorii. Wgrywaj tylko materiały, do których restauracja ma prawo (np. darmowe kolorowanki do użytku niekomercyjnego).</li>
</ol>
[gfxbox color="party"]
<h2>11. Menedżer menu [gfxbadge]menu_manager[/gfxbadge]</h2>
<div class="role-meta">Karta dań PL/EN i jej wersje do druku.</div>
[/gfxbox]
<ol class="steps">
<li><strong>Baza dań</strong> — dodaj lub popraw danie: nazwa i opis PL oraz EN, kategoria. Usunięcie dania usuwa je też z zapisanych wersji.</li>
<li><strong>Kreator menu</strong> — zaznacz dania w każdej kategorii, ustaw kolejność strzałkami, wybierz język, szablon („Dwie kolumny” lub „Podzielona strona”) i dodatkowy tekst. Sprawdź podgląd A4.</li>
<li>„Pobierz PDF” albo zapisz jako <strong>Wersję Standardową</strong>.</li>
<li><strong>Wersje</strong> — zaznacz kilka wersji, aby pobrać je w jednym pliku PDF (każda od nowej strony).</li>
<li><strong>Ustawienia PDF</strong> — logo, prowadnice, typografia. „Przywróć domyślne” zmienia tylko formularz — zmiany wchodzą po „Zapisz”.</li>
</ol>
[gfxpagebreak]
<h2>12. Pliki i Raporty dobowe</h2>
<h3>12.1 Pliki — biblioteka druków (wszyscy z dostępem)</h3>
<ol class="steps">
<li>Otwórz moduł Pliki, wybierz kategorię lub użyj wyszukiwarki.</li>
<li>Kliknij dokument, aby zobaczyć podgląd; „Drukuj” drukuje go od razu. Aby zapisać PDF, w oknie drukowania wybierz „Zapisz jako PDF”.</li>
<li>Kilka dokumentów: zaznacz je, ustaw liczbę kopii i wydrukuj grupowo.</li>
</ol>
<p><strong>Osoby zarządzające Plikami</strong> (domyślnie Menadżer Restauracji) mogą dodatkowo: tworzyć dokumenty w edytorze (tabele, listy zadań, pola do wpisania, miejsca na podpis), wgrywać PDF i zdjęcia, ograniczać widoczność dokumentu do wybranych ról, edytować, usuwać i zarządzać kategoriami.</p>
<h3>12.2 Raporty dobowe z utargu (role z dostępem)</h3>
<ol class="steps">
<li>Otwórz moduł raportów dobowych i wybierz dzień w kalendarzu.</li>
<li>Dodaj zdjęcia raportów z kasy fiskalnej i terminala („Zrób zdjęcie” lub z galerii). Zdjęcia rób prosto, ostro i w dobrym świetle.</li>
<li>Ustaw kolejność stron (przeciągnij miniatury; na telefonie — za uchwyt z numerem), w razie potrzeby obróć zdjęcie.</li>
<li>Kliknij „Generuj raport”. PDF pobierze się na urządzenie; możesz go też „Udostępnić” zgodnie z procedurą rozliczania utargu obowiązującą w restauracji.</li>
<li>Jeśli Archiwum jest włączone, raport zapisze się na serwerze i będzie dostępny w zakładce Archiwum.</li>
</ol>
[gfxbox color="yellow"]iPhone: jeśli pojawi się „format HEIC nie jest obsługiwany”, użyj przycisku „Zrób zdjęcie” albo ustaw Ustawienia → Aparat → Formaty → „Najbardziej zgodne”.[/gfxbox]
[gfxpagebreak]
[gfxbox color="party"]
<h2>13. Administrator</h2>
<div class="role-meta">Pełny dostęp do panelu i do wp-admin. Odpowiada za konfigurację, konta, uprawnienia i bezpieczeństwo danych.</div>
[/gfxbox]
<h3>13.1 Nowy pracownik</h3>
<ol class="steps">
<li>Utwórz konto w wp-admin → Użytkownicy (imię, nazwisko, e-mail) i nadaj role zgodne ze stanowiskiem — wyłącznie te, które są potrzebne.</li>
<li>W profilu zaznacz w razie potrzeby „Uprawnienie do zamykania lokalu”.</li>
<li>Przekaż pracownikowi politykę prywatności i instrukcję; po pierwszym logowaniu sprawdź status zgody w <code>GastroFlowx → Lista zgód</code>.</li>
<li>Wygeneruj kartę dostępu (<code>Karty i Listy</code>). Wydruk z hasłem przekaż osobiście; po wydaniu odbierz podpis na liście.</li>
</ol>
<h3>13.2 Odejście pracownika</h3>
<ol class="steps">
<li>W Grafiku oznacz osobę jako nieaktywną; w Napiwkach wpisz „Datę zakończenia pracy”.</li>
<li>Odbierz rolę dostępu do panelu (lub zablokuj konto) i zmień hasło — unieważnia to logowanie hasłem. Klucz QR zmienia się przy wygenerowaniu nowej karty; jeśli karta nie wróciła, wygeneruj ją ponownie, by unieważnić stary kod.</li>
<li>Dane rozliczeniowe przechowuj zgodnie z okresami z polityki prywatności, a potem usuń.</li>
</ol>
<h3>13.3 Konfiguracja</h3>
<ul>
<li><strong>GastroFlowx</strong>: Ustawienia ogólne (w tym link do polityki prywatności), Moduły, Dostęp ról, Tytuły pracowników, Lista zgód, Integracje (Firebase), Powiadomienia push.</li>
<li><strong>Ustawienia → Grafik: e-maile i push</strong>, <strong>Ustawienia → System Napiwków (SPA)</strong>, <strong>Ustawienia → Napiwki: e-maile</strong>, <strong>Ewidencja godzin → Ustawienia</strong>, <strong>Menu (dostęp)</strong>, <strong>Pliki (dokumenty)</strong>, <strong>Dokumenty</strong> (role, Archiwum).</li>
<li>Po istotnej zmianie polityki prywatności zaktualizuj dokument pod linkiem z Ustawień ogólnych, zmień numer wersji (opcja <code>gfx_privacy_policy_version</code>, domyślnie „1.0”) i poinformuj pracowników. Uwaga: system nie prosi automatycznie o ponowną zgodę po zmianie wersji — zapisuje tylko wersję przy kolejnych decyzjach.</li>
</ul>
<h3>13.4 Rejestr aktywności (menu „Aktywność użytkowników”)</h3>
<ul>
<li>Przeglądaj dziennik tylko w celach bezpieczeństwa i wyjaśniania błędów w rozliczeniach (kto i kiedy był zalogowany lub wprowadził zmianę) — nie do oceny pracy.</li>
<li>Ustaw okres przechowywania (domyślnie 90 dni) i wyłącz kategorie, których nie potrzebujesz (np. WooCommerce; rozważ wyłączenie lokalizacji, jeśli wystarczy sam adres IP).</li>
<li>Eksport CSV przechowuj bezpiecznie i usuwaj po wykorzystaniu.</li>
<li>Pamiętaj, że logowanie kodem QR nie trafia do dziennika jako „Logowanie”.</li>
</ul>
<h3>13.5 Ewidencja godzin — połączenie z Grafikiem i Napiwkami</h3>
<ul>
<li>Połączenie jest wbudowane w Ewidencję (wersja 1.1.0+) — wystarczy, że Grafik i Napiwki są aktywne. Stan połączeń widać w zakładce Ustawienia Ewidencji.</li>
<li>„Udział kuchni %” i „Udział baru %” w ustawieniach dotyczą <u>tylko</u> napiwków wpisanych ręcznie — kwoty z Napiwków mają już podatek i udziały policzone według zasad Napiwków.</li>
<li>W sekcji „Zarządzanie” możesz na dany dzień wpisać ręczne godziny podpowiedzi lub ręczne napiwki brutto — zastępują Grafik / Napiwki. Puste pole i „Zapisz” usuwa ręczny wpis.</li>
</ul>
<h3>13.6 Obowiązki związane z danymi</h3>
<ul>
<li>Regularne aktualizacje WordPressa i wtyczek, kopie zapasowe, silne hasło i ograniczenie liczby kont administratora.</li>
<li>Obsługa wniosków pracowników (dostęp, sprostowanie, usunięcie) — w ciągu miesiąca.</li>
<li>Reakcja na naruszenie (np. wyciek, zgubiony telefon z zalogowanym panelem): zablokuj dostęp, oceń ryzyko, w razie potrzeby zgłoś do UODO w ciągu 72 godzin, powiadom osoby, których to dotyczy, i poinformuj pracodawcę o zdarzeniu (bez przekazywania danych).</li>
<li>Nginx: dodaj blokady katalogów <code>/wp-content/uploads/gastroflowx-pliki/</code> i <code>/wp-content/uploads/gfx-dokumenty/</code> (na Apache robią to pliki .htaccess).</li>
</ul>
[gfxpagebreak]
<h2>14. Najczęstsze problemy</h2>
<table class="gfxdoc-table">
<tr><th style="width:38%;">Problem</th><th>Rozwiązanie</th></tr>
<tr><td>Po zalogowaniu widzę tylko ekran zgody</td><td>Zgoda nie została wyrażona albo została wycofana. Przeczytaj politykę i kliknij „Wyrażam zgodę”.</td></tr>
<tr><td>„Brak dostępu” w zakładce</td><td>Twoja rola nie ma uprawnień. Napisz do administratora.</td></tr>
<tr><td>Nie mogę zmienić dyspozycyjności</td><td>Okres zgłaszania jest zamknięty. Poproś menadżera o wpisanie zmiany lub ponowne otwarcie okresu.</td></tr>
<tr><td>Kwoty w Napiwkach się nie zgadzają</td><td>Sprawdź, czy nie obowiązywał wyjątek procentowy; zgłoś rozbieżność osobie rozliczającej.</td></tr>
<tr><td>Nie przychodzą powiadomienia</td><td>Zobacz dokument „Instalacja aplikacji (PWA) i powiadomienia push”, rozdział 5.</td></tr>
<tr><td>Kod QR nie loguje</td><td>Karta mogła zostać unieważniona (nowa karta). Zaloguj się hasłem lub poproś o nową kartę.</td></tr>
<tr><td>Grafik nie aktualizuje się w kalendarzu telefonu</td><td>Kalendarze odświeżają subskrypcje co kilka godzin — to normalne.</td></tr>
</table>
[gfxnote]Dokument uzupełnia „Opis systemu i funkcji GastroFlowx”, „Politykę prywatności systemu GastroFlowx” oraz „Regulamin podziału i rozliczania napiwków”. Pytania dotyczące danych osobowych: iod@gastroflowx.pl.[/gfxnote]
GFXDOC_SEED,
		),
		array(
			'title'   => 'Instalacja aplikacji (PWA) i powiadomienia push',
			'since'   => 1,
			'content' => <<<'GFXDOC_SEED'
<p><em>System GastroFlowx — Weranda Lunch and Wine</em></p><p class="gfxdoc-note">
    Ten dokument tłumaczy krok po kroku, jak zainstalować panel pracownika<br/>
    jako aplikację (PWA) na telefonie i komputerze oraz jak włączyć<br/>
    powiadomienia push (o nowym grafiku, urodzinach i napiwkach).<br/><br/>
    Wersja dokumentu: 1.0  |  Przygotowano dla: Weranda Lunch and Wine (werandalunchwine.pl)
  </p>
[gfxpagebreak]
<h1 style="font-size:18px;">Spis treści</h1>

<ol>
<li>Czym jest ta aplikacja i dlaczego warto ją zainstalować</li>
<li>iPhone / iPad (Safari)</li>
<li>Android (Chrome)</li>
<li>Komputer — Windows / Mac (Chrome, Edge)</li>
<li>Rozwiązywanie problemów</li>
</ol>

[gfxpagebreak]
<h1 style="font-size:18px;">1. Czym jest ta aplikacja i dlaczego warto ją zainstalować</h1>
<p>Panel pracownika GastroFlowx to strona internetowa, którą można „zainstalować” na telefonie lub komputerze jak zwykłą aplikację (tzw. <strong>PWA — Progressive Web App</strong>). Po instalacji:</p>
<ul>
<li>na ekranie głównym telefonu/komputera pojawia się ikona aplikacji (z nazwą i logo restauracji),</li>
<li>aplikacja otwiera się w swoim własnym oknie, bez paska adresu przeglądarki — wygląda i działa jak normalna aplikacja,</li>
<li>możesz otrzymywać <strong>powiadomienia push</strong> — np. o nowym grafiku, nadchodzących urodzinach współpracownika czy rozliczeniu napiwków — nawet gdy aplikacja jest zamknięta.</li>
</ul>
[gfxbox color="blue"]Instalacja jest bezpłatna, nie zajmuje miejsca jak „prawdziwa” aplikacja ze sklepu i nie wymaga konta Google/Apple — to wciąż ta sama strona, tylko wygodniej dostępna.[/gfxbox]
<p>Instrukcja instalacji różni się w zależności od telefonu/komputera — wybierz odpowiednią sekcję poniżej. Powiadomienia push włącza się przyciskiem <span class="btn-pill">🔔 Włącz powiadomienia</span>, który pojawia się automatycznie w prawym dolnym rogu ekranu po zalogowaniu — jeśli jeszcze nie podjąłeś/podjęłaś decyzji o zgodzie na powiadomienia w swojej przeglądarce.</p>
[gfxpagebreak]
[gfxbox color="party"]
<h2>2. iPhone / iPad (Safari) [gfxbadge]iOS[/gfxbadge]</h2>
<div class="platform-meta">Wymagane: <strong>system iOS 16.4 lub nowszy</strong> oraz przeglądarka <strong>Safari</strong> (Chrome na iPhone nie obsługuje instalacji ani powiadomień push).</div>
[/gfxbox]
<h3>2.1 Instalacja aplikacji na ekranie głównym</h3>
<ol class="steps">
<li>Otwórz panel pracownika w przeglądarce <strong>Safari</strong> (nie w Chrome) i zaloguj się.</li>
<li>Dotknij ikony <strong>Udostępnij</strong> (kwadrat ze strzałką skierowaną do góry) w dolnym pasku Safari.</li>
<li>Z listy opcji przewiń w dół i wybierz <strong>„Dodaj do ekranu początkowego”</strong> (Add to Home Screen).</li>
<li>Sprawdź proponowaną nazwę aplikacji (można ją zmienić) i dotknij <strong>„Dodaj”</strong> w prawym górnym rogu.</li>
<li>Na ekranie głównym telefonu pojawi się ikona aplikacji — od teraz uruchamiaj panel, dotykając tej ikony, a nie przez Safari.</li>
</ol>
[gfxbox color="yellow"]<strong>Ważne:</strong> na iPhonie/iPadzie powiadomienia push działają <u>wyłącznie</u> w aplikacji zainstalowanej w ten sposób (ikona na ekranie głównym) — nie zadziałają, jeśli otwierasz stronę tylko w karcie Safari. Krok 2.1 trzeba więc wykonać zawsze przed włączeniem powiadomień.[/gfxbox]
<h3>2.2 Włączanie powiadomień push</h3>
<ol class="steps">
<li>Uruchom aplikację <u>z ikony na ekranie głównym</u> (nie z Safari) i zaloguj się.</li>
<li>W prawym dolnym rogu ekranu powinien pojawić się niebieski przycisk <span class="btn-pill">🔔 Włącz powiadomienia</span>.</li>
<li>Dotknij tego przycisku — dopiero to dotknięcie wywoła systemowe okienko z pytaniem o zgodę (na iOS wymagany jest „prawdziwy” gest dotyku, samo wejście na stronę nie wystarczy).</li>
<li>W systemowym okienku wybierz <strong>„Zezwól”</strong> (Allow).</li>
<li>Jeśli wszystko poszło dobrze, przycisk zniknie — to znak, że zgoda została zapisana. Nie zobaczysz żadnego dodatkowego potwierdzenia na ekranie.</li>
</ol>
[gfxbox color="red"]<strong>Jeśli przycisk „Włącz powiadomienia” się nie pojawia:</strong> najprawdopodobniej już wcześniej podjęto decyzję (zgoda lub odmowa) w tej aplikacji — patrz rozdział 5 „Rozwiązywanie problemów”.[/gfxbox]
[gfxpagebreak]
[gfxbox color="party"]
<h2>3. Android (Chrome) [gfxbadge]Android[/gfxbadge]</h2>
<div class="platform-meta">Wymagane: przeglądarka <strong>Google Chrome</strong> (zalecana, domyślna na większości telefonów Android).</div>
[/gfxbox]
<h3>3.1 Instalacja aplikacji — sposób A (banner/przycisk automatyczny)</h3>
<ol class="steps">
<li>Otwórz panel pracownika w Chrome i zaloguj się.</li>
<li>W lewym dolnym rogu ekranu może się automatycznie pojawić czarny przycisk <span class="btn-pill dark">⬇️ Zainstaluj aplikację</span>.</li>
<li>Dotknij go i potwierdź instalację w wyskakującym okienku Chrome.</li>
</ol>
<h3>3.2 Instalacja aplikacji — sposób B (menu Chrome, zawsze dostępny)</h3>
<ol class="steps">
<li>Otwórz panel pracownika w Chrome i zaloguj się.</li>
<li>Dotknij ikony trzech kropek (⋮) w prawym górnym rogu przeglądarki.</li>
<li>Wybierz <strong>„Zainstaluj aplikację”</strong> lub <strong>„Dodaj do ekranu głównego”</strong> (nazwa zależy od wersji Chrome).</li>
<li>Potwierdź, dotykając <strong>„Zainstaluj”</strong>.</li>
<li>Ikona aplikacji pojawi się na ekranie głównym i/lub w szufladzie aplikacji telefonu.</li>
</ol>
<h3>3.3 Włączanie powiadomień push</h3>
<ol class="steps">
<li>Otwórz aplikację (z ikony lub w Chrome) i zaloguj się.</li>
<li>W prawym dolnym rogu powinien pojawić się przycisk <span class="btn-pill">🔔 Włącz powiadomienia</span>.</li>
<li>Dotknij go i w systemowym oknie wybierz <strong>„Zezwól”</strong> (Allow).</li>
<li>Przycisk zniknie po zapisaniu zgody — powiadomienia są aktywne.</li>
</ol>
<p class="note">Na Androidzie powiadomienia działają zarówno w zainstalowanej aplikacji, jak i w samej karcie Chrome — instalacja nie jest tu obowiązkowa (w przeciwieństwie do iPhone'a), ale ułatwia szybki dostęp do panelu.</p>
[gfxpagebreak]
[gfxbox color="party"]
<h2>4. Komputer — Windows / Mac [gfxbadge]Chrome, Edge[/gfxbadge]</h2>
<div class="platform-meta">Wymagane: przeglądarka <strong>Google Chrome</strong> lub <strong>Microsoft Edge</strong>. Firefox i Safari na komputerze nie wspierają instalacji tej aplikacji.</div>
[/gfxbox]
<h3>4.1 Instalacja aplikacji — sposób A (przycisk automatyczny)</h3>
<ol class="steps">
<li>Otwórz panel pracownika w Chrome/Edge i zaloguj się.</li>
<li>W lewym dolnym rogu okna może się automatycznie pojawić przycisk <span class="btn-pill dark">⬇️ Zainstaluj aplikację</span>.</li>
<li>Kliknij go i potwierdź instalację w wyskakującym okienku.</li>
</ol>
<h3>4.2 Instalacja aplikacji — sposób B (ikona w adresie / menu)</h3>
<ol class="steps">
<li>Otwórz panel pracownika w Chrome/Edge i zaloguj się.</li>
<li>Po prawej stronie pola adresu (URL), szukaj ikony instalacji (w Chrome: mały komputer ze strzałką; w Edge: podobna ikona „Aplikacje”). Kliknij ją.</li>
<li>Jeśli nie widzisz tej ikony, kliknij menu (trzy kropki ⋮ w Chrome lub trzy linie … w Edge) → <strong>„Zainstaluj aplikację”</strong> / <strong>„Aplikacje” → „Zainstaluj tę witrynę jako aplikację”</strong>.</li>
<li>Potwierdź nazwę i kliknij <strong>„Zainstaluj”</strong>.</li>
<li>Aplikacja otworzy się w osobnym oknie i pojawi się jako ikona na pulpicie / w menu Start (Windows) lub w Launchpadzie (Mac), zależnie od ustawień systemu.</li>
</ol>
<h3>4.3 Włączanie powiadomień push</h3>
<ol class="steps">
<li>Otwórz zainstalowaną aplikację (lub stronę w przeglądarce) i zaloguj się.</li>
<li>W prawym dolnym rogu okna powinien pojawić się przycisk <span class="btn-pill">🔔 Włącz powiadomienia</span>.</li>
<li>Kliknij go i w oknie przeglądarki wybierz <strong>„Zezwól”</strong> (Allow) na powiadomienia z tej strony.</li>
<li>Przycisk zniknie po zapisaniu zgody.</li>
</ol>
[gfxpagebreak]
<h1 style="font-size:18px;">5. Rozwiązywanie problemów</h1>
<h3>5.1 Nie widzę przycisku „Włącz powiadomienia”</h3>
<p>Przycisk pojawia się tylko wtedy, gdy Twoja przeglądarka jeszcze <u>nie podjęła decyzji</u> o powiadomieniach dla tej strony (status „jeszcze nie pytano”). Jeśli już wcześniej kliknięto „Zezwól” albo „Blokuj” (nawet przez przypadek), przycisk się nie pojawi, bo decyzja jest już zapisana w przeglądarce.</p>
<table class="gfxdoc-table">
<tr><th>Sytuacja</th><th>Co zrobić</th></tr>
<tr><td>Zgoda była już udzielona (przycisk zniknął, ale powiadomienia nie przychodzą)</td><td>Sprawdź w Ustawieniach telefonu/komputera, czy powiadomienia dla przeglądarki/aplikacji są włączone systemowo (patrz 5.3). Zgoda w przeglądarce to jeden warunek — trzeba też mieć włączone powiadomienia w systemie.</td></tr>
<tr><td>Zgoda była wcześniej odmówiona („Blokuj”)</td><td>Trzeba ręcznie zmienić uprawnienia strony w przeglądarce — patrz punkt 5.2.</td></tr>
</table>
<h3>5.2 Cofnięcie odmowy — jak ponownie zezwolić na powiadomienia</h3>
<table class="gfxdoc-table">
<tr><th>Przeglądarka / system</th><th>Jak zresetować uprawnienia strony</th></tr>
<tr><td>Safari (iPhone/iPad)</td><td>Ustawienia systemowe → Safari → Ustawienia dla witryn → Powiadomienia, znajdź adres panelu i zmień na „Zezwól”. Jeśli korzystasz z zainstalowanej aplikacji, czasem konieczne jest jej usunięcie z ekranu głównego i ponowna instalacja (patrz 2.1).</td></tr>
<tr><td>Chrome (Android)</td><td>Kliknij ikonę „kłódki” lub „i” po lewej stronie adresu → Uprawnienia/Powiadomienia → zmień na „Zezwól”. Odśwież stronę.</td></tr>
<tr><td>Chrome / Edge (komputer)</td><td>Kliknij ikonę kłódki po lewej stronie adresu URL → Ustawienia witryny → Powiadomienia → zmień na „Zezwól”. Odśwież stronę.</td></tr>
</table>
<p class="note">Po zmianie ustawień odśwież stronę/aplikację i zaloguj się ponownie — przycisk włączania powiadomień powinien albo automatycznie zapisać zgodę, albo pojawić się jeszcze raz.</p>
<h3>5.3 Powiadomienia systemowe są wyłączone</h3>
<p>Nawet po zgodzie w przeglądarce, telefon/komputer może blokować powiadomienia na poziomie systemu:</p>
<ul>
<li><strong>iPhone/iPad:</strong> Ustawienia → Powiadomienia → znajdź zainstalowaną aplikację (lub Safari) → włącz „Zezwól na powiadomienia”.</li>
<li><strong>Android:</strong> Ustawienia → Aplikacje → Chrome (lub zainstalowana aplikacja) → Powiadomienia → włącz.</li>
<li><strong>Windows:</strong> Ustawienia → System → Powiadomienia → sprawdź, czy Chrome/Edge ma włączone powiadomienia.</li>
<li><strong>Mac:</strong> Ustawienia systemowe → Powiadomienia → sprawdź wpis dla Chrome/Edge/aplikacji.</li>
</ul>
<h3>5.4 Nie widzę przycisku „Zainstaluj aplikację”</h3>
<ul>
<li>Upewnij się, że korzystasz z Chrome lub Edge (Android/komputer) albo Safari (iPhone/iPad) — inne przeglądarki nie wspierają instalacji.</li>
<li>Na iPhonie/iPadzie przycisk instalacji <u>nigdy się nie pojawia</u> — to normalne. Instalację robi się wyłącznie przez Udostępnij → Dodaj do ekranu początkowego (patrz punkt 2.1).</li>
<li>Na Androidzie/komputerze użyj metody z menu przeglądarki (punkt 3.2 lub 4.2), jeśli automatyczny przycisk się nie pojawił — bywa on czasem pomijany przez przeglądarkę, np. jeśli strona była już wcześniej odwiedzana bez instalacji.</li>
</ul>
<h3>5.5 Zmieniłem/-am telefon albo wyczyściłem historię przeglądarki</h3>
<p>Zgoda na powiadomienia jest zapisana per przeglądarka/urządzenie. Po zmianie telefonu, reinstalacji aplikacji albo wyczyszczeniu danych przeglądarki trzeba powtórzyć instalację (rozdziały 2–4) i ponownie kliknąć „Włącz powiadomienia”.</p>
<h3>5.6 Nic nie pomaga</h3>
<p>Skontaktuj się z administratorem systemu — może sprawdzić w panelu <code>GastroFlowx → Powiadomienia push → Zgody użytkowników</code>, czy Twoje urządzenie zostało prawidłowo zarejestrowane, oraz w <code>Historii wysyłki</code>, czy poprzednie powiadomienia próbowały do Ciebie dotrzeć.</p>
[gfxnote]Dokument uzupełnia „Opis funkcji systemu GastroFlowx” i „Instrukcje dla pracowników — podział na role”. Wygląd przycisków (kolor, tekst) może się nieznacznie różnić w zależności od wersji przeglądarki, ale ich działanie opisane powyżej pozostaje takie samo.[/gfxnote]
GFXDOC_SEED,
		),
		array(
			'title'   => 'Polityka prywatności systemu GastroFlowx',
			'since'   => 2,
			'content' => <<<'GFXDOC_SEED'
<p class="subtitle">Informacja o przetwarzaniu danych osobowych użytkowników panelu pracownika GastroFlowx (art. 13 RODO)</p>[gfxbadge]Zgodna z RODO (UE) 2016/679[/gfxbadge]
[gfxbox color="fill"]
<strong>Dane administratora — uzupełnij przed opublikowaniem:</strong>
<table class="gfxdoc-table" style="margin-top:8px;">
<tr><td style="width:38%;"><strong>Imię i nazwisko / nazwa działalności</strong></td><td>[gfxfillin]</td></tr>
<tr><td><strong>Adres</strong></td><td>[gfxfillin]</td></tr>
<tr><td><strong>NIP / REGON (jeśli dotyczy)</strong></td><td>[gfxfillin]</td></tr>
<tr><td><strong>Adres e-mail kontaktowy</strong></td><td>[gfxfillin]</td></tr>
<tr><td><strong>Adres panelu pracownika</strong></td><td>[gfxfillin]</td></tr>
</table>
[/gfxbox]
<h2>1. Kto jest administratorem danych</h2>
<p>Administratorem danych osobowych przetwarzanych w systemie GastroFlowx (dalej: <strong>„System”</strong>) jest osoba wskazana powyżej (dalej: <strong>„Administrator”</strong>) — twórca Systemu i osoba nim zarządzająca.</p>
[gfxbox color="yellow"]
<strong>Charakter Systemu.</strong> System został stworzony i jest utrzymywany przez Administratora na serwerze wynajmowanym przez niego u dostawcy hostingu <strong>SeoHost.pl</strong>. Administrator jest pracownikiem restauracji Weranda Lunch and Wine. Pracodawca wyraża zgodę na korzystanie z Systemu przez pracowników, ale <u>nie jest administratorem danych w Systemie</u> — nie ma dostępu technicznego do Systemu i nie decyduje o sposobach przetwarzania. <strong>Pracodawca nie otrzymuje żadnych danych z Systemu</strong> i prowadzi własną, odrębną dokumentację wynagrodzeń i rozliczeń wymaganą przepisami. Zasady współpracy określa pisemne <em>„Porozumienie z pracodawcą w sprawie korzystania z systemu GastroFlowx i przekazywania danych”</em>.
[/gfxbox]
[gfxbox color="blue"]
<strong>Kontakt w sprawach danych osobowych i zgłaszania naruszeń:</strong> <strong>iod@gastroflowx.pl</strong>
[/gfxbox]
<p class="note">Administrator nie ma obowiązku wyznaczenia Inspektora Ochrony Danych (art. 37 RODO). Powyższy adres jest punktem kontaktowym dla wszystkich pytań, wniosków i zgłoszeń.</p>
<h2>2. Kogo dotyczy polityka</h2>
<p>Polityka dotyczy osób, które mają konto w Systemie: pracowników restauracji (sala, bar, kuchnia, lunch) oraz osób zarządzających. System jest dostępny wyłącznie po zalogowaniu i <u>nie zbiera danych gości restauracji</u> ani osób odwiedzających publiczną stronę internetową — z wyjątkiem nazwy rezerwacji, którą osoba rozliczająca może wpisać przy podziale napiwków z większej rezerwacji (zaleca się nazwę opisową, np. „Wesele 12.10”, zamiast imienia i nazwiska gościa).</p>
<h2>3. Dobrowolność</h2>
<p>Korzystanie z Systemu jest <strong>dobrowolne</strong>. Nikt nie jest zobowiązany do korzystania z Systemu w celu wykonywania obowiązków pracowniczych. Odmowa lub wycofanie zgody nie powoduje żadnych negatywnych konsekwencji — grafik, rozliczenia i godziny są wtedy ustalane tradycyjnymi metodami stosowanymi przez pracodawcę.</p>
<h2>4. Jakie dane przetwarzamy</h2>
<table class="gfxdoc-table">
<tr><th style="width:24%;">Obszar</th><th>Dane</th><th style="width:22%;">Skąd pochodzą</th></tr>
<tr><td>Konto</td><td>Login, imię i nazwisko, adres e-mail, hasło (przechowywane wyłącznie w postaci zaszyfrowanej), role i tytuł stanowiska, zdjęcie profilowe (opcjonalne).</td><td>Administrator przy zakładaniu konta; Ty w „Moje konto”.</td></tr>
<tr><td>Data urodzenia (opcjonalna)</td><td>Data urodzenia, wyliczony wiek, data wygaśnięcia ulgi podatkowej „do 26 lat” (także ręcznie nadpisana).</td><td>Ty lub Administrator.</td></tr>
<tr><td>Zgody</td><td>Historia wyrażenia i wycofania zgody: data i godzina, wersja polityki, adres IP.</td><td>System, przy Twojej decyzji.</td></tr>
<tr><td>Grafik</td><td>Zmiany (dzień, godziny, zmiana zamykająca, komentarz), dyspozycyjność i notatki, wnioski o dni wolne (daty, powód z listy, komentarz, decyzja i notatka menadżera), prośby o zamianę, uprawnienie do zamykania lokalu, status „nieaktywny”, prywatny klucz kanału kalendarza (iCal).</td><td>Ty, menadżerowie, algorytm generowania (wersje robocze).</td></tr>
<tr><td>Napiwki</td><td>Dzienne kwoty napiwków z karty, serwisu i gotówki, przepracowane godziny, wyliczone pule i wypłaty, wyjątki procentowe, przypisanie do rezerwacji, kwoty fizycznie przekazane i różnice, data zakończenia pracy.</td><td>Ty, manager napiwków, osoby rozliczające bar i kuchnię.</td></tr>
<tr><td>Ewidencja godzin</td><td>Godziny rozpoczęcia i zakończenia pracy, liczba godzin, stawka godzinowa i jej nadpisania, notatki, napiwki dnia, wyliczony zarobek.</td><td>Ty i osoba zarządzająca ewidencją; godziny rozpoczęcia i zakończenia podpowiadane z Grafiku, napiwki dnia (brutto i netto) pobierane z modułu Napiwków.</td></tr>
<tr><td>Raporty dobowe</td><td>Kto i kiedy wygenerował raport lub dodał zdjęcie; przy włączonym Archiwum — zdjęcia wydruków z kasy i terminala (mogą zawierać np. nazwę kasjera).</td><td>Osoba tworząca raport.</td></tr>
<tr><td>Pliki i dokumenty</td><td>Autor dokumentu w bibliotece druków.</td><td>System.</td></tr>
<tr><td>Powiadomienia</td><td>Urządzenia z włączonymi powiadomieniami push (identyfikator urządzenia, token Firebase, data ostatniej aktywności), historia wysyłki (odbiorca, tytuł, status, ewentualny błąd), treść wysłanych e-maili.</td><td>Twoje urządzenie, System.</td></tr>
<tr><td>Karty dostępu</td><td>Indywidualny klucz logowania kodem QR; dane drukowane na karcie i liście (imię i nazwisko, login, rola, ewentualnie nowe hasło).</td><td>Administrator.</td></tr>
<tr><td>Rejestr aktywności</td><td>Logowania i wylogowania (data, godzina, czas trwania sesji), adresy odwiedzanych stron panelu (przy pełnym wczytaniu strony), edycje treści WordPress, czas ostatniej aktywności, adres IP oraz przybliżony kraj i miasto ustalone na podstawie IP.</td><td>System, automatycznie.</td></tr>
<tr><td>Dane techniczne</td><td>Adres IP i informacje o przeglądarce w standardowych dziennikach serwera hostingu; pliki cookie logowania WordPress.</td><td>Twoje urządzenie.</td></tr>
</table>
[gfxbox color="red"]
<strong>System nie służy do przetwarzania danych o zdrowiu ani innych danych szczególnych kategorii (art. 9 RODO).</strong> Prosimy nie wpisywać takich informacji w powodach dni wolnych, komentarzach, notatkach i wiadomościach. Zwolnienia lekarskie i inne dokumenty kadrowe składa się bezpośrednio pracodawcy, poza Systemem. Dane takie wpisane przez pomyłkę zostaną usunięte.
[/gfxbox]
<p>System <u>nie korzysta z GPS</u> ani precyzyjnej lokalizacji urządzenia (lokalizacja w rejestrze aktywności to tylko orientacyjne miasto przypisane do adresu IP), nie służy do oceny pracy i nie podejmuje wobec Ciebie decyzji opartych wyłącznie na zautomatyzowanym przetwarzaniu (art. 22 RODO). Automatycznie wygenerowany grafik jest tylko propozycją (wersją roboczą), którą zatwierdza menadżer; kwoty napiwków wylicza się według zasad regulaminu napiwków, a każde rozliczenie można zweryfikować i zakwestionować.</p>
[gfxpagebreak]
<h2>5. Cele i podstawy prawne</h2>
<table class="gfxdoc-table">
<tr><th style="width:44%;">Cel</th><th>Podstawa prawna</th></tr>
<tr><td>Prowadzenie konta i udostępnianie funkcji Systemu (grafik, napiwki, godziny, druki, menu, raporty dobowe, powiadomienia)</td><td>Zgoda — art. 6 ust. 1 lit. a RODO, wyrażana na ekranie zgody przy pierwszym logowaniu.</td></tr>
<tr><td>Data urodzenia: ulga podatkowa w wyliczeniach napiwków i moduł Urodziny</td><td>Zgoda — art. 6 ust. 1 lit. a RODO (podanie daty jest opcjonalne).</td></tr>
<tr><td>Powiadomienia push na danym urządzeniu</td><td>Zgoda — art. 6 ust. 1 lit. a RODO, wyrażana osobno przyciskiem „Włącz powiadomienia” i w przeglądarce.</td></tr>
<tr><td>Bezpieczeństwo Systemu, rozliczalność wpisów (kto i kiedy wprowadził dane), wykazanie zgód, obsługa wniosków i naruszeń</td><td>Prawnie uzasadniony interes Administratora — art. 6 ust. 1 lit. f RODO.</td></tr>
<tr><td>Ustalenie, dochodzenie lub obrona roszczeń związanych z rozliczeniami</td><td>Prawnie uzasadniony interes — art. 6 ust. 1 lit. f RODO.</td></tr>
<tr><td>Rejestr aktywności: wykrywanie nieuprawnionego dostępu i nadużyć, ustalenie, kto i kiedy wprowadził lub zmienił dane mające wpływ na rozliczenia</td><td>Prawnie uzasadniony interes Administratora — art. 6 ust. 1 lit. f RODO. Masz prawo sprzeciwu (pkt 9).</td></tr>
<tr><td>Przyjmowanie od kierowników zmian, menadżerów i osób rozliczających informacji o napiwkach, godzinach i grafiku osób z ich zespołu</td><td>Zgoda Użytkownika (art. 6 ust. 1 lit. a) oraz prawnie uzasadniony interes w rzetelnym rozliczeniu (art. 6 ust. 1 lit. f RODO).</td></tr>
<tr><td>Miesięczny raport rozliczenia napiwków udostępniany pracownikom danej sekcji</td><td>Prawnie uzasadniony interes uczestników podziału w możliwości weryfikacji rozliczenia — art. 6 ust. 1 lit. f RODO, na zasadach §3 regulaminu napiwków.</td></tr>
</table>
<p>Zgodę można wycofać w każdej chwili w zakładce „Moje konto” lub pisząc na iod@gastroflowx.pl. Wycofanie zgody nie wpływa na zgodność z prawem przetwarzania, którego dokonano przed jej wycofaniem. Po wycofaniu zgody dostęp do panelu zostaje zablokowany; dane przechowywane na podstawie prawnie uzasadnionego interesu (pkt 8) pozostają do końca okresu przechowywania.</p>
[gfxbox color="muted"]Ponieważ Administratorem jest osoba fizyczna, a nie pracodawca, przepisy Kodeksu pracy o monitoringu pracowników (art. 22<sup>2</sup>–22<sup>3</sup>) nie mają tu bezpośredniego zastosowania. System nie służy do kontroli pracy ani oceny pracowników.[/gfxbox]
<h2>6. Kto widzi Twoje dane w Systemie</h2>
<table class="gfxdoc-table">
<tr><th style="width:32%;">Kto</th><th>Co widzi</th></tr>
<tr><td>Ty</td><td>Własne konto, własne zmiany, wnioski, godziny, rozliczenia napiwków i raporty.</td></tr>
<tr><td>Cały zespół z dostępem do modułu</td><td>Opublikowany grafik całej restauracji („Gotowy grafik”), urodziny z wiekiem (jeśli podałeś datę), imię, nazwisko, tytuł i zdjęcie profilowe.</td></tr>
<tr><td>Pracownicy Twojej sekcji grafiku (kuchnia albo sala i bar)</td><td>Kalendarz offów sekcji: Twoje wnioski o dni wolne z datami, rodzajem i statusem; technicznie także wpisany powód i komentarz — dlatego wpisuj w nich tylko ogólne informacje.</td></tr>
<tr><td>Menadżer Twojej sekcji grafiku</td><td>Twoją dyspozycyjność, wnioski z powodami, zamiany, zmiany w sekcji.</td></tr>
<tr><td>Manager napiwków, Admin Bar / Admin Kuchnia</td><td>Kwoty napiwków, godziny i wypłaty osób, które rozliczają (w zakresie swojego działu).</td></tr>
<tr><td>Pracownicy Twojej sekcji uczestniczący w podziale napiwków</td><td>Raport miesięczny sekcji: imię i nazwisko oraz <strong>jawne kwoty</strong> napiwków (karta, serwis, kwoty dla baru i kuchni, suma) — na zasadach §3 „Regulaminu podziału i rozliczania napiwków”.</td></tr>
<tr><td>Osoba zarządzająca ewidencją godzin</td><td>Godziny, stawki i zarobek wszystkich osób.</td></tr>
<tr><td>Administrator</td><td>Wszystkie dane, w tym rejestr aktywności (wyłącznie Administrator) — wyłącznie w zakresie potrzebnym do utrzymania Systemu, pomocy użytkownikom i realizacji celów z pkt 5.</td></tr>
</table>
<p><strong>Skąd Administrator ma dane o Tobie:</strong> poza tym, co wpisujesz sam, informacje o kwotach napiwków, godzinach pracy i grafiku przekazują Administratorowi lub wprowadzają do Systemu kierownicy zmian, menadżerowie, manager napiwków oraz osoby rozliczające bar i kuchnię — w zakresie potrzebnym do rozliczenia.</p>
<p>Osoby mające w Systemie uprawnienia do danych innych pracowników działają na podstawie upoważnienia Administratora i są zobowiązane do zachowania poufności.</p>
<h2>7. Odbiorcy danych</h2>
<ul>
<li><strong>SeoHost.pl</strong> — dostawca hostingu i poczty serwera; przechowuje dane jako podmiot przetwarzający (art. 28 RODO) na podstawie umowy powierzenia.</li>
<li><strong>Google (Firebase Cloud Messaging)</strong> — doręcza powiadomienia push. Otrzymuje token urządzenia oraz tytuł i treść powiadomienia (np. informację o nowym grafiku, a przy powiadomieniach z Napiwków — kwoty rozliczenia). Google może przetwarzać dane poza Europejskim Obszarem Gospodarczym na podstawie decyzji stwierdzającej odpowiedni stopień ochrony (EU-U.S. Data Privacy Framework) lub standardowych klauzul umownych. Jeśli nie chcesz przekazywania tych danych, nie włączaj powiadomień push — e-mail działa niezależnie.</li>
<li><strong>Dostawcy bibliotek (CDN)</strong> — unpkg.com, cdnjs.cloudflare.com, cdn.jsdelivr.net: przy otwieraniu niektórych modułów przeglądarka pobiera z nich skrypty i czcionki, przez co otrzymują one Twój adres IP i informacje o przeglądarce (bez danych z Systemu).</li>
<li><strong>Twój kalendarz</strong> (Google, Apple) — tylko jeśli sam zasubskrybujesz grafik przez link iCal; wtedy dostawca kalendarza pobiera Twoje zmiany.</li>
<li><strong>ip-api.com</strong> — zewnętrzny serwis geolokalizacji, do którego rejestr aktywności wysyła adres IP (bez innych danych) w celu ustalenia kraju i miasta. Połączenie z tym serwisem nie jest szyfrowane (HTTP). Administrator może wyłączyć tę funkcję w ustawieniach rejestru.</li>
<li><strong>Pracodawca nie jest odbiorcą danych.</strong> Administrator nie przekazuje pracodawcy żadnych danych z Systemu — ani rozliczeń, ani godzin, ani rejestru aktywności. Pracodawca nie ma dostępu do Systemu.</li>
<li><strong>Organy publiczne</strong> — tylko gdy wymagają tego przepisy prawa.</li>
</ul>
<p class="note">Wcześniejsze wersje modułu Grafiku mogły korzystać z usługi OneSignal do powiadomień. Przy aktywnym panelu GastroFlowx z Firebase ten kanał jest wyłączony.</p>
[gfxpagebreak]
<h2>8. Jak długo przechowujemy dane</h2>
<table class="gfxdoc-table">
<tr><th style="width:36%;">Dane</th><th>Okres</th></tr>
<tr><td>Konto, zdjęcie, data urodzenia</td><td>Do wycofania zgody lub zakończenia korzystania z Systemu (np. odejścia z pracy); potem konto jest usuwane lub anonimizowane w ciągu [gfxfillin] dni.</td></tr>
<tr><td>Rozliczenia napiwków, ewidencja godzin, grafik</td><td>Przez czas korzystania z Systemu, a następnie nie dłużej niż [gfxfillin] lat od końca roku, którego dotyczą (zalecane: 3 lata — okres przedawnienia roszczeń ze stosunku pracy).</td></tr>
<tr><td>Historia zgód</td><td>Przez czas przetwarzania danych i okres przedawnienia ewentualnych roszczeń (do 6 lat) — w celu wykazania zgodności z RODO.</td></tr>
<tr><td>Tokeny urządzeń push</td><td>Do wyłączenia powiadomień, wycofania zgody lub usunięcia konta.</td></tr>
<tr><td>Historia wysyłki push</td><td>Ostatnie 5000 wpisów — starsze są usuwane automatycznie.</td></tr>
<tr><td>Raporty dobowe (Archiwum)</td><td>Archiwum domyślnie wyłączone (zdjęcia nie trafiają na serwer). Jeśli włączone — do usunięcia przez osobę zarządzającą, nie dłużej niż [gfxfillin] miesięcy.</td></tr>
<tr><td>Rejestr aktywności</td><td>[gfxfillin width="60"] dni (ustawienie domyślne: 90 dni), potem automatyczne usunięcie; wynik geolokalizacji adresu IP — 30 dni.</td></tr>
<tr><td>Raport miesięczny sekcji (wydruk)</td><td>Zgodnie z §3 regulaminu napiwków — zniszczenie po okresie wskazanym w regulaminie.</td></tr>
<tr><td>Dzienniki serwera hostingu</td><td>Zgodnie z polityką SeoHost.pl.</td></tr>
</table>
<p class="note">Wyłączenie lub usunięcie wtyczek nie kasuje danych automatycznie (ochrona przed przypadkową utratą rozliczeń). Po upływie okresów przechowywania Administrator usuwa dane ręcznie lub poprzez opcje czyszczenia w modułach.</p>
<h2>9. Twoje prawa</h2>
<ul>
<li><strong>dostęp</strong> do danych i otrzymanie ich kopii (art. 15),</li>
<li><strong>sprostowanie</strong> nieprawidłowych danych (art. 16) — dane konta poprawisz sam w „Moje konto”,</li>
<li><strong>usunięcie</strong> danych (art. 17),</li>
<li><strong>ograniczenie przetwarzania</strong> (art. 18),</li>
<li><strong>przenoszenie danych</strong> przetwarzanych na podstawie zgody (art. 20),</li>
<li><strong>sprzeciw</strong> wobec przetwarzania na podstawie prawnie uzasadnionego interesu (art. 21),</li>
<li><strong>wycofanie zgody</strong> w dowolnym momencie,</li>
<li><strong>skarga do Prezesa Urzędu Ochrony Danych Osobowych</strong> (ul. Stawki 2, 00-193 Warszawa).</li>
</ul>
<p>Wnioski kieruj na <strong>iod@gastroflowx.pl</strong>. Odpowiedź otrzymasz bez zbędnej zwłoki, nie później niż w ciągu miesiąca.</p>
<h2>10. Bezpieczeństwo</h2>
<ul>
<li>Szyfrowane połączenie HTTPS, hasła przechowywane w postaci zaszyfrowanej (hash).</li>
<li>Dostęp oparty na rolach, sprawdzany po stronie serwera w każdym module; podział grafiku na sekcje kuchni oraz sali i baru.</li>
<li>Blokada panelu technicznego WordPress dla wszystkich poza Administratorem.</li>
<li>Pliki zapisywane pod losowymi nazwami w katalogach bez bezpośredniego dostępu, wydawane po sprawdzeniu uprawnień.</li>
<li>Rejestr zgód, rejestr aktywności (dostępny tylko dla Administratora) i historia wysyłki powiadomień.</li>
<li>Kod QR logowania i prywatny link do kalendarza działają jak klucze — prosimy chronić je jak hasło. Utratę karty lub podejrzenie nieuprawnionego dostępu zgłoś natychmiast na iod@gastroflowx.pl.</li>
</ul>
<p>W razie naruszenia ochrony danych Administrator zgłasza je Prezesowi UODO w ciągu 72 godzin (gdy jest to wymagane), informuje osoby, których dotyczy wysokie ryzyko, oraz pracodawcę.</p>
<h2>11. Pliki cookie i pamięć urządzenia</h2>
<p>System używa wyłącznie niezbędnych plików cookie WordPress (utrzymanie sesji logowania, opcja „Zapamiętaj mnie”) oraz pamięci przeglądarki do działania aplikacji PWA i powiadomień (service worker, identyfikator urządzenia). Nie używamy plików cookie reklamowych ani analitycznych.</p>
<h2>12. Zmiany polityki</h2>
<p>O istotnych zmianach polityki użytkownicy zostaną poinformowani w panelu lub e-mailem przed ich wejściem w życie. Aktualna wersja jest zawsze dostępna pod linkiem na ekranie zgody i w „Moje konto”.</p>
[gfxbox color="fill"]
<table class="gfxdoc-table">
<tr><td style="width:50%;"><strong>Data wejścia w życie</strong></td><td>[gfxfillin]</td></tr>
<tr><td><strong>Wersja dokumentu</strong></td><td>3.1</td></tr>
</table>
[/gfxbox]
[gfxnote]Dokument przygotowany na podstawie analizy funkcji Systemu. Ma charakter pomocniczy i nie zastępuje porady prawnej — przed publikacją zaleca się konsultację z prawnikiem lub specjalistą ochrony danych osobowych.[/gfxnote]
GFXDOC_SEED,
		),
		array(
			'title'   => 'Regulamin podziału i rozliczania napiwków',
			'since'   => 1,
			'content' => <<<'GFXDOC_SEED'
<p class="subtitle">Restauracja Weranda Lunch and Wine</p>
[gfxbox color="yellow"]
<strong>To jest wzór do wypełnienia i dostosowania.</strong> Regulamin dotyczy podziału pieniędzy między pracownikami oraz ujawniania danych o ich zarobkach z napiwków — zalecana jest konsultacja z prawnikiem przed wprowadzeniem, szczególnie w zakresie zgodności z RODO i prawem pracy.
[/gfxbox]
[gfxbox color="party"]
<h3>Wydane przez</h3>
<table class="gfxdoc-table">
<tr><td style="width:35%;">Nazwa / imię i nazwisko (Pracodawca)</td><td>[gfxfillin]</td></tr>
<tr><td>Data wejścia w życie</td><td>[gfxfillin]</td></tr>
</table>
[/gfxbox]
<h2>§1. Cel regulaminu</h2>
<ol>
<li>Regulamin określa zasady podziału napiwków między pracowników sali (kelnerzy, barmani) i kuchni (kucharze) w restauracji Weranda Lunch and Wine, sposób ich rozliczania oraz zasady informowania zespołu o wynikach tego rozliczenia.</li>
<li>Celem regulaminu jest zapewnienie <strong>przejrzystego, sprawiedliwego i weryfikowalnego</strong> podziału napiwków oraz jasne poinformowanie pracowników o tym, jakie dane o ich zarobkach z napiwków są i będą ujawniane pozostałym członkom zespołu.</li>
</ol>
<h2>§2. Zasady podziału napiwków</h2>
<h3 style="color:#1D4ED8; font-size:12px; margin-top:10px;">2.1 Zasada standardowa</h3>
<ol>
<li>Każdy kelner odprowadza z uzyskanych napiwków — <strong>osobno z karty i osobno z serwisu</strong> — standardowo: <strong>10%</strong> do puli baru oraz <strong>20%</strong> do puli kuchni.</li>
<li>Pula baru i pula kuchni są dzielone między pracowników danego działu proporcjonalnie do przepracowanych godzin, chyba że rozliczający dział ustali inny sposób podziału (rozliczenie kwotowe) dla danego dnia.</li>
</ol>
<h3 style="color:#1D4ED8; font-size:12px; margin-top:10px;">2.2 Wyjątki procentowe na konkretny dzień i konkretną osobę</h3>
[gfxbox color="green"]
Standardowe wartości 10%/20% mogą zostać zmienione <strong>dla wybranego kelnera, tylko na jeden konkretny dzień</strong> — niezależnie dla karty, niezależnie dla serwisu i niezależnie dla gotówki. Każde z tych trzech źródeł napiwków jest rozliczane <u>całkowicie odrębnie</u> — wyjątek ustalony dla jednego źródła nie wpływa automatycznie na pozostałe. Następny dzień automatycznie wraca do wartości standardowych (10%/20%) dla wszystkich źródeł, jeśli nie ustalono dla niego odrębnego wyjątku.
[/gfxbox]
<ol>
<li>Wyjątek może dotyczyć dowolnej kombinacji sześciu wartości: procentu dla baru i dla kuchni — osobno z karty, osobno z serwisu i osobno z gotówki — każda z tych wartości może być ustawiona niezależnie od pozostałych, wyżej lub niżej niż wartość standardowa, także na 0%.</li>
<li><strong>Przykład:</strong> dla kelnera X na dzień 12. dnia miesiąca może zostać ustalone, że z karty oddaje 10% na bar i 15% na kuchnię (zamiast standardowych 10%/20%), podczas gdy z serwisu i z gotówki rozlicza się w tym samym dniu standardowo (10%/20%) — zmiana jednego źródła nie pociąga za sobą zmiany pozostałych. Kolejnego dnia (13. dnia) kelner X wraca automatycznie do standardowych 10%/20% na wszystkich źródłach, o ile nie ustalono dla tego dnia nowego wyjątku.</li>
<li>Wyjątki mogą ustalać wyłącznie: [gfxfillin] (np. administrator systemu i/lub manager napiwków, jeśli administrator na to zezwoli — do uzupełnienia zgodnie z faktyczną praktyką).</li>
<li>Każdy ustalony wyjątek musi być odnotowany w systemie rozliczeniowym (system GastroFlowx) — nie stosuje się wyjątków nieformalnych, ustalonych tylko ustnie i nigdzie nie zapisanych.</li>
</ol>
<h3 style="color:#1D4ED8; font-size:12px; margin-top:10px;">2.3 Napiwki z gotówki</h3>
[gfxbox color="blue"]
Rozliczanie napiwków z gotówki jest <strong>obowiązkowe i odbywa się codziennie</strong>: kwota gotówki przekazywana przez kelnera do baru i do kuchni jest zapisywana na kartce w barze i w kuchni. Standardowo odpowiada to 10% dla baru i 20% dla kuchni.
[/gfxbox]
<ol>
<li>Gotówka stanowi <strong>samodzielne, niezależne źródło</strong> napiwków — procent dla gotówki ustala się i ewentualnie zmienia (w ramach wyjątku, §2.2) osobno od karty i osobno od serwisu. Wyjątek ustalony dla karty lub dla serwisu <u>nie przenosi się automatycznie</u> na gotówkę — jeśli danego dnia ma obowiązywać inny procent również dla gotówki, musi to zostać ustalone i odnotowane jako odrębny wyjątek dotyczący właśnie gotówki.</li>
<li>Zapis na kartce (kwota przekazana do baru / do kuchni danego dnia) jest podstawą wpisu do systemu rozliczeniowego i powinien być zgodny z kwotą faktycznie przekazaną.</li>
</ol>
<h3 style="color:#1D4ED8; font-size:12px; margin-top:10px;">2.4 Przekazanie środków</h3>
<p>Kelner przekazuje fizycznie gotówkę należną barowi i kuchni osobom odpowiedzialnym za jej przyjęcie w danym dziale, zgodnie z procentami obowiązującymi danego dnia (standardowymi albo wynikającymi z ustalonego wyjątku).</p>
<h3 style="color:#1D4ED8; font-size:12px; margin-top:10px;">2.5 Napiwki z większych rezerwacji — podział według wykonanej pracy</h3>
[gfxbox color="green"]
Przy większych rezerwacjach procent napiwków należny barowi lub kuchni może zostać ustalony indywidualnie dla danej rezerwacji, w zależności od faktycznego zakresu pracy wykonanej przez dany dział — niezależnie od standardowych 10%/20% (§2.1) i niezależnie od dziennych wyjątków dla kelnera (§2.2).
[/gfxbox]
<ol>
<li><strong>Przykład (bar):</strong> jeśli bar przygotowuje pełny zakres napojów (kawy, drinki itp.) dla danej rezerwacji, otrzymuje standardowe 10%. Jeśli w ramach tej samej rezerwacji bar wykonuje tylko czynność ograniczoną (np. nalanie wody do karafki), procent może zostać zmniejszony, np. do 5% — analogiczna zasada obowiązuje dla kuchni, w zależności od zakresu faktycznie wykonanej pracy.</li>
<li><strong>Podział na konkretne zespoły/stanowiska:</strong> jeśli za realizację danej rezerwacji odpowiadał tylko jeden z wewnętrznych zespołów danego działu (np. wyłącznie „dolna kuchnia”, a nie „górna kuchnia”), cała pula napiwków należna kuchni z tej rezerwacji trafia wyłącznie do zespołu/osób, które faktycznie ją realizowały. Zespół, który nie brał udziału w danej rezerwacji, nie otrzymuje z niej żadnej części puli — niezależnie od ogólnego podziału obowiązującego dla pozostałych zamówień/rezerwacji tego samego dnia.</li>
<li>Decyzję o wysokości procentu oraz o podziale między zespoły dla konkretnej rezerwacji podejmuje: [gfxfillin] (do uzupełnienia — np. kierownik zmiany / szef kuchni / menadżer restauracji).</li>
<li>Techniczne wykonanie tej decyzji odbywa się w systemie: osoba z rolą „admin_bar” lub „admin_kuchnia” w zakładce „Rozliczenie serwisu” wybiera tryb ręczny (kwotowy) i przypisuje kwotę wynikającą z danej rezerwacji bezpośrednio wybranym, konkretnym pracownikom swojego działu — a nie całemu działowi proporcjonalnie do godzin. W ten sposób zarówno inny procent (np. 5% zamiast 10%), jak i ograniczenie odbiorców do konkretnego zespołu (np. tylko dolna kuchnia), są odzwierciedlone wprost w systemie rozliczeniowym, bez potrzeby prowadzenia dodatkowej dokumentacji poza systemem.</li>
</ol>
[gfxbox color="blue"]
<strong>W skrócie:</strong> ustalona kwota z danej rezerwacji trafia do puli działu (baru/kuchni) w wysokości ustalonej dla tej rezerwacji, a osoba rozliczająca dział (admin_bar/admin_kuchnia) ręcznie wskazuje w systemie, którzy konkretnie pracownicy tego działu otrzymują tę kwotę — pozostali pracownicy działu, którzy nie uczestniczyli w obsłudze danej rezerwacji, nie są przy tym rozliczeniu uwzględniani.
[/gfxbox]
<h2>§3. Miesięczne podsumowanie i jego udostępnianie zespołowi</h2>
[gfxbox color="blue"]
<strong>Kluczowa zasada jawności:</strong> Po zakończeniu każdego miesiąca rozliczeniowego przygotowywane jest zbiorcze podsumowanie napiwków wszystkich pracowników objętych podziałem, zawierające: imię i nazwisko, kwoty napiwków z karty i z serwisu, kwoty procentowe należne barowi i kuchni oraz łączną kwotę napiwków danej osoby. Podsumowanie to jest drukowane i udostępniane wszystkim pracownikom sali i kuchni uczestniczącym w podziale napiwków.
[/gfxbox]
<ol>
<li>Podstawą udostępniania tych danych całemu zespołowi (a nie tylko osobie bezpośrednio rozliczającej daną kwotę) jest zapewnienie, że każdy pracownik może zweryfikować, że podział napiwków przebiega zgodnie z zasadami niniejszego regulaminu i że wkład poszczególnych osób jest rozliczany rzetelnie.</li>
<li>Podsumowanie zawiera wyłącznie dane niezbędne do tej weryfikacji — nie zawiera innych danych osobowych (np. adresu, numeru konta bankowego, danych kontaktowych).</li>
<li>Jeżeli w danym miesiącu dla któregoś dnia i kelnera obowiązywał wyjątek procentowy (§2.2) lub indywidualna decyzja dotycząca konkretnej rezerwacji (§2.5), kwoty w podsumowaniu odzwierciedlają rzeczywiście zastosowany procent i podział — podsumowanie nie ujawnia jednak samego faktu zastosowania wyjątku/decyzji indywidualnej, ani jego przyczyny.</li>
<li>Wydrukowane podsumowania powinny być przechowywane wyłącznie w miejscu dostępnym dla pracowników restauracji (nie dla gości ani osób postronnych) i zniszczone (np. w niszczarce), gdy przestają być potrzebne do bieżącej weryfikacji rozliczeń — nie później niż [gfxfillin] dni od wydania.</li>
<li>Każdy pracownik, podpisując niniejszy regulamin, potwierdza, że został poinformowany o tej zasadzie jawności i ją akceptuje jako warunek uczestnictwa we wspólnym podziale napiwków.</li>
</ol>
<h2>§4. Rozliczenie z osobami przyjmującymi gotówkę</h2>
<ol>
<li>Osoby odpowiedzialne za przyjmowanie gotówki w barze i w kuchni potwierdzają otrzymane kwoty od poszczególnych kelnerów i zgłaszają ewentualne rozbieżności (nadwyżki, braki) niezwłocznie po ich stwierdzeniu.</li>
<li>Rozbieżności są wyjaśniane bezpośrednio między zainteresowanymi osobami, a w razie potrzeby — przy udziale [gfxfillin] (np. managera zmiany — do uzupełnienia).</li>
</ol>
<h2>§5. Ochrona danych osobowych</h2>
<ol>
<li>Dane wykorzystywane do sporządzenia miesięcznego podsumowania pochodzą z systemu informatycznego wykorzystywanego dobrowolnie przez pracowników do rozliczania napiwków (system GastroFlowx), zgodnie z odrębną polityką prywatności tego systemu.</li>
<li>Zasady ujawniania danych opisane w §3 niniejszego regulaminu stanowią uzupełnienie tej polityki prywatności w zakresie dotyczącym drukowanych, papierowych podsumowań miesięcznych.</li>
<li>Pytania i zgłoszenia dotyczące ochrony danych osobowych w tym zakresie należy kierować na adres: <strong>iod@gastroflowx.pl</strong>.</li>
</ol>
<h2>§6. Zmiany regulaminu</h2>
<p>Zmiany niniejszego regulaminu wymagają formy pisemnej i zostaną zakomunikowane wszystkim pracownikom objętym podziałem napiwków przed ich wejściem w życie.</p>
[gfxpagebreak]
<h2>Potwierdzenie zapoznania się i akceptacji przez pracowników</h2>
<p>Poniżsi pracownicy potwierdzają, że zapoznali się z treścią niniejszego regulaminu, w tym z zasadą udostępniania miesięcznych podsumowań napiwków całemu zespołowi (§3), i akceptują te zasady jako warunek uczestnictwa we wspólnym podziale napiwków.</p>
<table class="gfxdoc-table">
<tr><th style="width:28%;">Imię i nazwisko</th><th style="width:20%;">Rola</th><th style="width:18%;">Data</th><th>Podpis</th></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
<tr><td> </td><td> </td><td> </td><td> </td></tr>
</table>
[gfxnote]Niniejszy regulamin ma charakter pomocniczy i nie zastępuje indywidualnej porady prawnej. Zasady dotyczące procentów podziału, sposobu przechowywania wydruków oraz osób decyzyjnych powinny zostać dostosowane do faktycznej praktyki restauracji.[/gfxnote]
GFXDOC_SEED,
		),
		array(
			'title'   => 'Porozumienie z pracodawcą w sprawie korzystania z systemu GastroFlowx i przekazywania danych',
			'since'   => 2,
			'content' => <<<'GFXDOC_SEED'
<p class="subtitle">Porozumienie w sprawie korzystania z systemu GastroFlowx i przekazywania danych — restauracja Weranda Lunch and Wine</p>
[gfxbox color="yellow"]
<strong>Wzór do wypełnienia i dostosowania.</strong> Porozumienie dotyczy jednocześnie ochrony danych osobowych (RODO) i prawa pracy. Przed podpisaniem zalecana jest konsultacja z prawnikiem lub specjalistą ochrony danych osobowych.
[/gfxbox]
<p>Zawarte w [gfxfillin] (miejsce), dnia [gfxfillin] (data), pomiędzy:</p>
[gfxbox color="party"]
<h3>Pracodawca</h3>
[gfxfieldtable]
[gfxfield label="Nazwa / imię i nazwisko"][/gfxfield]
[gfxfield label="Adres"][/gfxfield]
[gfxfield label="NIP / KRS (jeśli dotyczy)"][/gfxfield]
[gfxfield label="Reprezentowany przez"][/gfxfield]
[/gfxfieldtable]
<p style="margin-bottom:0;">— zwanym dalej <strong>„Pracodawcą”</strong>,</p>
[/gfxbox]
<p style="text-align:center;">a</p>
[gfxbox color="party"]
<h3>Pracownik — Administrator systemu</h3>
[gfxfieldtable]
[gfxfield label="Imię i nazwisko"][/gfxfield]
[gfxfield label="Adres"][/gfxfield]
[gfxfield label="Stanowisko w restauracji"][/gfxfield]
[gfxfield label="E-mail kontaktowy"][/gfxfield]
[/gfxfieldtable]
<p style="margin-bottom:0;">— zwanym dalej <strong>„Administratorem”</strong>,</p>
[/gfxbox]
<p>zwanymi dalej łącznie <strong>„Stronami”</strong>.</p>
[gfxbox color="blue"]
<strong>Porozumienie w skrócie:</strong>
<ul>
<li>Administratorem systemu i danych w nim przetwarzanych jest <strong>Administrator</strong>.</li>
<li>Dane do rozliczeń <strong>przekazują Administratorowi</strong> kierownicy zmian, menadżerowie i osoby rozliczające — za zgodą Pracodawcy.</li>
<li><strong>Pracodawca prowadzi własną, odrębną dokumentację</strong> i <strong>nie otrzymuje żadnych danych</strong> z Systemu.</li>
<li>Na koniec miesiąca Administrator udostępnia <strong>raport miesięczny każdej sekcji</strong> jej pracownikom — kwoty w raporcie są jawne.</li>
</ul>
[/gfxbox]
<h2>§1. Definicje</h2>
<ol>
<li><strong>System</strong> — system informatyczny GastroFlowx stworzony i utrzymywany przez Administratora, opisany w dokumencie „Opis systemu i funkcji GastroFlowx”, obejmujący moduły wymienione w Załączniku nr 1.</li>
<li><strong>Użytkownik</strong> — pracownik restauracji, który dobrowolnie założył konto w Systemie i wyraził zgodę zgodnie z Polityką prywatności.</li>
<li><strong>Osoby przekazujące dane</strong> — kierownicy zmian, menadżerowie, Szef Kuchni, manager napiwków oraz osoby rozliczające bar i kuchnię, wskazane w Załączniku nr 2.</li>
<li><strong>Sekcja</strong> — zespół objęty wspólnym podziałem napiwków: sala (kelnerzy), bar, kuchnia — zgodnie z „Regulaminem podziału i rozliczania napiwków”.</li>
<li><strong>Raport miesięczny</strong> — zestawienie rozliczenia napiwków danej sekcji za miesiąc kalendarzowy, opisane w §5.</li>
<li><strong>Polityka prywatności</strong> — „Polityka prywatności systemu GastroFlowx”.</li>
</ol>
<h2>§2. Przedmiot porozumienia</h2>
<ol>
<li>Pracodawca oświadcza, że zna System i <strong>wyraża zgodę</strong> na jego dobrowolne wykorzystywanie przez pracowników restauracji jako narzędzia wspomagającego: grafik pracy, rozliczanie napiwków, ewidencję godzin, przygotowanie menu i druków oraz raporty dobowe.</li>
<li>Porozumienie określa: rolę Administratora jako administratora danych, zasady <strong>przekazywania danych Administratorowi</strong> przez Osoby przekazujące dane, zasady udostępniania Raportu miesięcznego oraz postępowanie po zakończeniu współpracy.</li>
<li>Porozumienie nie stanowi zlecenia budowy, rozwoju ani utrzymania Systemu i nie zmienia warunków umowy o pracę Administratora. System jest udostępniany nieodpłatnie, chyba że Strony postanowią inaczej w odrębnej umowie.</li>
<li>System działa na serwerze wynajmowanym przez Administratora u dostawcy hostingu <strong>SeoHost.pl</strong> i pozostaje pod wyłączną kontrolą techniczną Administratora.</li>
<li>Uruchomienie modułu z Załącznika nr 1, który dziś nie jest używany, nie wymaga nowego porozumienia — Administrator informuje o tym Pracodawcę z wyprzedzeniem. Dodanie nowego modułu, nieobjętego Załącznikiem nr 1, wymaga aneksu w formie pisemnej lub elektronicznej.</li>
</ol>
<h2>§3. Administrator danych i dobrowolność</h2>
<ol>
<li>Strony potwierdzają, że <strong>administratorem danych osobowych przetwarzanych w Systemie</strong> (art. 4 pkt 7 RODO) jest Administrator. Pracodawca nie jest administratorem ani współadministratorem tych danych, nie ma dostępu do Systemu i nie decyduje o sposobach przetwarzania.</li>
<li>Korzystanie z Systemu przez pracowników jest <strong>dobrowolne</strong>. Pracodawca nie będzie wymagał korzystania z Systemu ani traktował korzystania lub niekorzystania z niego jako podstawy oceny lub różnicowania pracowników, i zapewnia osobom niekorzystającym z Systemu rozliczenia tradycyjnymi metodami.</li>
<li>Administrator przetwarza dane zgodnie z RODO i Polityką prywatności, udostępnianą każdemu Użytkownikowi przed wyrażeniem zgody.</li>
</ol>
<h2>§4. Przekazywanie danych Administratorowi</h2>
<ol>
<li>Pracodawca <strong>wyraża zgodę</strong>, aby Osoby przekazujące dane przekazywały Administratorowi — ustnie, na piśmie lub przez wprowadzenie do Systemu w ramach nadanych uprawnień — informacje potrzebne do rozliczeń w Systemie, w szczególności:
<ol type="a">
<li>kwoty napiwków z karty i serwisu przypadające na poszczególnych kelnerów danego dnia,</li>
<li>kwoty gotówki przekazane do baru i kuchni,</li>
<li>godziny pracy personelu baru i kuchni,</li>
<li>informacje o podziale napiwków z większych rezerwacji i o wyjątkach procentowych,</li>
<li>grafik pracy, dyspozycyjność i dni wolne członków zespołu.</li>
</ol></li>
<li>Przekazywane są wyłącznie dane <strong>Użytkowników</strong> i wyłącznie w zakresie niezbędnym do rozliczeń. Nie przekazuje się danych o zdrowiu, zwolnieniach lekarskich, wynagrodzeniu zasadniczym, numerów PESEL, rachunków bankowych ani adresów zamieszkania.</li>
<li>Pracodawca poinformuje Osoby przekazujące dane o zasadach z niniejszego paragrafu. Osoby te działają w tym zakresie na podstawie upoważnienia Administratora (Załącznik nr 2) i są zobowiązane do zachowania poufności.</li>
<li>Przekazanie danych Administratorowi nie zastępuje dokumentacji prowadzonej przez Pracodawcę — Osoby przekazujące dane wykonują wobec Pracodawcy swoje obowiązki dokumentacyjne niezależnie od Systemu.</li>
</ol>
<h2>§5. Raport miesięczny sekcji</h2>
<ol>
<li>Po zakończeniu każdego miesiąca Administrator przygotowuje dla <strong>każdej sekcji osobno</strong> Raport miesięczny zawierający: imię i nazwisko, kwoty napiwków z karty i z serwisu, kwoty należne barowi i kuchni oraz łączną kwotę napiwków każdej osoby.</li>
<li>Raport jest udostępniany <strong>pracownikom danej sekcji</strong> uczestniczącym w podziale napiwków — kwoty w Raporcie są <strong>jawne</strong> dla tych osób, w celu umożliwienia weryfikacji rzetelności podziału. Zasady jawności, przechowywania i niszczenia wydruków określa §3 „Regulaminu podziału i rozliczania napiwków”.</li>
<li>Raport nie zawiera innych danych osobowych (np. adresu, rachunku bankowego, danych kontaktowych) ani informacji o przyczynach zastosowanych wyjątków.</li>
<li>Raport miesięczny <strong>nie jest przekazywany Pracodawcy</strong> przez Administratora i nie stanowi dokumentacji Pracodawcy.</li>
</ol>
<h2>§6. Brak przekazywania danych Pracodawcy</h2>
<ol>
<li>Administrator <strong>nie udostępnia Pracodawcy żadnych danych</strong> przetwarzanych w Systemie — w szczególności rozliczeń, godzin pracy, grafików, wniosków, rejestru aktywności, historii zgód ani Raportów miesięcznych.</li>
<li>Pracodawca <strong>prowadzi niezależnie własną, odrębną dokumentację</strong> wynagrodzeń, czasu pracy (art. 149 Kodeksu pracy) i rozliczeń, zgodną z przepisami, i nie opiera jej na danych z Systemu.</li>
<li>Pracodawca nie będzie uzyskiwał danych z Systemu w inny sposób — przez korzystanie z cudzych kont, zrzuty ekranu, wydruki przekazywane nieformalnie ani polecenia wydawane Użytkownikom.</li>
<li>Jeżeli obowiązek udostępnienia danych wynikać będzie z przepisów prawa (np. żądania uprawnionego organu), Administrator udostępni je wyłącznie temu organowi i w zakresie wymaganym przepisami.</li>
<li>Pracownik, który w ramach swoich obowiązków przygotowuje w Systemie dokument dla Pracodawcy (np. raport dobowy z utargu w formie PDF pobranego na swoje urządzenie), przekazuje go Pracodawcy sam, jako czynność służbową — nie jest to udostępnienie danych przez Administratora.</li>
</ol>
[gfxpagebreak]
<h2>§7. Obowiązki Administratora</h2>
<ol>
<li>Zapewnienie bezpieczeństwa Systemu (aktualizacje, kopie zapasowe, kontrola uprawnień, szyfrowane połączenie, rejestr aktywności z ograniczonym okresem przechowywania) oraz zawarcie umowy powierzenia z dostawcą hostingu.</li>
<li>Udostępnienie Polityki prywatności, zbieranie i dokumentowanie zgód Użytkowników oraz realizacja ich praw (iod@gastroflowx.pl).</li>
<li>Nadawanie uprawnień do danych innych pracowników wyłącznie osobom z Załącznika nr 2 i odbieranie ich niezwłocznie po zmianie funkcji lub ustaniu zatrudnienia.</li>
<li>Poinformowanie Pracodawcy — bez przekazywania danych osobowych — o naruszeniu ochrony danych mogącym dotyczyć pracowników restauracji, bez zbędnej zwłoki.</li>
</ol>
<h2>§8. Obowiązki Pracodawcy</h2>
<ol>
<li>Poinformowanie pracowników o istnieniu Systemu, jego dobrowolności, o tym, że administratorem danych jest Administrator, oraz że Pracodawca nie otrzymuje danych z Systemu.</li>
<li>Poinformowanie Osób przekazujących dane o zasadach z §4.</li>
<li>Niezwłoczne informowanie Administratora o zmianach osób pełniących funkcje z Załącznika nr 2 oraz o ustaniu zatrudnienia Użytkownika — wyłącznie w zakresie potrzebnym do zablokowania konta i odebrania uprawnień.</li>
</ol>
<h2>§9. Zakończenie zatrudnienia Administratora</h2>
<ol>
<li>Ustanie zatrudnienia Administratora nie powoduje automatycznego rozwiązania porozumienia ani zaprzestania działania Systemu.</li>
<li>Administrator, według własnego wyboru, może:
<ol type="a">
<li><strong>nadal udostępniać System</strong> pracownikom restauracji na dotychczasowych zasadach;</li>
<li><strong>usunąć System</strong> i wszystkie przetwarzane w nim dane;</li>
<li><strong>zakończyć dostęp</strong> pracowników do Systemu i zarchiwizować dane wyłącznie na potrzeby wyjaśnienia ewentualnych pytań lub roszczeń dotyczących wcześniejszych rozliczeń, przez okres wskazany w Polityce prywatności, a następnie je usunąć.</li>
</ol></li>
<li>Administrator informuje Pracodawcę i Użytkowników o wybranej opcji najpóźniej w dniu ustania zatrudnienia. Użytkownicy mogą przed zamknięciem Systemu pobrać własne raporty (PDF).</li>
<li>Żadna z opcji nie obejmuje przekazania danych Pracodawcy, chyba że Strony zawrą odrębną umowę, a Użytkownicy zostaną o tym poinformowani z co najmniej 30-dniowym wyprzedzeniem i będą mogli żądać wcześniejszego usunięcia swoich danych.</li>
</ol>
<h2>§10. Czas trwania i rozwiązanie</h2>
<ol>
<li>Porozumienie zawarto na czas nieokreślony. Każda Strona może je rozwiązać z [gfxfillin width="60"]-dniowym okresem wypowiedzenia, w formie pisemnej lub elektronicznej.</li>
<li>Po rozwiązaniu porozumienia stosuje się odpowiednio §9 ust. 2–4.</li>
<li>Obowiązki dotyczące poufności i ochrony danych obowiązują także po rozwiązaniu porozumienia.</li>
</ol>
<h2>§11. Postanowienia końcowe</h2>
<ol>
<li>Zmiany porozumienia wymagają formy pisemnej lub elektronicznej (e-mail z potwierdzeniem) pod rygorem nieważności.</li>
<li>Porozumienie nie jest umową powierzenia przetwarzania (art. 28 RODO) ani uzgodnieniem współadministratorów (art. 26 RODO).</li>
<li>W sprawach nieuregulowanych stosuje się przepisy Kodeksu cywilnego, RODO, ustawy o ochronie danych osobowych oraz Kodeksu pracy.</li>
<li>Porozumienie sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej Strony. Załączniki stanowią jego integralną część.</li>
</ol>
[gfxsigtable label1="Podpis Pracodawcy" label2="Podpis Administratora"]
[gfxpagebreak]
<h2>Załącznik nr 1 — Moduły Systemu</h2>
<table class="gfxdoc-table">
<tr><th style="width:30%;">Moduł</th><th>Zakres</th><th style="width:20%;">Używany od (data) / nieużywany</th></tr>
<tr><td>Panel Pracownika (Hub)</td><td>Logowanie, konta, zgody, urodziny, powiadomienia, aplikacja PWA</td><td>[gfxfillin width="110"]</td></tr>
<tr><td>Grafik Pracy</td><td>Zmiany, dyspozycyjność, offy, zamiany</td><td>[gfxfillin width="110"]</td></tr>
<tr><td>System Napiwków</td><td>Rozliczanie napiwków karta / serwis / gotówka, raport miesięczny</td><td>[gfxfillin width="110"]</td></tr>
<tr><td>Ewidencja Godzin</td><td>Godziny pracy (podpowiedź z Grafiku), stawki, napiwki brutto/netto z Napiwków, zarobek</td><td>[gfxfillin width="110"]</td></tr>
<tr><td>Lunch, Menu, Kolorowanki</td><td>Menu lunchowe, karta dań, materiały dla dzieci</td><td>[gfxfillin width="110"]</td></tr>
<tr><td>Pliki</td><td>Biblioteka druków (HACCP, listy obowiązków)</td><td>[gfxfillin width="110"]</td></tr>
<tr><td>Dokumenty (raporty dobowe)</td><td>Raporty z kasy fiskalnej i terminala</td><td>[gfxfillin width="110"]</td></tr>
<tr><td>Karty Dostępu</td><td>Karty z kodami QR, listy pracowników</td><td>[gfxfillin width="110"]</td></tr>
<tr><td>Rejestr Aktywności</td><td>Logowania, sesje, odwiedzane strony, IP — bezpieczeństwo</td><td>[gfxfillin width="110"]</td></tr>
</table>
<h2>Załącznik nr 2 — Osoby przekazujące dane i osoby z uprawnieniami</h2>
<table class="gfxdoc-table">
<tr><th style="width:26%;">Imię i nazwisko</th><th>Funkcja</th><th style="width:26%;">Jakie dane przekazuje / wprowadza</th><th style="width:13%;">Od</th><th style="width:13%;">Podpis</th></tr>
<tr><td> </td><td>Kierownik zmiany</td><td>Napiwki z karty i serwisu kelnerów</td><td> </td><td> </td></tr>
<tr><td> </td><td>Kierownik zmiany</td><td>Napiwki z karty i serwisu kelnerów</td><td> </td><td> </td></tr>
<tr><td> </td><td>Manager napiwków (<code>manager</code>)</td><td>Karta i serwis kelnerów, wyjątki</td><td> </td><td> </td></tr>
<tr><td> </td><td>Admin Bar (<code>admin_bar</code>)</td><td>Godziny baru, gotówka, serwis</td><td> </td><td> </td></tr>
<tr><td> </td><td>Admin Kuchnia (<code>admin_kuchnia</code>)</td><td>Godziny kuchni, gotówka, serwis</td><td> </td><td> </td></tr>
<tr><td> </td><td>Szef Kuchni (<code>rs_chef_manager</code>)</td><td>Grafik kuchni</td><td> </td><td> </td></tr>
<tr><td> </td><td>Menadżer Restauracji (<code>rs_restaurant_manager</code>)</td><td>Grafik sali i baru</td><td> </td><td> </td></tr>
</table>
<p class="note">Podpis w Załączniku nr 2 oznacza zobowiązanie do zachowania poufności przekazywanych i wprowadzanych danych oraz wykorzystywania ich wyłącznie do rozliczeń w Systemie.</p>
[gfxnote]Wzór ma charakter pomocniczy i nie zastępuje porady prawnej. Przed podpisaniem zaleca się konsultację z prawnikiem — w szczególności w zakresie przekazywania przez kierowników informacji o napiwkach innych pracowników oraz korzystania z prywatnej infrastruktury pracownika do celów związanych z pracą.[/gfxnote]
GFXDOC_SEED,
		),
	);
}
