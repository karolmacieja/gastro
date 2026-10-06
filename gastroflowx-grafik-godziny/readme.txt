=== GastroFlowx — Grafik i Napiwki → Godziny ===
Stable tag: 1.1.0
Requires PHP: 7.4

Łącznik dla Ewidencji Godzin (employee-timesheet):
* godzina rozpoczęcia pracy podpowiadana z Grafiku Pracy (restaurant-scheduler),
* napiwki dnia pobierane z Systemu Napiwków (system-napiwkow-spa).
Żadna z tych wtyczek nie jest modyfikowana.

== Instalacja ==
1. Wtyczki → Dodaj nową → Wyślij wtyczkę → gastroflowx-grafik-godziny.zip → Zainstaluj.
2. Aktywuj. Wymagana: Ewidencja Godzin. Grafik i Napiwki — każdy działa
   niezależnie (brak jednego wyłącza tylko jego część; w wp-admin pojawi się informacja).

== Godzina rozpoczęcia (Grafik) ==
* Najwcześniejsza zmiana pracownika w danym dniu.
* Pomijane: wersje robocze z auto-generowania oraz znaczniki „Nieobecny” / „Dostępny”.

== Napiwki dnia (Napiwki) ==
Kwota = wypłata napiwków tej osoby za ten dzień, liczona tymi samymi wzorami co moduł Napiwków:
* kelner: (karta netto + serwis netto + gotówka 100%) − pula baru − pula kuchni,
  z podatkiem / ulgą do 26 lat i wyjątkami procentowymi danego dnia,
* barman / kucharz / pomoc na barze: udział z gotówki + karty + serwisu
  (wartości wyliczone przez Napiwki po zapisie dnia),
* osoba z oboma udziałami tego samego dnia — suma.
Brak danych w Napiwkach = brak kwoty (pole puste / 0).

Ponieważ kwota jest już po podziale na bar i kuchnię, łącznik ustawia
w Ewidencji „% dla kuchni” i „% dla baru” na 0 (bez podwójnego odliczania).

== Pierwszeństwo ==
Ręczny wpis kierownika w Ewidencji (sekcja „Zarządzanie”) zawsze wygrywa
z Grafikiem i Napiwkami. Dane są odczytywane na bieżąco — zmiana w Napiwkach
od razu zmienia kwotę w Ewidencji; zapisany wpis godzin nie zmienia się po zmianie grafiku.
