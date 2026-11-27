System Napiwków SPA — instrukcja

1. Instalacja
   - Spakuj cały folder "system-napiwkow-spa" do ZIP i wgraj przez
     Wtyczki -> Dodaj nową -> Wyślij wtyczkę na serwer, albo wgraj
     folder ręcznie przez FTP do /wp-content/plugins/.
   - Aktywuj wtyczkę "Gastronomia - System Napiwków SPA".
   - Jeśli korzystałeś wcześniej ze starej wtyczki "System Napiwków",
     możesz ją bezpiecznie dezaktywować — ta wtyczka korzysta
     z tych samych tabel bazy danych, tych samych pól profilu
     (data urodzenia, data zwolnienia, ręczne wygaśnięcie ulgi)
     i tych samych ustawień podatkowych, więc dane i konfiguracja
     zostaną zachowane.

2. Użycie
   - Dodaj shortcode [napiwki_app] na dowolnej stronie/wpisie.
   - Aplikacja to jedna responsywna karta (SPA) z zakładkami widocznymi
     zależnie od roli:

     ZAKŁADKA "DZIEŃ"
       - manager        -> wpisuje kartę i serwis kelnerów
       - admin_bar      -> wpisuje godziny barmanów (+ pomoc kelnera)
                            oraz gotówkę od kelnerów (10% dla baru) —
                            kuchnia (20%) uzupełnia się automatycznie
       - admin_kuchnia  -> wpisuje godziny kucharzy oraz gotówkę od
                            kelnerów (20% dla kuchni) — bar (10%)
                            uzupełnia się automatycznie
       - kelner         -> wpisuje gotówkę oddaną do baru LUB kuchni —
                            druga wartość uzupełnia się sama (ta sama
                            gotówka to zawsze 10% dla baru / 20% dla
                            kuchni), widzi własne rozliczenie dnia
       - barman         -> wpisuje własne godziny (jeśli nie ma
                            roli admin_bar/admin_kuchnia), widzi
                            własną wypłatę dnia
       - kucharz        -> widzi własne godziny (tylko do odczytu)
                            i wypłatę dnia; godziny kucharzy wpisuje
                            wyłącznie admin_kuchnia
       - manager/administrator -> dodatkowo widzą:
           * "Kelnerzy (Rozliczenie Napiwków)" — karta netto/serwis
             netto/gotówka 100%/wypłata 70% dla każdego kelnera
           * "Podsumowanie dnia dla menagera" — kolorowy box z sumą
             karty/serwisu i tabelą kelnerów
       - manager/admin_bar/admin_kuchnia/administrator -> dodatkowo:
           * "Rozliczenie Personelu" — H/serwis/gotówka/karta/serwis/
             razem dla całego personelu (filtrowane wg działu)
           * "Podsumowanie dnia dla admina baru/kuchni" — kolorowy
             box z sumą gotówki/karty/serwisu personelu
       - każda z powyższych kart ma przycisk "📥 Pobierz PDF"

     ZAKŁADKA "MIESIĄC"
       - przełącznik "Typ raportu: Ogólny / Szczegółowy (dni)"
       - kelner   -> "Raport Kelnera" — widok ogólny (3 kategorie:
         Karty/Serwis/Gotówka) lub dzień po dniu
       - manager  -> "Raport Managera" — kelnerzy z kartą/serwisem
         brutto i netto oraz pulą 10%/20% (ogólny lub dzień po dniu)
       - admin_bar/admin_kuchnia/barman/kucharz -> "Raport Wypłat
         Personelu" — godziny/gotówka/karta/serwis/suma
       - manager/administrator -> dodatkowo "Podsumowanie Miesiąca":
         karty dzienne (Karty/Serwis/Gotówka x 10%/20%, ze szczegółami
         serwisu per kelner) oraz zbiorcza tabela z podziałem na
         10% z karty / 10% z serwisu / 20% z karty / 20% z serwisu
       - każdy raport ma przycisk "📥 Pobierz PDF"

     ZAKŁADKA "SPRAWDŹ PRACOWNIKA" (manager/administrator)
       - wybór dowolnego kelnera/barmana/kucharza (także zwolnionego)
         i dowolnego miesiąca -> pełny raport ogólny + dzień po dniu

     ZAKŁADKA "ROZLICZENIE SERWISU" (admin_bar/admin_kuchnia)
       - dla wybranego dnia: podział puli serwisu (10%/20% z netto
         serwisu kelnerów) na personel działu — godzinowo (ustawia
         service_hours i przelicza) albo ręcznie kwotowo (nadpisuje
         wyliczoną kwotę bez przeliczania godzinowego, tak jak
         w oryginalnej wtyczce)

     ZAKŁADKA "ROZLICZENIE Z KELNERAMI" (admin_bar/admin_kuchnia)
       - przełącznik "Rozliczam Bar" / "Rozliczam Kuchnię" (widoczny
         tylko dla administratora — pozostali widzą stały dział)
       - 4 karty statystyk: zadeklarowane w systemie / fizycznie
         wpłacono / nadwyżki łącznie / brakuje łącznie (liczone na
         żywo w przeglądarce)
       - tabela: Pracownik | Karta (10%/20%) | Serwis (10%/20%) |
         Suma do oddania | Fizyczna wpłata | Różnica | Status
         (odznaki: Rozliczony / Nadwyżka / Do dopłaty / Brak wpłaty)

     ZAKŁADKA "USTAWIENIA" (administrator)
       - reguły podatkowe (ulga do 26 lat, stawka podatku)
       - przełącznik "Manager napiwków może ustawiać wyjątki
         procentowe" (patrz sekcja 6 poniżej)

     ZAKŁADKA "DZIEŃ" — dodatkowa karta "Wyjątki procentowe (ten
     dzień)" (administrator zawsze; manager napiwków — tylko jeśli
     administrator to włączy w Ustawieniach)
       - lista aktywnych kelnerów danego dnia z polami do zmiany
         procentu dla baru/kuchni (domyślnie 10%/20%) TYLKO na ten
         jeden dzień; osoby z aktywnym wyjątkiem mają odznakę
         "wyjątek" i przycisk "Reset" przywracający wartości domyślne

     ZAKŁADKA "E-MAIL" (manager / admin_bar / admin_kuchnia / administrator)
       - dla każdej dostępnej grupy (Kelnerzy / Bar / Kuchnia) można
         edytować temat i treść wiadomości (z polami w nawiasach
         klamrowych, np. {imie}, {wyplata}) i zapisać szablon
       - przycisk "Wyślij teraz" wysyła wiadomości RĘCZNIE (nigdy
         automatycznie) do wszystkich pracowników danej grupy z
         niezerową aktywnością we wskazanym miesiącu:
           * Kelnerzy (wysyła manager) — karta netto, serwis netto,
             gotówka, kwota do oddania barowi (10%), kwota do oddania
             kuchni (20%), wypłata (70%)
           * Bar (wysyła admin baru) — zarobek barmana z gotówki,
             karty i serwisu w danym miesiącu
           * Kuchnia (wysyła admin kuchni) — analogicznie dla kucharza
       - przed wysyłką pojawia się okno z potwierdzeniem

     ZAKŁADKA "POWIADOMIENIA" (manager / admin_bar / admin_kuchnia / administrator)
       - dokładnie ten sam mechanizm co e-mail (edytowalny szablon,
         wybór miesiąca, przycisk "Wyślij teraz" z potwierdzeniem,
         nigdy automatycznie), ale wysyła powiadomienie push przez
         panel GastroFlowx (Firebase Cloud Messaging) zamiast
         e-maila — z tymi samymi polami do wstawienia (np. {imie},
         {wyplata}, {razem})
       - jeśli powiadomienia push nie są jeszcze aktywne, zakładka
         pokazuje ostrzeżenie i wskazuje panel GastroFlowx →
         Integracje

     ZAKŁADKA "USTAWIENIA" — sekcja "Powiadomienia push"
     (administrator)
       - Powiadomienia push są od wersji korzystającej z Firebase
         Cloud Messaging konfigurowane WYŁĄCZNIE centralnie w panelu
         GastroFlowx (gastroflowx-hub) → Integracje — Napiwki nie mają
         już własnego App ID / REST API Key (tak jak wcześniej przy
         OneSignal). Ta sekcja jest tylko informacyjna: pokazuje, czy
         integracja jest aktywna, i w razie potrzeby odsyła do panelu
         GastroFlowx, gdzie:
           1. Konfiguruje się projekt Firebase (konfiguracja Web SDK,
              klucz VAPID, konto serwisowe do wysyłki po stronie
              serwera) — jednorazowo, w jednym miejscu dla całego
              ekosystemu (Panel + Grafik + Napiwki).
           2. Panel automatycznie inicjalizuje SDK Firebase w
              przeglądarce KAŻDEGO zalogowanego pracownika, na KAŻDEJ
              stronie frontendu (nie tylko w Napiwkach) i zapisuje
              jego token urządzenia po zaakceptowaniu zgody na
              powiadomienia.
           3. Status zgody każdego pracownika i historię każdej
              wysyłki (ze wszystkich trzech wtyczek) znajdziesz w
              panelu GastroFlowx → Powiadomienia push.
       - jeśli panel GastroFlowx jest nieaktywny, przycisk "Wyślij
         teraz" w tej zakładce po prostu nic nie wysyła (z jasnym
         komunikatem o tym) — kanał e-mail działa niezależnie i
         zawsze.

3. Responsywność na telefonie (poprawka)
   Na wąskich ekranach szerokie tabele (np. "Raport Kelnera" z wieloma
   kolumnami, "Rozliczenie z kelnerami") są teraz przewijane w bok
   TYLKO wewnątrz swojego pudełka, a nie całą stroną — wcześniej
   brakująca ochrona przed "rozpychaniem" siatki kart (grid) przez
   szerokie tabele powodowała, że cały ekran robił się szerszy niż
   telefon i nie dało się normalnie przewinąć w bok, żeby zobaczyć
   resztę tabeli. Teraz:
   - każda szeroka tabela ma własny, przewijalny w poziomie kontener
     (z płynnym przewijaniem na iOS),
   - jeśli tabela faktycznie nie mieści się na ekranie, pojawia się
     nad nią podpowiedź "Przesuń w bok, aby zobaczyć więcej" oraz
     delikatny cień na prawej krawędzi (znika, gdy dojedziesz do końca),
   - reszta układu (karty, wiersze z polami K:/S:, H:/+K:) dostała
     dodatkową ochronę przed zawijaniem w nieprzewidziany sposób na
     bardzo wąskich ekranach.
   Sama strona (poza pojedynczymi tabelami) nigdy już nie robi się
   szersza niż ekran telefonu — przetestowane na 360px i 375px.

   Druga poprawka (przyklejony pasek zakładek zasłaniał treść):
   pasek podzakładek (np. "Miesiąc / Sprawdź pracownika / Rozliczenie
   serwisu / Rozliczenie z kelnerami") miał na sztywno wpisany
   odstęp od góry, który nie odpowiadał rzeczywistej wysokości paska
   nad nim — po przewinięciu ekranu zasłaniał górną część treści
   pod spodem (np. nagłówek karty czy komunikat ostrzegawczy o
   powiadomieniach push). Teraz:
   - odstęp jest mierzony NA ŻYWO w przeglądarce (wysokość paska
     głównych sekcji Dzień/Raporty/Więcej), a nie wpisany na sztywno,
     więc zawsze pasuje idealnie — również po zmianie rozmiaru okna,
   - na telefonie, gdzie ten górny pasek jest ukryty (jego rolę
     przejmuje dolna nawigacja), pasek podzakładek przykleja się od
     samej góry ekranu zamiast zostawiać niepotrzebną, zgadywaną lukę,
   - podzakładki nie zawijają się już do kilku wierszy na wąskich
     ekranach (co potrafiło zająć nawet pół ekranu) — teraz zawsze
     mieszczą się w jednej linii i przewijają w bok, tak jak reszta
     przewijalnych elementów w aplikacji.

   Trzecia poprawka (tabele "rozjeżdżały się" na komputerze):
   na szerokich ekranach tabele bez ograniczenia szerokości robiły
   się nienaturalnie rozciągnięte — pierwsza kolumna (np. z nazwiskiem)
   potrafiła zajmować nawet 40% szerokości tabeli mimo krótkiej treści,
   przez co wiersze wyglądały na "porozjeżdżane" mimo że kolumny
   technicznie były wyrównane co do piksela (sprawdzone pomiarem w
   przeglądarce). Teraz każda tabela ma sensowną maksymalną szerokość
   (960px), więc na szerokim monitorze nie rozciąga się na cały ekran —
   zachowanie na telefonie (przewijanie w bok szerokich tabel) zostaje
   bez zmian, bo ograniczenie działa tylko "w górę".

   Czwarta poprawka (listy rozwijane ucinały tekst od góry i od dołu):
   to był problem z natywnym wyglądem <select> w niektórych
   przeglądarkach — różnie liczą sobie wysokość pola względem
   font-size/line-height, co przy niestandardowym paddingu potrafiło
   przycinać tekst z góry i z dołu (mimo że mieścił się na długość).
   Rozwiązanie: wszystkie listy rozwijane w aplikacji mają teraz
   całkowicie własny wygląd (wyłączony natywny "chrome" przeglądarki
   przez `appearance: none`) z własną strzałką narysowaną w CSS —
   dzięki temu wysokość i padding są w 100% pod naszą kontrolą i nie
   zależą od tego, jak dana przeglądarka/silnik liczy to sobie
   wewnętrznie. Sprawdzone z najdłuższą nazwą miesiąca ("Październik").

   Piąta poprawka (dane w kartach dni "nie mieściły się w ramce"):
   gdy w danym dniu był wpisany serwis dla kilku pracowników, mini-
   tabelka w karcie dnia (widok "Karty Dniowe") realnie potrzebowała
   więcej miejsca w poziomie niż wąska karta miała do dyspozycji (np.
   339px treści w karcie o szerokości 320px) — a ta jedna tabelka,
   w przeciwieństwie do wszystkich innych w aplikacji, nie miała
   swojego przewijanego kontenera, więc po prostu wystawała/ucinała
   się na krawędzi karty. Teraz ma taki sam mechanizm jak reszta
   tabel w aplikacji: jeśli treść nie mieści się, karta pokazuje
   podpowiedź "Przesuń w bok, aby zobaczyć więcej" i pozwala
   przewinąć zawartość w poziomie, zamiast bezpowrotnie ją ucinać.
   Sam plik PDF nie był tym dotknięty (czyta dane wprost, nie robi
   zrzutu ekranu), ale teraz również podgląd na ekranie jest kompletny.

   Szósta poprawka (kolumny wyglądały "nierówno" mimo poprawnego
   wyrównania): w kolumnach z krótkim opisem tekstowym (np. "Rola" —
   kelner/barman/kucharz, "H / Serwis", "Godziny (H/S)", "Wiek")
   nagłówek i treść były wyrównane do prawej strony tak jak liczby
   pieniężne. Zmierzyłem to bezpośrednio w przeglądarce: nagłówek
   i treść miały identyczną prawą krawędź (czyli technicznie były
   wyrównane poprawnie), ale skoro "Rola" (4 znaki) i "kelner"
   (6 znaków) różnią się długością, ich lewe krawędzie wypadały w
   różnych miejscach — a to właśnie lewa krawędź jest tym, na co
   patrzy oko przy czytaniu, więc całość wyglądała na "porozjeżdżaną".
   Wyrównanie do prawej ma sens dla liczb (żeby porównywać ich
   wielkość), a nie dla krótkich etykiet tekstowych — więc kolumny
   Rola / H-Serwis / Godziny / Wiek są teraz wyrównane do lewej,
   tak jak kolumna Pracownik, a kolumny z kwotami (Karta, Serwis,
   Gotówka, Pula Bar/Kuchnia, Suma) zostają wyrównane do prawej
   bez zmian.

   Siódma poprawka (tabele "rozjeżdżały się" konkretnie na większych
   telefonach, choć na mniejszych było OK): poprzednia poprawka #3
   (limit szerokości tabeli na 960px) rozwiązywała tylko najgorszy
   przypadek na bardzo szerokich monitorach — ale każda tabela z
   `width:100%` i tak rozciąga się do pełnej szerokości swojego
   kontenera, nawet jeśli ten kontener ma 500-800px (typowy większy
   telefon), a treść potrzebuje dużo mniej miejsca. Stąd kolumna
   "Pracownik" nadal zajmowała nieproporcjonalnie dużo miejsca na
   takich ekranach. Zmieniłem podejście: tabela ma teraz szerokość
   dopasowaną do treści (nie na sztywno 100% kontenera), a jedynie
   nie może być SZERSZA niż kontener. Efekt: każda tabela ma teraz
   dokładnie tyle miejsca, ile faktycznie potrzebuje — niezależnie
   od tego, czy telefon ma 360px czy 800px szerokości — zamiast
   sztucznie się rozciągać. Sprawdzone pomiarem: kolumna Pracownik
   zajmowała wcześniej 232px na ekranie 700px (38% szerokości
   tabeli mimo krótkiego imienia i nazwiska), teraz zajmuje stałe,
   dopasowane do treści 103px na KAŻDEJ szerokości ekranu od 360px
   do 1400px. Szerokie tabele z wieloma kolumnami (np. Raport
   Managera, Rozliczenie z kelnerami), które faktycznie potrzebują
   więcej miejsca niż mieści mniejszy telefon, nadal poprawnie
   pokazują podpowiedź "Przesuń w bok" i przewijają się w poziomie
   — ten mechanizm (poprawka #2) działa bez zmian.

4. Pobieranie PDF (przebudowane od podstaw — poprzednie podejście dawało
   puste, białe strony)
   Poprzednia wersja robiła "zdjęcie" ekranu (html2canvas) i wklejała je
   jako obrazek do PDF-a przez bibliotekę jsPDF. Po dokładnym prześledzeniu
   problemu (dwoma niezależnymi silnikami PDF, na kilku wersjach jsPDF i
   html2pdf.js) okazało się, że to POŁĄCZENIE (obrazek z html2canvas
   wklejany przez jsPDF.addImage) ma realny, powtarzalny błąd w samej
   bibliotece — obrazek renderowany bezpośrednio z canvasa był idealnie
   czysty, ale po przejściu przez jsPDF wychodził uszkodzony/biały,
   niezależnie od wersji i od tego, czy to JPEG czy PNG.
   Dlatego teraz PDF generowany jest z PRAWDZIWYCH danych, a nie ze zrzutu
   ekranu: wtyczka czyta nagłówek, linijki podsumowania i zawartość
   tabeli (nagłówki, wiersze, wiersz SUMA z colspan) prosto z tego, co
   jest na ekranie, i rysuje to od nowa biblioteką jsPDF + jspdf-autotable
   (obie ładowane z CDN przy pierwszym kliknięciu — wymaga połączenia
   z internetem po stronie przeglądarki użytkownika). Dodatkowe zalety
   tego podejścia:
   - tekst w PDF jest prawdziwym, przeszukiwalnym i kopiowalnym tekstem
     (a nie nieostrym obrazkiem),
   - pliki są dużo mniejsze (dziesiątki KB, nie megabajty),
   - polskie znaki (ą ć ę ł ń ó ś ź ż) wyświetlają się poprawnie dzięki
     osadzonej, wyciętej pod potrzebne znaki czcionce DejaVu Sans
     (assets/dejavu-regular.b64, assets/dejavu-bold.b64 — razem ok. 70KB),
     zamiast domyślnych fontów PDF, które polskich znaków nie mają.
   Przetestowane end-to-end: rzeczywiste kliknięcia przycisków, pobrane
   pliki wyrenderowane do obrazu i sprawdzone pikselowo dwoma
   niezależnymi silnikami PDF (Poppler i MuPDF) — wszystkie 6 raportów
   z przyciskiem PDF wychodzą czyste, bez uszkodzeń.

5. Logika obliczeń (domyślnie jak w oryginale, teraz z możliwością wyjątków)
   - Ulga podatkowa PIT-0 obowiązuje do 26. urodzin włącznie,
     chyba że ustawiono ręczną datę nadpisania w profilu.
   - Domyślnie: pula karty dla działu = suma napiwków z karty (po
     podatku) x 10% (bar) lub 20% (kuchnia) — ale te 10%/20% można
     teraz nadpisać dla KONKRETNEGO kelnera na KONKRETNY dzień (patrz
     punkt 6 poniżej); wtedy pula danego działu liczy się jako suma
     wkładu każdej osoby jej WŁASNYM procentem, a nie jedną wspólną
     stawką dla wszystkich.
   - Pula karty pomniejszana jest o "dopłaty z rezerwacji".
   - Pula gotówki działu = suma kwot zadeklarowanych przez kelnerów
     jako oddane do danego działu.
   - Stawka godzinowa = pula / suma godzin pracowników danego działu.
   - Kelner: gotówka "100%" = kwota oddana do baru / procent dla baru
     (domyślnie ÷10%, czyli x10) albo do kuchni / procent dla kuchni
     (domyślnie ÷20%, czyli x5) — wzór automatycznie używa
     obowiązującego tego dnia procentu; wypłata kelnera = (karta
     netto + serwis netto + gotówka 100%) x (100% - procent bar -
     procent kuchnia), czyli domyślnie x70%.

Ustawienia podatkowe znajdziesz w: Ustawienia -> System Napiwków (SPA).

6. Wyjątki procentowe (nowa funkcja)
   Domyślnie każdy kelner oddaje 10% napiwków barowi i 20% kuchni —
   OSOBNO z karty i OSOBNO z serwisu. Można to nadpisać dla WYBRANEGO
   kelnera, tylko na JEDEN konkretny dzień — dowolnymi wartościami,
   także 0%, i to niezależnie dla karty i dla serwisu (np. kelner
   rozlicza kartę standardowo, ale serwis tego samego dnia zupełnie
   inaczej — albo w ogóle nic z serwisu). Kolejny dzień automatycznie
   wraca do wartości domyślnych, chyba że dla niego też ustawiono
   osobny wyjątek. Przykład: 2 października Amelia rozlicza kartę
   standardowo (10%/20%), ale z serwisu tego dnia nic nie oddaje
   (0%/0%) — Karol i Natalia tego samego dnia rozliczają się w
   całości standardowo. Już 3 października wszyscy wracają do
   10%/20% na obu, jeśli nikt nie ustawi nowego wyjątku na ten dzień.

   Kto może to ustawiać:
   - Administrator strony — zawsze, w dwóch miejscach:
     * na froncie, w zakładce Dzień, karta "Wyjątki procentowe
       (ten dzień)" — pojawia się automatycznie nad/pod kartą
       Managera, z listą aktywnych kelnerów tego dnia; każda osoba
       ma dwa osobne wiersze (Karta / Serwis), każdy z własnym
       polem procentu dla baru i dla kuchni, plus przycisk "Reset"
       przy osobach z aktywnym wyjątkiem,
     * w panelu WordPressa: Ustawienia -> System Napiwków (SPA) ->
       sekcja "Wyjątki procentowe" na dole strony — formularz
       (data + kelner + 4 pola procentowe: karta-bar, karta-kuchnia,
       serwis-bar, serwis-kuchnia) oraz tabela wszystkich ustawionych
       wyjątków z możliwością usunięcia.
   - Manager napiwków — TYLKO jeśli administrator to włączy: w
     panelu WordPressa (Ustawienia -> System Napiwków (SPA) ->
     zaznacz "Manager napiwków może ustawiać wyjątki") albo w
     zakładce Ustawienia na froncie (widoczna tylko dla
     administratora). Jeśli wyłączone, manager w ogóle nie widzi tej
     karty ani na froncie, ani nie ma do niej dostępu przez API —
     tylko administrator zarządza wyjątkami.

   Uwaga techniczna: gotówka ("100%" wyliczane z kwoty oddanej do
   baru/kuchni) korzysta z procentu ustawionego dla KARTY, nie dla
   serwisu — to ten sam "strumień" pieniędzy co karta (gotówka
   zostająca przy kelnerze), więc oba są rozliczane tym samym
   procentem; osobny procent dla serwisu dotyczy wyłącznie napiwków
   z serwisu.

   Przeliczenie stosuje się natychmiast po zapisaniu wyjątku —
   wypłaty personelu (bar/kuchnia) za dany dzień od razu odzwierciedlają
   nową kwotę. Przetestowane end-to-end (m.in. dokładnie taki scenariusz
   jak wyżej — karta standardowo, serwis inaczej, tego samego dnia,
   dla tej samej osoby): pula baru/kuchni poprawnie sumuje wkład każdej
   osoby jej własnym procentem osobno dla karty i osobno dla serwisu,
   dzień bez wyjątku poprawnie wraca do 10%/20% na obu, a reset
   przywraca wartości domyślne.

7. Architektura kodu (uwaga dla programisty)
   Ta wtyczka jest budowana narzędziami dostępnymi w tym środowisku
   (bez lokalnego Node/Vite), dlatego frontend to pojedynczy plik
   waniliowego JS montowany w jednym punkcie strony przez shortcode
   (bez przeładowań, komunikacja tylko przez REST API) — spełnia to
   ideę SPA z briefu technicznego, ale nie jest to Vue 3 + Pinia +
   Tailwind + Vite. Backend zaczyna iść w stronę obiektową: kolejne
   funkcjonalności (includes/tips-summary.php, includes/mailer.php,
   includes/notifier.php) to już osobne klasy zamiast kolejnych
   globalnych funkcji:
     - Snspa_TipsSummary — jedno miejsce liczące miesięczne
       podsumowanie napiwków per pracownik; z niego korzystają
       zarówno e-mail, jak i powiadomienia push, więc logika liczenia
       kwot istnieje tylko raz, a nie osobno dla każdego kanału.
     - Snspa_Mailer — szablony i wysyłka e-mail (wp_mail).
     - Snspa_Notifier — szablony i wysyłka push przez wspólny
       mechanizm panelu GastroFlowx (Firebase Cloud Messaging).
   Pełne przejście całego backendu na klasy oraz migracja frontendu na
   Vue 3 + Pinia + Tailwind + Vite (wymagająca configu builda i pliku
   package.json po stronie repo) to sensowny, ale osobny, większy
   etap — chętnie go zaplanuję, jeśli macie środowisko do budowania
   assetów (Node.js) na serwerze/w repo.

8. Design system (spójność z resztą systemu)
   Aktualny wygląd wtyczki dostosowany do makiety "GastroFlowx —
   Pełny Moduł Napiwków" (przesłanej jako wzór), tak aby wtyczka
   wtapiała się w panel innej wtyczki, w którym jest osadzona:
   - Kolory: akcent niebieski #2563EB (Tailwind blue-600, hover
     #1D4ED8), tła białe/#F9FAFB, obwódki #E5E7EB (neutralne) i
     odcienie niebieskiego #DBEAFE/#BFDBFE/#60A5FA (do oznaczania
     kart wprowadzania danych / raportów / kart z konkretnym wynikiem),
     zielony #15803D do kwot wypłat i sukcesu, czerwony do potrąceń.
   - Typografia: zwykły font systemowy (bez szeryfów), bazowy rozmiar
     16px — bez własnego brandingu wtyczki, żeby nie kolidować z
     resztą panelu.
   - Karty: rounded-3xl (24px), przyciski/pola rounded-xl (12px),
     zakładki sekcji i podzakładki w kształcie pigułki/miękkiej
     pigułki — dokładnie jak w przesłanej makiecie.
   - Ikony: Font Awesome 6 (taki sam CDN jak w makiecie) zamiast
     poprzednich emoji.
   - Bez własnego nagłówka/paska tytułowego wtyczki — od razu
     zaczyna się od zakładek Dzień/Raporty/Więcej, żeby nie
     duplikować nagłówka hosta (panelu z innej wtyczki, widocznego
     na przesłanym screenie z bocznym menu Lunch/Grafiki/Grafik/
     Napiwki/Godziny).
   - Nawigacja mobilna/desktopowa oraz cała logika biznesowa
     (obliczenia, uprawnienia, REST API) bez zmian — to była wyłącznie
     zmiana warstwy wizualnej.
   - Świadomie pominięty element: mockup pokazuje dodatkowy,
     najwyższy poziom przełącznika "Panel menadżera / Mój panel" dla
     JEDNEJ osoby przełączającej się między widokiem zarządzania a
     widokiem pracownika. W tej wtyczce role są przypisane per
     użytkownik WordPress i każda rola widzi automatycznie tylko
     swoje karty (bez ręcznego przełącznika) — to prostsze i
     równoważne funkcjonalnie rozwiązanie, więc nie dodawałem
     sztucznego przełącznika, który dublowałby już istniejące
     zachowanie.

9. Poprawka: komunikat "Nieprawidłowa data"
   Jeśli pole daty w zakładce Dzień zostało wyczyszczone (np. przez
   przypadkowe skasowanie na telefonie) albo wpisano w nie coś, co nie
   jest prawdziwą datą (np. 32. dzień miesiąca), wtyczka wysyłała to
   wprost do serwera, który odpowiadał błędem "Nieprawidłowa data".
   Teraz taka wartość jest sprawdzana i odrzucana PRZED wysłaniem —
   pole samo wraca do ostatniej poprawnej daty, bez żadnego zapytania
   do serwera i bez komunikatu o błędzie. Dodatkowo zabezpieczyłem też
   kartę "Wyjątki procentowe" przed sytuacją, w której odpowiedź z
   serwera nie zawiera oczekiwanych danych — wcześniej mogło to
   powodować błąd w konsoli przeglądarki (niewidoczny dla użytkownika,
   ale niepotrzebny), teraz po prostu pokazuje pusty stan.

   Druga, właściwa przyczyna tego samego komunikatu (zapis wyjątku
   procentowego): zapisywanie w bazie działało poprawnie za każdym
   razem, ale serwer, żeby od razu odesłać przeglądarce świeże dane,
   wewnętrznie wywoływał samego siebie przez "podrobiony" obiekt
   zapytania REST, który nie przekazywał daty tak, jak powinien —
   ta wewnętrzna, DRUGA (ukryta) próba odczytu danych kończyła się
   błędem "Nieprawidłowa data", mimo że zapis chwilę wcześniej się
   udał. Stąd dokładnie taki obraz: błąd na ekranie, nic się nie
   przeładowuje, ale po ręcznym odświeżeniu strony wszystko jest już
   poprawnie zapisane. Naprawione poprzez usunięcie tego "podrobionego"
   zapytania wszędzie, gdzie występowało (dotyczyło to też zapisu w
   zakładce Dzień, Rozliczenia serwisu i Rozliczenia z kelnerami) —
   teraz odpowiedź po zapisie jest natychmiastowa i poprawna,
   bez żadnego dodatkowego, niewidocznego kroku.

== E-maile (kod HTML każdej wiadomości) ==
Ustawienia → Napiwki: e-maile – podsumowania dla kelnerów, baru i kuchni. Każda
wiadomość ma własny kod HTML (nagłówek, style, treść, stopka) z edytorem kodu
i podglądem na żywo. Tagi kwot: {wyplata}, {karta_netto}, {serwis_netto}, {gotowka},
{kwota_bar}, {kwota_kuchnia}, {karta}, {serwis}, {razem}, a także {imie}, {miesiac_rok}.
{tresc} wstawia tekst z zakładki „E-mail” aplikacji. Temat jest wspólny z aplikacją.
Pliki: includes/email-designer.php, includes/email-catalog.php.


== 1.2.0 — ikona powiadomień push ==
* Zakładka Powiadomienia: możliwość wgrania własnej ikony dla powiadomienia każdej grupy (Kelnerzy / Bar / Kuchnia) — PNG/JPG/WebP/GIF, maks. 2 MB.
* Bez własnej ikony używana jest ikona ustawiona w panelu GastroFlowx (Powiadomienia push → Szablony i ikony).
* Typy powiadomień Napiwków są widoczne w panelu GastroFlowx; treść nadal edytuje się tutaj (kwoty per osoba).

== 1.2.4 — raport miesięczny dla sekcji (kuchnia / bar) ==
Raporty → „Raport dla sekcji” (widoczne i dostępne wyłącznie dla administratora strony —
uprawnienie manage_options sprawdzane także po stronie serwera, endpoint GET /section-report).
Wybór miesiąca i sekcji (Kuchnia albo Bar), podgląd i „Pobierz PDF” (A4 pionowo).
Jeden wiersz na kelnera za cały miesiąc: kwota dla sekcji z karty (10% bar / 20% kuchnia),
z serwisu, ich suma (dodawane są kwoty) oraz pusta rubryka „Kwota otrzymana (zł)” do
wpisania długopisem oraz wiersz RAZEM. PDF zawiera tylko tytuł, miesiąc i tabelę. Kwoty liczone jak w „Rozliczeniu
z kelnerami” (netto po podatku 26+, z wyjątkami procentowymi). Raport nie zawiera pełnych
napiwków kelnerów ani udziału drugiej sekcji; gotówka jest pominięta.

== 1.3.0 — Szybkie napiwki (telefon + PIN) ==
Osoby wyznaczone przez administratora wprowadzają napiwki z KARTY i SERWISU kelnerów
z telefonu, wyłącznie za dzisiejszy dzień.
- Adres: https://<strona>/?napiwki-szybkie (osobna, pełnoekranowa strona z manifestem —
  „Dodaj do ekranu początkowego” tworzy ikonę jak aplikacja). Alternatywnie shortcode
  [napiwki_szybkie] na dowolnej stronie.
- Logowanie PIN-em (4–8 cyfr, unikalny, przechowywany jako hash). Limit: 5 błędnych PIN-ów
  z adresu IP i 30 łącznie na 15 minut. Sesja ważna do końca dnia; następnego dnia PIN
  trzeba wpisać ponownie. Odebranie dostępu działa natychmiast.
- Lista kelnerów: obsada ustawiona przez administratora na ten dzień, a bez niej — kelnerzy
  z Grafiku (opublikowane zmiany), plus każdy, kto ma już wpis. Osobę można dodać z listy
  lub usunąć.
- Zapis działa jak zapis kierownika w zakładce „Dzień”: zastępuje karty i serwis całego
  dnia i przelicza dzień. Jeśli kierownik już wpisał kwoty — formularz otwiera się z nimi.
- Po zapisie: komunikat „Dane zostały zapisane” (kto, o której), tabela podsumowania
  i przycisk „Edytuj”.
- Ustawienia → „Szybkie napiwki”: adres strony, nadawanie/zmiana/odbieranie PIN-ów,
  obsada dnia (z podpowiedzią z Grafiku). Tylko administrator strony.
