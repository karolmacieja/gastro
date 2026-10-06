=== GastroFlowx — Grafik → Godziny ===
Stable tag: 1.0.0
Requires PHP: 7.4

Łącznik między Grafikiem Pracy (restaurant-scheduler) a Ewidencją Godzin
(employee-timesheet). W zakładce „Dzień” Ewidencji godzina rozpoczęcia pracy
jest podpowiadana z początku zmiany pracownika w Grafiku.

== Instalacja ==
1. Wtyczki → Dodaj nową → Wyślij wtyczkę → gastroflowx-grafik-godziny.zip → Zainstaluj.
2. Aktywuj. Grafik Pracy i Ewidencja Godzin muszą być aktywne (inaczej
   w wp-admin pojawi się ostrzeżenie).
3. Nic więcej nie trzeba ustawiać — obie wtyczki zostają bez zmian.

== Zasady ==
* Brana jest najwcześniejsza zmiana pracownika w danym dniu.
* Pomijane są wersje robocze z auto-generowania (status „draft”) oraz
  znaczniki menadżera „Nieobecny” / „Dostępny”.
* Ręczny wpis kierownika w Ewidencji (sekcja „Zarządzanie”) ma pierwszeństwo
  przed Grafikiem.
* Podpowiedź jest zaokrąglana przez Ewidencję do 15 minut. Pracownik może ją zmienić;
  zapisany wpis nie zmienia się, gdy później zmieni się grafik.
