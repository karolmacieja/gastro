<?php
if (!defined('ABSPATH')) exit;

/* Pola profilu użytkownika: data urodzenia, ręczne wygaśnięcie ulgi, data końca zatrudnienia */
add_action('show_user_profile', 'snspa_add_tax_fields');
add_action('edit_user_profile', 'snspa_add_tax_fields');
function snspa_add_tax_fields($user) {
    if (!current_user_can('manage_options') && !current_user_can('edit_user', $user->ID)) return;
    ?>
    <h3>System Napiwków — zatrudnienie i ulga podatkowa</h3>
    <table class="form-table">
        <tr>
            <th><label for="sn_employment_end">Data zakończenia pracy</label></th>
            <td>
                <input type="date" name="sn_employment_end" id="sn_employment_end" value="<?php echo esc_attr(get_the_author_meta('sn_employment_end', $user->ID)); ?>" class="regular-text" />
                <p class="description">Jeśli pracownik odszedł, ustaw datę ostatniego dnia pracy — zniknie z formularzy na kolejne dni, ale pozostanie w starych raportach.</p>
            </td>
        </tr>
        <tr>
            <th><label for="user_birth_date">Data urodzenia</label></th>
            <td>
                <input type="date" name="user_birth_date" id="user_birth_date" value="<?php echo esc_attr(get_the_author_meta('user_birth_date', $user->ID)); ?>" class="regular-text" />
                <p class="description">Domyślnie ulga podatkowa obowiązuje do dnia 26. urodzin włącznie.</p>
            </td>
        </tr>
        <tr>
            <th><label for="user_tax_extension">Ręczna data wygaśnięcia ulgi (nadpisanie)</label></th>
            <td>
                <input type="date" name="user_tax_extension" id="user_tax_extension" value="<?php echo esc_attr(get_the_author_meta('user_tax_extension', $user->ID)); ?>" class="regular-text" />
                <p class="description">Zostaw puste dla automatyki. Wpisz datę, aby wydłużyć lub skrócić ulgę względem 26. urodzin.</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action('personal_options_update', 'snspa_save_tax_fields');
add_action('edit_user_profile_update', 'snspa_save_tax_fields');
function snspa_save_tax_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) return false;
    if (isset($_POST['sn_employment_end'])) update_user_meta($user_id, 'sn_employment_end', sanitize_text_field($_POST['sn_employment_end']));
    if (isset($_POST['user_birth_date'])) update_user_meta($user_id, 'user_birth_date', sanitize_text_field($_POST['user_birth_date']));
    if (isset($_POST['user_tax_extension'])) update_user_meta($user_id, 'user_tax_extension', sanitize_text_field($_POST['user_tax_extension']));
}

/* Panel ustawień: reguły podatkowe (te same klucze opcji co oryginalna wtyczka) */
add_action('admin_menu', 'snspa_add_settings_menu');
function snspa_add_settings_menu() {
    add_options_page('Ustawienia Systemu Napiwków', 'System Napiwków (SPA)', 'manage_options', 'sn-spa-settings', 'snspa_settings_page');
}

function snspa_settings_page() {
    snspa_handle_percent_override_admin_form();
    ?>
    <div class="wrap">
        <h1>Ustawienia Systemu Napiwków</h1>
        <form method="post" action="options.php">
            <?php settings_fields('sn_settings_group'); do_settings_sections('sn-settings'); submit_button(); ?>
        </form>
        <p>Widżet aplikacji dodaj do dowolnej strony za pomocą shortcode: <code>[napiwki_app]</code></p>

        <hr>
        <h2>Wyjątki procentowe (bar/kuchnia) dla konkretnego dnia i kelnera</h2>
        <p>Domyślnie każdy kelner oddaje 10% napiwków barowi i 20% kuchni — osobno z karty i
           osobno z serwisu. Tutaj możesz ustawić inne wartości (także 0%) dla wybranego
           kelnera, tylko na jeden konkretny dzień — kolejne dni wracają automatycznie do
           wartości domyślnych, chyba że dodasz dla nich osobny wyjątek. Karta i serwis mogą
           mieć różne procenty tego samego dnia (np. kelner rozlicza kartę standardowo, ale
           serwis inaczej).</p>

        <form method="post">
            <?php wp_nonce_field('snspa_percent_override_action', 'snspa_percent_override_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="snspa_po_date">Data</label></th>
                    <td><input type="date" name="snspa_po_date" id="snspa_po_date" required></td>
                </tr>
                <tr>
                    <th><label for="snspa_po_waiter">Kelner</label></th>
                    <td>
                        <select name="snspa_po_waiter" id="snspa_po_waiter" required>
                            <option value="">— wybierz —</option>
                            <?php foreach (get_users(['role' => 'kelner']) as $w): ?>
                                <option value="<?php echo esc_attr($w->ID); ?>"><?php echo esc_html($w->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="snspa_po_card_bar">Karta — procent dla baru (%)</label></th>
                    <td><input type="number" step="0.01" min="0" max="100" name="snspa_po_card_bar" id="snspa_po_card_bar" value="10" required></td>
                </tr>
                <tr>
                    <th><label for="snspa_po_card_kitchen">Karta — procent dla kuchni (%)</label></th>
                    <td><input type="number" step="0.01" min="0" max="100" name="snspa_po_card_kitchen" id="snspa_po_card_kitchen" value="20" required></td>
                </tr>
                <tr>
                    <th><label for="snspa_po_service_bar">Serwis — procent dla baru (%)</label></th>
                    <td><input type="number" step="0.01" min="0" max="100" name="snspa_po_service_bar" id="snspa_po_service_bar" value="10" required></td>
                </tr>
                <tr>
                    <th><label for="snspa_po_service_kitchen">Serwis — procent dla kuchni (%)</label></th>
                    <td><input type="number" step="0.01" min="0" max="100" name="snspa_po_service_kitchen" id="snspa_po_service_kitchen" value="20" required></td>
                </tr>
            </table>
            <?php submit_button('Zapisz wyjątek', 'primary', 'snspa_po_save'); ?>
        </form>

        <h3>Aktualnie ustawione wyjątki</h3>
        <?php snspa_render_percent_overrides_table(); ?>
    </div>
    <?php
}

function snspa_handle_percent_override_admin_form() {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['snspa_percent_override_nonce']) || !wp_verify_nonce($_POST['snspa_percent_override_nonce'], 'snspa_percent_override_action')) return;

    global $wpdb;
    if (isset($_POST['snspa_po_save'])) {
        $date = sanitize_text_field($_POST['snspa_po_date'] ?? '');
        $waiter_id = intval($_POST['snspa_po_waiter'] ?? 0);
        $card_bar = round(floatval($_POST['snspa_po_card_bar'] ?? 10), 2);
        $card_kitchen = round(floatval($_POST['snspa_po_card_kitchen'] ?? 20), 2);
        $service_bar = round(floatval($_POST['snspa_po_service_bar'] ?? 10), 2);
        $service_kitchen = round(floatval($_POST['snspa_po_service_kitchen'] ?? 20), 2);
        if ($date && $waiter_id) {
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sn_percent_overrides WHERE date=%s AND waiter_id=%d", $date, $waiter_id));
            $data = ['date' => $date, 'waiter_id' => $waiter_id,
                'card_bar_percent' => $card_bar, 'card_kitchen_percent' => $card_kitchen,
                'service_bar_percent' => $service_bar, 'service_kitchen_percent' => $service_kitchen,
                'bar_percent' => $card_bar, 'kitchen_percent' => $card_kitchen, // zgodność wsteczna
                'created_by' => get_current_user_id()];
            if ($existing) $wpdb->update("{$wpdb->prefix}sn_percent_overrides", $data, ['id' => $existing]);
            else $wpdb->insert("{$wpdb->prefix}sn_percent_overrides", $data);
            snspa_recalculate($date);
            echo '<div class="notice notice-success"><p>Wyjątek zapisany.</p></div>';
        }
    } elseif (isset($_POST['snspa_po_delete_id'])) {
        $id = intval($_POST['snspa_po_delete_id']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT date FROM {$wpdb->prefix}sn_percent_overrides WHERE id=%d", $id));
        $wpdb->delete("{$wpdb->prefix}sn_percent_overrides", ['id' => $id]);
        if ($row) snspa_recalculate($row->date);
        echo '<div class="notice notice-success"><p>Wyjątek usunięty.</p></div>';
    }
}

function snspa_render_percent_overrides_table() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sn_percent_overrides ORDER BY date DESC LIMIT 100");
    if (!$rows) { echo '<p>Brak ustawionych wyjątków.</p>'; return; }
    ?>
    <table class="widefat striped">
        <thead><tr><th>Data</th><th>Kelner</th><th>Karta: Bar %</th><th>Karta: Kuchnia %</th><th>Serwis: Bar %</th><th>Serwis: Kuchnia %</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $u = get_userdata($r->waiter_id); ?>
            <tr>
                <td><?php echo esc_html($r->date); ?></td>
                <td><?php echo esc_html($u ? $u->display_name : ('ID ' . $r->waiter_id)); ?></td>
                <td><?php echo esc_html($r->card_bar_percent); ?>%</td>
                <td><?php echo esc_html($r->card_kitchen_percent); ?>%</td>
                <td><?php echo esc_html($r->service_bar_percent); ?>%</td>
                <td><?php echo esc_html($r->service_kitchen_percent); ?>%</td>
                <td>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('snspa_percent_override_action', 'snspa_percent_override_nonce'); ?>
                        <input type="hidden" name="snspa_po_delete_id" value="<?php echo esc_attr($r->id); ?>">
                        <button type="submit" class="button button-small" onclick="return confirm('Usunąć ten wyjątek i wrócić do wartości domyślnych (10%/20%)?');">Usuń</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

add_action('admin_init', 'snspa_register_settings');
function snspa_register_settings() {
    register_setting('sn_settings_group', 'sn_tax_card_over_26');
    register_setting('sn_settings_group', 'sn_tax_service_over_26');
    register_setting('sn_settings_group', 'sn_tax_rate_value');
    register_setting('sn_settings_group', 'sn_manager_percent_override_enabled');

    add_settings_section('sn_tax_section', 'Logika podatkowa (ulga do 26 lat)', null, 'sn-settings');
    add_settings_field('sn_tax_card_over_26', 'Napiwki z karty (podatek > 26 lat)', 'snspa_tax_card_render', 'sn-settings', 'sn_tax_section');
    add_settings_field('sn_tax_service_over_26', 'Serwis (podatek > 26 lat)', 'snspa_tax_service_render', 'sn-settings', 'sn_tax_section');
    add_settings_field('sn_tax_rate_value', 'Wartość podatku (%)', 'snspa_tax_rate_render', 'sn-settings', 'sn_tax_section');

    add_settings_section('sn_percent_override_section', 'Wyjątki procentowe — uprawnienia', null, 'sn-settings');
    add_settings_field('sn_manager_percent_override_enabled', 'Manager napiwków może ustawiać wyjątki', 'snspa_manager_override_toggle_render', 'sn-settings', 'sn_percent_override_section');
}

function snspa_manager_override_toggle_render() {
    $val = get_option('sn_manager_percent_override_enabled');
    echo '<label><input type="checkbox" name="sn_manager_percent_override_enabled" value="1" ' . checked(1, $val, false) . ' /> Pozwól roli "manager" (nie tylko administratorowi) ustawiać wyjątki procentowe dla dni/kelnerów, na froncie w zakładce Dzień</label>';
    echo '<p class="description">Jeśli wyłączone, tylko administrator widzi i ustawia te wyjątki (tutaj oraz na froncie).</p>';
}

function snspa_tax_card_render() {
    $val = get_option('sn_tax_card_over_26');
    echo '<input type="checkbox" name="sn_tax_card_over_26" value="1" ' . checked(1, $val, false) . ' /> Aktywuj potrącenie dla osób powyżej 26 lat';
}
function snspa_tax_service_render() {
    $val = get_option('sn_tax_service_over_26');
    echo '<input type="checkbox" name="sn_tax_service_over_26" value="1" ' . checked(1, $val, false) . ' /> Aktywuj potrącenie dla osób powyżej 26 lat';
}
function snspa_tax_rate_render() {
    $val = get_option('sn_tax_rate_value', '0');
    echo '<input type="number" step="0.01" name="sn_tax_rate_value" value="' . esc_attr($val) . '" /> % (np. 12 lub 23)';
}
