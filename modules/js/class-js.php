<?php
namespace TMWFR\Modules\JS;

use TMWFR\Options;
use TMWFR\Compat;
use TMWFR\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class JS {

    public static function init(): void {
        if (is_admin()) {
            return;
        }

        $opts = Options::instance();
        $compat = Compat::detect();

        $theme_overlap = !empty($compat['theme']['theme_perf_layer']);
        $overlap_allowed = (bool) $opts->get('js.overlap_allowed', false);

        // VideoJS dequeue is independent and can run even if we avoid overlap.
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_dequeue_videojs'], 999);

        if ($theme_overlap && !$overlap_allowed) {
            Logger::log('Theme JS delay system detected; skipping script tag rewrites (overlap not allowed).', 'JS');
            return;
        }

        add_filter('script_loader_tag', [__CLASS__, 'filter_script_tag'], 20, 3);

        // Loader for delayed scripts (only meaningful when mode is balanced/advanced)
        add_action('wp_footer', [__CLASS__, 'output_delay_loader'], 999);
    }

    public static function maybe_dequeue_videojs(): void {
        $opts = Options::instance();
        if (!$opts->get('js.dequeue_videojs_non_video_pages', false)) {
            return;
        }

        if (is_singular('video') || is_post_type_archive('video')) {
            return;
        }

        // Also keep on pages that might embed video shortcodes; conservative heuristic.
        if (is_singular() && has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'video')) {
            return;
        }

        $handles = [
            'videojs',
            'video-js',
            'videojs-quality',
            'videojs-quality-selector',
        ];

        foreach ($handles as $h) {
            if (wp_script_is($h, 'enqueued')) {
                wp_dequeue_script($h);
                Logger::log('Dequeued script: ' . $h, 'JS');
            }
            if (wp_style_is($h, 'enqueued')) {
                wp_dequeue_style($h);
                Logger::log('Dequeued style: ' . $h, 'JS');
            }
        }
    }

    /**
     * @param string $tag
     * @param string $handle
     * @param string $src
     * @return string
     */
    public static function filter_script_tag(string $tag, string $handle, string $src): string {
        if (is_admin() || is_user_logged_in() || is_preview()) {
            return $tag;
        }

        $opts = Options::instance();
        $mode = (string) $opts->get('js.mode', 'safe');

        $defer_handles = (array) $opts->get('js.defer_handles', []);
        $delay_hosts = (array) $opts->get('js.delay_hosts', []);

        $host = (string) parse_url($src, PHP_URL_HOST);

        // Delay third-party scripts in balanced/advanced.
        if (in_array($mode, ['balanced','advanced'], true) && $host && in_array($host, $delay_hosts, true)) {
            // Do not delay if already delayed.
            if (stripos($tag, 'data-tmwfr-delay') !== false) {
                return $tag;
            }

            $placeholder = '<script type="text/plain" data-tmwfr-delay="1" data-src="' . esc_url($src) . '"></script>';
            return $placeholder;
        }

        // Safe: add defer to selected handles.
        if (in_array($handle, $defer_handles, true)) {
            if (stripos($tag, ' defer') !== false) {
                return $tag;
            }
            // Inject defer before closing bracket of opening script tag.
            $tag = preg_replace('/<script\b(?![^>]*\bdefer\b)/i', '<script defer', $tag, 1);
            return $tag ?: $tag;
        }

        return $tag;
    }

    public static function output_delay_loader(): void {
        $opts = Options::instance();
        $mode = (string) $opts->get('js.mode', 'safe');

        if (!in_array($mode, ['balanced','advanced'], true)) {
            return;
        }

        ?>
        <script>
        (function () {
            var loaded = false;
            function loadDelayed() {
                if (loaded) { return; }
                loaded = true;
                var nodes = document.querySelectorAll('script[data-tmwfr-delay]');
                for (var i = 0; i < nodes.length; i++) {
                    var node = nodes[i];
                    var src = node.getAttribute('data-src');
                    if (!src) { continue; }
                    var s = document.createElement('script');
                    s.src = src;
                    s.async = true;
                    node.parentNode.insertBefore(s, node.nextSibling);
                }
            }

            ['scroll', 'pointerdown', 'click', 'touchstart', 'keydown'].forEach(function (evt) {
                window.addEventListener(evt, loadDelayed, { once: true, passive: true });
            });

            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(loadDelayed, { timeout: 2500 });
            } else {
                window.setTimeout(loadDelayed, 2500);
            }
        })();
        </script>
        <?php
    }
}
