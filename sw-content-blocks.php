<?php
/*
Plugin Name: SW Content Blocks
Description: Obsahové bloky s plánováním zobrazení, nastavením výpisu a shortcode.
Version: 1.4.1
Author: Smart Websites
*/

if (!defined('ABSPATH')) exit;

class SW_Content_Blocks {
    const CPT = 'sw_content_block';
    const OPT_LAYOUT = 'swcb_default_layout';
    const OPT_DESIGN = 'swcb_default_design';
    const OPT_LIMIT = 'swcb_default_limit';

    public function __construct() {
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
        return $dt->format('Y-m-d\TH:i');
    }

    private function local_to_ts($value) {
        $value = trim((string)$value);
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

        $group = get_post_meta($post->ID, '_swcb_group', true);
        $accent = get_post_meta($post->ID, '_swcb_accent', true);

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
    }

    public function render_visibility_metabox($post) {
        $from = get_post_meta($post->ID, '_swcb_from_ts', true);
        $to = get_post_meta($post->ID, '_swcb_to_ts', true);

        echo '<p><label for="swcb_from"><strong>Zobrazovat od</strong></label><br>';
        echo '<input type="datetime-local" style="width:100%" id="swcb_from" name="swcb_from" value="' . esc_attr($this->ts_to_local($from)) . '"></p>';

        echo '<p><label for="swcb_to"><strong>Zobrazovat do</strong></label><br>';
        echo '<input type="datetime-local" style="width:100%" id="swcb_to" name="swcb_to" value="' . esc_attr($this->ts_to_local($to)) . '"></p>';

        echo '<p class="description">Když pole necháte prázdné, blok nebude začátkem ani koncem omezený.</p>';
    }

    public function save_meta($post_id, $post) {
        if ($post->post_type !== self::CPT) return;
        if (!isset($_POST['swcb_nonce']) || !wp_verify_nonce($_POST['swcb_nonce'], 'swcb_save_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

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
            'sanitize_callback' => function($v) { $v = intval($v); return $v < 1 ? 12 : $v; },
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

        echo '<form method="post" action="options.php">';
        settings_fields('swcb_settings');

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

        submit_button('Uložit nastavení');
        echo '</form>';
        echo '</div>';
    }

    private function get_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data(__FILE__, false, false);
        return !empty($data['Version']) ? $data['Version'] : '—';
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
        wp_register_style('swcb-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.css', [], '1.4.1');
        wp_register_script('swcb-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.js', [], '1.4.1', true);
    }

    public function register_admin_assets($hook) {
        if (strpos((string)$hook, 'sw_content_block') === false && strpos((string)$hook, 'swcb-') === false) {
            return;
        }
        wp_enqueue_style('swcb-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '1.4.1');
        wp_enqueue_script('swcb-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', [], '1.4.1', true);
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
}

new SW_Content_Blocks();
