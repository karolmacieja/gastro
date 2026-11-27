<?php
/**
 * SZYBKIE NAPIWKI — uproszczone wprowadzanie napiwków z telefonu.
 *
 * Osoba wyznaczona przez administratora otwiera stronę „Szybkie napiwki”
 * (ikona na ekranie telefonu), wpisuje swój PIN i wprowadza napiwki
 * z KARTY i SERWISU kelnerów — wyłącznie za DZISIEJSZY dzień (strefa
 * czasowa WordPressa). Lista kelnerów jest podpowiadana: obsada ustawiona
 * przez administratora na ten dzień, a bez niej — kelnerzy z Grafiku;
 * plus każdy, kto ma już wpis na ten dzień. Osobę można dodać z listy
 * albo usunąć.
 *
 * Zapis zmienia tylko pola zmienione w formularzu (od 1.3.5) — nie kasuje kwot
 * wpisanych w międzyczasie przez innych ani kwot osób spoza formularza; potem
 * przelicza dzień. Jeśli kierownik już coś wpisał — formularz otwiera się z tymi kwotami.
 *
 * Bezpieczeństwo:
 *  - PIN-y przechowywane jako hash (wp_hash_password), unikalne,
 *  - limit błędnych PIN-ów: 5 na adres IP / 15 min oraz 30 łącznie / 15 min,
 *  - po PIN-ie wydawany jest token sesji ważny tylko DO KOŃCA DNIA, na który
 *    się zalogowano (zapis innego dnia jest niemożliwy),
 *  - każdy zapis jest logowany (kto, kiedy).
 */
if (!defined('ABSPATH')) exit;

const SNSPA_QUICK_PEOPLE = 'snspa_quick_people';  // [ user_id => [ 'pin' => hash, 'set' => Y-m-d H:i ] ]
const SNSPA_QUICK_ROSTER = 'snspa_quick_roster';  // [ Y-m-d => [ waiter_id, ... ] ]
const SNSPA_QUICK_LOG    = 'snspa_quick_log';     // [ Y-m-d => [ 'by' => user_id, 'at' => Y-m-d H:i:s ] ]
const SNSPA_WAITER_ORDER = 'snspa_waiter_order';  // [ waiter_id, ... ] — kolejność wyświetlania kelnerów

/* ---------------------------------------------------------
 * Dane pomocnicze
 * --------------------------------------------------------- */

function snspa_quick_today() {
    return current_time('Y-m-d');
}

function snspa_quick_people() {
    $p = get_option(SNSPA_QUICK_PEOPLE, []);
    return is_array($p) ? $p : [];
}

/**
 * Kolejność kelnerów ustawiona przez administratora (zakładka Obsada dnia).
 * Kelnerzy spoza listy (np. nowi) — na końcu, alfabetycznie.
 * @param array $map [ id => nazwa ]
 * @return array [ id => nazwa ] w ustalonej kolejności
 */
function snspa_sort_waiters(array $map) {
    $order = get_option(SNSPA_WAITER_ORDER, []);
    $pos = is_array($order) ? array_flip(array_map('intval', $order)) : [];
    $ids = array_keys($map);
    usort($ids, function ($a, $b) use ($pos, $map) {
        $pa = $pos[$a] ?? PHP_INT_MAX; $pb = $pos[$b] ?? PHP_INT_MAX;
        return $pa === $pb ? strcoll((string) $map[$a], (string) $map[$b]) : ($pa < $pb ? -1 : 1);
    });
    $out = [];
    foreach ($ids as $id) $out[$id] = $map[$id];
    return $out;
}

/** Kelnerzy aktywni w danym dniu: [ id => nazwa ] (w ustalonej kolejności). */
function snspa_quick_all_waiters($date) {
    $out = [];
    foreach (get_users(['role' => 'kelner', 'orderby' => 'display_name']) as $w) {
        if (snspa_is_user_active_on_date($w->ID, $date)) $out[$w->ID] = $w->display_name;
    }
    return snspa_sort_waiters($out);
}

/** Kelnerzy zatrudnieni dziś — lista do ustawiania kolejności. */
function snspa_quick_all_kelner() {
    $out = [];
    foreach (get_users(['role' => 'kelner', 'orderby' => 'display_name']) as $w) {
        if (snspa_is_user_active_on_date($w->ID, current_time('Y-m-d'))) $out[$w->ID] = $w->display_name;
    }
    return $out;
}

/** Kelnerzy na zmianie wg Grafiku (opublikowane zmiany, bez nieobecności). */
function snspa_quick_schedule_ids($date) {
    global $wpdb;
    if (!defined('RS_TABLE_SHIFTS')) return [];
    $table = $wpdb->prefix . RS_TABLE_SHIFTS;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT user_id FROM {$table} WHERE shift_date = %s AND status NOT IN ('draft', 'absent', 'available')", // phpcs:ignore
        $date
    ));
    return array_map('intval', (array) $ids);
}

/**
 * Lista kelnerów do formularza na dany dzień + źródło podpowiedzi.
 * @return array{ids:int[], source:string}
 */
function snspa_quick_roster_for($date) {
    global $wpdb;
    $all = snspa_quick_all_waiters($date);
    $roster = get_option(SNSPA_QUICK_ROSTER, []);
    if (!empty($roster[$date])) {
        $ids = array_map('intval', (array) $roster[$date]);
        $source = 'admin';
    } else {
        $ids = snspa_quick_schedule_ids($date);
        $source = $ids ? 'grafik' : 'none';
    }
    // Każdy, kto już ma wpis na ten dzień (np. od kierownika), też jest na liście.
    $with = $wpdb->get_col($wpdb->prepare(
        "SELECT waiter_id FROM {$wpdb->prefix}sn_waiter_tips WHERE date=%s UNION SELECT waiter_id FROM {$wpdb->prefix}sn_waiter_service WHERE date=%s",
        $date, $date
    ));
    $ids = array_values(array_unique(array_merge($ids, array_map('intval', (array) $with))));
    // Tylko kelnerzy (Grafik zawiera też barmanów i kucharzy).
    $ids = array_values(array_filter($ids, function ($id) use ($all) { return isset($all[$id]); }));
    $ids = array_values(array_intersect(array_keys($all), $ids)); // kolejność jak w ustawieniu
    return ['ids' => $ids, 'source' => $source];
}

/** Pełny obraz dnia dla formularza. */
function snspa_quick_day_payload($date, $user_id) {
    global $wpdb;
    $all = snspa_quick_all_waiters($date);
    $r = snspa_quick_roster_for($date);
    $entries = [];
    foreach ($wpdb->get_results($wpdb->prepare("SELECT waiter_id, SUM(amount) AS a FROM {$wpdb->prefix}sn_waiter_tips WHERE date=%s GROUP BY waiter_id", $date)) as $row) {
        $entries[(int) $row->waiter_id]['card'] = round((float) $row->a, 2);
    }
    foreach ($wpdb->get_results($wpdb->prepare("SELECT waiter_id, SUM(amount) AS a FROM {$wpdb->prefix}sn_waiter_service WHERE date=%s GROUP BY waiter_id", $date)) as $row) {
        $entries[(int) $row->waiter_id]['service'] = round((float) $row->a, 2);
    }
    $entry = snspa_entry_log_get($date);
    $saved = null;
    if ($entry) {
        $saved = ['by' => $entry['by'] . ($entry['via'] === 'panel' ? ' (panel kierownika)' : ''), 'at' => $entry['at']];
    } elseif ($entries) {
        $saved = ['by' => 'panel kierownika', 'at' => ''];
    }
    $u = get_userdata($user_id);
    return [
        'date' => $date,
        'name' => $u ? $u->display_name : '',
        'source' => $r['source'],
        'waiters' => array_map(function ($id) use ($all, $entries) {
            return ['id' => $id, 'name' => $all[$id], 'card' => $entries[$id]['card'] ?? 0, 'service' => $entries[$id]['service'] ?? 0];
        }, $r['ids']),
        'allWaiters' => array_map(function ($id, $name) { return ['id' => $id, 'name' => $name]; }, array_keys($all), array_values($all)),
        'saved' => $saved,
    ];
}

/* ---------------------------------------------------------
 * Kto wpisał napiwki (karta/serwis) na dany dzień — zapis przez szybkie
 * napiwki i przez panel kierownika. Ostatni wpis + do 5 wcześniejszych.
 * Format opcji: [ Y-m-d => [ 'by', 'at', 'via' => quick|panel, 'history' => [...] ] ]
 * (starsze wpisy z 1.3.0 nie mają 'via' — to były szybkie napiwki).
 * --------------------------------------------------------- */

function snspa_entry_log_record($date, $user_id, $via) {
    $log = get_option(SNSPA_QUICK_LOG, []);
    $log = is_array($log) ? $log : [];
    $prev = $log[$date] ?? null;
    $history = [];
    if ($prev) {
        $history = (array) ($prev['history'] ?? []);
        array_unshift($history, ['by' => (int) $prev['by'], 'at' => $prev['at'], 'via' => $prev['via'] ?? 'quick']);
        $history = array_slice($history, 0, 5);
    }
    $log[$date] = ['by' => (int) $user_id, 'at' => current_time('Y-m-d H:i:s'), 'via' => $via, 'history' => $history];
    update_option(SNSPA_QUICK_LOG, array_slice($log, -120, null, true), false);
}

/** [ 'by' => nazwa, 'at', 'via', 'history' => [ ... ] ] albo null. */
function snspa_entry_log_get($date) {
    $log = get_option(SNSPA_QUICK_LOG, []);
    if (empty($log[$date])) return null;
    $name = function ($id) { $u = get_userdata((int) $id); return $u ? $u->display_name : '—'; };
    $e = $log[$date];
    return [
        'by' => $name($e['by']),
        'at' => $e['at'],
        'via' => $e['via'] ?? 'quick',
        'history' => array_map(function ($h) use ($name) { return ['by' => $name($h['by']), 'at' => $h['at'], 'via' => $h['via'] ?? 'quick']; }, (array) ($e['history'] ?? [])),
    ];
}

/* ---------------------------------------------------------
 * PIN i token sesji
 * --------------------------------------------------------- */

function snspa_quick_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR']) : 'x';
}

function snspa_quick_locked() {
    return (int) get_transient('snspa_qf_' . md5(snspa_quick_ip())) >= 5 || (int) get_transient('snspa_qf_all') >= 30;
}

function snspa_quick_fail() {
    foreach (['snspa_qf_' . md5(snspa_quick_ip()), 'snspa_qf_all'] as $k) {
        set_transient($k, (int) get_transient($k) + 1, 15 * MINUTE_IN_SECONDS);
    }
}

/** Użytkownik po PIN-ie albo 0. */
function snspa_quick_user_by_pin($pin) {
    foreach (snspa_quick_people() as $uid => $p) {
        if (!empty($p['pin']) && wp_check_password($pin, $p['pin'])) return (int) $uid;
    }
    return 0;
}

function snspa_quick_issue_token($user_id) {
    $token = wp_generate_password(40, false, false);
    $date = snspa_quick_today();
    // Ważny do końca dnia (WP) — maks. 24 h.
    $end = strtotime($date . ' 23:59:59') - (current_time('timestamp') - time());
    $ttl = max(60, min(DAY_IN_SECONDS, $end - time()));
    set_transient('snspa_qt_' . hash('sha256', $token), ['user' => $user_id, 'date' => $date], $ttl);
    return $token;
}

/** Sesja z nagłówka X-SNSPA-Quick: ['user','date'] albo WP_Error. */
function snspa_quick_session(WP_REST_Request $req) {
    $token = (string) $req->get_header('x_snspa_quick');
    if (strlen($token) < 20) return new WP_Error('snspa_quick_auth', 'Wpisz PIN.', ['status' => 401]);
    $s = get_transient('snspa_qt_' . hash('sha256', $token));
    if (!$s || empty($s['user'])) return new WP_Error('snspa_quick_auth', 'Sesja wygasła — wpisz PIN ponownie.', ['status' => 401]);
    if ($s['date'] !== snspa_quick_today()) return new WP_Error('snspa_quick_auth', 'Nowy dzień — wpisz PIN ponownie.', ['status' => 401]);
    if (!isset(snspa_quick_people()[$s['user']])) return new WP_Error('snspa_quick_auth', 'Twój dostęp został wyłączony.', ['status' => 401]);
    return $s;
}

/* ---------------------------------------------------------
 * REST
 * --------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route(SNSPA_REST_NS, '/quick/login', [
        'methods' => 'POST', 'callback' => 'snspa_quick_rest_login', 'permission_callback' => '__return_true',
    ]);
    register_rest_route(SNSPA_REST_NS, '/quick/day', [
        ['methods' => 'GET', 'callback' => 'snspa_quick_rest_get_day', 'permission_callback' => '__return_true'],
        ['methods' => 'POST', 'callback' => 'snspa_quick_rest_save_day', 'permission_callback' => '__return_true'],
    ]);
    register_rest_route(SNSPA_REST_NS, '/quick/logout', [
        'methods' => 'POST', 'callback' => 'snspa_quick_rest_logout', 'permission_callback' => '__return_true',
    ]);
    // Zarządzanie (administrator strony).
    register_rest_route(SNSPA_REST_NS, '/quick/admin', [
        ['methods' => 'GET', 'callback' => 'snspa_quick_rest_admin_get', 'permission_callback' => 'snspa_quick_admin_permission'],
        ['methods' => 'POST', 'callback' => 'snspa_quick_rest_admin_save', 'permission_callback' => 'snspa_quick_admin_permission'],
    ]);
});

function snspa_quick_admin_permission() {
    return current_user_can('manage_options');
}

function snspa_quick_rest_login(WP_REST_Request $req) {
    if (snspa_quick_locked()) return new WP_Error('snspa_quick_locked', 'Zbyt wiele błędnych prób. Spróbuj ponownie za 15 minut.', ['status' => 429]);
    $pin = preg_replace('/\D/', '', (string) ($req->get_json_params()['pin'] ?? ''));
    $uid = strlen($pin) >= 4 ? snspa_quick_user_by_pin($pin) : 0;
    if (!$uid) {
        snspa_quick_fail();
        return new WP_Error('snspa_quick_pin', 'Nieprawidłowy PIN.', ['status' => 401]);
    }
    $out = snspa_quick_day_payload(snspa_quick_today(), $uid);
    $out['token'] = snspa_quick_issue_token($uid);
    return $out;
}

function snspa_quick_rest_get_day(WP_REST_Request $req) {
    $s = snspa_quick_session($req);
    if (is_wp_error($s)) return $s;
    return snspa_quick_day_payload($s['date'], $s['user']);
}

function snspa_quick_rest_logout(WP_REST_Request $req) {
    $token = (string) $req->get_header('x_snspa_quick');
    if ($token) delete_transient('snspa_qt_' . hash('sha256', $token));
    return ['success' => true];
}

/** Kwota z pola formularza: „12,50”, „12.5”, „1 234,00”. */
function snspa_quick_amount($v) {
    $v = str_replace([' ', "\xC2\xA0", ','], ['', '', '.'], (string) $v);
    return is_numeric($v) ? max(0, min(100000, round((float) $v, 2))) : 0;
}

function snspa_quick_rest_save_day(WP_REST_Request $req) {
    global $wpdb;
    $s = snspa_quick_session($req);
    if (is_wp_error($s)) return $s;
    $date = $s['date']; // zawsze dzisiejszy dzień z sesji — nie z żądania
    $all = snspa_quick_all_waiters($date);
    $rows = (array) ($req->get_json_params()['rows'] ?? []);

    // Tylko pola ZMIENIONE w formularzu (nowa wartość ≠ wartość z chwili otwarcia):
    // zapis nie kasuje kwot wpisanych w międzyczasie przez kogoś innego ani kwot osób,
    // których nie ma w formularzu. Pozostali kelnerzy — bez zmian.
    $changed = 0;
    foreach ($rows as $r) {
        $id = intval($r['id'] ?? 0);
        if (!isset($all[$id])) continue; // tylko kelnerzy
        foreach (['card' => 'sn_waiter_tips', 'service' => 'sn_waiter_service'] as $k => $table) {
            $new = snspa_quick_amount($r[$k] ?? 0);
            if (array_key_exists('orig_' . $k, $r) && snspa_quick_amount($r['orig_' . $k]) === $new) continue;
            snspa_set_waiter_amount($table, $date, $id, $new);
            $changed++;
        }
    }

    // Zapamiętujemy listę osób (także z zerami), żeby po edycji wróciła ta sama obsada.
    $roster = get_option(SNSPA_QUICK_ROSTER, []);
    $roster = is_array($roster) ? $roster : [];
    $listed = array_values(array_unique(array_filter(array_map(function ($r) use ($all) { $id = intval($r['id'] ?? 0); return isset($all[$id]) ? $id : 0; }, $rows))));
    if ($listed) $roster[$date] = $listed;
    ksort($roster);
    $roster = array_slice($roster, -90, null, true); // ostatnie 90 dni (z planem na przyszłe tygodnie)
    update_option(SNSPA_QUICK_ROSTER, $roster, false);

    if ($changed) snspa_entry_log_record($date, $s['user'], 'quick');

    if ($changed) snspa_recalculate($date);
    $out = snspa_quick_day_payload($date, $s['user']);
    $out['justSaved'] = true;
    $out['changed'] = $changed;
    return $out;
}

/* --- administracja: osoby z PIN-em i obsada dnia --- */

/** Poniedziałek tygodnia, w którym leży $date (Y-m-d). */
function snspa_quick_week_start($date) {
    $ts = strtotime($date . ' 12:00:00');
    $dow = (int) gmdate('N', $ts); // 1 = poniedziałek
    return gmdate('Y-m-d', $ts - ($dow - 1) * DAY_IN_SECONDS);
}

/** Obsada całego tygodnia: dni z ręczną obsadą / Grafikiem + kelnerzy aktywni w tym tygodniu. */
function snspa_quick_week_payload($week_start) {
    $roster = get_option(SNSPA_QUICK_ROSTER, []);
    $days = [];
    $waiters = [];
    for ($i = 0; $i < 7; $i++) {
        $d = gmdate('Y-m-d', strtotime($week_start . ' 12:00:00') + $i * DAY_IN_SECONDS);
        $manual = array_map('intval', (array) ($roster[$d] ?? []));
        $days[] = ['date' => $d, 'roster' => $manual, 'schedule' => snspa_quick_schedule_ids($d), 'manual' => (bool) $manual];
        foreach (snspa_quick_all_waiters($d) as $id => $name) $waiters[$id] = $name;
    }
    $waiters = snspa_sort_waiters($waiters);
    return [
        'start' => $week_start,
        'end' => $days[6]['date'],
        'days' => $days,
        'waiters' => array_map(function ($id, $name) { return ['id' => $id, 'name' => $name]; }, array_keys($waiters), array_values($waiters)),
    ];
}

function snspa_quick_rest_admin_get(WP_REST_Request $req) {
    $date = sanitize_text_field((string) $req->get_param('date')) ?: snspa_quick_today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = snspa_quick_today();
    $people = [];
    foreach (snspa_quick_people() as $uid => $p) {
        $u = get_userdata($uid);
        if ($u) $people[] = ['id' => (int) $uid, 'name' => $u->display_name, 'set' => $p['set'] ?? ''];
    }
    $candidates = [];
    foreach (get_users(['orderby' => 'display_name', 'fields' => ['ID', 'display_name']]) as $u) {
        $candidates[] = ['id' => (int) $u->ID, 'name' => $u->display_name];
    }
    $roster = get_option(SNSPA_QUICK_ROSTER, []);
    return [
        'people' => $people,
        'candidates' => $candidates,
        'date' => $date,
        'roster' => array_map('intval', (array) ($roster[$date] ?? [])),
        'schedule' => snspa_quick_schedule_ids($date),
        'waiters' => array_map(function ($id, $name) { return ['id' => $id, 'name' => $name]; }, array_keys(snspa_quick_all_waiters($date)), array_values(snspa_quick_all_waiters($date))),
        'url' => snspa_quick_url(),
        'order' => array_map(function ($id, $name) { return ['id' => $id, 'name' => $name]; }, array_keys(snspa_sort_waiters(snspa_quick_all_kelner())), array_values(snspa_sort_waiters(snspa_quick_all_kelner()))),
        'orderCustom' => (bool) get_option(SNSPA_WAITER_ORDER, []),
        'week' => snspa_quick_week_payload(snspa_quick_week_start($req->get_param('week') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param('week')) ? (string) $req->get_param('week') : $date)),
    ];
}

function snspa_quick_rest_admin_save(WP_REST_Request $req) {
    $b = (array) $req->get_json_params();
    $action = $b['action'] ?? '';
    if ($action === 'set_pin') {
        $uid = intval($b['user_id'] ?? 0);
        $pin = preg_replace('/\D/', '', (string) ($b['pin'] ?? ''));
        if (!get_userdata($uid)) return new WP_Error('bad_user', 'Wybierz osobę.', ['status' => 400]);
        if (strlen($pin) < 4 || strlen($pin) > 8) return new WP_Error('bad_pin', 'PIN musi mieć od 4 do 8 cyfr.', ['status' => 400]);
        $people = snspa_quick_people();
        foreach ($people as $other => $p) {
            if ((int) $other !== $uid && !empty($p['pin']) && wp_check_password($pin, $p['pin'])) {
                return new WP_Error('pin_taken', 'Ten PIN ma już inna osoba — wybierz inny.', ['status' => 400]);
            }
        }
        $people[$uid] = ['pin' => wp_hash_password($pin), 'set' => current_time('Y-m-d H:i')];
        update_option(SNSPA_QUICK_PEOPLE, $people, false);
    } elseif ($action === 'remove') {
        $people = snspa_quick_people();
        unset($people[intval($b['user_id'] ?? 0)]);
        update_option(SNSPA_QUICK_PEOPLE, $people, false);
    } elseif ($action === 'roster') {
        $date = sanitize_text_field((string) ($b['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return new WP_Error('bad_date', 'Nieprawidłowa data.', ['status' => 400]);
        $all = snspa_quick_all_waiters($date);
        $ids = array_values(array_filter(array_map('intval', (array) ($b['ids'] ?? [])), function ($id) use ($all) { return isset($all[$id]); }));
        $roster = get_option(SNSPA_QUICK_ROSTER, []);
        $roster = is_array($roster) ? $roster : [];
        if ($ids) $roster[$date] = $ids; else unset($roster[$date]);
        ksort($roster);
        update_option(SNSPA_QUICK_ROSTER, array_slice($roster, -90, null, true), false);
        $req->set_param('date', $date);
    } elseif ($action === 'order') {
        // { ids: [ ... ] } — kolejność wyświetlania kelnerów (puste = alfabetycznie)
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($b['ids'] ?? [])))));
        if ($ids) update_option(SNSPA_WAITER_ORDER, $ids, false); else delete_option(SNSPA_WAITER_ORDER);
    } elseif ($action === 'roster_week') {
        // { days: { 'Y-m-d': [ids] | null } } — null/pusta lista = dzień wraca do Grafiku.
        $roster = get_option(SNSPA_QUICK_ROSTER, []);
        $roster = is_array($roster) ? $roster : [];
        $first = null;
        foreach ((array) ($b['days'] ?? []) as $date => $ids) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) continue;
            $first = $first ?: $date;
            $all = snspa_quick_all_waiters($date);
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids), function ($id) use ($all) { return isset($all[$id]); })));
            if ($ids) $roster[$date] = $ids; else unset($roster[$date]);
        }
        ksort($roster);
        update_option(SNSPA_QUICK_ROSTER, array_slice($roster, -90, null, true), false);
        if ($first) $req->set_param('week', $first);
    } else {
        return new WP_Error('bad_action', 'Nieznana operacja.', ['status' => 400]);
    }
    return snspa_quick_rest_admin_get($req);
}

/* ---------------------------------------------------------
 * Strona „Szybkie napiwki”: /?napiwki-szybkie (osobna, pełnoekranowa,
 * z manifestem — po „Dodaj do ekranu początkowego” działa jak aplikacja).
 * Alternatywnie shortcode [napiwki_szybkie] na dowolnej stronie.
 * --------------------------------------------------------- */

function snspa_quick_url() {
    return add_query_arg('napiwki-szybkie', '', home_url('/'));
}

add_action('template_redirect', function () {
    if (!isset($_GET['napiwki-szybkie'])) return; // phpcs:ignore
    if ($_GET['napiwki-szybkie'] === 'manifest') { // phpcs:ignore
        $icon = get_site_icon_url(512);
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode([
            'name' => 'Napiwki — ' . get_bloginfo('name'),
            'short_name' => 'Napiwki',
            'start_url' => snspa_quick_url(),
            'scope' => home_url('/'),
            'display' => 'standalone',
            'background_color' => '#f3f4f6',
            'theme_color' => '#2563eb',
            'icons' => $icon ? [['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png']] : [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    $icon = get_site_icon_url(180);
    ?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#2563eb">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Napiwki">
<title>Napiwki — <?php echo esc_html(get_bloginfo('name')); ?></title>
<link rel="manifest" href="<?php echo esc_url(add_query_arg('napiwki-szybkie', 'manifest', home_url('/'))); ?>">
<?php if ($icon) : ?><link rel="apple-touch-icon" href="<?php echo esc_url($icon); ?>"><link rel="icon" href="<?php echo esc_url($icon); ?>"><?php endif; ?>
</head><body class="snq-body">
<?php echo snspa_quick_markup(); // phpcs:ignore ?>
</body></html><?php
    exit;
});

add_shortcode('napiwki_szybkie', function () {
    return snspa_quick_markup();
});

function snspa_quick_markup() {
    $cfg = [
        'rest' => esc_url_raw(rest_url(SNSPA_REST_NS . '/quick')),
        'site' => get_bloginfo('name'),
    ];
    ob_start();
    ?>
<div id="snq-root" class="snq"></div>
<style><?php echo snspa_quick_css(); // phpcs:ignore ?></style>
<script>window.SNQ_CFG = <?php echo wp_json_encode($cfg); ?>;</script>
<script><?php echo snspa_quick_js(); // phpcs:ignore ?></script>
    <?php
    return ob_get_clean();
}

function snspa_quick_css() {
    return <<<'CSS'
.snq-body{margin:0;background:#f3f4f6;}
.snq{--a:#2563eb;--ok:#16a34a;--bad:#dc2626;--t:#111827;--m:#6b7280;--b:#e5e7eb;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--t);max-width:560px;margin:0 auto;padding:16px 16px calc(96px + env(safe-area-inset-bottom));box-sizing:border-box;-webkit-text-size-adjust:100%;}
.snq *{box-sizing:border-box;}
.snq h1{font-size:22px;margin:8px 0 2px;}
.snq .sub{color:var(--m);font-size:14px;margin:0 0 16px;}
.snq .card{background:#fff;border:1px solid var(--b);border-radius:16px;padding:16px;margin:0 0 12px;box-shadow:0 1px 2px rgba(0,0,0,.04);}
.snq .pin{font-size:32px;letter-spacing:.4em;text-align:center;width:100%;padding:14px;border:2px solid var(--b);border-radius:14px;font-variant-numeric:tabular-nums;}
.snq .pin:focus,.snq input:focus,.snq select:focus{outline:none;border-color:var(--a);}
.snq .btn{display:block;width:100%;border:0;border-radius:14px;padding:16px;font-size:17px;font-weight:700;background:var(--a);color:#fff;cursor:pointer;margin-top:12px;}
.snq .btn:disabled{opacity:.6;}
.snq .btn.sec{background:#fff;color:var(--a);border:2px solid var(--a);}
.snq .link{background:none;border:0;color:var(--m);font-size:14px;padding:8px 0;cursor:pointer;text-decoration:underline;}
.snq .err{background:#fef2f2;border:1px solid #fecaca;color:var(--bad);border-radius:12px;padding:10px 12px;font-size:14px;margin:12px 0 0;}
.snq .okbox{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:14px;padding:14px;font-size:15px;font-weight:600;margin:0 0 12px;}
.snq .okbox small{display:block;font-weight:400;color:#15803d;margin-top:2px;}
.snq .w{display:grid;grid-template-columns:1fr 1fr auto;gap:8px 10px;align-items:end;}
.snq .w .nm{grid-column:1/-1;font-weight:700;font-size:16px;display:flex;justify-content:space-between;align-items:center;}
.snq .w label{font-size:12px;color:var(--m);display:block;margin-bottom:4px;}
.snq .w input{width:100%;font-size:20px;padding:12px;border:2px solid var(--b);border-radius:12px;text-align:right;font-variant-numeric:tabular-nums;}
.snq .rm{background:none;border:0;color:var(--m);font-size:22px;line-height:1;padding:4px 6px;cursor:pointer;}
.snq select{width:100%;font-size:16px;padding:12px;border:2px solid var(--b);border-radius:12px;background:#fff;}
.snq .hint{font-size:13px;color:var(--m);margin:6px 0 0;}
.snq table{width:100%;border-collapse:collapse;font-size:15px;font-variant-numeric:tabular-nums;}
.snq th,.snq td{padding:9px 6px;border-bottom:1px solid var(--b);text-align:right;white-space:nowrap;}
.snq td:first-child{white-space:normal;}
.snq th:first-child,.snq td:first-child{text-align:left;}
.snq th{font-size:12px;color:var(--m);text-transform:uppercase;letter-spacing:.03em;}
.snq tfoot td{font-weight:800;border-bottom:0;}
.snq .bar{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid var(--b);padding:10px 16px calc(10px + env(safe-area-inset-bottom));}
.snq .bar .in{max-width:528px;margin:0 auto;display:flex;gap:12px;align-items:center;}
.snq .bar .tot{font-size:13px;color:var(--m);white-space:nowrap;}
.snq .bar .tot b{display:block;color:var(--t);font-size:17px;}
.snq .bar .btn{margin:0;}
CSS;
}

function snspa_quick_js() {
    return <<<'JS'
(function () {
  var C = window.SNQ_CFG || {}, root = document.getElementById('snq-root');
  if (!root) return;
  var KEY = 'snq_token', st = { token: null, day: null, edit: false, msg: '', busy: false, rows: [] };
  try { st.token = localStorage.getItem(KEY); } catch (e) {}

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function num(v) { v = String(v == null ? '' : v).replace(/\s/g, '').replace(',', '.'); var n = parseFloat(v); return isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : 0; }
  function amt(n) { return (Math.round((n || 0) * 100) / 100).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function zl(n) { return (Math.round((n || 0) * 100) / 100).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' zł'; }
  function dateLabel(d) { var p = d.split('-'); var dt = new Date(+p[0], +p[1] - 1, +p[2]); return dt.toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long' }); }
  function setToken(t) { st.token = t; try { if (t) localStorage.setItem(KEY, t); else localStorage.removeItem(KEY); } catch (e) {} }

  function api(path, method, body) {
    var h = { 'Content-Type': 'application/json' };
    if (st.token) h['X-SNSPA-Quick'] = st.token;
    return fetch(C.rest + path, { method: method || 'GET', headers: h, body: body ? JSON.stringify(body) : undefined, credentials: 'omit' })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) { var e = new Error(d.message || 'Błąd połączenia'); e.status = r.status; throw e; } return d; }); });
  }

  function loadDay(d) {
    st.day = d;
    st.rows = d.waiters.map(function (w) { return { id: w.id, name: w.name, card: w.card ? String(w.card).replace('.', ',') : '', service: w.service ? String(w.service).replace('.', ',') : '', origCard: w.card || 0, origService: w.service || 0 }; });
    // Dzień już zapisany → najpierw podsumowanie; inaczej od razu formularz.
    st.edit = !d.saved;
  }

  function renderPin() {
    root.innerHTML = '<h1>Napiwki</h1><p class="sub">' + esc(C.site || '') + '</p>' +
      '<form class="card" id="snq-pin"><label class="sub" for="snq-p" style="display:block;margin:0 0 10px;">Wpisz swój PIN</label>' +
      '<input id="snq-p" class="pin" type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="8" required>' +
      (st.msg ? '<div class="err">' + esc(st.msg) + '</div>' : '') +
      '<button class="btn" type="submit"' + (st.busy ? ' disabled' : '') + '>' + (st.busy ? 'Sprawdzam…' : 'Dalej') + '</button></form>';
    var inp = document.getElementById('snq-p'); inp.focus();
    document.getElementById('snq-pin').onsubmit = function (e) {
      e.preventDefault(); st.busy = true; st.msg = ''; renderPin();
      api('/login', 'POST', { pin: inp.value }).then(function (d) { setToken(d.token); st.busy = false; loadDay(d); render(); })
        .catch(function (err) { st.busy = false; st.msg = err.message; renderPin(); });
    };
  }

  function totals() {
    var c = 0, s = 0;
    st.rows.forEach(function (r) { c += num(r.card); s += num(r.service); });
    return { c: c, s: s };
  }

  function renderSummary() {
    var d = st.day, sv = d.saved;
    var rows = d.waiters.filter(function (w) { return w.card > 0 || w.service > 0; });
    var tc = 0, ts = 0, h = '';
    rows.forEach(function (w) { tc += w.card; ts += w.service; h += '<tr><td>' + esc(w.name) + '</td><td>' + amt(w.card) + '</td><td>' + amt(w.service) + '</td><td><b>' + amt(w.card + w.service) + '</b></td></tr>'; });
    root.innerHTML = head() +
      '<div class="okbox">✓ Dane zostały zapisane' + (sv ? '<small>' + (sv.at ? 'o ' + esc(sv.at.slice(11, 16)) + ', ' : '') + 'przez: ' + esc(sv.by) + '</small>' : '') + '</div>' +
      '<div class="card"><table><thead><tr><th>Kelner</th><th>Karta</th><th>Serwis</th><th>Razem (zł)</th></tr></thead><tbody>' +
      (h || '<tr><td colspan="4" style="text-align:center;color:#6b7280;">Brak kwot na ten dzień.</td></tr>') +
      '</tbody><tfoot><tr><td>Razem</td><td>' + amt(tc) + '</td><td>' + amt(ts) + '</td><td>' + amt(tc + ts) + '</td></tr></tfoot></table></div>' +
      '<button class="btn sec" id="snq-edit" type="button">Edytuj</button>' + foot();
    document.getElementById('snq-edit').onclick = function () { st.edit = true; st.msg = ''; render(); };
    bindFoot();
  }

  function head() {
    return '<h1>Napiwki — dziś</h1><p class="sub">' + esc(dateLabel(st.day.date)) + ' · ' + esc(st.day.name) + '</p>';
  }
  function foot() { return '<p style="text-align:center;margin:18px 0 0;"><button class="link" id="snq-out" type="button">Wyloguj</button></p>'; }
  function bindFoot() {
    document.getElementById('snq-out').onclick = function () { api('/logout', 'POST').catch(function () {}); setToken(null); st.day = null; st.msg = ''; render(); };
  }

  function renderForm() {
    var d = st.day, used = {}, h = head();
    st.rows.forEach(function (r) { used[r.id] = 1; });
    if (d.saved) h += '<div class="okbox" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;">Edytujesz zapisany dzień<small style="color:#1d4ed8;">Zapisane zostaną tylko pola, które zmienisz — pozostałe kwoty zostają bez zmian.</small></div>';
    else if (d.source === 'grafik') h += '<p class="hint" style="margin:0 0 10px;">Lista z Grafiku — osoby na dzisiejszej zmianie.</p>';
    else if (d.source === 'admin') h += '<p class="hint" style="margin:0 0 10px;">Lista ustawiona przez administratora.</p>';
    st.rows.forEach(function (r, i) {
      h += '<div class="card w"><div class="nm"><span>' + esc(r.name) + '</span><button class="rm" type="button" data-rm="' + i + '" aria-label="Usuń z listy">×</button></div>' +
        '<div><label>Karta (zł)</label><input inputmode="decimal" data-i="' + i + '" data-k="card" value="' + esc(r.card) + '" placeholder="0,00"></div>' +
        '<div><label>Serwis (zł)</label><input inputmode="decimal" data-i="' + i + '" data-k="service" value="' + esc(r.service) + '" placeholder="0,00"></div><div></div></div>';
    });
    if (!st.rows.length) h += '<div class="card hint" style="margin-top:0;">Brak osób na liście — dodaj kelnerów poniżej.</div>';
    var free = d.allWaiters.filter(function (w) { return !used[w.id]; });
    if (free.length) {
      h += '<div class="card"><label class="sub" style="display:block;margin:0 0 8px;">Dodaj osobę</label><select id="snq-add"><option value="">— wybierz kelnera —</option>' +
        free.map(function (w) { return '<option value="' + w.id + '">' + esc(w.name) + '</option>'; }).join('') + '</select></div>';
    }
    if (st.msg) h += '<div class="err">' + esc(st.msg) + '</div>';
    if (d.saved) h += '<button class="link" id="snq-cancel" type="button" style="display:block;margin:6px auto 0;">Anuluj edycję</button>';
    h += foot();
    var t = totals();
    h += '<div class="bar"><div class="in"><div class="tot">Razem<b id="snq-tot">' + zl(t.c + t.s) + '</b></div><button class="btn" id="snq-save" type="button"' + (st.busy ? ' disabled' : '') + '>' + (st.busy ? 'Zapisuję…' : 'Zapisz dzień') + '</button></div></div>';
    root.innerHTML = h;

    root.querySelectorAll('input[data-i]').forEach(function (el) {
      el.oninput = function () { st.rows[+el.dataset.i][el.dataset.k] = el.value; var t = totals(); document.getElementById('snq-tot').textContent = zl(t.c + t.s); };
    });
    root.querySelectorAll('[data-rm]').forEach(function (el) {
      el.onclick = function () {
        var r = st.rows[+el.dataset.rm];
        if (r.origCard || r.origService) { alert(r.name + ' ma już zapisane kwoty — nie znikną z dnia. Aby je skasować, wpisz 0 w polach i zapisz.'); return; }
        if ((num(r.card) || num(r.service)) && !confirm('Usunąć ' + r.name + ' z listy? Niezapisane kwoty znikną.')) return;
        st.rows.splice(+el.dataset.rm, 1); render();
      };
    });
    var add = document.getElementById('snq-add');
    if (add) add.onchange = function () {
      var w = d.allWaiters.filter(function (x) { return String(x.id) === add.value; })[0];
      if (w) { st.rows.push({ id: w.id, name: w.name, card: '', service: '', origCard: 0, origService: 0 }); render(); var ins = root.querySelectorAll('input[data-k="card"]'); if (ins.length) ins[ins.length - 1].focus(); }
    };
    var cancel = document.getElementById('snq-cancel');
    if (cancel) cancel.onclick = function () { loadDay(st.day); render(); };
    document.getElementById('snq-save').onclick = save;
    bindFoot();
  }

  function save() {
    // Wysyłamy tylko zmienione wiersze (z wartościami z chwili otwarcia) — reszta dnia zostaje.
    var rows = st.rows.filter(function (r) { return num(r.card) !== r.origCard || num(r.service) !== r.origService; })
      .map(function (r) { return { id: r.id, card: num(r.card), service: num(r.service), orig_card: r.origCard, orig_service: r.origService }; });
    if (!rows.length) {
      if (st.day.saved) { st.edit = false; render(); return; }
      st.msg = 'Nie wpisano żadnych kwot.'; render(); return;
    }
    var cleared = rows.filter(function (r) { return (r.orig_card && !r.card) || (r.orig_service && !r.service); });
    if (cleared.length && !confirm('Wyczyszczono zapisane kwoty u: ' + cleared.map(function (r) { return st.rows.filter(function (x) { return x.id === r.id; })[0].name; }).join(', ') + '. Zapisać?')) return;
    st.busy = true; st.msg = ''; render();
    api('/day', 'POST', { rows: rows })
      .then(function (d) { st.busy = false; loadDay(d); st.edit = false; render(); window.scrollTo(0, 0); })
      .catch(function (err) { st.busy = false; if (err.status === 401) { setToken(null); st.day = null; } st.msg = err.message; render(); });
  }

  function render() {
    if (!st.token || !st.day) return renderPin();
    return st.edit ? renderForm() : renderSummary();
  }

  if (st.token) {
    api('/day').then(function (d) { loadDay(d); render(); })
      .catch(function (err) { setToken(null); st.msg = err.status === 401 ? err.message : ''; render(); });
  } else {
    render();
  }
})();
JS;
}
