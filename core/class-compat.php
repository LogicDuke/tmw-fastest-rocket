<?php
namespace TMWFR;

if (!defined('ABSPATH')) {
    exit;
}

final class Compat {

    /**
     * @return array<string,mixed>
     */
    public static function detect(): array {
        // Theme detection
        $theme = wp_get_theme();
        $stylesheet = $theme ? (string) $theme->get_stylesheet() : '';
        $template = $theme ? (string) $theme->get_template() : '';

        $is_retrotube_child_v3 = ($stylesheet === 'retrotube-child-v3');

        // Theme performance layer detection (we do not overlap by default).
        $theme_perf = function_exists('tmw_child_is_heavy_media_view')
            || function_exists('tmw_child_perf_buffer_should_run')
            || file_exists(get_stylesheet_directory() . '/inc/frontend/performance.php')
            || file_exists(get_stylesheet_directory() . '/inc/frontend/perf-buffer-rewrite.php');

        // Active plugin detection (best-effort; works even if is_plugin_active not loaded).
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $network_active = (array) get_site_option('active_sitewide_plugins', []);
            $active = array_merge($active, array_keys($network_active));
        }

        $active = array_map('strtolower', $active);

        $is_active = static function (string $plugin_file) use ($active): bool {
            return in_array(strtolower($plugin_file), $active, true);
        };

        $conflicts = [
            'wp_fastest_cache' => $is_active('wp-fastest-cache/wpFastestCache.php'),
            'sg_optimizer'     => $is_active('sg-cachepress/sg-cachepress.php') || $is_active('sg-cachepress/sg-cachepress.php'),
            'cloudflare'       => $is_active('cloudflare/cloudflare.php'),
            'wp_asset_cleanup' => $is_active('wp-asset-clean-up/wp-asset-clean-up.php'),
            'autoptimize'      => $is_active('autoptimize/autoptimize.php'),
            'litespeed'        => $is_active('litespeed-cache/litespeed-cache.php'),
            'w3tc'             => $is_active('w3-total-cache/w3-total-cache.php'),
            'nitropack'        => (bool) glob(WP_CONTENT_DIR . '/config-*-nitropack', GLOB_ONLYDIR),
            'cf_super_cache'   => $is_active('wp-cloudflare-super-page-cache/wp-cloudflare-super-page-cache.php'),
        ];

        // Drop-in detection
        $dropins = [
            'advanced-cache.php' => file_exists(WP_CONTENT_DIR . '/advanced-cache.php'),
            'object-cache.php'   => file_exists(WP_CONTENT_DIR . '/object-cache.php'),
        ];

        // WP_CACHE constant
        $wp_cache = (defined('WP_CACHE') && WP_CACHE);

        return [
            'theme' => [
                'stylesheet' => $stylesheet,
                'template' => $template,
                'is_retrotube_child_v3' => $is_retrotube_child_v3,
                'theme_perf_layer' => $theme_perf,
            ],
            'conflicts' => $conflicts,
            'dropins' => $dropins,
            'wp_cache' => $wp_cache,
        ];
    }
}
