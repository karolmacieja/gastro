<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {

    register_rest_route(SNSPA_REST_NS, '/bootstrap', [
        'methods' => 'GET',
        'callback' => 'snspa_rest_bootstrap',
        'permission_callback' => 'is_user_logged_in',
    ]);

    register_rest_route(SNSPA_REST_NS, '/day', [
        [
            'methods' => 'GET',
            'callback' => 'snspa_rest_get_day',
            'permission_callback' => 'is_user_logged_in',
            'args' => ['date' => ['required' => true]],
        ],
        [
            'methods' => 'POST',
            'callback' => 'snspa_rest_save_day',
            'permission_callback' => 'is_user_logged_in',
        ],
    ]);

    register_rest_route(SNSPA_REST_NS, '/month', [
        'methods' => 'GET',
        'callback' => 'snspa_rest_get_month',
        'permission_callback' => 'is_user_logged_in',
    ]);

    register_rest_route(SNSPA_REST_NS, '/month-summary', [
        'methods' => 'GET',
        'callback' => 'snspa_rest_get_month_summary',
        'permission_callback' => function () { $f = snspa_current_flags(); return $f['isManager']; },
    ]);

    register_rest_route(SNSPA_REST_NS, '/staff-list', [
        'methods' => 'GET',
        'callback' => 'snspa_rest_staff_list',
        'permission_callback' => function () { $f = snspa_current_flags(); return $f['isManager']; },
    ]);

    register_rest_route(SNSPA_REST_NS, '/staff-check', [
        'methods' => 'GET',
        'callback' => 'snspa_rest_staff_check',
        'permission_callback' => function () { $f = snspa_current_flags(); return $f['isManager']; },
    ]);

    register_rest_route(SNSPA_REST_NS, '/service-pool', [
        [
            'methods' => 'GET',
            'callback' => 'snspa_rest_get_service_pool',
            'permission_callback' => function () { $f = snspa_current_flags(); return $f['isAdminBar'] || $f['isAdminKtc']; },
        ],
        [
            'methods' => 'POST',
            'callback' => 'snspa_rest_save_service_pool',
            'permission_callback' => function () { $f = snspa_current_flags(); return $f['isAdminBar'] || $f['isAdminKtc']; },
        ],
    ]);

    register_rest_route(SNSPA_REST_NS, '/waiter-settlement', [
        [
            'methods' => 'GET',
            'callback' => 'snspa_rest_get_waiter_settlement',
            'permission_callback' => function () { $f = snspa_current_flags(); return $f['isAdminBar'] || $f['isAdminKtc']; },
        ],
        [
            'methods' => 'POST',
            'callback' => 'snspa_rest_save_waiter_settlement',
            'permission_callback' => function () { $f = snspa_current_flags(); return $f['isAdminBar'] || $f['isAdminKtc']; },
        ],
    ]);

    register_rest_route(SNSPA_REST_NS, '/email-template', [
        [
            'methods' => 'GET',
            'callback' => 'snspa_rest_get_email_template',
            'permission_callback' => 'snspa_rest_email_permission',
        ],
        [
            'methods' => 'POST',
            'callback' => 'snspa_rest_save_email_template',
            'permission_callback' => 'snspa_rest_email_permission',
        ],
    ]);

    register_rest_route(SNSPA_REST_NS, '/send-emails', [
        'methods' => 'POST',
        'callback' => 'snspa_rest_send_emails',
        'permission_callback' => 'snspa_rest_email_permission_from_body',
    ]);

    register_rest_route(SNSPA_REST_NS, '/push-template', [
        [
            'methods' => 'GET',
            'callback' => 'snspa_rest_get_push_template',
            'permission_callback' => 'snspa_rest_email_permission',
        ],
        [
            'methods' => 'POST',
            'callback' => 'snspa_rest_save_push_template',
            'permission_callback' => 'snspa_rest_email_permission',
        ],
    ]);

    register_rest_route(SNSPA_REST_NS, '/push-icon', [
        'methods' => 'POST',
        'callback' => 'snspa_rest_upload_push_icon',
        // "group" przychodzi jako pole formularza multipart - get_param() go widzi.
        'permission_callback' => 'snspa_rest_email_permission',
    ]);

    register_rest_route(SNSPA_REST_NS, '/send-push', [
        'methods' => 'POST',
        'callback' => 'snspa_rest_send_push',
        'permission_callback' => 'snspa_rest_email_permission_from_body',
    ]);

    register_rest_route(SNSPA_REST_NS, '/onesignal-settings', [
        'methods' => 'GET',
        'callback' => 'snspa_rest_get_onesignal_settings',
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);

    register_rest_route(SNSPA_REST_NS, '/settings', [
        [
            'methods' => 'GET',
            'callback' => 'snspa_rest_get_settings',
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ],
        [
            'methods' => 'POST',
            'callback' => 'snspa_rest_save_settings',
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ],
    ]);

    // Wyjątki procentowe (10%/20% domyślnie, ale nadpisywalne per dzień/kelner)
    register_rest_route(SNSPA_REST_NS, '/section-report', [
        'methods' => 'GET',
        'callback' => 'snspa_rest_get_section_report',
        'permission_callback' => 'snspa_rest_section_report_permission',
    ]);
    register_rest_route(SNSPA_REST_NS, '/percent-overrides', [
        [
            'methods' => 'GET',
            'callback' => 'snspa_rest_get_percent_overrides',
            'permission_callback' => 'snspa_rest_percent_override_permission',
        ],
        [
            'methods' => 'POST',
            'callback' => 'snspa_rest_save_percent_override',
            'permission_callback' => 'snspa_rest_percent_override_permission',
        ],
        [
            'methods' => 'DELETE',
            'callback' => 'snspa_rest_delete_percent_override',
            'permission_callback' => 'snspa_rest_percent_override_permission',
        ],
    ]);
});

/* -----------------------------------------------------------
 * GET /section-report?m=&y=&dept=kuchnia|bar — raport miesięczny dla sekcji.
 * Tylko administrator strony (manage_options). Dla każdego kelnera, który
 * w miesiącu miał napiwki z karty lub serwisu: KWOTA przypadająca wybranej
 * sekcji z karty (domyślnie 10% bar / 20% kuchnia), z serwisu i ich suma.
 * Pełnych napiwków kelnera ani udziału drugiej sekcji nie zwracamy.
 * Kwoty liczone jak w „Rozliczeniu z kelnerami”: od netto (podatek 26+),
 * z wyjątkami procentowymi na pojedyncze dni. Gotówka pominięta.
 * ----------------------------------------------------------- */
function snspa_rest_section_report_permission() {
    return current_user_can('manage_options');
}

function snspa_rest_get_section_report(WP_REST_Request $req) {
    $m = intval($req->get_param('m')) ?: intval(current_time('m'));
    $y = intval($req->get_param('y')) ?: intval(current_time('Y'));
    $dept = $req->get_param('dept') === 'bar' ? 'bar' : 'kuchnia';
    if ($m < 1 || $m > 12 || $y < 2000 || $y > 2100) return new WP_Error('bad_input', 'Nieprawidłowy miesiąc', ['status' => 400]);
    return snspa_get_section_report_data($m, $y, $dept);
}

function snspa_get_section_report_data($m, $y, $dept) {
    $data = snspa_get_waiter_settlement_data($m, $y, $dept);
    $rows = [];
    foreach ($data['rows'] as $r) {
        $card = round($r['owedCard'], 2);
        $service = round($r['owedService'], 2);
        if ($card <= 0 && $service <= 0) continue;
        $rows[] = ['id' => $r['id'], 'name' => $r['name'], 'card' => $card, 'service' => $service, 'total' => round($card + $service, 2)];
    }
    usort($rows, function ($a, $b) { return strcoll($a['name'], $b['name']); });
    $sum = function ($k) use ($rows) { return round(array_sum(array_column($rows, $k)), 2); };
    return [
        'dept' => $dept, 'month' => $m, 'year' => $y, 'rows' => $rows,
        'pct' => $dept === 'bar' ? 10 : 20,
        'totals' => ['card' => $sum('card'), 'service' => $sum('service'), 'total' => $sum('total')],
        'generated' => current_time('Y-m-d H:i'),
    ];
}

// Administrator zawsze może; manager napiwków — tylko jeśli włączone w Ustawieniach.
function snspa_rest_percent_override_permission() {
    $f = snspa_current_flags();
    if ($f['isAdmin']) return true;
    if ($f['isManager'] && snspa_manager_can_set_percent_overrides()) return true;
    return false;
}

function snspa_rest_get_percent_overrides(WP_REST_Request $req) {
    $date = sanitize_text_field($req->get_param('date'));
    return snspa_get_percent_overrides_data($date);
}

// Wydzielone z callbacku REST, żeby dało się to samo wywołać WEWNĘTRZNIE (np. zaraz po
// zapisie) bez sztucznego, "podrobionego" obiektu WP_REST_Request — ręcznie konstruowany
// WP_REST_Request nie przekazuje parametrów tak, jak mogłoby się wydawać (get_param() ich
// wtedy nie widzi), więc taka wewnętrzna funkcja z prawdziwym argumentem $date jest jedynym
// niezawodnym sposobem na ponowne użycie tej samej logiki.
function snspa_get_percent_overrides_data($date) {
    global $wpdb;
    if (!$date || !strtotime($date)) return new WP_Error('bad_date', 'Nieprawidłowa data', ['status' => 400]);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT waiter_id, card_bar_percent, card_kitchen_percent, service_bar_percent, service_kitchen_percent FROM {$wpdb->prefix}sn_percent_overrides WHERE date=%s", $date
    ));
    $overrides = [];
    foreach ($rows as $r) {
        $overrides[intval($r->waiter_id)] = [
            'cardBarPercent' => floatval($r->card_bar_percent), 'cardKitchenPercent' => floatval($r->card_kitchen_percent),
            'serviceBarPercent' => floatval($r->service_bar_percent), 'serviceKitchenPercent' => floatval($r->service_kitchen_percent),
        ];
    }
    $waiters = [];
    foreach (get_users(['role' => 'kelner']) as $w) {
        if (!snspa_is_user_active_on_date($w->ID, $date) && !isset($overrides[$w->ID])) continue;
        $o = $overrides[$w->ID] ?? null;
        $waiters[] = [
            'id' => $w->ID, 'name' => $w->display_name,
            'cardBarPercent' => $o ? $o['cardBarPercent'] : 10, 'cardKitchenPercent' => $o ? $o['cardKitchenPercent'] : 20,
            'serviceBarPercent' => $o ? $o['serviceBarPercent'] : 10, 'serviceKitchenPercent' => $o ? $o['serviceKitchenPercent'] : 20,
            'isOverride' => $o !== null,
        ];
    }
    return ['date' => $date, 'waiters' => $waiters];
}

function snspa_rest_save_percent_override(WP_REST_Request $req) {
    global $wpdb;
    $body = $req->get_json_params();
    $date = sanitize_text_field($body['date'] ?? '');
    $waiter_id = intval($body['waiterId'] ?? 0);
    $card_bar = round(floatval($body['cardBarPercent'] ?? 10), 2);
    $card_kitchen = round(floatval($body['cardKitchenPercent'] ?? 20), 2);
    $service_bar = round(floatval($body['serviceBarPercent'] ?? 10), 2);
    $service_kitchen = round(floatval($body['serviceKitchenPercent'] ?? 20), 2);
    if (!$date || !strtotime($date) || !$waiter_id) return new WP_Error('bad_input', 'Nieprawidłowe dane', ['status' => 400]);
    foreach ([$card_bar, $card_kitchen, $service_bar, $service_kitchen] as $p) {
        if ($p < 0 || $p > 100) return new WP_Error('bad_range', 'Procent musi być w zakresie 0–100', ['status' => 400]);
    }

    // Jeśli wszystkie 4 wartości wracają do domyślnych (10/20/10/20), po prostu usuwamy
    // wyjątek — czyściej niż trzymać w bazie rekordy, które i tak niczego nie zmieniają.
    $is_default = (abs($card_bar - 10) < 0.001 && abs($card_kitchen - 20) < 0.001 && abs($service_bar - 10) < 0.001 && abs($service_kitchen - 20) < 0.001);
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}sn_percent_overrides WHERE date=%s AND waiter_id=%d", $date, $waiter_id
    ));
    if ($is_default) {
        if ($existing) $wpdb->delete("{$wpdb->prefix}sn_percent_overrides", ['id' => $existing]);
    } else {
        $data = ['date' => $date, 'waiter_id' => $waiter_id,
            'card_bar_percent' => $card_bar, 'card_kitchen_percent' => $card_kitchen,
            'service_bar_percent' => $service_bar, 'service_kitchen_percent' => $service_kitchen,
            // Stare kolumny (bar_percent/kitchen_percent) trzymamy zsynchronizowane z wartością
            // dla karty — tylko dla zgodności wstecznej, nic już z nich aktywnie nie czyta.
            'bar_percent' => $card_bar, 'kitchen_percent' => $card_kitchen,
            'created_by' => get_current_user_id()];
        if ($existing) $wpdb->update("{$wpdb->prefix}sn_percent_overrides", $data, ['id' => $existing]);
        else $wpdb->insert("{$wpdb->prefix}sn_percent_overrides", $data);
    }
    // Przeliczamy dzień od razu, żeby wypłaty personelu odzwierciedlały nowy procent bez czekania.
    snspa_recalculate($date);
    return snspa_get_percent_overrides_data($date);
}

function snspa_rest_delete_percent_override(WP_REST_Request $req) {
    global $wpdb;
    $body = $req->get_json_params();
    $date = sanitize_text_field($body['date'] ?? $req->get_param('date') ?? '');
    $waiter_id = intval($body['waiterId'] ?? $req->get_param('waiterId') ?? 0);
    if (!$date || !$waiter_id) return new WP_Error('bad_input', 'Nieprawidłowe dane', ['status' => 400]);
    $wpdb->delete("{$wpdb->prefix}sn_percent_overrides", ['date' => $date, 'waiter_id' => $waiter_id]);
    snspa_recalculate($date);
    return snspa_get_percent_overrides_data($date);
}

// Lista aktywnych kelnerów z aktualną kwotą gotówki oddaną do wskazanego działu (bar/kuchnia) danego dnia
function snspa_waiter_cash_rows($date, $dept) {
    global $wpdb;
    $amounts = $wpdb->get_results($wpdb->prepare("SELECT waiter_id, amount FROM {$wpdb->prefix}sn_waiter_cash WHERE date=%s AND dept=%s", $date, $dept), OBJECT_K);
    $rows = [];
    foreach (get_users(['role' => 'kelner']) as $w) {
        $amt = isset($amounts[$w->ID]) ? floatval($amounts[$w->ID]->amount) : 0;
        if (!snspa_is_user_active_on_date($w->ID, $date) && $amt == 0) continue;
        $rows[] = ['id' => $w->ID, 'name' => $w->display_name, 'amount' => $amt];
    }
    return $rows;
}

function snspa_current_flags() {
    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    $is_admin = in_array('administrator', $roles);
    return [
        'user_id'      => $user->ID,
        'display_name' => $user->display_name,
        'roles'        => $roles,
        'isAdmin'      => $is_admin,
        'isManager'    => $is_admin || in_array('manager', $roles),
        'isAdminBar'   => $is_admin || in_array('admin_bar', $roles),
        'isAdminKtc'   => $is_admin || in_array('admin_kuchnia', $roles),
        'isKelner'     => in_array('kelner', $roles),
        'isBarman'     => in_array('barman', $roles),
        'isKucharz'    => in_array('kucharz', $roles),
    ];
}

function snspa_rest_bootstrap(WP_REST_Request $req) {
    $f = snspa_current_flags();
    return [
        'flags' => $f,
        'today' => current_time('Y-m-d'),
        'canSeeSettings' => current_user_can('manage_options'),
        'canSectionReport' => snspa_rest_section_report_permission(),
        'canSetPercentOverrides' => snspa_rest_percent_override_permission(),
    ];
}

/* -----------------------------------------------------------
 * GET /day  — payload zależny od ról zalogowanego użytkownika
 * ----------------------------------------------------------- */
function snspa_rest_get_day(WP_REST_Request $req) {
    $date = sanitize_text_field($req->get_param('date'));
    return snspa_get_day_data($date);
}

function snspa_get_day_data($date) {
    global $wpdb;
    $f = snspa_current_flags();
    if (!$date || !strtotime($date)) return new WP_Error('bad_date', 'Nieprawidłowa data', ['status' => 400]);
    $u_id = $f['user_id'];
    $tax = snspa_tax_settings();
    $out = ['date' => $date];

    // Rezerwacje - informacyjnie dla ról zarządzających
    if ($f['isManager'] || $f['isAdminBar'] || $f['isAdminKtc']) {
        $res = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_reservations WHERE date = %s", $date));
        $out['reservations'] = array_map(function ($r) {
            return [
                'name' => $r->name,
                'amount' => floatval($r->amount),
                'paymentType' => $r->payment_type,
                'barPercent' => intval($r->bar_percent),
                'ktcPercent' => intval($r->ktc_percent),
            ];
        }, $res);
    }

    // --- MANAGER: karta + serwis kelnerów ---
    if ($f['isManager']) {
        $waiters = get_users(['role' => 'kelner']);
        $cards = $wpdb->get_results($wpdb->prepare("SELECT waiter_id, amount FROM {$wpdb->prefix}sn_waiter_tips WHERE date=%s", $date), OBJECT_K);
        $service = $wpdb->get_results($wpdb->prepare("SELECT waiter_id, amount FROM {$wpdb->prefix}sn_waiter_service WHERE date=%s", $date), OBJECT_K);
        $rows = []; $sumCard = 0; $sumService = 0;
        foreach ($waiters as $w) {
            $c = isset($cards[$w->ID]) ? floatval($cards[$w->ID]->amount) : 0;
            $s = isset($service[$w->ID]) ? floatval($service[$w->ID]->amount) : 0;
            if (!snspa_is_user_active_on_date($w->ID, $date) && $c == 0 && $s == 0) continue;
            $rows[] = ['id' => $w->ID, 'name' => $w->display_name, 'card' => $c, 'service' => $s];
            $sumCard += $c; $sumService += $s;
        }
        $out['manager'] = ['waiters' => $rows, 'sumCard' => $sumCard, 'sumService' => $sumService];
        // Kto wpisał karty/serwis tego dnia (szybkie napiwki albo panel kierownika).
        $out['manager']['enteredBy'] = function_exists('snspa_entry_log_get') ? snspa_entry_log_get($date) : null;
    }

    // --- ADMIN_BAR: godziny barmanów (+ pomoc kelnerów) ---
    if ($f['isAdminBar']) {
        // OBJECT_K indeksuje wyniki PIERWSZĄ kolumną — musi to być user_id (a nie id wiersza),
        // inaczej formularz pokazuje 0 h przy już zapisanych osobach i kolejny zapis je kasuje.
        $staff_data = $wpdb->get_results($wpdb->prepare("SELECT user_id, hours, extra_card, service_hours FROM {$wpdb->prefix}sn_staff_work WHERE date=%s AND type='barman'", $date), OBJECT_K);
        $bar_users = get_users(['role' => 'barman']);
        $bar_ids = wp_list_pluck($bar_users, 'ID');
        $extra_ids = [];
        foreach ($staff_data as $uid => $sd) if (!in_array($uid, $bar_ids)) $extra_ids[] = $uid;
        $all_ids = array_merge($bar_ids, $extra_ids);
        $rows = [];
        foreach ($all_ids as $uid) {
            $u = get_userdata($uid); if (!$u) continue;
            $h = isset($staff_data[$uid]) ? floatval($staff_data[$uid]->hours) : 0;
            $ec = isset($staff_data[$uid]) ? floatval($staff_data[$uid]->extra_card) : 0;
            if (!snspa_is_user_active_on_date($uid, $date) && $h == 0 && $ec == 0) continue;
            $rows[] = ['id' => $uid, 'name' => $u->display_name, 'hours' => $h, 'extraCard' => $ec, 'helper' => !in_array($uid, $bar_ids)];
        }
        $helperOptions = [];
        foreach (get_users(['role' => 'kelner']) as $k) {
            if (in_array($k->ID, $all_ids)) continue;
            if (!snspa_is_user_active_on_date($k->ID, $date)) continue;
            $helperOptions[] = ['id' => $k->ID, 'name' => $k->display_name];
        }
        $out['bar'] = ['staff' => $rows, 'helperOptions' => $helperOptions, 'cashPool' => floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM {$wpdb->prefix}sn_waiter_cash WHERE date=%s AND dept='bar'", $date)) ?: 0)];
        $out['bar']['waiterCash'] = snspa_waiter_cash_rows($date, 'bar');
    }

    // --- ADMIN_KTC: godziny kucharzy ---
    if ($f['isAdminKtc']) {
        $staff_data = $wpdb->get_results($wpdb->prepare("SELECT user_id, hours, extra_card, service_hours FROM {$wpdb->prefix}sn_staff_work WHERE date=%s AND type='kucharz'", $date), OBJECT_K);
        $rows = [];
        foreach (get_users(['role' => 'kucharz']) as $u) {
            $h = isset($staff_data[$u->ID]) ? floatval($staff_data[$u->ID]->hours) : 0;
            $ec = isset($staff_data[$u->ID]) ? floatval($staff_data[$u->ID]->extra_card) : 0;
            if (!snspa_is_user_active_on_date($u->ID, $date) && $h == 0 && $ec == 0) continue;
            $rows[] = ['id' => $u->ID, 'name' => $u->display_name, 'hours' => $h, 'extraCard' => $ec];
        }
        $out['kitchen'] = ['staff' => $rows, 'cashPool' => floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM {$wpdb->prefix}sn_waiter_cash WHERE date=%s AND dept='kuchnia'", $date)) ?: 0)];
        $out['kitchen']['waiterCash'] = snspa_waiter_cash_rows($date, 'kuchnia');
    }

    // --- KELNER: własne dane dnia + rozliczenie ---
    if ($f['isKelner']) {
        $dob = get_user_meta($u_id, 'user_birth_date', true);
        $age = $dob ? (new DateTime($dob))->diff(new DateTime($date))->y : null;
        $k_raw = floatval($wpdb->get_var($wpdb->prepare("SELECT amount FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND date=%s", $u_id, $date)) ?: 0);
        $s_raw = floatval($wpdb->get_var($wpdb->prepare("SELECT amount FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND date=%s", $u_id, $date)) ?: 0);
        $cash_bar = floatval($wpdb->get_var($wpdb->prepare("SELECT amount FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND date=%s AND dept='bar'", $u_id, $date)) ?: 0);
        $cash_ktc = floatval($wpdb->get_var($wpdb->prepare("SELECT amount FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND date=%s AND dept='kuchnia'", $u_id, $date)) ?: 0);
        $pct = snspa_percent_from_day_map(snspa_get_day_percent_overrides($date), $u_id);
        // Gotówka korzysta z procentu ustawionego dla karty (patrz snspa_sync_waiter_cash).
        $cash100 = snspa_cash100_from_row($cash_bar, $cash_ktc, $pct['card']['bar'], $pct['card']['kitchen']);
        $exempt = snspa_is_tax_exempt($u_id, $date);
        $k_net = ($tax['cardActive'] && !$exempt) ? $k_raw * (1 - $tax['rate']) : $k_raw;
        $s_net = ($tax['servActive'] && !$exempt) ? $s_raw * (1 - $tax['rate']) : $s_raw;
        // Karta+gotówka rozliczane procentem KARTY, serwis — procentem SERWISU (mogą się różnić).
        $card_and_cash = $k_net + $cash100;
        $poolBar = $card_and_cash * $pct['card']['bar'] + $s_net * $pct['service']['bar'];
        $poolKtc = $card_and_cash * $pct['card']['kitchen'] + $s_net * $pct['service']['kitchen'];
        $total_net = $k_net + $s_net + $cash100;
        $out['kelnerSelf'] = [
            'age' => $age, 'cardRaw' => $k_raw, 'cardNet' => $k_net,
            'serviceRaw' => $s_raw, 'serviceNet' => $s_net,
            'cashGivenBar' => $cash_bar, 'cashGivenKtc' => $cash_ktc, 'cash100' => $cash100,
            'taxCut' => ($k_raw - $k_net) + ($s_raw - $s_net),
            'cardBarPercent' => $pct['card']['bar'] * 100, 'cardKitchenPercent' => $pct['card']['kitchen'] * 100,
            'serviceBarPercent' => $pct['service']['bar'] * 100, 'serviceKitchenPercent' => $pct['service']['kitchen'] * 100,
            'isPercentOverride' => ($pct['card']['bar'] !== 0.10 || $pct['card']['kitchen'] !== 0.20 || $pct['service']['bar'] !== 0.10 || $pct['service']['kitchen'] !== 0.20),
            'poolBar' => $poolBar, 'poolKtc' => $poolKtc,
            'payout' => $total_net - $poolBar - $poolKtc,
        ];
    }

    // --- BARMAN / KUCHARZ: własne godziny i wyliczona wypłata ---
    if ($f['isBarman'] || $f['isKucharz']) {
        $type = $f['isBarman'] ? 'barman' : 'kucharz';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_staff_work WHERE date=%s AND user_id=%d AND type=%s", $date, $u_id, $type));
        $out['staffSelf'] = [
            'type' => $type,
            'canEditHours' => ($type === 'barman'),
            'hours' => $row ? floatval($row->hours) : 0,
            'serviceHours' => $row ? floatval($row->service_hours) : 0,
            'extraCard' => $row ? floatval($row->extra_card) : 0,
            'calcCash' => $row ? floatval($row->calc_cash) : 0,
            'calcCard' => $row ? floatval($row->calc_card) : 0,
            'calcService' => $row ? floatval($row->calc_service) : 0,
        ];
    }

    // --- Zbiorcze zestawienie personelu (dla ról zarządzających / admina / samego siebie) ---
    if ($f['isManager'] || $f['isAdminBar'] || $f['isAdminKtc'] || $f['isBarman'] || $f['isKucharz']) {
        $filter = '';
        if ($f['isManager']) {
            $filter = ''; // manager/administrator widzi wszystkich
        } elseif ($f['isAdminBar']) {
            $filter = "AND type='barman'";
        } elseif ($f['isAdminKtc']) {
            $filter = "AND type='kucharz'";
        } elseif ($f['isBarman'] || $f['isKucharz']) {
            $filter = $wpdb->prepare("AND user_id=%d", $u_id);
        }
        $staff = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_staff_work WHERE date='" . esc_sql($date) . "' $filter");
        $rows = [];
        foreach ($staff as $s) {
            $u = get_userdata($s->user_id); if (!$u) continue;
            $rows[] = [
                'id' => $s->user_id, 'name' => $u->display_name, 'type' => $s->type,
                'hours' => floatval($s->hours), 'serviceHours' => floatval($s->service_hours),
                'extraCard' => floatval($s->extra_card), 'calcCash' => floatval($s->calc_cash),
                'calcCard' => floatval($s->calc_card), 'calcService' => floatval($s->calc_service),
            ];
        }
        $out['staffSummary'] = $rows;
    }

    return $out;
}

/* -----------------------------------------------------------
 * Zapis godzin działu przez admina — aktualizuje tylko osoby przesłane
 * w formularzu (zamiast kasować cały dzień i wstawiać od nowa).
 * Dzięki temu nie giną: wpisy osób spoza formularza, godziny wpisane
 * samodzielnie przez barmana/kucharza ani godziny serwisu (service_hours).
 *
 * Ochrona przed nadpisaniem (v1.2.2): formularz przesyła też wartości,
 * które admin WIDZIAŁ przy otwarciu ($orig). Pole, którego admin nie zmienił,
 * nie jest zapisywane — jeśli ktoś (np. barman) zmienił je w międzyczasie,
 * jego wpis zostaje. Pole zmienione przez admina jest zapisywane, a gdy
 * w bazie była już inna wartość niż ta widziana przez admina, zwracamy
 * informację o konflikcie.
 * Zwraca ['kept' => [...], 'overwritten' => [...]] z nazwami osób.
 * ----------------------------------------------------------- */
function snspa_upsert_staff_rows($date, $type, array $hours, array $extra, $orig = null) {
    global $wpdb;
    $table = "{$wpdb->prefix}sn_staff_work";
    $r2 = function($v){ return round(floatval($v), 2); };
    $origH = is_array($orig) ? (array) ($orig['hours'] ?? []) : null;
    $origE = is_array($orig) ? (array) ($orig['extraCard'] ?? []) : null;
    $report = ['kept' => [], 'overwritten' => []];

    $uids = array_unique(array_merge(array_keys($hours), array_keys($extra)));
    foreach ($uids as $uid) {
        $uid = intval($uid); if ($uid <= 0) continue;
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, hours, extra_card, service_hours FROM $table WHERE date=%s AND user_id=%d AND type=%s", $date, $uid, $type));
        $dbH = $row ? $r2($row->hours) : 0.0;
        $dbE = $row ? $r2($row->extra_card) : 0.0;

        $newH = $r2($hours[$uid] ?? 0);
        $newE = $r2($extra[$uid] ?? 0);
        $kept = false; $over = false;

        // Godziny
        if ($origH !== null && array_key_exists($uid, $origH)) {
            $seen = $r2($origH[$uid]);
            if ($newH === $seen) {                 // admin nie ruszał pola
                if ($dbH !== $seen) $kept = true;  // ktoś zmienił w międzyczasie — zostawiamy
                $newH = $dbH;
            } elseif ($dbH !== $seen) {
                $over = true;                      // obie strony zmieniły — wygrywa admin
            }
        }
        // Dodatkowa karta
        if ($origE !== null && array_key_exists($uid, $origE)) {
            $seen = $r2($origE[$uid]);
            if ($newE === $seen) {
                if ($dbE !== $seen) $kept = true;
                $newE = $dbE;
            } elseif ($dbE !== $seen) {
                $over = true;
            }
        }

        if ($row) {
            if ($newH === $dbH && $newE === $dbE) {
                // bez zmian
            } elseif ($newH <= 0 && $newE <= 0 && floatval($row->service_hours) <= 0) {
                $wpdb->delete($table, ['id' => $row->id]);
            } else {
                $wpdb->update($table, ['hours' => $newH, 'extra_card' => $newE], ['id' => $row->id]);
            }
        } elseif ($newH > 0 || $newE > 0) {
            $wpdb->insert($table, ['date' => $date, 'user_id' => $uid, 'hours' => $newH, 'service_hours' => 0, 'extra_card' => $newE, 'type' => $type]);
        }

        if ($kept || $over) {
            $u = get_userdata($uid);
            $name = $u ? $u->display_name : ('ID ' . $uid);
            if ($over) $report['overwritten'][] = $name; else $report['kept'][] = $name;
        }
    }
    return $report;
}

/* -----------------------------------------------------------
 * POST /day — zapis, tylko sekcje do których użytkownik ma prawo
 * ----------------------------------------------------------- */
function snspa_rest_save_day(WP_REST_Request $req) {
    global $wpdb;
    $f = snspa_current_flags();
    $body = $req->get_json_params();
    $date = isset($body['date']) ? sanitize_text_field($body['date']) : '';
    if (!$date || !strtotime($date)) return new WP_Error('bad_date', 'Nieprawidłowa data', ['status' => 400]);
    $u_id = $f['user_id'];
    $conflicts = ['kept' => [], 'overwritten' => []];

    if ($f['isManager'] && !empty($body['manager'])) {
        $wpdb->delete("{$wpdb->prefix}sn_waiter_tips", ['date' => $date]);
        $wpdb->delete("{$wpdb->prefix}sn_waiter_service", ['date' => $date]);
        foreach ((array) ($body['manager']['cards'] ?? []) as $id => $amt) {
            $amt = floatval($amt);
            if ($amt > 0) $wpdb->insert("{$wpdb->prefix}sn_waiter_tips", ['date' => $date, 'waiter_id' => intval($id), 'amount' => $amt]);
        }
        foreach ((array) ($body['manager']['service'] ?? []) as $id => $amt) {
            $amt = floatval($amt);
            if ($amt > 0) $wpdb->insert("{$wpdb->prefix}sn_waiter_service", ['date' => $date, 'waiter_id' => intval($id), 'amount' => $amt]);
        }
        if (function_exists('snspa_entry_log_record')) snspa_entry_log_record($date, $u_id, 'panel');
    }

    if ($f['isAdminBar'] && !empty($body['bar'])) {
        if (isset($body['bar']['hours']) || !empty($body['bar']['helper'])) {
            $hours = (array) ($body['bar']['hours'] ?? []);
            $extra = (array) ($body['bar']['extraCard'] ?? []);
            if (!empty($body['bar']['helper']) && !empty($body['bar']['helper']['id'])) {
                $hid = intval($body['bar']['helper']['id']);
                $hh = floatval($body['bar']['helper']['hours'] ?? 0);
                $hc = floatval($body['bar']['helper']['card'] ?? 0);
                if ($hh > 0 || $hc > 0) { $hours[$hid] = $hh; $extra[$hid] = $hc; }
            }
            $conflicts = snspa_merge_conflicts($conflicts, snspa_upsert_staff_rows($date, 'barman', $hours, $extra, $body['bar']['orig'] ?? null));
        }
        // Gotówka zadeklarowana PRZEZ ADMINA BARU za kelnerów — synchronizuje się automatycznie z kuchnią (x2, bo 20%=2x10%)
        foreach ((array) ($body['bar']['cash'] ?? []) as $wid => $amt) {
            snspa_sync_waiter_cash($date, intval($wid), 'bar', $amt);
        }
    }

    if ($f['isAdminKtc'] && !empty($body['kitchen'])) {
        if (isset($body['kitchen']['hours'])) {
            $hours = (array) ($body['kitchen']['hours'] ?? []);
            $extra = (array) ($body['kitchen']['extraCard'] ?? []);
            $conflicts = snspa_merge_conflicts($conflicts, snspa_upsert_staff_rows($date, 'kucharz', $hours, $extra, $body['kitchen']['orig'] ?? null));
        }
        // Gotówka zadeklarowana PRZEZ ADMINA KUCHNI za kelnerów — synchronizuje się automatycznie z barem (x0.5, bo 10%=0.5x20%)
        foreach ((array) ($body['kitchen']['cash'] ?? []) as $wid => $amt) {
            snspa_sync_waiter_cash($date, intval($wid), 'kuchnia', $amt);
        }
    }

    // Kelner: własna gotówka oddana do baru/kuchni (10%/20%) — druga wartość dopełnia się automatycznie
    if ($f['isKelner'] && isset($body['kelnerCash'])) {
        $amt_bar = floatval($body['kelnerCash']['bar'] ?? 0);
        $amt_ktc = floatval($body['kelnerCash']['ktc'] ?? 0);
        if ($amt_bar > 0) snspa_sync_waiter_cash($date, $u_id, 'bar', $amt_bar);
        elseif ($amt_ktc > 0) snspa_sync_waiter_cash($date, $u_id, 'kuchnia', $amt_ktc);
        else { snspa_upsert_cash_row($date, $u_id, 'bar', 0); snspa_upsert_cash_row($date, $u_id, 'kuchnia', 0); }
    }

    // Barman: własne godziny (bez uprawnień admina działu).
    // Kucharz NIE wpisuje sam godzin — robi to wyłącznie admin kuchni (v1.2.3).
    if ($f['isBarman'] && !$f['isAdminBar'] && !$f['isAdminKtc'] && isset($body['myHours'])) {
        $type = 'barman';
        $h = floatval($body['myHours']);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_staff_work WHERE date=%s AND user_id=%d AND type=%s", $date, $u_id, $type));
        if ($existing) $wpdb->update("{$wpdb->prefix}sn_staff_work", ['hours' => $h], ['id' => $existing]);
        else $wpdb->insert("{$wpdb->prefix}sn_staff_work", ['date' => $date, 'user_id' => $u_id, 'hours' => $h, 'service_hours' => 0, 'extra_card' => 0, 'type' => $type]);
    }

    snspa_recalculate($date);
    $out = snspa_get_day_data($date);
    if ($conflicts['kept'] || $conflicts['overwritten']) $out['saveConflicts'] = $conflicts;
    return $out;
}

function snspa_merge_conflicts($a, $b) {
    return ['kept' => array_merge($a['kept'], $b['kept']), 'overwritten' => array_merge($a['overwritten'], $b['overwritten'])];
}

/* -----------------------------------------------------------
 * GET /month — raporty miesięczne wg roli
 * ----------------------------------------------------------- */
function snspa_rest_get_month(WP_REST_Request $req) {
    global $wpdb;
    $f = snspa_current_flags();
    $m = intval($req->get_param('m')) ?: intval(current_time('m'));
    $y = intval($req->get_param('y')) ?: intval(current_time('Y'));
    $tax = snspa_tax_settings();
    $u_id = $f['user_id'];
    $view = ($req->get_param('view') === 'daily') ? 'daily' : 'general';
    $out = ['month' => $m, 'year' => $y, 'view' => $view];

    if ($f['isKelner']) {
        $overrides = snspa_get_month_percent_overrides($m, $y);
        if ($view === 'general') {
            $k_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $u_id, $m, $y));
            $card_raw = 0; $c_net = 0; $c_pool10 = 0; $c_pool20 = 0;
            foreach ($k_entries as $e) {
                $amt = floatval($e->amount); $card_raw += $amt;
                $ex = snspa_is_tax_exempt($u_id, $e->date);
                $net = ($tax['cardActive'] && !$ex) ? $amt * (1 - $tax['rate']) : $amt;
                $c_net += $net;
                $pct = snspa_percent_from_month_map($overrides, $e->date, $u_id);
                $c_pool10 += $net * $pct['card']['bar']; $c_pool20 += $net * $pct['card']['kitchen'];
            }
            $s_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $u_id, $m, $y));
            $serv_raw = 0; $s_net = 0; $s_pool10 = 0; $s_pool20 = 0;
            foreach ($s_entries as $e) {
                $amt = floatval($e->amount); $serv_raw += $amt;
                $ex = snspa_is_tax_exempt($u_id, $e->date);
                $net = ($tax['servActive'] && !$ex) ? $amt * (1 - $tax['rate']) : $amt;
                $s_net += $net;
                $pct = snspa_percent_from_month_map($overrides, $e->date, $u_id);
                $s_pool10 += $net * $pct['service']['bar']; $s_pool20 += $net * $pct['service']['kitchen'];
            }
            $cash100 = 0; $g_pool10 = 0; $g_pool20 = 0;
            $daily_cash = $wpdb->get_results($wpdb->prepare("SELECT date, SUM(CASE WHEN dept='bar' THEN amount ELSE 0 END) as cb, SUM(CASE WHEN dept='kuchnia' THEN amount ELSE 0 END) as ck FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d GROUP BY date", $u_id, $m, $y));
            foreach ($daily_cash as $dc) {
                $pct = snspa_percent_from_month_map($overrides, $dc->date, $u_id);
                $day_cash100 = snspa_cash100_from_row(floatval($dc->cb), floatval($dc->ck), $pct['card']['bar'], $pct['card']['kitchen']);
                $cash100 += $day_cash100;
                $g_pool10 += $day_cash100 * $pct['card']['bar']; $g_pool20 += $day_cash100 * $pct['card']['kitchen'];
            }

            $out['kelnerReport'] = ['categories' => [
                ['label' => 'KARTY', 'raw' => $card_raw, 'net' => $c_net, 'pool10' => $c_pool10, 'pool20' => $c_pool20, 'pay70' => $c_net - $c_pool10 - $c_pool20],
                ['label' => 'SERWIS', 'raw' => $serv_raw, 'net' => $s_net, 'pool10' => $s_pool10, 'pool20' => $s_pool20, 'pay70' => $s_net - $s_pool10 - $s_pool20],
                ['label' => 'GOTÓWKA', 'raw' => $cash100, 'net' => $cash100, 'pool10' => $g_pool10, 'pool20' => $g_pool20, 'pay70' => $cash100 - $g_pool10 - $g_pool20],
            ]];
        } else {
            $days = $wpdb->get_results($wpdb->prepare("
                SELECT d.date,
                  COALESCE((SELECT amount FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND date=d.date),0) as card,
                  COALESCE((SELECT amount FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND date=d.date),0) as serv,
                  COALESCE((SELECT amount FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND date=d.date AND dept='bar'),0) as cb,
                  COALESCE((SELECT amount FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND date=d.date AND dept='kuchnia'),0) as ck
                FROM (
                    SELECT DISTINCT date FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d
                    UNION SELECT DISTINCT date FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d
                    UNION SELECT DISTINCT date FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d
                ) d ORDER BY d.date ASC",
                $u_id, $u_id, $u_id, $u_id, $u_id, $m, $y, $u_id, $m, $y, $u_id, $m, $y));

            $rows = [];
            foreach ($days as $d) {
                $pct = snspa_percent_from_month_map($overrides, $d->date, $u_id);
                $exempt = snspa_is_tax_exempt($u_id, $d->date);
                $c_net = ($tax['cardActive'] && !$exempt) ? $d->card * (1 - $tax['rate']) : $d->card;
                $s_net = ($tax['servActive'] && !$exempt) ? $d->serv * (1 - $tax['rate']) : $d->serv;
                $cash100 = snspa_cash100_from_row(floatval($d->cb), floatval($d->ck), $pct['card']['bar'], $pct['card']['kitchen']);
                $card_and_cash = $c_net + $cash100;
                $poolBar = $card_and_cash * $pct['card']['bar'] + $s_net * $pct['service']['bar'];
                $poolKtc = $card_and_cash * $pct['card']['kitchen'] + $s_net * $pct['service']['kitchen'];
                $base = $c_net + $s_net + $cash100;
                $rows[] = ['date'=>$d->date,'cardRaw'=>floatval($d->card),'cardNet'=>$c_net,'servRaw'=>floatval($d->serv),'servNet'=>$s_net,'cash100'=>$cash100,
                    'pool10'=>$poolBar,'pool20'=>$poolKtc,'pay70'=>$base-$poolBar-$poolKtc,
                    'cardBarPercent'=>$pct['card']['bar']*100,'cardKitchenPercent'=>$pct['card']['kitchen']*100,
                    'serviceBarPercent'=>$pct['service']['bar']*100,'serviceKitchenPercent'=>$pct['service']['kitchen']*100];
            }
            $out['kelnerReport'] = ['days' => $rows];
        }
    }

    if ($f['isManager']) {
        $rows = [];
        $overrides = snspa_get_month_percent_overrides($m, $y);
        if ($view === 'general') {
            foreach (get_users(['role' => 'kelner']) as $w) {
                $dob = get_user_meta($w->ID, 'user_birth_date', true);
                $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : null;
                $k_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $w->ID, $m, $y));
                $k_raw = 0; $k_net = 0; $pool_bar = 0; $pool_ktc = 0;
                foreach ($k_entries as $e) {
                    $amt = floatval($e->amount); $k_raw += $amt;
                    $ex = snspa_is_tax_exempt($w->ID, $e->date);
                    $net = ($tax['cardActive'] && !$ex) ? $amt * (1 - $tax['rate']) : $amt;
                    $k_net += $net;
                    $pct = snspa_percent_from_month_map($overrides, $e->date, $w->ID);
                    $pool_bar += $net * $pct['card']['bar']; $pool_ktc += $net * $pct['card']['kitchen'];
                }
                $s_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $w->ID, $m, $y));
                $s_raw = 0; $s_net = 0;
                foreach ($s_entries as $e) {
                    $amt = floatval($e->amount); $s_raw += $amt;
                    $ex = snspa_is_tax_exempt($w->ID, $e->date);
                    $net = ($tax['servActive'] && !$ex) ? $amt * (1 - $tax['rate']) : $amt;
                    $s_net += $net;
                    $pct = snspa_percent_from_month_map($overrides, $e->date, $w->ID);
                    $pool_bar += $net * $pct['service']['bar']; $pool_ktc += $net * $pct['service']['kitchen'];
                }
                $net_base = $k_net + $s_net;
                if ($net_base <= 0) continue;
                $rows[] = ['name'=>$w->display_name,'age'=>$age,'cardRaw'=>$k_raw,'cardNet'=>$k_net,'servRaw'=>$s_raw,'servNet'=>$s_net,'poolBar'=>$pool_bar,'poolKtc'=>$pool_ktc];
            }
        } else {
            $logs = $wpdb->get_results($wpdb->prepare("
                SELECT d.date, d.waiter_id,
                COALESCE((SELECT amount FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=d.waiter_id AND date=d.date),0) as card,
                COALESCE((SELECT amount FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=d.waiter_id AND date=d.date),0) as serv
                FROM (
                    SELECT DISTINCT date, waiter_id FROM {$wpdb->prefix}sn_waiter_tips WHERE MONTH(date)=%d AND YEAR(date)=%d
                    UNION SELECT DISTINCT date, waiter_id FROM {$wpdb->prefix}sn_waiter_service WHERE MONTH(date)=%d AND YEAR(date)=%d
                ) d ORDER BY d.date ASC, d.waiter_id ASC", $m, $y, $m, $y));
            foreach ($logs as $l) {
                $u_info = get_userdata($l->waiter_id); if (!$u_info) continue;
                $dob = get_user_meta($l->waiter_id, 'user_birth_date', true);
                $age = $dob ? (new DateTime($dob))->diff(new DateTime($l->date))->y : null;
                $is_exempt = snspa_is_tax_exempt($l->waiter_id, $l->date);
                $k_net = ($tax['cardActive'] && !$is_exempt) ? $l->card * (1 - $tax['rate']) : $l->card;
                $s_net = ($tax['servActive'] && !$is_exempt) ? $l->serv * (1 - $tax['rate']) : $l->serv;
                $pct = snspa_percent_from_month_map($overrides, $l->date, $l->waiter_id);
                $rows[] = ['date'=>$l->date,'name'=>$u_info->display_name,'age'=>$age,'cardRaw'=>floatval($l->card),'cardNet'=>$k_net,'servRaw'=>floatval($l->serv),'servNet'=>$s_net,
                    'poolBar'=>$k_net*$pct['card']['bar']+$s_net*$pct['service']['bar'],'poolKtc'=>$k_net*$pct['card']['kitchen']+$s_net*$pct['service']['kitchen']];
            }
        }
        $out['managerReport'] = $rows;
    }

    if ($f['isAdminBar'] || $f['isAdminKtc'] || $f['isBarman'] || $f['isKucharz']) {
        $filter = '';
        if ($f['isAdmin']) $filter = '';
        elseif ($f['isAdminBar']) $filter = " AND type='barman'";
        elseif ($f['isAdminKtc']) $filter = " AND type='kucharz'";
        elseif ($f['isBarman']) $filter = $wpdb->prepare(" AND user_id=%d", $u_id);
        elseif ($f['isKucharz']) $filter = $wpdb->prepare(" AND user_id=%d", $u_id);

        $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_staff_work WHERE MONTH(date)=$m AND YEAR(date)=$y $filter ORDER BY date ASC, user_id ASC");
        $rows = [];
        if ($view === 'general') {
            $agg = [];
            foreach ($data as $r) {
                if (!isset($agg[$r->user_id])) $agg[$r->user_id] = ['hours'=>0,'serviceHours'=>0,'cash'=>0,'card'=>0,'service'=>0,'extra'=>0];
                $agg[$r->user_id]['hours'] += floatval($r->hours);
                $agg[$r->user_id]['serviceHours'] += floatval($r->service_hours);
                $agg[$r->user_id]['cash'] += floatval($r->calc_cash);
                $agg[$r->user_id]['card'] += floatval($r->calc_card);
                $agg[$r->user_id]['service'] += floatval($r->calc_service);
                $agg[$r->user_id]['extra'] += floatval($r->extra_card);
            }
            foreach ($agg as $uid => $a) {
                $u = get_userdata($uid); if (!$u) continue;
                $a['name'] = $u->display_name;
                $a['total'] = $a['card'] + $a['service'];
                $rows[] = $a;
            }
        } else {
            foreach ($data as $r) {
                $u = get_userdata($r->user_id); if (!$u) continue;
                $rows[] = ['date'=>$r->date,'name'=>$u->display_name,'hours'=>floatval($r->hours),'serviceHours'=>floatval($r->service_hours),
                    'cash'=>floatval($r->calc_cash),'card'=>floatval($r->calc_card),'service'=>floatval($r->calc_service),
                    'extra'=>floatval($r->extra_card),'total'=>floatval($r->calc_card)+floatval($r->calc_service)];
            }
        }
        $out['staffReport'] = $rows;
    }

    return $out;
}

/* -----------------------------------------------------------
 * "Podsumowanie Miesiąca" — karty dzienne + tabela zbiorcza z podziałem
 * na 10%/20% osobno dla karty i serwisu (odpowiednik [widzet_miesieczny_pelny])
 * ----------------------------------------------------------- */
function snspa_rest_get_month_summary(WP_REST_Request $req) {
    global $wpdb;
    $m = intval($req->get_param('m')) ?: intval(current_time('m'));
    $y = intval($req->get_param('y')) ?: intval(current_time('Y'));
    $tax = snspa_tax_settings();
    $waiters = get_users(['role' => 'kelner']);
    $days_in_month = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
    $month_overrides = snspa_get_month_percent_overrides($m, $y);

    $dayCards = [];
    for ($d = 1; $d <= $days_in_month; $d++) {
        $current_date = sprintf('%04d-%02d-%02d', $y, $m, $d);
        $cards_d = $wpdb->get_results($wpdb->prepare("SELECT waiter_id, amount FROM {$wpdb->prefix}sn_waiter_tips WHERE date=%s", $current_date), OBJECT_K);
        $servs_d = $wpdb->get_results($wpdb->prepare("SELECT waiter_id, amount FROM {$wpdb->prefix}sn_waiter_service WHERE date=%s", $current_date), OBJECT_K);
        $cash_d = $wpdb->get_results($wpdb->prepare("SELECT waiter_id, amount, dept FROM {$wpdb->prefix}sn_waiter_cash WHERE date=%s", $current_date));
        if (empty($cards_d) && empty($servs_d) && empty($cash_d)) continue;

        $day_c_net = 0; $day_s_net = 0; $day_g100 = 0; $servDetails = [];
        $c_p10 = 0; $c_p20 = 0; $s_p10 = 0; $s_p20 = 0; $g_p10 = 0; $g_p20 = 0;
        foreach ($waiters as $w) {
            $c_raw = isset($cards_d[$w->ID]) ? floatval($cards_d[$w->ID]->amount) : 0;
            $s_raw = isset($servs_d[$w->ID]) ? floatval($servs_d[$w->ID]->amount) : 0;
            $exempt = snspa_is_tax_exempt($w->ID, $current_date);
            $c_net = ($tax['cardActive'] && !$exempt) ? $c_raw * (1 - $tax['rate']) : $c_raw;
            $day_c_net += $c_net;
            $s_net = ($tax['servActive'] && !$exempt) ? $s_raw * (1 - $tax['rate']) : $s_raw;
            $day_s_net += $s_net;
            $pct = snspa_percent_from_month_map($month_overrides, $current_date, $w->ID);
            $c_p10 += $c_net * $pct['card']['bar']; $c_p20 += $c_net * $pct['card']['kitchen'];
            $s_p10 += $s_net * $pct['service']['bar']; $s_p20 += $s_net * $pct['service']['kitchen'];
            $w_cb = 0; $w_ck = 0;
            foreach ($cash_d as $cr) { if ($cr->waiter_id == $w->ID) { if ($cr->dept === 'bar') $w_cb = floatval($cr->amount); if ($cr->dept === 'kuchnia') $w_ck = floatval($cr->amount); } }
            $w_cash100 = snspa_cash100_from_row($w_cb, $w_ck, $pct['card']['bar'], $pct['card']['kitchen']);
            $day_g100 += $w_cash100;
            $g_p10 += $w_cash100 * $pct['card']['bar']; $g_p20 += $w_cash100 * $pct['card']['kitchen'];
            if ($s_raw > 0) $servDetails[] = ['name' => $w->display_name, 'net' => $s_net, 'taxed' => ($s_raw > $s_net), 'barPercent' => $pct['service']['bar']*100, 'kitchenPercent' => $pct['service']['kitchen']*100];
        }

        $dayCards[] = [
            'date' => $current_date,
            'card' => ['net' => $day_c_net, 'p10' => $c_p10, 'p20' => $c_p20],
            'service' => ['net' => $day_s_net, 'p10' => $s_p10, 'p20' => $s_p20],
            'cash' => ['net' => $day_g100, 'p10' => $g_p10, 'p20' => $g_p20],
            'serviceDetails' => $servDetails,
        ];
    }

    $summaryRows = [];
    foreach ($waiters as $w) {
        $card_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $w->ID, $m, $y));
        $c_net = 0; $m_card = 0; $k10 = 0; $k20 = 0;
        foreach ($card_entries as $e) {
            $m_card += floatval($e->amount);
            $ex = snspa_is_tax_exempt($w->ID, $e->date);
            $net = ($tax['cardActive'] && !$ex) ? floatval($e->amount) * (1 - $tax['rate']) : floatval($e->amount);
            $c_net += $net;
            $pct = snspa_percent_from_month_map($month_overrides, $e->date, $w->ID);
            $k10 += $net * $pct['card']['bar']; $k20 += $net * $pct['card']['kitchen'];
        }
        $serv_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $w->ID, $m, $y));
        $s_net = 0; $m_serv = 0; $s10 = 0; $s20 = 0;
        foreach ($serv_entries as $e) {
            $m_serv += floatval($e->amount);
            $ex = snspa_is_tax_exempt($w->ID, $e->date);
            $net = ($tax['servActive'] && !$ex) ? floatval($e->amount) * (1 - $tax['rate']) : floatval($e->amount);
            $s_net += $net;
            $pct = snspa_percent_from_month_map($month_overrides, $e->date, $w->ID);
            $s10 += $net * $pct['service']['bar']; $s20 += $net * $pct['service']['kitchen'];
        }
        $cash_entries = $wpdb->get_results($wpdb->prepare("SELECT date, SUM(CASE WHEN dept='bar' THEN amount ELSE 0 END) as cb, SUM(CASE WHEN dept='kuchnia' THEN amount ELSE 0 END) as ck FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d GROUP BY date", $w->ID, $m, $y));
        $m_cash100 = 0;
        foreach ($cash_entries as $ce) {
            $pct = snspa_percent_from_month_map($month_overrides, $ce->date, $w->ID);
            $m_cash100 += snspa_cash100_from_row(floatval($ce->cb), floatval($ce->ck), $pct['card']['bar'], $pct['card']['kitchen']);
        }
        if ($m_card == 0 && $m_serv == 0 && $m_cash100 == 0) continue;
        $summaryRows[] = ['name' => $w->display_name, 'cardNet' => $c_net, 'servNet' => $s_net, 'cash100' => $m_cash100,
            'k10' => $k10, 's10' => $s10, 'k20' => $k20, 's20' => $s20, 'sum10' => $k10 + $s10, 'sum20' => $k20 + $s20];
    }

    return ['month' => $m, 'year' => $y, 'days' => $dayCards, 'summary' => $summaryRows];
}

/* -----------------------------------------------------------
 * Lista pracowników do "Sprawdź pracownika" (kelner/barman/kucharz)
 * ----------------------------------------------------------- */
function snspa_rest_staff_list() {
    $users = get_users(['role__in' => ['kelner', 'barman', 'kucharz']]);
    $out = [];
    foreach ($users as $u) {
        $roles = (array) $u->roles;
        $role = in_array('kelner', $roles) ? 'kelner' : (in_array('barman', $roles) ? 'barman' : 'kucharz');
        $end_date = get_user_meta($u->ID, 'sn_employment_end', true);
        $terminated = !empty($end_date) && strtotime($end_date) < time();
        $out[] = ['id' => $u->ID, 'name' => $u->display_name, 'role' => $role, 'terminated' => $terminated];
    }
    return $out;
}

/* -----------------------------------------------------------
 * "Sprawdź pracownika" — pełny raport miesięczny dowolnej osoby
 * (odpowiednik [sprawdzenie_miesiac])
 * ----------------------------------------------------------- */
function snspa_rest_staff_check(WP_REST_Request $req) {
    global $wpdb;
    $uid = intval($req->get_param('user_id'));
    $m = intval($req->get_param('m')) ?: intval(current_time('m'));
    $y = intval($req->get_param('y')) ?: intval(current_time('Y'));
    $target = get_userdata($uid);
    if (!$target) return new WP_Error('not_found', 'Nie znaleziono pracownika', ['status' => 404]);

    $tax = snspa_tax_settings();
    $roles = (array) $target->roles;
    $is_kelner = in_array('kelner', $roles);
    $dob = get_user_meta($uid, 'user_birth_date', true);
    $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : null;

    $out = ['userId' => $uid, 'name' => $target->display_name, 'age' => $age, 'isKelner' => $is_kelner, 'month' => $m, 'year' => $y];

    if ($is_kelner) {
        $month_overrides = snspa_get_month_percent_overrides($m, $y);
        $k_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $uid, $m, $y));
        $card_raw = 0; $c_net = 0;
        foreach ($k_entries as $e) { $amt = floatval($e->amount); $card_raw += $amt; $ex = snspa_is_tax_exempt($uid, $e->date); $c_net += ($tax['cardActive'] && !$ex) ? $amt*(1-$tax['rate']) : $amt; }
        $s_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $uid, $m, $y));
        $serv_raw = 0; $s_net = 0;
        foreach ($s_entries as $e) { $amt = floatval($e->amount); $serv_raw += $amt; $ex = snspa_is_tax_exempt($uid, $e->date); $s_net += ($tax['servActive'] && !$ex) ? $amt*(1-$tax['rate']) : $amt; }

        $days = $wpdb->get_results($wpdb->prepare("
            SELECT d.date,
              COALESCE((SELECT amount FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND date=d.date),0) as card,
              COALESCE((SELECT amount FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND date=d.date),0) as serv,
              COALESCE((SELECT amount FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND date=d.date AND dept='bar'),0) as cb,
              COALESCE((SELECT amount FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND date=d.date AND dept='kuchnia'),0) as ck
            FROM (
                SELECT DISTINCT date FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d
                UNION SELECT DISTINCT date FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d
                UNION SELECT DISTINCT date FROM {$wpdb->prefix}sn_waiter_cash WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d
            ) d ORDER BY d.date ASC", $uid,$uid,$uid,$uid, $uid,$m,$y, $uid,$m,$y, $uid,$m,$y));

        $dayRows = []; $cash100_total = 0; $poolBar_total = 0; $poolKtc_total = 0; $payout_total = 0;
        foreach ($days as $d) {
            $pct = snspa_percent_from_month_map($month_overrides, $d->date, $uid);
            $ex = snspa_is_tax_exempt($uid, $d->date);
            $dc_net = ($tax['cardActive'] && !$ex) ? $d->card*(1-$tax['rate']) : $d->card;
            $ds_net = ($tax['servActive'] && !$ex) ? $d->serv*(1-$tax['rate']) : $d->serv;
            $d_cash100 = snspa_cash100_from_row(floatval($d->cb), floatval($d->ck), $pct['card']['bar'], $pct['card']['kitchen']);
            $card_and_cash = $dc_net + $d_cash100;
            $poolBar = $card_and_cash * $pct['card']['bar'] + $ds_net * $pct['service']['bar'];
            $poolKtc = $card_and_cash * $pct['card']['kitchen'] + $ds_net * $pct['service']['kitchen'];
            $base = $dc_net + $ds_net + $d_cash100;
            $payout = $base - $poolBar - $poolKtc;
            $cash100_total += $d_cash100; $poolBar_total += $poolBar; $poolKtc_total += $poolKtc; $payout_total += $payout;
            $dayRows[] = ['date'=>$d->date,'cardRaw'=>floatval($d->card),'cardNet'=>$dc_net,'servRaw'=>floatval($d->serv),'servNet'=>$ds_net,'cash100'=>$d_cash100,
                'poolBar'=>$poolBar,'poolKtc'=>$poolKtc,'payout'=>$payout,
                'cardBarPercent'=>$pct['card']['bar']*100,'cardKitchenPercent'=>$pct['card']['kitchen']*100,
                'serviceBarPercent'=>$pct['service']['bar']*100,'serviceKitchenPercent'=>$pct['service']['kitchen']*100];
        }

        $out['kelner'] = [
            'totals' => ['cardRaw'=>$card_raw,'cardNet'=>$c_net,'servRaw'=>$serv_raw,'servNet'=>$s_net,'cash100'=>$cash100_total,
                'poolBar'=>$poolBar_total,'poolKtc'=>$poolKtc_total,'payout'=>$payout_total],
            'days' => $dayRows,
        ];
    } else {
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_staff_work WHERE user_id=%d AND MONTH(date)=%d AND YEAR(date)=%d ORDER BY date ASC", $uid, $m, $y));
        $sum = ['hours'=>0,'serviceHours'=>0,'cash'=>0,'card'=>0,'service'=>0,'extra'=>0];
        $dayRows = [];
        foreach ($results as $r) {
            $sum['hours'] += floatval($r->hours); $sum['serviceHours'] += floatval($r->service_hours);
            $sum['cash'] += floatval($r->calc_cash); $sum['card'] += floatval($r->calc_card); $sum['service'] += floatval($r->calc_service);
            $sum['extra'] += floatval($r->extra_card);
            $dayRows[] = ['date'=>$r->date,'hours'=>floatval($r->hours),'serviceHours'=>floatval($r->service_hours),
                'calcCash'=>floatval($r->calc_cash),'calcCard'=>floatval($r->calc_card),'calcService'=>floatval($r->calc_service),'extraCard'=>floatval($r->extra_card)];
        }
        $out['staff'] = ['totals' => $sum, 'days' => $dayRows];
    }

    return $out;
}

/* -----------------------------------------------------------
 * Rozliczenie serwisu działu (odpowiednik [rozlicz_serwis_admin])
 * ----------------------------------------------------------- */
function snspa_rest_get_service_pool(WP_REST_Request $req) {
    $date = sanitize_text_field($req->get_param('date')) ?: current_time('Y-m-d');
    return snspa_get_service_pool_data($date);
}

function snspa_get_service_pool_data($date) {
    global $wpdb;
    $f = snspa_current_flags();
    $dept = $f['isAdminBar'] ? 'bar' : 'kuchnia';
    $type = $f['isAdminBar'] ? 'barman' : 'kucharz';
    $pct = $f['isAdminBar'] ? 0.10 : 0.20;
    $tax = snspa_tax_settings();

    $waiter_services = $wpdb->get_results($wpdb->prepare("SELECT waiter_id, amount FROM {$wpdb->prefix}sn_waiter_service WHERE date=%s", $date));
    $pool_raw = 0; $pool_taxed = 0; $contributors = [];
    foreach ($waiter_services as $ws) {
        $amt = floatval($ws->amount); $pool_raw += $amt;
        $u = get_userdata($ws->waiter_id);
        $exempt = snspa_is_tax_exempt($ws->waiter_id, $date);
        $taxed = $tax['servActive'] && !$exempt;
        if ($taxed) $amt *= (1 - $tax['rate']);
        $pool_taxed += $amt;
        $contributors[] = ['name' => $u ? $u->display_name : ('ID ' . $ws->waiter_id), 'share' => $amt * $pct, 'taxed' => $taxed];
    }
    $available = $pool_taxed * $pct;

    $staff = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sn_staff_work WHERE date=%s AND type=%s", $date, $type));
    $staffRows = [];
    foreach ($staff as $s) {
        $u = get_userdata($s->user_id); if (!$u) continue;
        $staffRows[] = ['id' => $s->user_id, 'name' => $u->display_name, 'serviceHours' => floatval($s->service_hours), 'calcService' => floatval($s->calc_service)];
    }

    return ['date' => $date, 'dept' => $dept, 'poolRaw' => $pool_raw, 'poolTaxed' => $pool_taxed, 'available' => $available, 'contributors' => $contributors, 'staff' => $staffRows];
}

function snspa_rest_save_service_pool(WP_REST_Request $req) {
    global $wpdb;
    $f = snspa_current_flags();
    $body = $req->get_json_params();
    $date = sanitize_text_field($body['date'] ?? '');
    if (!$date || !strtotime($date)) return new WP_Error('bad_date', 'Nieprawidłowa data', ['status' => 400]);
    $type = $f['isAdminBar'] ? 'barman' : 'kucharz';
    $mode = $body['mode'] ?? 'hours';

    if ($mode === 'hours') {
        foreach ((array) ($body['hours'] ?? []) as $uid => $h) {
            $wpdb->update("{$wpdb->prefix}sn_staff_work", ['service_hours' => floatval($h)], ['date' => $date, 'user_id' => intval($uid), 'type' => $type]);
        }
        snspa_recalculate($date);
    } else {
        foreach ((array) ($body['amounts'] ?? []) as $uid => $amt) {
            $wpdb->update("{$wpdb->prefix}sn_staff_work", ['calc_service' => floatval($amt)], ['date' => $date, 'user_id' => intval($uid), 'type' => $type]);
        }
        // Uwaga: w trybie ręcznym NIE wywołujemy recalculate (tak samo jak w oryginalnej wtyczce),
        // aby nie nadpisać ręcznie wpisanych kwot przeliczeniem godzinowym.
    }

    return snspa_get_service_pool_data($date);
}

/* -----------------------------------------------------------
 * Rozliczenie z kelnerami (odpowiednik [rozliczenie_miesieczne_admin])
 * ----------------------------------------------------------- */
function snspa_rest_get_waiter_settlement(WP_REST_Request $req) {
    $f = snspa_current_flags();
    $m = intval($req->get_param('m')) ?: intval(current_time('m'));
    $y = intval($req->get_param('y')) ?: intval(current_time('Y'));
    $dept = sanitize_text_field($req->get_param('dept'));
    if (!$dept) $dept = $f['isAdminBar'] ? 'bar' : 'kuchnia';
    if ($dept === 'bar' && !$f['isAdminBar'] && !$f['isAdmin']) $dept = 'kuchnia';
    if ($dept === 'kuchnia' && !$f['isAdminKtc'] && !$f['isAdmin']) $dept = 'bar';
    return snspa_get_waiter_settlement_data($m, $y, $dept);
}

function snspa_get_waiter_settlement_data($m, $y, $dept) {
    global $wpdb;
    $pct_key = ($dept === 'bar') ? 'bar' : 'kitchen';
    $tax = snspa_tax_settings();
    $month_overrides = snspa_get_month_percent_overrides($m, $y);

    $rows = [];
    foreach (get_users(['role' => 'kelner']) as $w) {
        $k_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_tips WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $w->ID, $m, $y));
        $owed_c = 0;
        foreach ($k_entries as $e) {
            $ex = snspa_is_tax_exempt($w->ID, $e->date);
            $net = ($tax['cardActive'] && !$ex) ? floatval($e->amount) * (1 - $tax['rate']) : floatval($e->amount);
            $pct = snspa_percent_from_month_map($month_overrides, $e->date, $w->ID);
            $owed_c += $net * $pct['card'][$pct_key];
        }
        $s_entries = $wpdb->get_results($wpdb->prepare("SELECT date, amount FROM {$wpdb->prefix}sn_waiter_service WHERE waiter_id=%d AND MONTH(date)=%d AND YEAR(date)=%d", $w->ID, $m, $y));
        $owed_s = 0;
        foreach ($s_entries as $e) {
            $ex = snspa_is_tax_exempt($w->ID, $e->date);
            $net = ($tax['servActive'] && !$ex) ? floatval($e->amount) * (1 - $tax['rate']) : floatval($e->amount);
            $pct = snspa_percent_from_month_map($month_overrides, $e->date, $w->ID);
            $owed_s += $net * $pct['service'][$pct_key];
        }
        $owed_total = $owed_c + $owed_s;
        $received = get_user_meta($w->ID, "sn_settled_{$dept}_{$m}_{$y}", true);
        $received_val = ($received !== '') ? floatval($received) : null;
        if ($owed_total <= 0 && $received_val === null) continue;

        $rows[] = ['id' => $w->ID, 'name' => $w->display_name, 'owedCard' => $owed_c, 'owedService' => $owed_s, 'owedTotal' => $owed_total, 'received' => $received_val];
    }

    return ['dept' => $dept, 'month' => $m, 'year' => $y, 'rows' => $rows];
}

function snspa_rest_save_waiter_settlement(WP_REST_Request $req) {
    $f = snspa_current_flags();
    $body = $req->get_json_params();
    $m = intval($body['m'] ?? 0); $y = intval($body['y'] ?? 0);
    $dept = sanitize_text_field($body['dept'] ?? '');
    if (!$m || !$y || !in_array($dept, ['bar', 'kuchnia'])) return new WP_Error('bad_input', 'Nieprawidłowe dane', ['status' => 400]);
    if ($dept === 'bar' && !$f['isAdminBar'] && !$f['isAdmin']) return new WP_Error('forbidden', 'Brak uprawnień', ['status' => 403]);
    if ($dept === 'kuchnia' && !$f['isAdminKtc'] && !$f['isAdmin']) return new WP_Error('forbidden', 'Brak uprawnień', ['status' => 403]);

    foreach ((array) ($body['received'] ?? []) as $wid => $amount) {
        update_user_meta(intval($wid), "sn_settled_{$dept}_{$m}_{$y}", floatval($amount));
    }
    return snspa_get_waiter_settlement_data($m, $y, $dept);
}

/* -----------------------------------------------------------
 * Szablony e-mail i ręczne wysyłanie (Snspa_Mailer)
 * ----------------------------------------------------------- */
function snspa_rest_email_permission(WP_REST_Request $req) {
    $group = sanitize_text_field($req->get_param('group'));
    if (!in_array($group, Snspa_TipsSummary::ROLES, true)) return false;
    $f = snspa_current_flags();
    return Snspa_Mailer::can_manage($group, $f);
}

function snspa_rest_email_permission_from_body(WP_REST_Request $req) {
    $body = $req->get_json_params();
    $group = sanitize_text_field($body['group'] ?? '');
    if (!in_array($group, Snspa_TipsSummary::ROLES, true)) return false;
    $f = snspa_current_flags();
    return Snspa_Mailer::can_manage($group, $f);
}

function snspa_rest_get_email_template(WP_REST_Request $req) {
    $group = sanitize_text_field($req->get_param('group'));
    return Snspa_Mailer::get_template($group);
}

function snspa_rest_save_email_template(WP_REST_Request $req) {
    $body = $req->get_json_params();
    $group = sanitize_text_field($body['group'] ?? '');
    if (!in_array($group, Snspa_TipsSummary::ROLES, true)) return new WP_Error('bad_group', 'Nieprawidłowa grupa', ['status' => 400]);
    return Snspa_Mailer::save_template($group, $body['subject'] ?? '', $body['body'] ?? '');
}

function snspa_rest_send_emails(WP_REST_Request $req) {
    $body = $req->get_json_params();
    $group = sanitize_text_field($body['group'] ?? '');
    $m = intval($body['m'] ?? 0);
    $y = intval($body['y'] ?? 0);
    if (!in_array($group, Snspa_TipsSummary::ROLES, true) || !$m || !$y) {
        return new WP_Error('bad_input', 'Nieprawidłowe dane', ['status' => 400]);
    }
    return Snspa_Mailer::send_for_group($group, $m, $y);
}

/* -----------------------------------------------------------
 * Powiadomienia push (OneSignal) — szablony, wysyłka, ustawienia
 * ----------------------------------------------------------- */
function snspa_rest_get_push_template(WP_REST_Request $req) {
    $group = sanitize_text_field($req->get_param('group'));
    return Snspa_Notifier::get_template($group);
}

function snspa_rest_save_push_template(WP_REST_Request $req) {
    $body = $req->get_json_params();
    $group = sanitize_text_field($body['group'] ?? '');
    if (!in_array($group, Snspa_TipsSummary::ROLES, true)) return new WP_Error('bad_group', 'Nieprawidłowa grupa', ['status' => 400]);
    $icon_id = array_key_exists('icon_id', (array) $body) ? absint($body['icon_id']) : null;
    return Snspa_Notifier::save_template($group, $body['title'] ?? '', $body['message'] ?? '', $icon_id);
}

function snspa_rest_upload_push_icon(WP_REST_Request $req) {
    return Snspa_Notifier::upload_icon();
}

function snspa_rest_send_push(WP_REST_Request $req) {
    $body = $req->get_json_params();
    $group = sanitize_text_field($body['group'] ?? '');
    $m = intval($body['m'] ?? 0);
    $y = intval($body['y'] ?? 0);
    if (!in_array($group, Snspa_TipsSummary::ROLES, true) || !$m || !$y) {
        return new WP_Error('bad_input', 'Nieprawidłowe dane', ['status' => 400]);
    }
    return Snspa_Notifier::send_for_group($group, $m, $y);
}

function snspa_rest_get_onesignal_settings() {
    // Konfiguracja push jest teraz WYŁĄCZNIE w panelu GastroFlowx - tu
    // zwracamy tylko informację, czy jest aktywna (do banera w Ustawieniach).
    return ['hubManaged' => Snspa_Notifier::is_hub_managed()];
}

function snspa_rest_get_settings() {
    $s = snspa_tax_settings();
    $s['managerPercentOverrideEnabled'] = snspa_manager_can_set_percent_overrides();
    return $s;
}

function snspa_rest_save_settings(WP_REST_Request $req) {
    $body = $req->get_json_params();
    update_option('sn_tax_card_over_26', !empty($body['cardActive']) ? 1 : 0);
    update_option('sn_tax_service_over_26', !empty($body['servActive']) ? 1 : 0);
    update_option('sn_tax_rate_value', floatval($body['rate'] ?? 0));
    if (array_key_exists('managerPercentOverrideEnabled', $body)) {
        update_option('sn_manager_percent_override_enabled', !empty($body['managerPercentOverrideEnabled']) ? 1 : 0);
    }
    return snspa_rest_get_settings();
}
