<?php
namespace TMWFR;

use TMWFR\Modules\HTML\HTML;
use TMWFR\Modules\Media\Media;
use TMWFR\Modules\Preload\Preload;
use TMWFR\Modules\JS\JS;
use TMWFR\Modules\Cache\Cache;

if (!defined('ABSPATH')) {
    exit;
}

final class Modules {

    public static function init_frontend(): void {
        $opts = Options::instance();

        // Safe Mode disables all front-end behaviors.
        if ($opts->safe_mode()) {
            Logger::log('Safe Mode active: all front-end modules are disabled.', 'CORE');
            return;
        }

        // Track shortcode context (used by Media module).
        self::init_context_tracking();

        if ($opts->is_module_enabled('html')) {
            HTML::init();
        }

        if ($opts->is_module_enabled('media')) {
            Media::init();
        }

        if ($opts->is_module_enabled('preload')) {
            Preload::init();
        }

        if ($opts->is_module_enabled('js')) {
            JS::init();
        }

        if ($opts->is_module_enabled('cache')) {
            Cache::init();
        }
    }

    private static function init_context_tracking(): void {
        // Enter context when shortcode is parsed.
        add_filter('shortcode_atts_tmw_home_categories', function ($out) {
            Context::push('tmw_home_categories');
            return $out;
        }, 5);

        add_filter('shortcode_atts_models_flipboxes', function ($out) {
            Context::push('models_flipboxes');
            return $out;
        }, 5);

        add_filter('shortcode_atts_actors_flipboxes', function ($out) {
            Context::push('actors_flipboxes');
            return $out;
        }, 5);

        // Exit context once shortcode output is returned.
        add_filter('do_shortcode_tag', function ($output, $tag, $attr, $m) {
            $tag = strtolower((string) $tag);
            if (in_array($tag, ['tmw_home_categories', 'models_flipboxes', 'actors_flipboxes'], true)) {
                Context::pop($tag);
            }
            return $output;
        }, 5, 4);
    }
}
