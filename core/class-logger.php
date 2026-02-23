<?php
namespace TMWFR;

if (!defined('ABSPATH')) {
    exit;
}

final class Logger {

    /** @var self|null */
    private static $instance = null;

    /** @var bool */
    private $enabled = false;

    public static function instance(): self {
        if (self::$instance instanceof self) {
            return self::$instance;
        }
        self::$instance = new self();
        self::$instance->enabled = (bool) (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG);
        return self::$instance;
    }

    public static function is_enabled(): bool {
        return self::instance()->enabled;
    }

    public static function log(string $message, string $channel = 'CORE'): void {
        $inst = self::instance();
        if (!$inst->enabled) {
            return;
        }

        $channel = strtoupper(preg_replace('/[^A-Z0-9\-]/i', '', $channel));
        if ($channel === '') {
            $channel = 'CORE';
        }

        // Add timestamp for easier grep.
        $prefix = sprintf('[TMW-FR-%s] ', $channel);
        error_log($prefix . $message);
    }
}
