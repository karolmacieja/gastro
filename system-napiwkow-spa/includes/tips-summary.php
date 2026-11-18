<?php
if (!defined('ABSPATH')) exit;

/**
 * Snspa_TipsSummary
 * Zbiera miesięczne podsumowanie napiwków dla grupy odbiorców ('kelner'/'bar'/'kuchnia'),
 * gotowe do wstawienia w szablon (e-mail lub push) — używane wspólnie przez Snspa_Mailer
 * i Snspa_Notifier, żeby logika liczenia kwot istniała w jednym miejscu.
 */
class Snspa_TipsSummary {

    const ROLES = ['kelner', 'bar', 'kuchnia'];

    public static function can_manage($group, array $flags) {
        if ($flags['isAdmin']) return true;
        if ($group === 'kelner') return $flags['isManager'];
        if ($group === 'bar') return $flags['isAdminBar'];
        if ($group === 'kuchnia') return $flags['isAdminKtc'];
        return false;
    }

    public static function months() {
        return ['', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
    }

    public static function render($tpl, array $vars) {
        $out = $tpl;
        foreach ($vars as $key => $val) {
            $out = str_replace('{' . $key . '}', $val, $out);
        }
        return $out;
    }

    /**
     * Zwraca listę [{ user: WP_User, vars: [...] }] dla pracowników z niezerową
     * aktywnością w danym miesiącu. Pomija tych bez żadnych napiwków w tym okresie.
     */
    public static function collect($group, $m, $y) {
        global $wpdb;
        $tax = snspa_tax_settings();
        $miesiac_rok = self::months()[$m] . ' ' . $y;
        $out = [];

        if ($group === 'kelner') {
            foreach (get_users(['role' => 'kelner']) as $w) {
                $k_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $w->ID, $m, $y));
                $k_net = 0;
                foreach ($k_entries as $e) { $ex = snspa_is_tax_exempt($w->ID, $e->date); $k_net += ($tax['cardActive'] && !$ex) ? floatval($e->amount) * (1 - $tax['rate']) : floatval($e->amount); }
                $s_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $w->ID, $m, $y));
                $s_net = 0;
                foreach ($s_entries as $e) { $ex = snspa_is_tax_exempt($w->ID, $e->date); $s_net += ($tax['servActive'] && !$ex) ? floatval($e->amount) * (1 - $tax['rate']) : floatval($e->amount); }

                // Gotówka i podział na bar/kuchnię liczone DZIEŃ PO DNIU, bo procent może się różnić
                // w zależności od dnia (wyjątki procentowe) — sumowanie z góry jedną stawką byłoby błędne.
                $month_overrides = snspa_get_month_percent_overrides($m, $y);
                $cash_entries = $wpdb->get_results($wpdb->prepare("SELECT date, SUM(CASE WHEN dept='bar' THEN amount ELSE 0 END) as cb, SUM(CASE WHEN dept='kuchnia' THEN amount ELSE 0 END) as ck FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d GROUP BY date", $w->ID, $m, $y));
                $cash100 = 0;
                foreach ($cash_entries as $ce) {
                    $pct = snspa_percent_from_month_map($month_overrides, $ce->date, $w->ID);
                    $cash100 += snspa_cash100_from_row(floatval($ce->cb), floatval($ce->ck), $pct['card']['bar'], $pct['card']['kitchen']);
                }
                $total = $k_net + $s_net + $cash100;
                if ($total <= 0) continue;

                // Kwoty dla baru/kuchni liczone per dzień z odpowiednim procentem — karta+gotówka
                // procentem karty, serwis procentem serwisu (mogą się różnić tego samego dnia).
                $kwota_bar = 0; $kwota_kuchnia = 0;
                $all_dates = array_unique(array_merge(
                    array_map(function($e){ return $e->date; }, $k_entries),
                    array_map(function($e){ return $e->date; }, $s_entries),
                    array_map(function($ce){ return $ce->date; }, $cash_entries)
                ));
                foreach ($all_dates as $d) {
                    $pct = snspa_percent_from_month_map($month_overrides, $d, $w->ID);
                    $day_card = 0; foreach ($k_entries as $e) if ($e->date === $d) { $ex = snspa_is_tax_exempt($w->ID, $d); $day_card += ($tax['cardActive'] && !$ex) ? floatval($e->amount)*(1-$tax['rate']) : floatval($e->amount); }
                    $day_serv = 0; foreach ($s_entries as $e) if ($e->date === $d) { $ex = snspa_is_tax_exempt($w->ID, $d); $day_serv += ($tax['servActive'] && !$ex) ? floatval($e->amount)*(1-$tax['rate']) : floatval($e->amount); }
                    $day_cash = 0; foreach ($cash_entries as $ce) if ($ce->date === $d) $day_cash = snspa_cash100_from_row(floatval($ce->cb), floatval($ce->ck), $pct['card']['bar'], $pct['card']['kitchen']);
                    $kwota_bar += ($day_card + $day_cash) * $pct['card']['bar'] + $day_serv * $pct['service']['bar'];
                    $kwota_kuchnia += ($day_card + $day_cash) * $pct['card']['kitchen'] + $day_serv * $pct['service']['kitchen'];
                }
                $wyplata = $total - $kwota_bar - $kwota_kuchnia;

                $out[] = ['user' => $w, 'vars' => [
                    'imie' => $w->display_name,
                    'miesiac_rok' => $miesiac_rok,
                    'karta_netto' => number_format($k_net, 2),
                    'serwis_netto' => number_format($s_net, 2),
                    'gotowka' => number_format($cash100, 2),
                    'kwota_bar' => number_format($kwota_bar, 2),
                    'kwota_kuchnia' => number_format($kwota_kuchnia, 2),
                    'wyplata' => number_format($wyplata, 2),
                ]];
            }
        } elseif (in_array($group, ['bar', 'kuchnia'], true)) {
            $role = $group === 'bar' ? 'barman' : 'kucharz';
            foreach (get_users(['role' => $role]) as $u) {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_staff_work WHERE user_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $u->ID, $m, $y));
                $cash = 0; $card = 0; $service = 0;
                foreach ($rows as $r) { $cash += floatval($r->calc_cash); $card += floatval($r->calc_card); $service += floatval($r->calc_service); }
                $total = $cash + $card + $service;
                if ($total <= 0) continue;

                $out[] = ['user' => $u, 'vars' => [
                    'imie' => $u->display_name,
                    'miesiac_rok' => $miesiac_rok,
                    'gotowka' => number_format($cash, 2),
                    'karta' => number_format($card, 2),
                    'serwis' => number_format($service, 2),
                    'razem' => number_format($total, 2),
                ]];
            }
        }

        return $out;
    }
}
