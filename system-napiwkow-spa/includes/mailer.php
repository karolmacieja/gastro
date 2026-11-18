<?php
if (!defined('ABSPATH')) exit;

/**
 * Snspa_Mailer
 * Obsługuje edytowalne szablony e-mail i ręczne (nigdy automatyczne) wysyłanie
 * podsumowań miesięcznych do pracowników, z podziałem na role:
 *  - 'kelner'  -> wysyła manager (karta/serwis/gotówka + kwoty do oddania barowi/kuchni + wypłata 70%)
 *  - 'bar'     -> wysyła admin_bar (zarobek barmana z napiwków w danym miesiącu)
 *  - 'kuchnia' -> wysyła admin_kuchnia (zarobek kucharza z napiwków w danym miesiącu)
 */
class Snspa_Mailer {

    const OPTION_PREFIX = 'sn_email_tpl_';

    public static function can_manage($group, array $flags) {
        return Snspa_TipsSummary::can_manage($group, $flags);
    }

    public static function default_template($group) {
        $defaults = [
            'kelner' => [
                'subject' => 'Podsumowanie napiwków — {miesiac_rok}',
                'body' => "Cześć {imie},\n\nOto podsumowanie Twoich napiwków za {miesiac_rok}:\n\n- Karta (netto): {karta_netto} zł\n- Serwis (netto): {serwis_netto} zł\n- Gotówka (100%): {gotowka} zł\n\nDo oddania:\n- Bar (10%): {kwota_bar} zł\n- Kuchnia (20%): {kwota_kuchnia} zł\n\nTwoja wypłata (70%): {wyplata} zł\n\nPozdrawiamy,\nZespół",
            ],
            'bar' => [
                'subject' => 'Twój zarobek z napiwków — {miesiac_rok}',
                'body' => "Cześć {imie},\n\nOto podsumowanie Twojego zarobku z napiwków (bar) za {miesiac_rok}:\n\n- Z gotówki: {gotowka} zł\n- Z karty: {karta} zł\n- Z serwisu: {serwis} zł\n- Razem: {razem} zł\n\nPozdrawiamy,\nZespół",
            ],
            'kuchnia' => [
                'subject' => 'Twój zarobek z napiwków — {miesiac_rok}',
                'body' => "Cześć {imie},\n\nOto podsumowanie Twojego zarobku z napiwków (kuchnia) za {miesiac_rok}:\n\n- Z gotówki: {gotowka} zł\n- Z karty: {karta} zł\n- Z serwisu: {serwis} zł\n- Razem: {razem} zł\n\nPozdrawiamy,\nZespół",
            ],
        ];
        return $defaults[$group] ?? ['subject' => '', 'body' => ''];
    }

    public static function get_template($group) {
        $stored = get_option(self::OPTION_PREFIX . $group);
        if (empty($stored) || !is_array($stored) || empty($stored['body'])) {
            return self::default_template($group);
        }
        return ['subject' => $stored['subject'] ?? '', 'body' => $stored['body'] ?? ''];
    }

    public static function save_template($group, $subject, $body) {
        update_option(self::OPTION_PREFIX . $group, ['subject' => sanitize_text_field($subject), 'body' => wp_kses_post($body)]);
        return self::get_template($group);
    }

    /** Zbiera dane i wysyła e-maile ręcznie (na żądanie) dla wskazanej grupy i miesiąca. Zwraca statystyki. */
    public static function send_for_group($group, $m, $y) {
        $tpl = self::get_template($group);
        $entries = Snspa_TipsSummary::collect($group, $m, $y);

        $sent = 0; $skipped = 0; $recipients = []; $errors = [];
        foreach ($entries as $entry) {
            $user = $entry['user'];
            if (empty($user->user_email)) { $skipped++; continue; }
            // Wysyłka w kodzie HTML wiadomości (Ustawienia → Napiwki: e-maile).
            $ok = Snspa_Email_Designer::send_message(
                $user->user_email,
                'tips_' . $group,
                $entry['vars'],
                Snspa_Email_Designer::render_body($tpl['body'], $entry['vars']),
                Snspa_TipsSummary::render($tpl['subject'], $entry['vars'])
            );
            if ($ok) { $sent++; $recipients[] = $user->display_name; } else { $errors[] = $user->display_name; }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'recipients' => $recipients, 'errors' => $errors];
    }
}
