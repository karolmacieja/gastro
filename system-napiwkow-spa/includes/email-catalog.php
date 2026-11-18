<?php
if (!defined('ABSPATH')) exit;

/**
 * Snspa_Email_Catalog
 * E-maile Systemu Napiwków (podsumowania miesięczne dla kelnerów, baru i
 * kuchni) - każdy z domyślnym kodem HTML, tagami kwot i przykładowymi
 * danymi do podglądu. Kod edytuje się w „Ustawienia → Napiwki: e-maile”.
 * Temat nadal jest wspólny z zakładką „E-mail” aplikacji.
 */
class Snspa_Email_Catalog {

    const ACCENT = '#2563EB';

    public static function config() {
        return [
            'slug'       => 'snspa',
            'page_title' => 'System Napiwków — e-maile',
            'menu_title' => 'Napiwki: e-maile',
            'parent'     => 'options-general.php',
            'capability' => 'manage_options',
            'registry'   => [__CLASS__, 'registry'],
            'app_url'    => 'home_url',
        ];
    }

    private static function tpl($inner) {
        return Snspa_Email_Designer::starter_html([
            'brand'     => get_bloginfo('name'),
            'tagline'   => 'Podsumowanie napiwków',
            'inner'     => $inner,
            'accent'    => self::ACCENT,
            'header_bg' => self::ACCENT,
            'footer'    => get_bloginfo('name') . '<br>Wiadomość wysłana z Systemu Napiwków.',
        ]);
    }

    private static function p($t) {
        return "\n              <p style=\"margin:0 0 14px;\">" . $t . '</p>';
    }

    private static function h1($t) {
        return "\n              <h1 style=\"margin:0 0 18px;font-size:21px;line-height:1.35;color:#1F2937;\">" . $t . '</h1>';
    }

    private static function highlight($label, $tag) {
        return '
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:8px 0 22px;">
                <tr>
                  <td align="center" bgcolor="' . self::ACCENT . '" style="padding:20px;border-radius:10px;color:#ffffff;">
                    <div style="font-size:13px;letter-spacing:.06em;text-transform:uppercase;opacity:.85;">' . $label . '</div>
                    <div style="font-size:32px;font-weight:700;line-height:1.3;">' . $tag . ' zł</div>
                  </td>
                </tr>
              </table>';
    }

    public static function registry() {
        $kelner_html = self::tpl(
            self::h1('Podsumowanie napiwków — {miesiac_rok}')
            . self::p('Cześć {imie},')
            . self::p('oto podsumowanie Twoich napiwków za {miesiac_rok}:')
            . self::highlight('Twoja wypłata (70%)', '{wyplata}')
            . Snspa_Email_Designer::starter_box([
                'Karta (netto)'  => '{karta_netto} zł',
                'Serwis (netto)' => '{serwis_netto} zł',
                'Gotówka (100%)' => '{gotowka} zł',
            ], self::ACCENT)
            . self::p('<strong>Do oddania:</strong>')
            . Snspa_Email_Designer::starter_box([
                'Bar (10%)'     => '{kwota_bar} zł',
                'Kuchnia (20%)' => '{kwota_kuchnia} zł',
            ], '#F59E0B')
            . self::p('Pozdrawiamy,<br>Zespół')
        );

        $staff_html = function ($dzial) {
            return self::tpl(
                self::h1('Twój zarobek z napiwków — {miesiac_rok}')
                . self::p('Cześć {imie},')
                . self::p('oto podsumowanie Twojego zarobku z napiwków (' . $dzial . ') za {miesiac_rok}:')
                . self::highlight('Razem', '{razem}')
                . Snspa_Email_Designer::starter_box([
                    'Z gotówki' => '{gotowka} zł',
                    'Z karty'   => '{karta} zł',
                    'Z serwisu' => '{serwis} zł',
                ], self::ACCENT)
                . self::p('Pozdrawiamy,<br>Zespół')
            );
        };

        $groups = [
            'kelner' => [
                'label' => 'Podsumowanie napiwków — kelnerzy', 'recipient' => 'kelnerzy',
                'desc'  => 'Wysyłana przez managera przyciskiem „Wyślij teraz” w zakładce E-mail.',
                'html'  => $kelner_html,
                'vars'  => [
                    'imie' => 'imię i nazwisko', 'miesiac_rok' => 'miesiąc i rok',
                    'karta_netto' => 'karta (netto)', 'serwis_netto' => 'serwis (netto)', 'gotowka' => 'gotówka (100%)',
                    'kwota_bar' => 'kwota do oddania barowi', 'kwota_kuchnia' => 'kwota do oddania kuchni', 'wyplata' => 'wypłata (70%)',
                ],
                'sample' => [
                    'imie' => 'Anna Kowalska', 'miesiac_rok' => 'wrzesień 2026', 'karta_netto' => '1,240.50', 'serwis_netto' => '860.00',
                    'gotowka' => '540.00', 'kwota_bar' => '264.05', 'kwota_kuchnia' => '528.10', 'wyplata' => '1,848.35',
                ],
            ],
            'bar' => [
                'label' => 'Zarobek z napiwków — bar', 'recipient' => 'barmani',
                'desc'  => 'Wysyłana przez admina baru przyciskiem „Wyślij teraz” w zakładce E-mail.',
                'html'  => $staff_html('bar'),
                'vars'  => ['imie' => 'imię i nazwisko', 'miesiac_rok' => 'miesiąc i rok', 'gotowka' => 'kwota z gotówki', 'karta' => 'kwota z karty', 'serwis' => 'kwota z serwisu', 'razem' => 'kwota razem'],
                'sample' => ['imie' => 'Piotr Wiśniewski', 'miesiac_rok' => 'wrzesień 2026', 'gotowka' => '310.00', 'karta' => '455.20', 'serwis' => '190.00', 'razem' => '955.20'],
            ],
            'kuchnia' => [
                'label' => 'Zarobek z napiwków — kuchnia', 'recipient' => 'kucharze',
                'desc'  => 'Wysyłana przez admina kuchni przyciskiem „Wyślij teraz” w zakładce E-mail.',
                'html'  => $staff_html('kuchnia'),
                'vars'  => ['imie' => 'imię i nazwisko', 'miesiac_rok' => 'miesiąc i rok', 'gotowka' => 'kwota z gotówki', 'karta' => 'kwota z karty', 'serwis' => 'kwota z serwisu', 'razem' => 'kwota razem'],
                'sample' => ['imie' => 'Marek Nowak', 'miesiac_rok' => 'wrzesień 2026', 'gotowka' => '620.00', 'karta' => '910.40', 'serwis' => '380.00', 'razem' => '1,910.40'],
            ],
        ];

        $list = [];
        foreach ($groups as $group => $g) {
            $list[] = [
                'key'             => 'tips_' . $group,
                'label'           => $g['label'],
                'type'            => 'manual',
                'recipient'       => $g['recipient'],
                'description'     => $g['desc'],
                'subject'         => Snspa_Mailer::default_template($group)['subject'],
                'html'            => $g['html'],
                'vars'            => $g['vars'],
                'sample'          => $g['sample'],
                'tresc'           => true,
                'sample_tresc'    => function () use ($group) { return Snspa_Mailer::get_template($group)['body']; },
                'load_subject'    => function () use ($group) { return Snspa_Mailer::get_template($group)['subject']; },
                'save_subject'    => function ($subject) use ($group) {
                    Snspa_Mailer::save_template($group, $subject, Snspa_Mailer::get_template($group)['body']);
                },
                'default_subject' => function () use ($group) { return Snspa_Mailer::default_template($group)['subject']; },
            ];
        }
        return $list;
    }
}

Snspa_Email_Designer::init(Snspa_Email_Catalog::config());
