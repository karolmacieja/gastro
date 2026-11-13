CZCIONKI PDF — Menu GastroFlowX
================================
Dompdf ładuje je z absolutnej ścieżki serwera (plugin_dir_path()).
Dla każdej roli używany jest PIERWSZY istniejący plik z listy:

  Kategorie („zupy”, „przystawki”)   Poppins-<Grubość>.ttf   (dołączone: Regular…Black)
  Podtytuły („NA POCZĄTEK”)          wybór w Ustawieniach PDF: SweetBelly-Sans.ttf (domyślnie),
                                     SweetBelly-Script.ttf lub AdornGarland.ttf
                                     → zamiennik: SweetBelly-Sans.ttf, potem Allura-Regular.ttf
  Nazwa dania                        Fabrikat Mono Bold LUB Medium (wybór w Ustawieniach PDF)
                                     → zamiennik: druga grubość Fabrikat, potem MyriadPro-Bold.ttf
  Opis dania                         Fabrikat Mono Regular      → zamiennik: MyriadPro-Regular.ttf

  Fabrikat Mono wykrywany jest PO NAZWIE PLIKU — działa np.:
    FabrikatMono-Regular.ttf / FabrikatMono-Bold.ttf
    Fabrikat Mono Regular.ttf / Fabrikat Mono Bold.ttf
    HVD Fabrikat Mono.ttf     / HVD Fabrikat Mono Bold.ttf
  (pliki Italic są pomijane).

SweetBelly-Sans.ttf / SweetBelly-Script.ttf — z plików OTF Sweet Belly
(© 2017 Angga Mahardika) dostarczonych przez właściciela: przekonwertowane
do TTF (kontury kwadratowe dla Dompdf), z dorysowanymi z glifów kroju
polskimi znakami Ą Ę Ć Ł Ń Ó Ś Ź Ż; małe litery wskazują na wersaliki
(krój ma tylko wersaliki). Używaj zgodnie z licencją kroju.

AdornGarland.ttf, FabrikatMono-*.ttf i MyriadPro-*.ttf dostarczył
właściciel wtyczki (pliki na jego licencji). Zamienniki OFL są używane
tylko, gdy któregoś pliku zabraknie.

WAŻNE:
* Wymagany format .ttf. Myriad Pro zwykle jest dystrybuowany jako .otf
  z konturami CFF — Dompdf ich nie obsługuje. Przekonwertuj do TTF
  (np. FontForge, transfonter.org) upewniając się, że licencja na to pozwala.
* Nie używaj czcionek „variable” (np. Poppins[wght].ttf).
* Po podmianie pliku cache odświeża się sam (nazwa rodziny zależy od daty pliku).

Dołączone pliki (Poppins, Allura, Source Sans 3) — licencja SIL Open Font License 1.1.
