<?php
namespace TMWFR;

if (!defined('ABSPATH')) {
    exit;
}

final class SafeMode {

    /**
     * If a fatal error occurs inside this plugin, automatically enable Safe Mode
     * so the site can recover on next request.
     */
    public static function register_fatal_error_guard(): void {
        if (Utils::is_cli()) {
            return;
        }

        register_shutdown_function([__CLASS__, 'on_shutdown']);
    }

    public static function on_shutdown(): void {
        $error = error_get_last();
        if (!is_array($error) || empty($error['type'])) {
            return;
        }

        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) $error['type'], $fatal_types, true)) {
            return;
        }

        $file = isset($error['file']) ? (string) $error['file'] : '';
        if ($file === '' || strpos($file, TMWFR_DIR) !== 0) {
            return;
        }

        $opts = Options::instance();
        if ($opts->safe_mode()) {
            return;
        }

        $opts->set('safe_mode', true);

        $msg = isset($error['message']) ? (string) $error['message'] : 'Unknown fatal error';
        Logger::log('Fatal error detected. Safe Mode enabled automatically. Message: ' . $msg, 'SAFEMODE');
    }
}
