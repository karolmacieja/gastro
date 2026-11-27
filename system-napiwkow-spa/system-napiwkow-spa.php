<?php
/*
Plugin Name: Gastronomia - System Napiwków SPA
Description: Responsywna aplikacja SPA (jeden widok, karty według roli) zgodna z logiką obliczeniową wtyczki "System Napiwków" (ulga podatkowa < 26 lat, pule 10% bar / 20% kuchnia / 70% kelner, rozliczanie godzinowe barmanów i kucharzy). Korzysta z tych samych tabel bazy danych, ustawień i pól profilu użytkownika co oryginalna wtyczka, więc może ją zastąpić bez utraty danych.
Version: 1.3.0
Author: Claude
*/

if (!defined('ABSPATH')) exit;

define('SNSPA_VERSION', '1.3.0');
define('SNSPA_REST_NS', 'sn-spa/v1');
define('SNSPA_DB_VERSION', '1.2');

/* =========================================================
 * 1. INSTALACJA — te same tabele co oryginalna wtyczka
 * ========================================================= */
register_activation_hook(__FILE__, 'snspa_install');
// Automatyczna migracja bazy przy aktualizacji plików wtyczki — nie wymaga
// ręcznej dezaktywacji/aktywacji, żeby nowa tabela pojawiła się na produkcji.
add_action('plugins_loaded', 'snspa_maybe_upgrade_db');
function snspa_maybe_upgrade_db() {
    if (get_option('sn_db_version') !== SNSPA_DB_VERSION) {
        snspa_install();
        update_option('sn_db_version', SNSPA_DB_VERSION);
    }
}
function snspa_install() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = [];
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sn_waiter_tips (id mediumint(9) NOT NULL AUTO_INCREMENT, date date NOT NULL, waiter_id bigint(20) NOT NULL, amount decimal(10,2) NOT NULL, PRIMARY KEY (id)) $charset_collate;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sn_waiter_service (id mediumint(9) NOT NULL AUTO_INCREMENT, date date NOT NULL, waiter_id bigint(20) NOT NULL, amount decimal(10,2) NOT NULL, PRIMARY KEY (id)) $charset_collate;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sn_waiter_cash (id mediumint(9) NOT NULL AUTO_INCREMENT, date date NOT NULL, waiter_id bigint(20) NOT NULL, amount decimal(10,2) NOT NULL, dept varchar(10) NOT NULL, PRIMARY KEY (id)) $charset_collate;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sn_staff_work (id mediumint(9) NOT NULL AUTO_INCREMENT, date date NOT NULL, user_id bigint(20) NOT NULL, hours decimal(5,2) DEFAULT 0 NOT NULL, service_hours decimal(5,2) DEFAULT 0 NOT NULL, extra_card decimal(10,2) DEFAULT 0 NOT NULL, calc_cash decimal(10,2) DEFAULT 0 NOT NULL, calc_card decimal(10,2) DEFAULT 0 NOT NULL, calc_service decimal(10,2) DEFAULT 0 NOT NULL, type varchar(20) NOT NULL, PRIMARY KEY (id)) $charset_collate;";
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sn_reservations (id mediumint(9) NOT NULL AUTO_INCREMENT, date date NOT NULL, name varchar(255) NOT NULL, waiter_ids text NOT NULL, barman_ids text NOT NULL, kucharz_ids text NOT NULL, amount decimal(10,2) NOT NULL, payment_type varchar(50) NOT NULL, bar_percent int(11) NOT NULL DEFAULT 10, ktc_percent int(11) NOT NULL DEFAULT 20, PRIMARY KEY (id)) $charset_collate;";
    // Wyjątki procentowe: dla konkretnego kelnera i konkretnego dnia można nadpisać
    // domyślny podział 10% bar / 20% kuchnia dowolnymi wartościami (także 0%).
    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sn_percent_overrides (id mediumint(9) NOT NULL AUTO_INCREMENT, date date NOT NULL, waiter_id bigint(20) NOT NULL, bar_percent decimal(5,2) NOT NULL, kitchen_percent decimal(5,2) NOT NULL, created_by bigint(20) NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY date_waiter (date, waiter_id)) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    foreach ($sql as $s) dbDelta($s);
    $wpdb->query("ALTER TABLE {$wpdb->prefix}sn_staff_work ADD COLUMN IF NOT EXISTS extra_card decimal(10,2) DEFAULT 0 NOT NULL");
    // v1.2: procent baru/kuchni można teraz ustawić OSOBNO dla karty i osobno dla serwisu
    // (np. kelner standardowo z karty, ale inaczej z serwisu tego samego dnia).
    $wpdb->query("ALTER TABLE {$wpdb->prefix}sn_percent_overrides ADD COLUMN IF NOT EXISTS card_bar_percent decimal(5,2) NULL");
    $wpdb->query("ALTER TABLE {$wpdb->prefix}sn_percent_overrides ADD COLUMN IF NOT EXISTS card_kitchen_percent decimal(5,2) NULL");
    $wpdb->query("ALTER TABLE {$wpdb->prefix}sn_percent_overrides ADD COLUMN IF NOT EXISTS service_bar_percent decimal(5,2) NULL");
    $wpdb->query("ALTER TABLE {$wpdb->prefix}sn_percent_overrides ADD COLUMN IF NOT EXISTS service_kitchen_percent decimal(5,2) NULL");
    // Migracja istniejących wierszy (sprzed rozdzielenia karta/serwis): stara jedna para
    // procentów obowiązywała jednakowo dla obu, więc kopiujemy ją do obu nowych par.
    $wpdb->query("UPDATE {$wpdb->prefix}sn_percent_overrides SET
        card_bar_percent = COALESCE(card_bar_percent, bar_percent),
        card_kitchen_percent = COALESCE(card_kitchen_percent, kitchen_percent),
        service_bar_percent = COALESCE(service_bar_percent, bar_percent),
        service_kitchen_percent = COALESCE(service_kitchen_percent, kitchen_percent)
        WHERE card_bar_percent IS NULL OR service_bar_percent IS NULL");
}

/* =========================================================
 * 2. HELPERY OBLICZENIOWE — logika 1:1 z oryginalnej wtyczki
 * ========================================================= */
function snspa_is_user_active_on_date($user_id, $action_date) {
    $end_date = get_user_meta($user_id, 'sn_employment_end', true);
    if (empty($end_date)) return true;
    return strtotime($action_date) <= strtotime($end_date);
}

function snspa_is_tax_exempt($user_id, $action_date) {
    $birth_date = get_user_meta($user_id, 'user_birth_date', true);
    $override_date = get_user_meta($user_id, 'user_tax_extension', true);
    if (empty($birth_date)) return false;
    try {
        $dob = new DateTime($birth_date);
        $action = new DateTime($action_date);
        $birthday_26 = clone $dob;
        $birthday_26->modify('+26 years');
        if (!empty($override_date)) {
            $custom_end = new DateTime($override_date);
            return $action <= $custom_end;
        }
        return $action <= $birthday_26;
    } catch (Exception $e) {
        return false;
    }
}

function snspa_tax_settings() {
    return [
        'cardActive' => (bool) get_option('sn_tax_card_over_26'),
        'servActive' => (bool) get_option('sn_tax_service_over_26'),
        'rate'       => floatval(get_option('sn_tax_rate_value', 0)) / 100,
    ];
}

/* =========================================================
 * WYJĄTKI PROCENTOWE — domyślnie każdy kelner oddaje 10% barowi i 20%
 * kuchni, ale dla konkretnego dnia i konkretnej osoby administrator (albo,
 * jeśli włączone w ustawieniach, manager napiwków) może to nadpisać
 * dowolnymi wartościami — także 0%. Brak wpisu = wartości domyślne.
 * ========================================================= */
function snspa_default_percents() {
    return ['card' => ['bar' => 0.10, 'kitchen' => 0.20], 'service' => ['bar' => 0.10, 'kitchen' => 0.20]];
}

// Wszystkie nadpisania dla JEDNEGO dnia naraz (klucz = waiter_id) — jedno zapytanie.
function snspa_get_day_percent_overrides($date) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT waiter_id, card_bar_percent, card_kitchen_percent, service_bar_percent, service_kitchen_percent FROM {$wpdb->prefix}sn_percent_overrides WHERE date=%s",
        $date
    ));
    $map = [];
    foreach ($rows as $r) {
        $map[intval($r->waiter_id)] = [
            'card' => ['bar' => floatval($r->card_bar_percent) / 100, 'kitchen' => floatval($r->card_kitchen_percent) / 100],
            'service' => ['bar' => floatval($r->service_bar_percent) / 100, 'kitchen' => floatval($r->service_kitchen_percent) / 100],
        ];
    }
    return $map;
}

// Wszystkie nadpisania dla CAŁEGO MIESIĄCA naraz (klucz = "data|waiter_id") — jedno zapytanie,
// używane w raportach miesięcznych, żeby nie odpytywać bazy osobno dla każdego dnia.
function snspa_get_month_percent_overrides($m, $y) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT date, waiter_id, card_bar_percent, card_kitchen_percent, service_bar_percent, service_kitchen_percent FROM {$wpdb->prefix}sn_percent_overrides WHERE MONTH(date)=%d AND YEAR(date)=%d",
        $m, $y
    ));
    $map = [];
    foreach ($rows as $r) {
        $map[$r->date . '|' . intval($r->waiter_id)] = [
            'card' => ['bar' => floatval($r->card_bar_percent) / 100, 'kitchen' => floatval($r->card_kitchen_percent) / 100],
            'service' => ['bar' => floatval($r->service_bar_percent) / 100, 'kitchen' => floatval($r->service_kitchen_percent) / 100],
        ];
    }
    return $map;
}

function snspa_percent_from_day_map($map, $waiter_id) {
    return $map[intval($waiter_id)] ?? snspa_default_percents();
}

function snspa_percent_from_month_map($map, $date, $waiter_id) {
    return $map[$date . '|' . intval($waiter_id)] ?? snspa_default_percents();
}

function snspa_manager_can_set_percent_overrides() {
    return (bool) get_option('sn_manager_percent_override_enabled');
}

// Silnik przeliczeń dnia — identyczny algorytm jak sn_v24_recalculate(), ale pula procentowa
// dla baru/kuchni jest teraz liczona PER KELNER (żeby uwzględnić ewentualne wyjątki), a nie
// jedną wspólną stawką dla wszystkich naraz.
function snspa_recalculate($date) {
    global $wpdb;
    $t = snspa_tax_settings();
    $overrides = snspa_get_day_percent_overrides($date);

    $all_cards = $wpdb->get_results($wpdb->prepare("SELECT waiter_id, amount FROM {$wpdb->prefix}sn_waiter_tips WHERE date = %s", $date));
    $card_net_by_waiter = [];
    foreach ($all_cards as $row) {
        $amt = floatval($row->amount);
        $exempt = snspa_is_tax_exempt($row->waiter_id, $date);
        if ($t['cardActive'] && !$exempt) $amt *= (1 - $t['rate']);
        $wid = intval($row->waiter_id);
        $card_net_by_waiter[$wid] = ($card_net_by_waiter[$wid] ?? 0) + $amt;
    }

    $all_service = $wpdb->get_results($wpdb->prepare("SELECT waiter_id, amount FROM {$wpdb->prefix}sn_waiter_service WHERE date = %s", $date));
    $service_net_by_waiter = [];
    foreach ($all_service as $row) {
        $amt = floatval($row->amount);
        $exempt = snspa_is_tax_exempt($row->waiter_id, $date);
        if ($t['servActive'] && !$exempt) $amt *= (1 - $t['rate']);
        $wid = intval($row->waiter_id);
        $service_net_by_waiter[$wid] = ($service_net_by_waiter[$wid] ?? 0) + $amt;
    }

    // Sumujemy wkład KAŻDEGO kelnera do puli baru/kuchni jego WŁASNYM procentem
    // (domyślnym 10%/20% albo wyjątkiem na ten dzień), zamiast mnożyć jedną wspólną sumę
    // przez jedną wspólną stawkę — to jedyny sposób, żeby wyjątek dla jednej osoby nie
    // wpływał na resztę.
    $waiter_ids = array_unique(array_merge(array_keys($card_net_by_waiter), array_keys($service_net_by_waiter)));
    $dept_card_pool = ['bar' => 0, 'kuchnia' => 0];
    $dept_service_pool = ['bar' => 0, 'kuchnia' => 0];
    foreach ($waiter_ids as $wid) {
        $pct = snspa_percent_from_day_map($overrides, $wid);
        $cnet = $card_net_by_waiter[$wid] ?? 0;
        $snet = $service_net_by_waiter[$wid] ?? 0;
        $dept_card_pool['bar'] += $cnet * $pct['card']['bar'];
        $dept_card_pool['kuchnia'] += $cnet * $pct['card']['kitchen'];
        $dept_service_pool['bar'] += $snet * $pct['service']['bar'];
        $dept_service_pool['kuchnia'] += $snet * $pct['service']['kitchen'];
    }

    $configs = [
        'barman'  => ['dept' => 'bar'],
        'kucharz' => ['dept' => 'kuchnia'],
    ];

    foreach ($configs as $type => $conf) {
        $dept = $conf['dept'];
        $cash_pool = $wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM {$wpdb->prefix}sn_waiter_cash WHERE date = %s AND dept = %s", $date, $dept)) ?: 0;
        $total_h  = $wpdb->get_var($wpdb->prepare("SELECT SUM(hours) FROM {$wpdb->prefix}sn_staff_work WHERE date = %s AND type = %s", $date, $type)) ?: 0;
        $total_sh = $wpdb->get_var($wpdb->prepare("SELECT SUM(service_hours) FROM {$wpdb->prefix}sn_staff_work WHERE date = %s AND type = %s", $date, $type)) ?: 0;
        $total_extra_card = $wpdb->get_var($wpdb->prepare("SELECT SUM(extra_card) FROM {$wpdb->prefix}sn_staff_work WHERE date = %s AND type = %s", $date, $type)) ?: 0;

        $remaining_card_pool = max(0, $dept_card_pool[$dept] - $total_extra_card);

        $cash_rate    = ($total_h > 0) ? ($cash_pool / $total_h) : 0;
        $card_rate    = ($total_h > 0) ? ($remaining_card_pool / $total_h) : 0;
        $service_rate = ($total_sh > 0) ? ($dept_service_pool[$dept] / $total_sh) : 0;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}sn_staff_work
             SET calc_cash = hours * %f,
                 calc_card = (hours * %f) + extra_card,
                 calc_service = service_hours * %f
             WHERE date = %s AND type = %s",
            $cash_rate, $card_rate, $service_rate, $date, $type
        ));
    }
}

// Gotówka "100%" kelnera na podstawie kwoty oddanej do baru/kuchni. Domyślnie bar=10%
// (mnożnik x10) i kuchnia=20% (mnożnik x5), ale jeśli dla tego dnia/kelnera obowiązuje
// wyjątek procentowy, przelicza się z FAKTYCZNEGO procentu (total = kwota / procent).
function snspa_cash100_from_row($cash_bar, $cash_ktc, $bar_pct = 0.10, $kitchen_pct = 0.20) {
    if ($cash_bar > 0 && $bar_pct > 0) return $cash_bar / $bar_pct;
    if ($cash_ktc > 0 && $kitchen_pct > 0) return $cash_ktc / $kitchen_pct;
    return 0;
}

/* =========================================================
 * SYNCHRONIZACJA GOTÓWKI KELNERA MIĘDZY BAREM A KUCHNIĄ
 * Kwota oddana do baru to zawsze X% całości, do kuchni Y% całości
 * (domyślnie 10%/20%, chyba że dla tego dnia/kelnera obowiązuje wyjątek),
 * więc znając jedną wartość i oba procenty można wyliczyć drugą:
 *   total = bar / bar_pct = kuchnia / kitchen_pct
 * Dzięki temu wystarczy, że KTOKOLWIEK (kelner, admin baru lub
 * admin kuchni) wpisze jedną z wartości, a druga uzupełni się sama —
 * u wszystkich stron widoczne są od razu obie kwoty, z uwzględnieniem
 * ewentualnego wyjątku procentowego na ten dzień.
 * ========================================================= */
function snspa_upsert_cash_row($date, $waiter_id, $dept, $amount) {
    global $wpdb;
    $amount = round(floatval($amount), 2);
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}sn_waiter_cash WHERE date=%s AND waiter_id=%d AND dept=%s",
        $date, $waiter_id, $dept
    ));
    if ($amount > 0) {
        if ($existing) $wpdb->update("{$wpdb->prefix}sn_waiter_cash", ['amount' => $amount], ['id' => $existing]);
        else $wpdb->insert("{$wpdb->prefix}sn_waiter_cash", ['date' => $date, 'waiter_id' => $waiter_id, 'amount' => $amount, 'dept' => $dept]);
    } elseif ($existing) {
        $wpdb->delete("{$wpdb->prefix}sn_waiter_cash", ['id' => $existing]);
    }
}

function snspa_sync_waiter_cash($date, $waiter_id, $source_dept, $amount) {
    $amount = floatval($amount);
    $overrides = snspa_get_day_percent_overrides($date);
    $pct = snspa_percent_from_day_map($overrides, $waiter_id);
    // Gotówka jest "przedłużeniem" karty (to samo źródło — pieniądze kelnera zostające
    // przy nim), więc korzysta z procentu ustawionego dla KARTY, nie dla serwisu.
    $card_pct = $pct['card'];
    if ($source_dept === 'bar') {
        $bar = $amount;
        $total = ($card_pct['bar'] > 0) ? ($amount / $card_pct['bar']) : 0;
        $kuchnia = $total * $card_pct['kitchen'];
    } else {
        $kuchnia = $amount;
        $total = ($card_pct['kitchen'] > 0) ? ($amount / $card_pct['kitchen']) : 0;
        $bar = $total * $card_pct['bar'];
    }
    snspa_upsert_cash_row($date, $waiter_id, 'bar', $bar);
    snspa_upsert_cash_row($date, $waiter_id, 'kuchnia', $kuchnia);
}

require_once __DIR__ . '/includes/user-fields.php';
require_once __DIR__ . '/includes/tips-summary.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/email-designer.php';
require_once __DIR__ . '/includes/email-catalog.php';
require_once __DIR__ . '/includes/notifier.php';
require_once __DIR__ . '/includes/rest-api.php';
require_once __DIR__ . '/includes/shortcode.php';
require_once __DIR__ . '/includes/quick-entry.php';
