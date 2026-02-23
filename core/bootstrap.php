<?php
namespace TMWFR;

if (!defined('ABSPATH')) {
    exit;
}

final class Bootstrap {

    public static function init(): void {
        self::require_files();

        // Boot order: options + logger first.
        Options::instance();
        Logger::instance();

        SafeMode::register_fatal_error_guard();

        // Admin UI
        if (is_admin()) {
            Admin::init();
            return;
        }

        // Front-end modules
        Modules::init_frontend();
    }

    private static function require_files(): void {
        require_once TMWFR_DIR . 'core/class-utils.php';
        require_once TMWFR_DIR . 'core/class-options.php';
        require_once TMWFR_DIR . 'core/class-logger.php';
        require_once TMWFR_DIR . 'core/class-context.php';
        require_once TMWFR_DIR . 'core/class-compat.php';
        require_once TMWFR_DIR . 'core/class-safemode.php';
        require_once TMWFR_DIR . 'core/class-admin.php';
        require_once TMWFR_DIR . 'core/class-modules.php';

        // Modules
        require_once TMWFR_DIR . 'modules/html/class-html.php';
        require_once TMWFR_DIR . 'modules/media/class-media.php';
        require_once TMWFR_DIR . 'modules/preload/class-preload.php';
        require_once TMWFR_DIR . 'modules/js/class-js.php';
        require_once TMWFR_DIR . 'modules/cache/class-cache.php';
    }
}
