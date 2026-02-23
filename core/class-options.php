<?php
namespace TMWFR;

if (!defined('ABSPATH')) {
    exit;
}

final class Options {

    private const OPTION_KEY = 'tmwfr_options';

    /** @var self|null */
    private static $instance = null;

    /** @var array<string,mixed> */
    private $options = [];

    public static function instance(): self {
        if (self::$instance instanceof self) {
            return self::$instance;
        }
        self::$instance = new self();
        self::$instance->load();
        return self::$instance;
    }

    /**
     * Default options.
     *
     * IMPORTANT: All modules default to disabled for safety.
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array {
        return [
            'safe_mode' => false,

            'modules' => [
                'html'    => ['enabled' => false],
                'media'   => ['enabled' => false],
                'preload' => ['enabled' => false],
                'js'      => ['enabled' => false],
                'cache'   => ['enabled' => false],
            ],

            'html' => [
                'remove_emoji' => true,
                'remove_embeds' => true,
                'dequeue_dashicons_for_guests' => true,
            ],

            'media' => [
                // Promote first N content images per request (eager + fetchpriority=high).
                'promote_first_images_count' => 1,

                // If inside [tmw_home_categories] output, enforce a conservative sizes attr to avoid 768px downloads.
                'home_categories_sizes' => '(max-width: 600px) 50vw, 240px',
            ],

            'preload' => [
                // Preload first post thumbnail on category archives (helps LCP if LCP is a thumb).
                'category_preload_first_thumb' => true,

                // Optional: preconnect to common third-party domains (OFF by default).
                'preconnect_third_party' => false,
            ],

            'js' => [
                'mode' => 'safe', // safe|balanced|advanced
                'overlap_allowed' => false, // If the theme already delays scripts, we do NOT overlap by default.

                // If enabled, optionally dequeue videojs assets on non-video pages.
                'dequeue_videojs_non_video_pages' => false,

                // Whitelist handles to add defer (safe mode).
                'defer_handles' => [
                    'jquery-bxslider',
                    'bxslider',
                    'jquery-fancybox',
                    'fancybox',
                    'jquery-touchSwipe',
                    'jquery-touchswipe',
                    'cookie-consent',
                ],

                // Third-party hosts to delay (balanced/advanced).
                'delay_hosts' => [
                    'www.googletagmanager.com',
                    'pagead2.googlesyndication.com',
                    'pagead2.g.doubleclick.net',
                    'connect.facebook.net',
                    'static.cloudflareinsights.com',
                ],
            ],

            'cache' => [
                'mode' => 'standard', // standard|dropin
                'ttl' => 3600, // seconds

                'mobile_separate' => true,

                // GET only, no query string, no logged-in cookies.
                'exclude_paths' => [
                    '/wp-admin',
                    '/wp-login.php',
                    '/wp-json',
                    '/xmlrpc.php',
                    '/cart',
                    '/checkout',
                    '/my-account',
                ],
            ],
        ];
    }

    private function load(): void {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $this->options = self::merge_recursive(self::defaults(), $stored);
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function merge_recursive(array $base, array $over): array {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = self::merge_recursive($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /** @return array<string,mixed> */
    public function all(): array {
        return $this->options;
    }

    /** @param array<string,mixed> $new */
    public function update_all(array $new): void {
        // Merge into defaults to avoid missing keys.
        $merged = self::merge_recursive(self::defaults(), $new);
        $this->options = $merged;
        update_option(self::OPTION_KEY, $merged, false);
    }

    /**
     * Get a nested option like "modules.cache.enabled".
     *
     * @param string $path
     * @param mixed $default
     * @return mixed
     */
    public function get(string $path, $default = null) {
        $parts = explode('.', $path);
        $value = $this->options;

        foreach ($parts as $p) {
            if (!is_array($value) || !array_key_exists($p, $value)) {
                return $default;
            }
            $value = $value[$p];
        }

        return $value;
    }

    public function is_module_enabled(string $module): bool {
        return (bool) $this->get('modules.' . $module . '.enabled', false);
    }

    public function safe_mode(): bool {
        return (bool) $this->get('safe_mode', false);
    }

    /**
     * Set a nested option path.
     *
     * @param string $path
     * @param mixed $value
     */
    public function set(string $path, $value): void {
        $parts = explode('.', $path);
        $ref =& $this->options;

        foreach ($parts as $p) {
            if (!is_array($ref)) {
                $ref = [];
            }
            if (!array_key_exists($p, $ref)) {
                $ref[$p] = [];
            }
            $ref =& $ref[$p];
        }

        $ref = $value;
        update_option(self::OPTION_KEY, $this->options, false);
    }
}
