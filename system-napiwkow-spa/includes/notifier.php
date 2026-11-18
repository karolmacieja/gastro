<?php
if (!defined('ABSPATH') ) exit;

/**
 * Snspa_Notifier
 * Wysyła powiadomienia push (tytuł + treść, edytowalne szablony per grupa
 * odbiorców) RĘCZNIE (nigdy automatycznie) po kliknięciu przycisku przez
 * managera / admina działu.
 *
 * Wysyłka idzie WYŁĄCZNIE przez wspólny mechanizm panelu GastroFlowx
 * (gastroflowx-hub), przez filtr `gfx_dispatch_push` — Firebase Cloud
 * Messaging. Napiwki NIE mają już własnej, samodzielnej konfiguracji push
 * (w odróżnieniu od wcześniejszej integracji z OneSignal) — konfiguracja
 * FCM jest zbyt złożona (konto serwisowe, kilka kluczy Web SDK), żeby
 * duplikować ją w trzech miejscach. Jeśli panel jest nieaktywny, wysyłka
 * push jest po prostu niedostępna (kanał e-mail w tej wtyczce działa
 * niezależnie i zawsze).
 */
class Snspa_Notifier {

    const OPTION_TPL_PREFIX = 'sn_push_tpl_';

    public static function can_manage($group, array $flags) {
        return Snspa_TipsSummary::can_manage($group, $flags);
    }

    /**
     * Czy panel GastroFlowx jest aktywny i przyjmuje wysyłkę push.
     */
    public static function is_hub_managed() {
        return (bool) apply_filters('gfx_push_is_managed', false);
    }

    public static function default_template($group) {
        $defaults = [
            'kelner' => [
                'title' => 'Podsumowanie napiwków — {miesiac_rok}',
                'message' => 'Karta: {karta_netto} zł, serwis: {serwis_netto} zł, gotówka: {gotowka} zł. Twoja wypłata (70%): {wyplata} zł.',
            ],
            'bar' => [
                'title' => 'Twój zarobek z napiwków — {miesiac_rok}',
                'message' => 'Gotówka: {gotowka} zł, karta: {karta} zł, serwis: {serwis} zł. Razem: {razem} zł.',
            ],
            'kuchnia' => [
                'title' => 'Twój zarobek z napiwków — {miesiac_rok}',
                'message' => 'Gotówka: {gotowka} zł, karta: {karta} zł, serwis: {serwis} zł. Razem: {razem} zł.',
            ],
        ];
        return $defaults[$group] ?? ['title' => '', 'message' => ''];
    }

    public static function get_template($group) {
        $stored = get_option(self::OPTION_TPL_PREFIX . $group);
        $icon_id = (is_array($stored) && !empty($stored['icon_id'])) ? absint($stored['icon_id']) : 0;
        if (empty($stored) || !is_array($stored) || empty($stored['message'])) {
            $tpl = self::default_template($group);
        } else {
            $tpl = ['title' => $stored['title'] ?? '', 'message' => $stored['message'] ?? ''];
        }
        $tpl['icon_id'] = $icon_id;
        $tpl['icon_url'] = self::icon_url($icon_id);
        // Co zostanie użyte, gdy tu nie ustawiono ikony (ikona typu /
        // domyślna ikona z panelu GastroFlowx) - do podglądu w SPA.
        $tpl['fallback_icon_url'] = class_exists('GFX_Push_Templates')
            ? GFX_Push_Templates::icon_url('napiwki_' . $group)
            : '';
        return $tpl;
    }

    /**
     * @param int|null $icon_id  null = nie zmieniaj zapisanej ikony, 0 = usuń.
     */
    public static function save_template($group, $title, $message, $icon_id = null) {
        $prev = get_option(self::OPTION_TPL_PREFIX . $group);
        $prev_icon = (is_array($prev) && !empty($prev['icon_id'])) ? absint($prev['icon_id']) : 0;
        if (null === $icon_id) {
            $icon_id = $prev_icon;
        }
        $icon_id = absint($icon_id);
        if ($icon_id && !wp_attachment_is_image($icon_id)) {
            $icon_id = $prev_icon;
        }
        update_option(self::OPTION_TPL_PREFIX . $group, [
            'title'   => sanitize_text_field($title),
            'message' => sanitize_textarea_field($message),
            'icon_id' => $icon_id,
        ]);
        return self::get_template($group);
    }

    public static function icon_url($icon_id) {
        $icon_id = absint($icon_id);
        if (!$icon_id || !wp_attachment_is_image($icon_id)) return '';
        $src = wp_get_attachment_image_src($icon_id, [256, 256]);
        if (!$src) $src = wp_get_attachment_image_src($icon_id, 'full');
        return $src ? (string) $src[0] : '';
    }

    /**
     * Wgrywa plik ikony przesłany z SPA (pole "file") do biblioteki mediów.
     * Uprawnienia sprawdza trasa REST (manager / admin działu danej grupy).
     */
    public static function upload_icon() {
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            return new WP_Error('no_file', 'Nie przesłano pliku.', ['status' => 400]);
        }
        $file = $_FILES['file'];
        if (!empty($file['error'])) {
            return new WP_Error('upload_error', 'Błąd przesyłania pliku (kod ' . intval($file['error']) . ').', ['status' => 400]);
        }
        if ($file['size'] > 2 * MB_IN_BYTES) {
            return new WP_Error('too_big', 'Plik jest za duży (maks. 2 MB).', ['status' => 400]);
        }
        $allowed = ['png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed);
        if (empty($check['type']) || !in_array($check['type'], $allowed, true)) {
            return new WP_Error('bad_type', 'Dozwolone formaty ikony: PNG, JPG, WebP, GIF.', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload('file', 0, [], ['test_form' => false, 'mimes' => $allowed]);
        if (is_wp_error($attachment_id)) {
            $attachment_id->add_data(['status' => 400]);
            return $attachment_id;
        }
        return ['icon_id' => (int) $attachment_id, 'icon_url' => self::icon_url($attachment_id)];
    }

    /**
     * Rejestruje typy powiadomień Napiwków w panelu GastroFlowx
     * (Powiadomienia push → Szablony i ikony). Treść zostaje edytowana
     * TUTAJ (w SPA Napiwków), bo zawiera kwoty liczone per osoba - panel
     * pozwala dodatkowo ustawić ikonę typu.
     */
    public static function register_hub_types($types) {
        foreach (Snspa_TipsSummary::ROLES as $group) {
            $types['napiwki_' . $group] = [
                'label'              => 'Napiwki — ' . self::group_label($group),
                'category'           => __('Napiwki', 'system-napiwkow-spa'),
                'subcategory'        => self::group_label($group),
                'content_managed_by' => 'aplikacja Napiwki → Powiadomienia (szablon „' . self::group_label($group) . '”)',
            ];
        }
        return $types;
    }

    /**
     * Wysyła powiadomienia push ręcznie (na żądanie) dla wskazanej grupy i
     * miesiąca, przez panel GastroFlowx (który filtruje odbiorców po
     * REALNEJ zgodzie PRZED wysyłką i loguje wynik w centralnej Historii).
     * Zwraca statystyki.
     */
    public static function send_for_group($group, $m, $y) {
        if (!self::is_hub_managed()) {
            return ['sent' => 0, 'skipped' => 0, 'recipients' => [], 'errors' => [], 'configError' => 'Powiadomienia push są nieaktywne — skonfiguruj Firebase Cloud Messaging centralnie w panelu GastroFlowx → Integracje.'];
        }

        $tpl = self::get_template($group);
        $entries = Snspa_TipsSummary::collect($group, $m, $y);

        $sent = 0; $skipped = 0; $recipients = []; $errors = [];
        foreach ($entries as $entry) {
            $user = $entry['user'];
            $title = Snspa_TipsSummary::render($tpl['title'], $entry['vars']);
            $message = Snspa_TipsSummary::render($tpl['message'], $entry['vars']);

            // Treść (kwoty) różni się per odbiorca, więc wołamy wspólny
            // mechanizm panelu per osoba - wciąż JEDNYM, scentralizowanym
            // kanałem, z filtrowaniem po zgodzie i logowaniem w Historii
            // wysyłki, tak jak każda inna wysyłka w tym ekosystemie.
            $result = apply_filters('gfx_dispatch_push', null, [
                'type'        => 'napiwki_' . $group,
                'vars'        => $entry['vars'],
                // Ikona z szablonu Napiwków (jeśli ustawiona) ma pierwszeństwo;
                // w przeciwnym razie panel użyje ikony typu / domyślnej.
                'icon'        => !empty($tpl['icon_url']) ? $tpl['icon_url'] : '',
                'user_ids'    => [$user->ID],
                'title'       => $title,
                'message'     => $message,
                'category'    => __('Napiwki', 'system-napiwkow-spa'),
                'subcategory' => self::group_label($group),
            ]);

            if (is_array($result) && !empty($result['handled'])) {
                $stats = $result['stats'];
                if ($stats['sent'] > 0) {
                    $sent++;
                    $recipients[] = $user->display_name;
                } elseif ($stats['errors'] > 0) {
                    $errors[] = $user->display_name . ' (błąd wysyłki — szczegóły w GastroFlowx → Powiadomienia push → Historia wysyłki)';
                } else {
                    $skipped++;
                }
            } else {
                // Panel przestał być aktywny w trakcie pętli (bardzo rzadkie).
                $skipped++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'recipients' => $recipients, 'errors' => $errors];
    }

    private static function group_label($group) {
        $labels = ['kelner' => 'Kelnerzy', 'bar' => 'Bar', 'kuchnia' => 'Kuchnia'];
        return $labels[$group] ?? $group;
    }
}

add_filter('gfx_push_notification_types', ['Snspa_Notifier', 'register_hub_types']);
