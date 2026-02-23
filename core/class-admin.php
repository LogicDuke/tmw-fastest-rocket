<?php
namespace TMWFR;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    public static function register_menu(): void {
        $cap = 'manage_options';
        $slug = 'tmw-fastest-rocket';

        add_menu_page(
            'TMW Fastest Rocket',
            'TMW Fastest Rocket',
            $cap,
            $slug,
            [__CLASS__, 'page_dashboard'],
            'dashicons-rocket',
            3
        );

        add_submenu_page($slug, 'Dashboard', 'Dashboard', $cap, $slug, [__CLASS__, 'page_dashboard']);
        add_submenu_page($slug, 'Safe Mode', 'Safe Mode', $cap, 'tmwfr-safe-mode', [__CLASS__, 'page_safe_mode']);
        add_submenu_page($slug, 'HTML Trims', 'HTML', $cap, 'tmwfr-html', [__CLASS__, 'page_html']);
        add_submenu_page($slug, 'Media', 'Media', $cap, 'tmwfr-media', [__CLASS__, 'page_media']);
        add_submenu_page($slug, 'Preload', 'Preload', $cap, 'tmwfr-preload', [__CLASS__, 'page_preload']);
        add_submenu_page($slug, 'JavaScript', 'JavaScript', $cap, 'tmwfr-js', [__CLASS__, 'page_js']);
        add_submenu_page($slug, 'Cache', 'Cache', $cap, 'tmwfr-cache', [__CLASS__, 'page_cache']);
        add_submenu_page($slug, 'Compatibility', 'Compatibility', $cap, 'tmwfr-compat', [__CLASS__, 'page_compat']);
        add_submenu_page($slug, 'Logs', 'Logs', $cap, 'tmwfr-logs', [__CLASS__, 'page_logs']);
        add_submenu_page($slug, 'Import / Export', 'Import / Export', $cap, 'tmwfr-import-export', [__CLASS__, 'page_import_export']);
    }

    public static function enqueue_admin_assets(string $hook): void {
        if (strpos($hook, 'tmw-fastest-rocket') === false && strpos($hook, 'tmwfr-') === false) {
            return;
        }

        $css = TMWFR_URL . 'assets/admin/admin.css';
        wp_enqueue_style('tmwfr-admin', $css, [], TMWFR_VERSION);
    }

    public static function admin_notices(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = Options::instance();
        $compat = Compat::detect();

        if ($opts->safe_mode()) {
            echo '<div class="notice notice-warning"><p><strong>TMW Fastest Rocket:</strong> Safe Mode is <strong>ON</strong>. All front-end modules are disabled.</p></div>';
        }

        // Cache conflicts notice.
        if ($opts->is_module_enabled('cache')) {
            $conf = $compat['conflicts'] ?? [];
            $has_other_cache = !empty($conf['wp_fastest_cache']) || !empty($conf['sg_optimizer']) || !empty($conf['litespeed']) || !empty($conf['w3tc']) || !empty($conf['cf_super_cache']);
            if ($has_other_cache) {
                echo '<div class="notice notice-error"><p><strong>TMW Fastest Rocket:</strong> Another cache plugin appears to be active. Consider disabling other page cache layers before enabling TMWFR Cache.</p></div>';
            }
        }

        // JS overlap notice.
        if ($opts->is_module_enabled('js') && !empty($compat['theme']['theme_perf_layer'])) {
            $overlap_allowed = (bool) $opts->get('js.overlap_allowed', false);
            if (!$overlap_allowed) {
                echo '<div class="notice notice-warning"><p><strong>TMW Fastest Rocket:</strong> The child theme already contains a JS delay/defer system. TMWFR JS module will stay conservative unless you explicitly allow overlap (JavaScript page).</p></div>';
            }
        }
    }

    /* -------------------------------------------------------------------------
     * Pages
     * ---------------------------------------------------------------------- */

    public static function page_dashboard(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = Options::instance();
        $compat = Compat::detect();

        // Handle quick actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tmwfr_action'])) {
            check_admin_referer('tmwfr_dashboard');

            $action = sanitize_text_field((string) $_POST['tmwfr_action']);

            if ($action === 'save_modules') {
                $modules = (array) ($opts->get('modules', []));

                foreach (['html','media','preload','js','cache'] as $m) {
                    $modules[$m]['enabled'] = !empty($_POST['modules'][$m]['enabled']);
                }

                $all = $opts->all();
                $all['modules'] = $modules;
                $opts->update_all($all);

                echo '<div class="notice notice-success"><p>Module settings saved.</p></div>';
            }

            if ($action === 'apply_safe_preset') {
                $all = $opts->all();
                $all['modules']['html']['enabled'] = true;
                $all['modules']['media']['enabled'] = true;
                $all['modules']['preload']['enabled'] = true;
                // Keep JS + Cache OFF in the safe preset.
                $all['modules']['js']['enabled'] = false;
                $all['modules']['cache']['enabled'] = false;
                $opts->update_all($all);

                echo '<div class="notice notice-success"><p>Safe preset applied (HTML + Media + Preload enabled). JS + Cache remain OFF.</p></div>';
            }
        }

        $modules = (array) $opts->get('modules', []);

        echo '<div class="wrap tmwfr-wrap">';
        echo '<h1>TMW Fastest Rocket</h1>';

        echo '<div class="tmwfr-grid">';

        // Left: status
        echo '<div class="tmwfr-card">';
        echo '<h2>Status</h2>';
        echo '<ul class="tmwfr-list">';
        echo '<li><strong>Version:</strong> ' . esc_html(TMWFR_VERSION) . '</li>';
        echo '<li><strong>Theme:</strong> ' . esc_html(($compat['theme']['stylesheet'] ?? 'unknown')) . ' (parent: ' . esc_html(($compat['theme']['template'] ?? 'unknown')) . ')</li>';
        echo '<li><strong>WP_CACHE:</strong> ' . esc_html(!empty($compat['wp_cache']) ? 'true' : 'false') . '</li>';
        echo '<li><strong>advanced-cache.php:</strong> ' . esc_html(!empty($compat['dropins']['advanced-cache.php']) ? 'present' : 'missing') . '</li>';
        echo '<li><strong>Theme performance layer:</strong> ' . esc_html(!empty($compat['theme']['theme_perf_layer']) ? 'detected' : 'not detected') . '</li>';
        echo '</ul>';
        echo '</div>';

        // Right: modules
        echo '<div class="tmwfr-card">';
        echo '<h2>Modules</h2>';
        echo '<form method="post">';
        wp_nonce_field('tmwfr_dashboard');
        echo '<input type="hidden" name="tmwfr_action" value="save_modules" />';

        echo '<table class="widefat striped tmwfr-table">';
        echo '<thead><tr><th>Module</th><th>Enabled</th><th>Notes</th></tr></thead><tbody>';

        self::render_module_row('HTML', 'html', $modules, 'Safe trims (emoji, embeds, dashicons).');
        self::render_module_row('Media', 'media', $modules, 'Image attribute tuning (LCP promotion, sizes).');
        self::render_module_row('Preload', 'preload', $modules, 'Preload first thumb on category archives, optional preconnect.');
        self::render_module_row('JavaScript', 'js', $modules, 'Defer/delay controls (use carefully with theme overlap).');
        self::render_module_row('Cache', 'cache', $modules, 'File-based page cache (OFF by default; highest impact).');

        echo '</tbody></table>';

        echo '<p class="submit"><button class="button button-primary">Save</button></p>';
        echo '</form>';

        echo '<hr/>';

        echo '<form method="post" style="margin-top:12px">';
        wp_nonce_field('tmwfr_dashboard');
        echo '<input type="hidden" name="tmwfr_action" value="apply_safe_preset" />';
        echo '<button class="button">Apply Safe Preset (HTML + Media + Preload)</button>';
        echo '</form>';

        echo '</div>'; // card
        echo '</div>'; // grid

        echo '</div>'; // wrap
    }

    private static function render_module_row(string $label, string $key, array $modules, string $notes): void {
        $enabled = !empty($modules[$key]['enabled']);
        echo '<tr>';
        echo '<td><strong>' . esc_html($label) . '</strong></td>';
        echo '<td><label><input type="checkbox" name="modules[' . esc_attr($key) . '][enabled]" value="1"' . checked($enabled, true, false) . '> Enabled</label></td>';
        echo '<td class="description">' . esc_html($notes) . '</td>';
        echo '</tr>';
    }

    public static function page_safe_mode(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = Options::instance();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tmwfr_save_safe_mode'])) {
            check_admin_referer('tmwfr_safe_mode');
            $safe = !empty($_POST['safe_mode']);
            $opts->set('safe_mode', $safe);
            echo '<div class="notice notice-success"><p>Safe Mode updated.</p></div>';
        }

        $safe_mode = $opts->safe_mode();

        echo '<div class="wrap tmwfr-wrap">';
        echo '<h1>Safe Mode</h1>';
        echo '<p>Safe Mode disables <strong>all front-end modules</strong> without deactivating the plugin. Use it as a panic button.</p>';

        echo '<form method="post">';
        wp_nonce_field('tmwfr_safe_mode');
        echo '<label style="font-size:16px"><input type="checkbox" name="safe_mode" value="1"' . checked($safe_mode, true, false) . '> Enable Safe Mode</label>';
        echo '<p class="submit"><button class="button button-primary" name="tmwfr_save_safe_mode" value="1">Save</button></p>';
        echo '</form>';

        echo '</div>';
    }

    public static function page_html(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = Options::instance();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tmwfr_save_html'])) {
            check_admin_referer('tmwfr_html');

            $all = $opts->all();
            $all['html']['remove_emoji'] = !empty($_POST['remove_emoji']);
            $all['html']['remove_embeds'] = !empty($_POST['remove_embeds']);
            $all['html']['dequeue_dashicons_for_guests'] = !empty($_POST['dequeue_dashicons_for_guests']);
            $opts->update_all($all);

            echo '<div class="notice notice-success"><p>HTML settings saved.</p></div>';
        }

        $remove_emoji = (bool) $opts->get('html.remove_emoji', true);
        $remove_embeds = (bool) $opts->get('html.remove_embeds', true);
        $dequeue_dashicons = (bool) $opts->get('html.dequeue_dashicons_for_guests', true);

        echo '<div class="wrap tmwfr-wrap">';
        echo '<h1>HTML Trims</h1>';
        echo '<p>These are safe front-end trims. They do not modify your markup; they remove unused core assets.</p>';

        echo '<form method="post">';
        wp_nonce_field('tmwfr_html');

        echo '<p><label><input type="checkbox" name="remove_emoji" value="1"' . checked($remove_emoji, true, false) . '> Remove emoji scripts/styles</label></p>';
        echo '<p><label><input type="checkbox" name="remove_embeds" value="1"' . checked($remove_embeds, true, false) . '> Remove oEmbed (wp-embed) script</label></p>';
        echo '<p><label><input type="checkbox" name="dequeue_dashicons_for_guests" value="1"' . checked($dequeue_dashicons, true, false) . '> Dequeue Dashicons for logged-out visitors</label></p>';

        echo '<p class="submit"><button class="button button-primary" name="tmwfr_save_html" value="1">Save</button></p>';
        echo '</form>';
        echo '</div>';
    }

    public static function page_media(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = Options::instance();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tmwfr_save_media'])) {
            check_admin_referer('tmwfr_media');

            $count = isset($_POST['promote_first_images_count']) ? absint($_POST['promote_first_images_count']) : 1;
            if ($count > 10) {
                $count = 10; // hard cap
            }

            $sizes = isset($_POST['home_categories_sizes']) ? sanitize_text_field((string) $_POST['home_categories_sizes']) : '';
            if ($sizes === '') {
                $sizes = Options::defaults()['media']['home_categories_sizes'];
            }

            $all = $opts->all();
            $all['media']['promote_first_images_count'] = $count;
            $all['media']['home_categories_sizes'] = $sizes;
            $opts->update_all($all);

            echo '<div class="notice notice-success"><p>Media settings saved.</p></div>';
        }

        $count = (int) $opts->get('media.promote_first_images_count', 1);
        $sizes = (string) $opts->get('media.home_categories_sizes', Options::defaults()['media']['home_categories_sizes']);

        echo '<div class="wrap tmwfr-wrap">';
        echo '<h1>Media</h1>';
        echo '<p>Controls for image loading behavior. Keep this conservative to avoid bandwidth spikes.</p>';

        echo '<form method="post">';
        wp_nonce_field('tmwfr_media');

        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Promote first images</th><td>';
        echo '<label>Number of images per request to set as <code>loading=eager</code> + <code>fetchpriority=high</code>: ';
        echo '<input type="number" min="0" max="10" name="promote_first_images_count" value="' . esc_attr((string) $count) . '" />';
        echo '</label>';
        echo '<p class="description">Helps LCP on media-heavy pages by forcing the first image(s) to load early. Default: 1.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Home categories sizes</th><td>';
        echo '<input type="text" class="regular-text" name="home_categories_sizes" value="' . esc_attr($sizes) . '" />';
        echo '<p class="description">Applied to images rendered inside <code>[tmw_home_categories]</code> to prevent oversized downloads.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        echo '<p class="submit"><button class="button button-primary" name="tmwfr_save_media" value="1">Save</button></p>';
        echo '</form>';
        echo '</div>';
    }

    public static function page_preload(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = Options::instance();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tmwfr_save_preload'])) {
            check_admin_referer('tmwfr_preload');

            $all = $opts->all();
            $all['preload']['category_preload_first_thumb'] = !empty($_POST['category_preload_first_thumb']);
            $all['preload']['preconnect_third_party'] = !empty($_POST['preconnect_third_party']);
            $opts->update_all($all);

            echo '<div class="notice notice-success"><p>Preload settings saved.</p></div>';
        }

        $category_preload = (bool) $opts->get('preload.category_preload_first_thumb', true);
        $preconnect = (bool) $opts->get('preload.preconnect_third_party', false);

        echo '<div class="wrap tmwfr-wrap">';
        echo '<h1>Preload</h1>';
        echo '<p>These hints can improve LCP by making the browser discover key resources sooner.</p>';

        echo '<form method="post">';
        wp_nonce_field('tmwfr_preload');

        echo '<p><label><input type="checkbox" name="category_preload_first_thumb" value="1"' . checked($category_preload, true, false) . '> Preload the first post thumbnail on category archives</label></p>';
        echo '<p class="description">Useful when a category page LCP is a post thumbnail (common on grid/list layouts).</p>';

        echo '<hr/>';

        echo '<p><label><input type="checkbox" name="preconnect_third_party" value="1"' . checked($preconnect, true, false) . '> Add preconnect hints for common third-party hosts (ads/analytics)</label></p>';
        echo '<p class="description">OFF by default. Turn ON only if you keep those scripts enabled and want a faster handshake.</p>';

        echo '<p class="submit"><button class="button button-primary" name="tmwfr_save_preload" value="1">Save</button></p>';
        echo '</form>';
        echo '</div>';
    }

    public static function page_js(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = Options::instance();
        $compat = Compat::detect();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tmwfr_save_js'])) {
            check_admin_referer('tmwfr_js');

            $all = $opts->all();

            $mode = isset($_POST['mode']) ? sanitize_text_field((string) $_POST['mode']) : 'safe';
            if (!in_array($mode, ['safe','balanced','advanced'], true)) {
                $mode = 'safe';
            }
            $all['js']['mode'] = $mode;

            $all['js']['overlap_allowed'] = !empty($_POST['overlap_allowed']);
            $all['js']['dequeue_videojs_non_video_pages'] = !empty($_POST['dequeue_videojs_non_video_pages']);

            // Handle list fields
            $defer = isset($_POST['defer_handles']) ? (string) $_POST['defer_handles'] : '';
            $delay = isset($_POST['delay_hosts']) ? (string) $_POST['delay_hosts'] : '';

            $all['js']['defer_handles'] = self::sanitize_lines_list($defer);
            $all['js']['delay_hosts'] = self::sanitize_lines_list($delay);

            $opts->update_all($all);

            echo '<div class="notice notice-success"><p>JavaScript settings saved.</p></div>';
        }

        $mode = (string) $opts->get('js.mode', 'safe');
        $overlap = (bool) $opts->get('js.overlap_allowed', false);
        $dequeue_videojs = (bool) $opts->get('js.dequeue_videojs_non_video_pages', false);
        $defer_handles = (array) $opts->get('js.defer_handles', []);
        $delay_hosts = (array) $opts->get('js.delay_hosts', []);

        echo '<div class="wrap tmwfr-wrap">';
        echo '<h1>JavaScript</h1>';

        if (!empty($compat['theme']['theme_perf_layer'])) {
            echo '<div class="notice notice-info"><p><strong>Detected:</strong> your child theme already runs JS defer/delay logic. By default, TMWFR avoids overlapping it.</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('tmwfr_js');

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row">Mode</th><td>';
        echo '<select name="mode">';
        foreach (['safe'=>'Safe (defer only)','balanced'=>'Balanced (delay third-party)','advanced'=>'Advanced (manual delay rules)'] as $k=>$label) {
            echo '<option value="' . esc_attr($k) . '"' . selected($mode, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Theme overlap</th><td>';
        echo '<label><input type="checkbox" name="overlap_allowed" value="1"' . checked($overlap, true, false) . '> Allow overlap with theme JS delay system</label>';
        echo '<p class="description">Only enable if you know what you are doing. Double-delays can break ad/analytics or video players.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">VideoJS assets</th><td>';
        echo '<label><input type="checkbox" name="dequeue_videojs_non_video_pages" value="1"' . checked($dequeue_videojs, true, false) . '> Dequeue VideoJS on non-video pages</label>';
        echo '<p class="description">May reduce unused JS on archives/home if VideoJS is enqueued globally. Test thoroughly.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Defer handles (one per line)</th><td>';
        echo '<textarea name="defer_handles" rows="8" class="large-text code">' . esc_textarea(implode("\n", $defer_handles)) . '</textarea>';
        echo '<p class="description">In Safe mode, these script handles get <code>defer</code>.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Delay hosts (one per line)</th><td>';
        echo '<textarea name="delay_hosts" rows="8" class="large-text code">' . esc_textarea(implode("\n", $delay_hosts)) . '</textarea>';
        echo '<p class="description">In Balanced/Advanced, scripts from these hosts can be delayed until user interaction.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        echo '<p class="submit"><button class="button button-primary" name="tmwfr_save_js" value="1">Save</button></p>';
        echo '</form>';

        echo '</div>';
    }

    /** @return array<int,string> */
    private static function sanitize_lines_list(string $raw): array {
        $lines = preg_split('/\r\n|\n|\r/', $raw);
        if (!is_array($lines)) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/[^a-z0-9\-\._\/]/i', '', $line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        // Unique
        $out = array_values(array_unique($out));
        return $out;
    }

    public static function page_cache(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = Options::instance();
        $compat = Compat::detect();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tmwfr_cache_action'])) {
            check_admin_referer('tmwfr_cache');

            $action = sanitize_text_field((string) $_POST['tmwfr_cache_action']);

            if ($action === 'save') {
                $all = $opts->all();

                $mode = isset($_POST['mode']) ? sanitize_text_field((string) $_POST['mode']) : 'standard';
                if (!in_array($mode, ['standard','dropin'], true)) {
                    $mode = 'standard';
                }
                $ttl = isset($_POST['ttl']) ? absint($_POST['ttl']) : 3600;
                if ($ttl < 60) {
                    $ttl = 60;
                }
                if ($ttl > 604800) {
                    $ttl = 604800; // 7 days
                }

                $mobile_sep = !empty($_POST['mobile_separate']);

                $exclude_paths_raw = isset($_POST['exclude_paths']) ? (string) $_POST['exclude_paths'] : '';
                $exclude_paths = self::sanitize_paths_list($exclude_paths_raw);

                $all['cache']['mode'] = $mode;
                $all['cache']['ttl'] = $ttl;
                $all['cache']['mobile_separate'] = $mobile_sep;
                $all['cache']['exclude_paths'] = $exclude_paths;

                $opts->update_all($all);

                // If cache module is enabled and drop-in mode selected, refresh config file.
                \TMWFR\Modules\Cache\Cache::write_config_file();

                echo '<div class="notice notice-success"><p>Cache settings saved.</p></div>';
            }

            if ($action === 'purge') {
                $deleted = \TMWFR\Modules\Cache\Cache::purge_all();
                echo '<div class="notice notice-success"><p>Cache purged. Deleted ' . esc_html((string) $deleted) . ' file(s).</p></div>';
            }

            if ($action === 'install_dropin') {
                $result = \TMWFR\Modules\Cache\Cache::install_dropin();
                if ($result === true) {
                    echo '<div class="notice notice-success"><p>advanced-cache.php installed.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Could not install advanced-cache.php: ' . esc_html((string) $result) . '</p></div>';
                }
            }

            if ($action === 'remove_dropin') {
                $result = \TMWFR\Modules\Cache\Cache::remove_dropin();
                if ($result === true) {
                    echo '<div class="notice notice-success"><p>advanced-cache.php removed.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Could not remove advanced-cache.php: ' . esc_html((string) $result) . '</p></div>';
                }
            }
        }

        $mode = (string) $opts->get('cache.mode', 'standard');
        $ttl = (int) $opts->get('cache.ttl', 3600);
        $mobile_sep = (bool) $opts->get('cache.mobile_separate', true);
        $exclude_paths = (array) $opts->get('cache.exclude_paths', []);

        echo '<div class="wrap tmwfr-wrap">';
        echo '<h1>Cache</h1>';
        echo '<p><strong>Important:</strong> Cache module is OFF by default. Enable it from the Dashboard when you are ready.</p>';

        echo '<div class="tmwfr-card">';
        echo '<h2>Cache status</h2>';
        echo '<ul class="tmwfr-list">';
        echo '<li><strong>WP_CACHE:</strong> ' . esc_html(!empty($compat['wp_cache']) ? 'true' : 'false') . '</li>';
        echo '<li><strong>advanced-cache.php:</strong> ' . esc_html(!empty($compat['dropins']['advanced-cache.php']) ? 'present' : 'missing') . '</li>';
        echo '<li><strong>Drop-in mode:</strong> ' . esc_html($mode) . '</li>';
        echo '</ul>';

        if (empty($compat['wp_cache'])) {
            echo '<p class="description"><strong>Heads up:</strong> WP_CACHE is currently false. Drop-in mode will not run until WP_CACHE is set to true in wp-config.php.</p>';
        }

        echo '</div>';

        echo '<form method="post">';
        wp_nonce_field('tmwfr_cache');
        echo '<input type="hidden" name="tmwfr_cache_action" value="save" />';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row">Cache mode</th><td>';
        echo '<select name="mode">';
        echo '<option value="standard"' . selected($mode, 'standard', false) . '>Standard (serve at parse_request)</option>';
        echo '<option value="dropin"' . selected($mode, 'dropin', false) . '>Drop-in (advanced-cache.php)</option>';
        echo '</select>';
        echo '<p class="description">Drop-in mode is fastest but requires WP_CACHE=true.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">TTL</th><td>';
        echo '<input type="number" min="60" max="604800" name="ttl" value="' . esc_attr((string) $ttl) . '" />';
        echo '<p class="description">Time-to-live in seconds. Default: 3600 (1 hour).</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Separate mobile cache</th><td>';
        echo '<label><input type="checkbox" name="mobile_separate" value="1"' . checked($mobile_sep, true, false) . '> Yes</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Exclude paths (one per line)</th><td>';
        echo '<textarea name="exclude_paths" rows="8" class="large-text code">' . esc_textarea(implode("\n", $exclude_paths)) . '</textarea>';
        echo '<p class="description">Paths starting with these prefixes are never cached.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        echo '<p class="submit"><button class="button button-primary">Save</button></p>';
        echo '</form>';

        echo '<hr/>';

        echo '<form method="post" style="display:inline-block;margin-right:8px">';
        wp_nonce_field('tmwfr_cache');
        echo '<input type="hidden" name="tmwfr_cache_action" value="purge" />';
        echo '<button class="button">Purge Cache Now</button>';
        echo '</form>';

        echo '<form method="post" style="display:inline-block;margin-right:8px">';
        wp_nonce_field('tmwfr_cache');
        echo '<input type="hidden" name="tmwfr_cache_action" value="install_dropin" />';
        echo '<button class="button">Install advanced-cache.php</button>';
        echo '</form>';

        echo '<form method="post" style="display:inline-block">';
        wp_nonce_field('tmwfr_cache');
        echo '<input type="hidden" name="tmwfr_cache_action" value="remove_dropin" />';
        echo '<button class="button">Remove advanced-cache.php</button>';
        echo '</form>';

        echo '</div>';
    }

    /** @return array<int,string> */
    private static function sanitize_paths_list(string $raw): array {
        $lines = preg_split('/\r\n|\n|\r/', $raw);
        if (!is_array($lines)) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if ($line[0] !== '/') {
                $line = '/' . $line;
            }
            // Keep it simple: only safe URL path chars.
            $line = preg_replace('#[^/a-z0-9\-\._]#i', '', $line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    public static function page_compat(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $compat = Compat::detect();

        echo '<div class="wrap tmwfr-wrap">';
        echo '<h1>Compatibility</h1>';
        echo '<p>This is a read-only detector. It does not change anything.</p>';

        echo '<div class="tmwfr-grid">';

        // Theme
        echo '<div class="tmwfr-card">';
        echo '<h2>Theme</h2>';
        echo '<ul class="tmwfr-list">';
        echo '<li><strong>Child:</strong> ' . esc_html($compat['theme']['stylesheet'] ?? 'unknown') . '</li>';
        echo '<li><strong>Parent:</strong> ' . esc_html($compat['theme']['template'] ?? 'unknown') . '</li>';
        echo '<li><strong>RetroTube Child v3:</strong> ' . esc_html(!empty($compat['theme']['is_retrotube_child_v3']) ? 'yes' : 'no') . '</li>';
        echo '<li><strong>Theme performance layer:</strong> ' . esc_html(!empty($compat['theme']['theme_perf_layer']) ? 'detected' : 'not detected') . '</li>';
        echo '</ul>';
        echo '</div>';

        // Conflicts
        echo '<div class="tmwfr-card">';
        echo '<h2>Plugins / Cache layers</h2>';
        echo '<ul class="tmwfr-list">';
        foreach ((array) ($compat['conflicts'] ?? []) as $k => $v) {
            echo '<li><strong>' . esc_html($k) . ':</strong> ' . esc_html($v ? 'active/detected' : 'no') . '</li>';
        }
        echo '</ul>';
        echo '</div>';

        // Drop-ins
        echo '<div class="tmwfr-card">';
        echo '<h2>Drop-ins</h2>';
        echo '<ul class="tmwfr-list">';
        foreach ((array) ($compat['dropins'] ?? []) as $k => $v) {
            echo '<li><strong>' . esc_html($k) . ':</strong> ' . esc_html($v ? 'present' : 'missing') . '</li>';
        }
        echo '</ul>';
        echo '<p><strong>WP_CACHE:</strong> ' . esc_html(!empty($compat['wp_cache']) ? 'true' : 'false') . '</p>';
        echo '</div>';

        echo '</div>'; // grid
        echo '</div>'; // wrap
    }

    public static function page_logs(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $log_file = WP_CONTENT_DIR . '/debug.log';
        $tail = Utils::tail_file($log_file, 250);

        echo '<div class="wrap tmwfr-wrap">';
        echo '<h1>Logs</h1>';
        echo '<p>Showing the last ~250 lines from <code>wp-content/debug.log</code>. Filter for <code>[TMW-FR-</code>.</p>';

        if ($tail === '') {
            echo '<p>No readable log output found.</p>';
        } else {
            echo '<textarea rows="22" class="large-text code" readonly>' . esc_textarea($tail) . '</textarea>';
        }

        echo '</div>';
    }

    public static function page_import_export(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = Options::instance();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tmwfr_ie_action'])) {
            check_admin_referer('tmwfr_import_export');

            $action = sanitize_text_field((string) $_POST['tmwfr_ie_action']);

            if ($action === 'import') {
                $raw = isset($_POST['tmwfr_import_json']) ? (string) wp_unslash($_POST['tmwfr_import_json']) : '';
                $decoded = json_decode($raw, true);

                if (!is_array($decoded)) {
                    echo '<div class="notice notice-error"><p>Invalid JSON.</p></div>';
                } else {
                    $opts->update_all($decoded);
                    echo '<div class="notice notice-success"><p>Settings imported.</p></div>';
                }
            }
        }

        $json = wp_json_encode($opts->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        echo '<div class="wrap tmwfr-wrap">';
        echo '<h1>Import / Export</h1>';

        echo '<h2>Export</h2>';
        echo '<p>Copy this JSON and store it as a backup.</p>';
        echo '<textarea rows="18" class="large-text code" readonly>' . esc_textarea((string) $json) . '</textarea>';

        echo '<hr/>';

        echo '<h2>Import</h2>';
        echo '<p>Paste a JSON export here to restore settings.</p>';
        echo '<form method="post">';
        wp_nonce_field('tmwfr_import_export');
        echo '<input type="hidden" name="tmwfr_ie_action" value="import" />';
        echo '<textarea name="tmwfr_import_json" rows="18" class="large-text code"></textarea>';
        echo '<p class="submit"><button class="button button-primary">Import</button></p>';
        echo '</form>';

        echo '</div>';
    }
}
