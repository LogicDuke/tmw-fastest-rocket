<?php
namespace TMWFR\Modules\Preload;

use TMWFR\Options;
use TMWFR\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class Preload {

    public static function init(): void {
        add_action('wp_head', [__CLASS__, 'output_hints'], 1);
    }

    public static function output_hints(): void {
        if (is_admin() || is_feed() || is_preview()) {
            return;
        }

        $opts = Options::instance();

        if ($opts->get('preload.preconnect_third_party', false)) {
            self::preconnect_common_third_party();
        }

        if ($opts->get('preload.category_preload_first_thumb', true)) {
            self::preload_first_thumb_on_term_archives();
        }
    }

    private static function preload_first_thumb_on_term_archives(): void {
        if (is_paged()) {
            return;
        }

        if (!(is_category() || is_tag())) {
            return;
        }

        global $wp_query;
        if (!$wp_query || empty($wp_query->posts) || !is_array($wp_query->posts)) {
            return;
        }

        $first = $wp_query->posts[0] ?? null;
        if (!$first instanceof \WP_Post) {
            return;
        }

        $thumb_id = get_post_thumbnail_id($first);
        if (!$thumb_id) {
            return;
        }

        // Use medium by default to avoid massive preloads; browser can still choose srcset variant.
        $url = wp_get_attachment_image_url($thumb_id, 'medium');
        if (!is_string($url) || $url === '') {
            return;
        }

        echo "\n" . '<link rel="preload" as="image" href="' . esc_url($url) . '" fetchpriority="high">' . "\n";
        Logger::log('Preloaded first category thumb: ' . $url, 'PRELOAD');
    }

    private static function preconnect_common_third_party(): void {
        // These are common in this project; customize as needed.
        $hosts = [
            'https://www.googletagmanager.com',
            'https://pagead2.googlesyndication.com',
            'https://pagead2.g.doubleclick.net',
            'https://connect.facebook.net',
            'https://static.cloudflareinsights.com',
        ];

        foreach ($hosts as $h) {
            echo "\n" . '<link rel="preconnect" href="' . esc_url($h) . '" crossorigin>' . "\n";
        }

        Logger::log('Preconnect hints output.', 'PRELOAD');
    }
}
