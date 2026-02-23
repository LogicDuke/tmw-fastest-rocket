<?php
/**
 * Plugin Name: TMW Fastest Rocket
 * Description: Performance framework for top-models.webcam (RetroTube Child v3). Cloudflare-first headers + LCP boosters.
 * Version: 0.4.2
 * Author: TMW
 * License: GPLv2 or later
 * Text Domain: tmw-fastest-rocket
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('TMWFR_LOADED')) {
    return;
}
define('TMWFR_LOADED', true);

define('TMWFR_VERSION', '0.4.2');
define('TMWFR_FILE', __FILE__);
define('TMWFR_DIR', plugin_dir_path(__FILE__));
define('TMWFR_URL', plugin_dir_url(__FILE__));
define('TMWFR_BASENAME', plugin_basename(__FILE__));

/**
 * Cloudflare-first cache headers (FINAL, no cookies)
 * - Public cache for guests
 * - Private/no-store for admin, logged-in, preview, search, REST/AJAX/login
 * - Allows GET + HEAD (so curl -I doesn't trigger BYPASS)
 */
add_action('send_headers', function () {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

    // Admin / login / backend paths
    if (
        is_admin() ||
        strpos($uri, '/wp-admin') !== false ||
        strpos($uri, 'wp-login.php') !== false
    ) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-TMW-Cache: BYPASS');
        header('X-TMW-Bypass-Reason: admin_login');
        return;
    }

    // Logged-in users
    if (is_user_logged_in()) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-TMW-Cache: BYPASS');
        header('X-TMW-Bypass-Reason: logged_in');
        return;
    }

    // Preview / search
    if (isset($_GET['preview'])) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-TMW-Cache: BYPASS');
        header('X-TMW-Bypass-Reason: preview');
        return;
    }
    if (isset($_GET['s'])) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-TMW-Cache: BYPASS');
        header('X-TMW-Bypass-Reason: search');
        return;
    }

    // REST / AJAX (do not cache)
    if ((defined('REST_REQUEST') && REST_REQUEST) || strpos($uri, '/wp-json/') !== false) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-TMW-Cache: BYPASS');
        header('X-TMW-Bypass-Reason: rest');
        return;
    }
    if (defined('DOING_AJAX') && DOING_AJAX) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-TMW-Cache: BYPASS');
        header('X-TMW-Bypass-Reason: ajax');
        return;
    }

    // Only cache GET + HEAD
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-TMW-Cache: BYPASS');
        header('X-TMW-Bypass-Reason: method');
        return;
    }

    // Cacheable public pages (guests)
    header('Cache-Control: public, max-age=1800');
    header('X-TMW-Cache: CACHE');
    header('X-TMW-Bypass-Reason: none');
}, 999);

/**
 * LCP Boosters:
 * - Preconnect/DNS-prefetch to affiliate/provider image CDN (reduces LCP handshake cost)
 */
add_filter('wp_resource_hints', function ($urls, $relation_type) {
    if (is_admin() || is_user_logged_in()) {
        return $urls;
    }

    // IMPORTANT: keep as hosts you actually use.
    $preconnect = [
        'https://galleryn1.vcmdiawe.com',
        'https://vcmdiawe.com',
        'https://cdn.gtranslate.net',
    ];

    if ($relation_type === 'preconnect') {
        foreach ($preconnect as $u) {
            $urls[] = $u;
        }
    }

    if ($relation_type === 'dns-prefetch') {
        foreach ($preconnect as $u) {
            $host = parse_url($u, PHP_URL_HOST);
            if ($host) {
                $urls[] = '//' . $host;
            }
        }
    }

    // de-dupe
    $urls = array_values(array_unique($urls));
    return $urls;
}, 10, 2);

/**
 * Disable WP native lazy-loading on key landing pages (guests only).
 * Helps Lighthouse when LCP image is delayed due to lazy-loading.
 */
add_filter('wp_lazy_loading_enabled', function ($default, $tag_name, $context) {
    if (is_admin() || is_user_logged_in()) {
        return $default;
    }

    // Home + Category/Archive pages
    if ((is_front_page() || is_home() || is_category() || is_archive()) && $tag_name === 'img') {
        return false;
    }

    return $default;
}, 10, 3);

// Keep your existing framework bootstrap
require_once TMWFR_DIR . 'core/bootstrap.php';
\TMWFR\Bootstrap::init();

/**
 * Boost LCP on Category/Archive pages:
 * - Give the first visible image higher priority (guests only)
 */
add_filter('wp_get_attachment_image_attributes', function ($attr, $attachment, $size) {
    if (is_admin() || is_user_logged_in()) {
        return $attr;
    }

    if (is_category() || is_archive()) {
        static $tmw_first = true;
        if ($tmw_first) {
            $attr['fetchpriority'] = 'high';
            $attr['decoding'] = 'async';
            $attr['loading'] = 'eager';
            $tmw_first = false;
        }
    }

    return $attr;
}, 10, 3);

/**
 * Category/Archive LCP Fix:
 * Convert the FIRST lazy image (data-src) into a real eager src load.
 * - Guests only
 * - Category/Archive only
 * - Touches ONLY the first occurrence of: <img ... data-src="...">
 */
add_action('template_redirect', function () {
    if (is_admin() || is_user_logged_in()) {
        return;
    }

    if (!(is_category() || is_archive())) {
        return;
    }

    ob_start(function ($html) {
        // Only rewrite once
        $done = false;

        $html = preg_replace_callback(
            '/<img\b([^>]*?)\sdata-src="([^"]+)"([^>]*)>/i',
            function ($m) use (&$done) {
                if ($done) {
                    return $m[0];
                }
                $done = true;

                $before = $m[1];
                $src    = $m[2];
                $after  = $m[3];

                // Remove any existing loading attribute (we'll set eager)
                $before = preg_replace('/\sloading="[^"]*"/i', '', $before);
                $after  = preg_replace('/\sloading="[^"]*"/i', '', $after);

                // Add eager + priority hints
                $inject = ' loading="eager" fetchpriority="high" decoding="async"';

                return '<img' . $before . ' src="' . $src . '"' . $inject . $after . '>';
            },
            $html,
            1 // first match only
        );

        return $html;
    });
}, 0);

