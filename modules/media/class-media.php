<?php
namespace TMWFR\Modules\Media;

use TMWFR\Options;
use TMWFR\Context;
use TMWFR\Logger;
use TMWFR\Utils;

if (!defined('ABSPATH')) {
    exit;
}

final class Media {

    /** @var int */
    private static $content_img_count = 0;

    public static function init(): void {
        add_filter('wp_get_attachment_image_attributes', [__CLASS__, 'filter_image_attributes'], 20, 3);
        add_filter('get_custom_logo_image_attributes', [__CLASS__, 'filter_logo_attributes'], 20, 2);
    }

    /**
     * @param array<string,string|int> $attr
     * @param \WP_Post|int $attachment
     * @param string|array<int,int> $size
     * @return array<string,string|int>
     */
    public static function filter_image_attributes($attr, $attachment, $size): array {
        if (is_admin()) {
            return $attr;
        }

        if (!is_array($attr)) {
            return $attr;
        }

        // Always prefer async decode where possible.
        if (empty($attr['decoding'])) {
            $attr['decoding'] = 'async';
        }

        // Home categories: enforce sizes to prevent overserving.
        if (Context::has('tmw_home_categories')) {
            $sizes = (string) Options::instance()->get('media.home_categories_sizes', '(max-width: 600px) 50vw, 240px');
            if ($sizes !== '') {
                $attr['sizes'] = $sizes;
            }
        }

        // Avoid touching avatars, emojis, admin bar etc.
        $classes = isset($attr['class']) ? (string) $attr['class'] : '';
        if ($classes !== '' && preg_match('/\b(avatar|emoji|admin-bar|custom-logo)\b/i', $classes)) {
            return $attr;
        }

        $opts = Options::instance();
        $promote_n = (int) $opts->get('media.promote_first_images_count', 1);
        if ($promote_n < 1) {
            return $attr;
        }

        if (!self::is_promotable_view()) {
            return $attr;
        }

        if (self::$content_img_count >= $promote_n) {
            return $attr;
        }

        self::$content_img_count++;

        // Promote this image.
        $attr['loading'] = 'eager';
        $attr['fetchpriority'] = 'high';
        $attr['decoding'] = 'async';

        Logger::log('Promoted image #' . self::$content_img_count . ' to eager/high.', 'MEDIA');

        return $attr;
    }

    /**
     * @param array<string,string|int> $attrs
     * @param int $custom_logo_id
     * @return array<string,string|int>
     */
    public static function filter_logo_attributes($attrs, $custom_logo_id): array {
        if (!is_array($attrs)) {
            return $attrs;
        }

        if (empty($attrs['decoding'])) {
            $attrs['decoding'] = 'async';
        }

        // Do not force eager/fetchpriority for logo by default; theme may already handle LCP.
        return $attrs;
    }

    private static function is_promotable_view(): bool {
        // Promote on typical LCP-sensitive contexts.
        if (is_front_page() && !is_paged()) {
            return true;
        }

        // Category/tag archives (Lighthouse shows LCP issues there).
        if ((is_category() || is_tag()) && !is_paged()) {
            return true;
        }

        // Models / media-heavy views (use theme helper if present).
        if (function_exists('tmw_child_is_heavy_media_view')) {
            try {
                return (bool) tmw_child_is_heavy_media_view();
            } catch (\Throwable $e) {
                // fallthrough
            }
        }

        // Generic fallbacks.
        if (is_post_type_archive(['model','video']) && !is_paged()) {
            return true;
        }

        if (is_singular(['model','video']) && !is_preview()) {
            return true;
        }

        return false;
    }
}
