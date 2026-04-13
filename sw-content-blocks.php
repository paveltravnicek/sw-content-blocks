<?php
/*
Plugin Name: Obsahové bloky
Description: Obsahové bloky s plánováním zobrazení, nastavením výpisu a shortcode.
Version: 1.0
Author: Smart Websites
Author URI: https://smart-websites.cz
Update URI: https://github.com/paveltravnicek/sw-content-blocks/
Text Domain: sw-content-blocks
SW Plugin: yes
SW Service Type: passive
SW License Group: both
*/

if (!defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$swUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/paveltravnicek/sw-content-blocks/',
    __FILE__,
    'sw-content-blocks'
);

$swUpdateChecker->setBranch('main');
$swUpdateChecker->getVcsApi()->enableReleaseAssets('/\.zip$/i');

final class SW_Content_Blocks {
    const CPT = 'sw_content_block';
    const OPT_LAYOUT = 'swcb_default_layout';
    const OPT_DESIGN = 'swcb_default_design';
    const OPT_LIMIT = 'swcb_default_limit';
    const LICENSE_OPTION = 'swcb_license';
    const LICENSE_CRON_HOOK = 'swcb_license_daily_check';
    const HUB_BASE = 'https://smart-websites.cz';
    const PLUGIN_SLUG = 'sw-content-blocks';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'register_metaboxes']);
        add_action('save_post', [$this, 'save_meta'], 10, 2);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'column_content'], 10, 2);

        add_shortcode('sw_content_blocks', [$this, 'shortcode']);

        add_action('wp_enqueue_scripts', [$this, 'register_front_assets']);
        add_action('admin_enqueue_scripts', [$this, 'register_admin_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);
        add_action(self::LICENSE_CRON_HOOK, [$this, 'cron_refresh_plugin_license']);

        if (is_admin()) {
            add_action('admin_post_swcb_verify_license', [$this, 'handle_verify_license']);
            add_action('admin_post_swcb_remove_license', [$this, 'handle_remove_license']);
            add_action('admin_init', [$this, 'maybe_refresh_plugin_license']);
            add_action('admin_init', [$this, 'block_direct_deactivate']);
        }
    }

    public function activate() {
        if (!wp_next_scheduled(self::LICENSE_CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::LICENSE_CRON_HOOK);
        }
    }

    public function deactivate() {
        $timestamp = wp_next_scheduled(self::LICENSE_CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::LICENSE_CRON_HOOK);
        }
    }

    public function cron_refresh_plugin_license() {
        $this->refresh_plugin_license('cron');
    }

    private function default_license_state(): array {
        return [
            'key' => '',
            'status' => 'missing',
            'type' => '',
            'valid_to' => '',
            'domain' => '',
            'message' => '',
            'last_check' => 0,
            'last_success' => 0,
        ];
    }

    private function get_license_state(): array {
        $state = get_option(self::LICENSE_OPTION, []);
        if (!is_array($state)) {
            $state = [];
        }
        return wp_parse_args($state, $this->default_license_state());
    }

    private function update_license_state(array $data): void {
        $current = $this->get_license_state();
        $new = array_merge($current, $data);
        $new['key'] = sanitize_text_field((string) ($new['key'] ?? ''));
        $new['status'] = sanitize_key((string) ($new['status'] ?? 'missing'));
        $new['type'] = sanitize_key((string) ($new['type'] ?? ''));
        $new['valid_to'] = sanitize_text_field((string) ($new['valid_to'] ?? ''));
        $new['domain'] = sanitize_text_field((string) ($new['domain'] ?? ''));
        $new['message'] = sanitize_text_field((string) ($new['message'] ?? ''));
        $new['last_check'] = (int) ($new['last_check'] ?? 0);
        $new['last_success'] = (int) ($new['last_success'] ?? 0);
        update_option(self::LICENSE_OPTION, $new, false);
    }

    private function get_management_context(): array {
        $guard_present = function_exists('sw_guard_get_service_state');
        $management_status = $guard_present ? (string) get_option('swg_management_status', 'NONE') : 'NONE';
        $service_state = $guard_present ? (string) sw_guard_get_service_state(self::PLUGIN_SLUG) : 'off';
        $guard_last_success = $guard_present ? (int) get_option('swg_last_success_ts', 0) : 0;
        $connected_recently = $guard_last_success > 0 && (time() - $guard_last_success) <= (8 * DAY_IN_SECONDS);

        return [
            'guard_present' => $guard_present,
            'management_status' => $management_status,
            'service_state' => in_array($service_state, ['active', 'passive', 'off'], true) ? $service_state : 'off',
            'guard_last_success' => $guard_last_success,
            'connected_recently' => $connected_recently,
            'is_active' => $guard_present && $connected_recently && $management_status === 'ACTIVE' && $service_state === 'active',
        ];
    }

    private function has_active_standalone_license(): bool {
        $license = $this->get_license_state();
        return $license['key'] !== '' && $license['status'] === 'active' && $license['type'] === 'plugin_single';
    }

    private function plugin_is_operational(): bool {
        $management = $this->get_management_context();
        if ($management['is_active']) {
            return true;
        }
        return $this->has_active_standalone_license();
    }

    public function add_plugin_action_links($links) {
        array_unshift(
            $links,
            '<a href="' . esc_url(admin_url('edit.php?post_type=' . self::CPT . '&page=swcb-settings')) . '">' . esc_html__('Nastavení', 'sw-content-blocks') . '</a>'
        );

        $management = $this->get_management_context();
        if ($management['is_active']) {
            unset($links['deactivate']);
        }

        return $links;
    }

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Obsahové bloky',
                'singular_name' => 'Obsahový blok',
                'add_new' => 'Přidat blok',
                'add_new_item' => 'Přidat nový blok',
                'edit_item' => 'Upravit blok',
                'new_item' => 'Nový blok',
                'view_item' => 'Zobrazit blok',
                'search_items' => 'Hledat bloky',
                'not_found' => 'Žádné bloky nebyly nalezeny',
                'menu_name' => 'Obsahové bloky',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-screenoptions',
            'supports' => ['title', 'editor', 'page-attributes'],
            'show_in_rest' => true,
            'map_meta_cap' => true,
        ]);
    }

    public function register_metaboxes() {
        add_meta_box('swcb_usage', 'Použití bloku', [$this, 'render_usage_metabox'], self::CPT, 'normal', 'high');
        add_meta_box('swcb_visibility', 'Zobrazení a platnost', [$this, 'render_visibility_metabox'], self::CPT, 'side', 'high');
    }

    private function ts_to_local($ts) {
        if (!$ts) return '';
        $dt = new DateTime('@' . intval($ts));
        $dt->setTimezone(wp_timezone());
        return $dt->format('Y-m-d\\TH:i');
    }

    private function local_to_ts($value) {
        $value = trim((string) $value);
        if ($value === '') return 0;
        try {
            $dt = new DateTime($value, wp_timezone());
            return $dt->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    public function render_usage_metabox($post) {
        wp_nonce_field('swcb_save_meta', 'swcb_nonce');
        $can_edit = $this->plugin_is_operational();

        $group = get_post_meta($post->ID, '_swcb_group', true);
        $accent = get_post_meta($post->ID, '_swcb_accent', true);

        if (!$can_edit) {
            echo '<div class="notice notice-warning inline"><p>Plugin momentálně nemá platnou licenci. Nastavení bloku je pouze pro čtení.</p></div>';
        }

        echo '<fieldset ' . disabled(!$can_edit, true, false) . '>';
        echo '<div class="swcb-admin-help">';
        echo '<p><strong>K čemu to slouží?</strong><br>Každý blok je jedna samostatná karta nebo položka výpisu. Níže určíte, do jaké sekce patří a jaký barevný akcent má použít.</p>';
        echo '</div>';

        echo '<p><label for="swcb_group"><strong>Sekce použití</strong></label><br>';
        echo '<input type="text" class="widefat" id="swcb_group" name="swcb_group" value="' . esc_attr($group) . '" placeholder="např. homepage, promo, upozorneni">';
        echo '<span class="description">Stejnou sekci pak použijete v shortcode. Například bloky se sekcí <code>promo</code> vypíšete přes <code>[sw_content_blocks group=&quot;promo&quot;]</code>.</span></p>';

        echo '<p><label for="swcb_accent"><strong>Barevný akcent</strong></label><br>';
        echo '<select class="widefat" id="swcb_accent" name="swcb_accent">';
        foreach (['primary' => 'Primary', 'success' => 'Success', 'info' => 'Info', 'warning' => 'Warning', 'danger' => 'Danger'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($accent ?: 'primary', $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<span class="description">Akcent je jen doplněk vzhledu. Skutečný layout a design určuje shortcode nebo výchozí nastavení pluginu.</span></p>';

        echo '<p><strong>Pořadí ve výpisu</strong><br><span class="description">Pořadí bloků změníte v pravém panelu v poli <em>Pořadí</em>. Nižší číslo se zobrazí dříve.</span></p>';
        echo '</fieldset>';
    }

    public function render_visibility_metabox($post) {
        $can_edit = $this->plugin_is_operational();
        $from = get_post_meta($post->ID, '_swcb_from_ts', true);
        $to = get_post_meta($post->ID, '_swcb_to_ts', true);

        echo '<fieldset ' . disabled(!$can_edit, true, false) . '>';
        echo '<p><label for="swcb_from"><strong>Zobrazovat od</strong></label><br>';
        echo '<input type="datetime-local" style="width:100%" id="swcb_from" name="swcb_from" value="' . esc_attr($this->ts_to_local($from)) . '"></p>';

        echo '<p><label for="swcb_to"><strong>Zobrazovat do</strong></label><br>';
        echo '<input type="datetime-local" style="width:100%" id="swcb_to" name="swcb_to" value="' . esc_attr($this->ts_to_local($to)) . '"></p>';

        echo '<p class="description">Když pole necháte prázdné, blok nebude začátkem ani koncem omezený.</p>';
        echo '</fieldset>';
    }

    public function save_meta($post_id, $post) {
        if ($post->post_type !== self::CPT) return;
        if (!isset($_POST['swcb_nonce']) || !wp_verify_nonce($_POST['swcb_nonce'], 'swcb_save_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!$this->plugin_is_operational()) return;

        update_post_meta($post_id, '_swcb_group', sanitize_key($_POST['swcb_group'] ?? ''));
        update_post_meta($post_id, '_swcb_accent', sanitize_key($_POST['swcb_accent'] ?? 'primary'));
        update_post_meta($post_id, '_swcb_from_ts', $this->local_to_ts($_POST['swcb_from'] ?? ''));
        update_post_meta($post_id, '_swcb_to_ts', $this->local_to_ts($_POST['swcb_to'] ?? ''));
    }

    public function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            'Jak plugin funguje',
            'Jak plugin funguje',
            'edit_posts',
            'swcb-help',
            [$this, 'render_help_page']
        );

        add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            'Nastavení výpisu',
            'Nastavení',
            'manage_options',
            'swcb-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('swcb_settings', self::OPT_LAYOUT, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_layout'],
            'default' => 'cards',
        ]);

        register_setting('swcb_settings', self::OPT_DESIGN, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_design'],
            'default' => 'soft',
        ]);

        register_setting('swcb_settings', self::OPT_LIMIT, [
            'type' => 'integer',
            'sanitize_callback' => function ($v) { $v = intval($v); return $v < 1 ? 12 : $v; },
            'default' => 12,
        ]);
    }

    public function sanitize_layout($value) {
        $allowed = ['cards', 'list', 'carousel'];
        return in_array($value, $allowed, true) ? $value : 'cards';
    }

    public function sanitize_design($value) {
        $allowed = ['soft', 'outline', 'contrast'];
        return in_array($value, $allowed, true) ? $value : 'soft';
    }

    private function render_license_box(): void {
        $license = $this->get_license_state();
        $management = $this->get_management_context();
        $is_operational = $this->plugin_is_operational();
        $status_payload = $this->get_license_panel_data($license, $management, $is_operational);

        if (!empty($_GET['swcb_license_message'])) {
            echo '<div class="notice notice-success"><p>' . esc_html(sanitize_text_field((string) $_GET['swcb_license_message'])) . '</p></div>';
        }

        echo '<div class="swcb-card swcb-card--licence">';
        echo '<div class="swcb-card__head">';
        echo '<div>';
        echo '<h2>Licence pluginu</h2>';
        echo '<p class="swcb-intro">Plugin může běžet buď v rámci platné správy webu, nebo přes samostatnou licenci.</p>';
        echo '</div>';
        echo '<span class="swcb-licence-badge swcb-licence-badge--' . esc_attr($status_payload['badge_class']) . '">' . esc_html($status_payload['badge_label']) . '</span>';
        echo '</div>';

        echo '<div class="swcb-licence-grid">';
        echo '<div class="swcb-licence-item"><span class="swcb-licence-label">Režim</span><strong>' . esc_html($status_payload['mode']) . '</strong>';
        if ($status_payload['subline']) echo '<span>' . esc_html($status_payload['subline']) . '</span>';
        echo '</div>';
        echo '<div class="swcb-licence-item"><span class="swcb-licence-label">Platnost do</span><strong>' . esc_html($status_payload['valid_to']) . '</strong>';
        if ($status_payload['domain']) echo '<span>' . esc_html($status_payload['domain']) . '</span>';
        echo '</div>';
        echo '<div class="swcb-licence-item"><span class="swcb-licence-label">Poslední ověření</span><strong>' . esc_html($status_payload['last_check']) . '</strong>';
        if ($status_payload['message']) echo '<span>' . esc_html($status_payload['message']) . '</span>';
        echo '</div>';
        echo '</div>';

        if (!$management['is_active']) {
            echo '<div class="swcb-license-form-wrap">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="swcb-license-form">';
            wp_nonce_field('swcb_verify_license');
            echo '<input type="hidden" name="action" value="swcb_verify_license">';
            echo '<label for="swcb_license_key"><strong>Licenční kód pluginu</strong></label>';
            echo '<input type="text" id="swcb_license_key" name="license_key" value="' . esc_attr($license['key']) . '" class="regular-text" placeholder="SWLIC-..." />';
            echo '<p class="description">Použijte pouze pro samostatnou licenci pluginu. Pokud máte Správu webu, kód vyplňovat nemusíte.</p>';
            echo '<div class="swcb-license-actions">';
            echo '<button type="submit" class="button button-primary">Ověřit a uložit licenci</button>';
            if ($license['key'] !== '') {
                echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=swcb_remove_license'), 'swcb_remove_license')) . '" class="button button-secondary">Odebrat licenční kód</a>';
            }
            echo '</div></form></div>';
        } else {
            echo '<div class="swcb-note">Plugin je provozován v rámci Správy webu. Samostatný licenční kód není potřeba.</div>';
        }

        echo '</div>';

        if (!$is_operational) {
            echo '<div class="notice notice-warning"><p>Plugin momentálně nemá platnou licenci. Nastavení zůstává pouze pro čtení a shortcode nic nevypisuje.</p></div>';
        }
    }

    public function render_help_page() {
        echo '<div class="wrap swcb-wrap">';
        echo '<div class="swcb-hero">';
        echo '<div class="swcb-hero__content">';
        echo '<span class="swcb-badge">Smart Websites</span>';
        echo '<h1>Jak plugin funguje</h1>';
        echo '<p>Rychlý přehled práce s obsahem, sekcemi použití a shortcode pro výpis bloků na různých místech webu.</p>';
        echo '</div>';
        echo '<div class="swcb-hero__meta">';
        echo '<div class="swcb-stat">';
        echo '<strong>' . esc_html($this->get_plugin_version()) . '</strong>';
        echo '<span>Verze pluginu</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        $this->render_license_box();

        echo '<div class="swcb-cards swcb-cards--spaced">';
        echo '<div class="swcb-card"><h2>1. Vytvoříte blok</h2><p>Každý blok má vlastní název a obsah. Může jít o aktualitu, promo sdělení, upozornění nebo jinou obsahovou kartu.</p></div>';
        echo '<div class="swcb-card"><h2>2. Určíte sekci použití</h2><p>Do pole <strong>Sekce použití</strong> napíšete například <code>homepage</code>, <code>promo</code> nebo <code>upozorneni</code>. Díky tomu můžete na webu vypisovat různé skupiny bloků na různých místech.</p></div>';
        echo '<div class="swcb-card"><h2>3. Vložíte shortcode</h2><p>Do stránky nebo šablony vložíte shortcode podle toho, co chcete zobrazit. Výpis může mít podobu karet, seznamu nebo karuselu.</p></div>';
        echo '</div>';

        echo '<div class="swcb-card swcb-card--wide">';
        echo '<h2>Přehled shortcode</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Shortcode</th><th>Co udělá</th></tr></thead><tbody>';
        echo '<tr><td><code>[sw_content_blocks]</code></td><td>Vypíše všechny aktuálně aktivní bloky ve výchozím nastavení pluginu.</td></tr>';
        echo '<tr><td><code>[sw_content_blocks group="homepage"]</code></td><td>Vypíše jen bloky určené pro sekci <code>homepage</code>.</td></tr>';
        echo '<tr><td><code>[sw_content_blocks group="promo" title="Akční nabídka"]</code></td><td>Vypíše jen promo bloky a nad výpis doplní vlastní nadpis.</td></tr>';
        echo '<tr><td><code>[sw_content_blocks group="promo" layout="list"]</code></td><td>Vypíše promo bloky jako seznam místo karet.</td></tr>';
        echo '<tr><td><code>[sw_content_blocks group="homepage" layout="carousel" design="contrast"]</code></td><td>Vypíše homepage bloky v karuselu a výraznějším designu.</td></tr>';
        echo '</tbody></table>';
        echo '<p class="description">Parametry <code>layout</code> a <code>design</code> se vždy vztahují na celý jeden shortcode. To znamená, že jeden výpis má vždy jednotný vzhled.</p>';
        echo '</div>';

        echo '<div class="swcb-card swcb-card--wide">';
        echo '<h2>Jak si vybrat vzhled</h2>';
        echo '<ul class="swcb-list">';
        echo '<li><strong>soft</strong> – jemný a univerzální vzhled pro většinu webů</li>';
        echo '<li><strong>outline</strong> – čistý minimalistický vzhled s důrazem na obrys</li>';
        echo '<li><strong>contrast</strong> – výraznější vzhled s větším barevným akcentem</li>';
        echo '</ul>';
        echo '</div>';

        echo '</div>';
    }

    public function render_settings_page() {
        $can_edit = $this->plugin_is_operational();

        echo '<div class="wrap swcb-wrap">';
        echo '<div class="swcb-hero">';
        echo '<div class="swcb-hero__content">';
        echo '<span class="swcb-badge">Smart Websites</span>';
        echo '<h1>Nastavení výpisu</h1>';
        echo '<p>Výchozí layout, vzhled a počet položek pro shortcode, pokud nejsou jednotlivé parametry zadané přímo ve výpisu.</p>';
        echo '</div>';
        echo '<div class="swcb-hero__meta">';
        echo '<div class="swcb-stat">';
        echo '<strong>' . esc_html($this->get_plugin_version()) . '</strong>';
        echo '<span>Verze pluginu</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        $this->render_license_box();

        echo '<form method="post" action="options.php" class="' . ($can_edit ? '' : 'is-readonly') . '">';
        settings_fields('swcb_settings');

        echo '<fieldset ' . disabled(!$can_edit, true, false) . '>';
        echo '<div class="swcb-cards swcb-cards--spaced">';
        echo '<div class="swcb-card">';
        echo '<h2>Výchozí layout</h2>';
        echo '<p>Určuje, jak budou bloky vypadat, pokud shortcode neobsahuje parametr <code>layout</code>.</p>';
        echo '<select name="' . esc_attr(self::OPT_LAYOUT) . '" class="widefat">';
        foreach (['cards' => 'Karty', 'list' => 'Seznam', 'carousel' => 'Karusel'] as $k => $label) {
            echo '<option value="' . esc_attr($k) . '"' . selected(get_option(self::OPT_LAYOUT, 'cards'), $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="swcb-card">';
        echo '<h2>Výchozí design</h2>';
        echo '<p>Určuje vzhled výpisu, pokud shortcode neobsahuje parametr <code>design</code>.</p>';
        echo '<select name="' . esc_attr(self::OPT_DESIGN) . '" class="widefat">';
        foreach (['soft' => 'Soft', 'outline' => 'Outline', 'contrast' => 'Contrast'] as $k => $label) {
            echo '<option value="' . esc_attr($k) . '"' . selected(get_option(self::OPT_DESIGN, 'soft'), $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="swcb-card">';
        echo '<h2>Výchozí počet položek</h2>';
        echo '<p>Kolik bloků se má maximálně vypsat, pokud shortcode neobsahuje parametr <code>limit</code>.</p>';
        echo '<input type="number" min="1" step="1" class="widefat" name="' . esc_attr(self::OPT_LIMIT) . '" value="' . esc_attr((int) get_option(self::OPT_LIMIT, 12)) . '">';
        echo '</div>';
        echo '</div>';
        echo '</fieldset>';

        submit_button('Uložit nastavení', 'primary', 'submit', false, $can_edit ? [] : ['disabled' => 'disabled']);
        echo '</form>';
        echo '</div>';
    }

    private function get_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data(__FILE__, false, false);
        return !empty($data['Version']) ? (string) $data['Version'] : '—';
    }

    public function columns($columns) {
        $columns['swcb_group'] = 'Sekce';
        $columns['swcb_schedule'] = 'Platnost';
        return $columns;
    }

    public function column_content($column, $post_id) {
        if ($column === 'swcb_group') {
            $group = get_post_meta($post_id, '_swcb_group', true);
            echo $group ? esc_html($group) : '—';
        }
        if ($column === 'swcb_schedule') {
            $from = (int) get_post_meta($post_id, '_swcb_from_ts', true);
            $to = (int) get_post_meta($post_id, '_swcb_to_ts', true);
            if (!$from && !$to) {
                echo 'Bez omezení';
                return;
            }
            if ($from) echo 'Od: ' . esc_html(wp_date('j. n. Y H:i', $from)) . '<br>';
            if ($to) echo 'Do: ' . esc_html(wp_date('j. n. Y H:i', $to));
        }
    }

    public function register_front_assets() {
        $css_path = plugin_dir_path(__FILE__) . 'assets/frontend.css';
        $js_path = plugin_dir_path(__FILE__) . 'assets/frontend.js';
        wp_register_style('swcb-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.css', [], file_exists($css_path) ? (string) filemtime($css_path) : '1.4.1');
        wp_register_script('swcb-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.js', [], file_exists($js_path) ? (string) filemtime($js_path) : '1.4.1', true);
    }

    public function register_admin_assets($hook) {
        if (strpos((string) $hook, 'sw_content_block') === false && strpos((string) $hook, 'swcb-') === false) {
            return;
        }
        $css_path = plugin_dir_path(__FILE__) . 'assets/admin.css';
        $js_path = plugin_dir_path(__FILE__) . 'assets/admin.js';
        wp_enqueue_style('swcb-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], file_exists($css_path) ? (string) filemtime($css_path) : '1.4.1');
        wp_enqueue_script('swcb-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', [], file_exists($js_path) ? (string) filemtime($js_path) : '1.4.1', true);
    }

    private function active_query_args($atts) {
        $now = current_time('timestamp');
        $meta_query = [
            'relation' => 'AND',
            [
                'relation' => 'OR',
                ['key' => '_swcb_from_ts', 'compare' => 'NOT EXISTS'],
                ['key' => '_swcb_from_ts', 'value' => 0, 'type' => 'NUMERIC', 'compare' => '='],
                ['key' => '_swcb_from_ts', 'value' => $now, 'type' => 'NUMERIC', 'compare' => '<='],
            ],
            [
                'relation' => 'OR',
                ['key' => '_swcb_to_ts', 'compare' => 'NOT EXISTS'],
                ['key' => '_swcb_to_ts', 'value' => 0, 'type' => 'NUMERIC', 'compare' => '='],
                ['key' => '_swcb_to_ts', 'value' => $now, 'type' => 'NUMERIC', 'compare' => '>='],
            ],
        ];

        if (!empty($atts['group'])) {
            $meta_query[] = [
                'key' => '_swcb_group',
                'value' => sanitize_key($atts['group']),
                'compare' => '=',
            ];
        }

        return [
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => max(1, intval($atts['limit'])),
            'orderby' => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'meta_query' => $meta_query,
            'no_found_rows' => true,
        ];
    }

    public function shortcode($atts) {
        if (!$this->plugin_is_operational()) {
            return '';
        }

        $atts = shortcode_atts([
            'group' => '',
            'layout' => get_option(self::OPT_LAYOUT, 'cards'),
            'design' => get_option(self::OPT_DESIGN, 'soft'),
            'title' => '',
            'limit' => (int) get_option(self::OPT_LIMIT, 12),
        ], $atts, 'sw_content_blocks');

        $atts['layout'] = $this->sanitize_layout($atts['layout']);
        $atts['design'] = $this->sanitize_design($atts['design']);
        $atts['limit'] = max(1, intval($atts['limit']));

        $q = new WP_Query($this->active_query_args($atts));
        if (!$q->have_posts()) return '';

        wp_enqueue_style('swcb-frontend');
        if ($atts['layout'] === 'carousel') {
            wp_enqueue_script('swcb-frontend');
        }

        ob_start();
        $wrapper_class = 'swcb swcb-layout-' . $atts['layout'] . ' swcb-design-' . $atts['design'];

        echo '<section class="' . esc_attr($wrapper_class) . '">';
        if ($atts['title'] !== '') {
            echo '<h2 class="swcb-section-title">' . esc_html($atts['title']) . '</h2>';
        }

        if ($atts['layout'] === 'carousel') {
            echo '<div class="swcb-carousel">';
            echo '<button type="button" class="swcb-carousel__nav swcb-carousel__nav--prev" aria-label="Předchozí">&#10094;</button>';
            echo '<div class="swcb-carousel__track">';
        } else {
            echo '<div class="swcb-items">';
        }

        while ($q->have_posts()) {
            $q->the_post();
            $accent = sanitize_key(get_post_meta(get_the_ID(), '_swcb_accent', true) ?: 'primary');
            echo '<article class="swcb-item swcb-item--' . esc_attr($accent) . '">';
            echo '<div class="swcb-item__inner">';
            echo '<h3 class="swcb-item__title">' . esc_html(get_the_title()) . '</h3>';
            echo '<div class="swcb-item__content">' . apply_filters('the_content', get_the_content()) . '</div>';
            echo '</div>';
            echo '</article>';
        }

        if ($atts['layout'] === 'carousel') {
            echo '</div>';
            echo '<button type="button" class="swcb-carousel__nav swcb-carousel__nav--next" aria-label="Další">&#10095;</button>';
            echo '</div>';
        } else {
            echo '</div>';
        }

        echo '</section>';

        wp_reset_postdata();
        return ob_get_clean();
    }

    private function get_license_panel_data(array $license, array $management, bool $is_operational): array {
        $format_dt = static function (int $ts): string {
            return $ts > 0 ? wp_date('j. n. Y H:i', $ts) : '—';
        };
        $format_date = static function (string $ymd): string {
            if ($ymd === '') {
                return '—';
            }
            $ts = strtotime($ymd . ' 12:00:00');
            return $ts ? wp_date('j. n. Y', $ts) : $ymd;
        };

        $base = [
            'badge_class' => 'inactive',
            'badge_label' => 'Licence chybí',
            'mode'        => 'Samostatná licence pluginu',
            'subline'     => '',
            'valid_to'    => '—',
            'domain'      => '',
            'last_check'  => '—',
            'message'     => '',
        ];

        if ($management['guard_present']) {
            if ($management['is_active']) {
                return array_merge($base, [
                    'badge_class' => 'active',
                    'badge_label' => 'Platná licence',
                    'mode'        => 'Správa webu',
                    'valid_to'    => $format_date((string) get_option('swg_managed_until', '')),
                    'domain'      => (string) get_option('swg_licence_domain', ''),
                    'last_check'  => $format_dt((int) $management['guard_last_success']),
                ]);
            }
            if ($management['management_status'] !== 'NONE') {
                return array_merge($base, [
                    'badge_class' => 'inactive',
                    'badge_label' => 'Licence neplatná',
                    'mode'        => 'Správa webu',
                    'subline'     => 'Správa webu je po expiraci nebo omezená. Bloky se na webu nevypisují.',
                    'valid_to'    => $format_date((string) get_option('swg_managed_until', '')),
                    'domain'      => (string) get_option('swg_licence_domain', ''),
                    'last_check'  => $format_dt((int) $management['guard_last_success']),
                    'message'     => 'Po expiraci lze plugin deaktivovat nebo smazat.',
                ]);
            }
        }

        if ($license['status'] === 'active') {
            return array_merge($base, [
                'badge_class' => 'active',
                'badge_label' => 'Platná licence',
                'mode'        => 'Samostatná licence pluginu',
                'subline'     => $license['key'] !== '' ? 'Licenční kód: ' . $license['key'] : '',
                'valid_to'    => $format_date((string) $license['valid_to']),
                'domain'      => (string) $license['domain'],
                'last_check'  => $format_dt((int) $license['last_success']),
                'message'     => $license['message'] !== '' ? $license['message'] : 'Plugin běží přes samostatnou licenci.',
            ]);
        }

        return array_merge($base, [
            'badge_class' => $is_operational ? 'active' : 'inactive',
            'badge_label' => $is_operational ? 'Platná licence' : 'Licence chybí',
            'mode'        => 'Samostatná licence pluginu',
            'subline'     => $license['key'] !== '' ? 'Licenční kód: ' . $license['key'] : 'Zatím nebyl uložen žádný licenční kód.',
            'valid_to'    => $format_date((string) $license['valid_to']),
            'domain'      => (string) $license['domain'],
            'last_check'  => $format_dt((int) $license['last_check']),
            'message'     => $license['message'] !== '' ? $license['message'] : 'Bez platné licence plugin shortcode nic nevypisuje.',
        ]);
    }

    public function maybe_refresh_plugin_license() {
        $management = $this->get_management_context();
        if ($management['is_active']) {
            return;
        }

        $license = $this->get_license_state();
        if ($license['key'] === '' || !current_user_can('manage_options')) {
            return;
        }
        if (!empty($_POST['license_key'])) {
            return;
        }
        if ($license['last_check'] > 0 && (time() - (int) $license['last_check']) < (12 * HOUR_IN_SECONDS)) {
            return;
        }

        $this->refresh_plugin_license('admin-auto');
    }

    private function refresh_plugin_license(string $reason = 'manual', string $override_key = ''): array {
        $key = $override_key !== '' ? sanitize_text_field($override_key) : (string) $this->get_license_state()['key'];
        if ($key === '') {
            $this->update_license_state([
                'key' => '',
                'status' => 'missing',
                'type' => '',
                'valid_to' => '',
                'domain' => '',
                'message' => 'Licenční kód zatím není uložený.',
                'last_check' => time(),
            ]);
            return ['ok' => false, 'error' => 'missing_key'];
        }

        $site_id = (string) get_option('swg_site_id', '');
        $payload = [
            'license_key' => $key,
            'plugin_slug' => self::PLUGIN_SLUG,
            'site_id' => $site_id,
            'site_url' => home_url('/'),
            'reason' => $reason,
            'plugin_version' => $this->get_plugin_version(),
        ];

        $res = wp_remote_post(rtrim(self::HUB_BASE, '/') . '/wp-json/swlic/v2/plugin-license', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);

        if (is_wp_error($res)) {
            $this->update_license_state([
                'key' => $key,
                'status' => 'error',
                'message' => $res->get_error_message(),
                'last_check' => time(),
            ]);
            return ['ok' => false, 'error' => $res->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        $data = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $api_message = 'Nepodařilo se ověřit licenci.';
            if (is_array($data) && !empty($data['message'])) {
                $api_message = sanitize_text_field((string) $data['message']);
            } elseif ($code > 0) {
                $api_message = 'Hub vrátil neočekávanou odpověď (HTTP ' . $code . ').';
            }

            $this->update_license_state([
                'key' => $key,
                'status' => 'error',
                'message' => $api_message,
                'last_check' => time(),
            ]);
            return ['ok' => false, 'error' => 'bad_response', 'message' => $api_message, 'http_code' => $code];
        }

        $this->update_license_state([
            'key' => $key,
            'status' => sanitize_key((string) ($data['status'] ?? 'missing')),
            'type' => sanitize_key((string) ($data['licence_type'] ?? 'plugin_single')),
            'valid_to' => sanitize_text_field((string) ($data['valid_to'] ?? '')),
            'domain' => sanitize_text_field((string) ($data['assigned_domain'] ?? '')),
            'message' => sanitize_text_field((string) ($data['message'] ?? '')),
            'last_check' => time(),
            'last_success' => !empty($data['ok']) ? time() : 0,
        ]);

        return $data;
    }

    public function handle_verify_license() {
        if (!current_user_can('manage_options')) {
            wp_die('Zakázáno.', 'Zakázáno', ['response' => 403]);
        }
        check_admin_referer('swcb_verify_license');
        $key = sanitize_text_field((string) ($_POST['license_key'] ?? ''));
        $result = $this->refresh_plugin_license('manual', $key);
        $message = !empty($result['message']) ? (string) $result['message'] : (!empty($result['ok']) ? 'Licence byla ověřena.' : 'Licenci se nepodařilo ověřit.');
        wp_safe_redirect(add_query_arg('swcb_license_message', rawurlencode($message), admin_url('edit.php?post_type=' . self::CPT . '&page=swcb-settings')));
        exit;
    }

    public function handle_remove_license() {
        if (!current_user_can('manage_options')) {
            wp_die('Zakázáno.', 'Zakázáno', ['response' => 403]);
        }
        check_admin_referer('swcb_remove_license');
        delete_option(self::LICENSE_OPTION);
        wp_safe_redirect(add_query_arg('swcb_license_message', rawurlencode('Licenční kód byl odebrán.'), admin_url('edit.php?post_type=' . self::CPT . '&page=swcb-settings')));
        exit;
    }

    public function block_direct_deactivate() {
        $management = $this->get_management_context();
        if (!$management['is_active']) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';
        $plugin = isset($_GET['plugin']) ? sanitize_text_field((string) $_GET['plugin']) : '';
        if ($action === 'deactivate' && $plugin === plugin_basename(__FILE__)) {
            wp_die('Tento plugin nelze deaktivovat při aktivní správě webu.', 'Chráněný plugin', ['response' => 403]);
        }
    }
}

new SW_Content_Blocks();
