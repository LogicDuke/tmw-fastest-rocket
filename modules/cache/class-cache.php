<?php
namespace TMWFR\Modules\Cache;

use TMWFR\Options;
use TMWFR\Logger;
use TMWFR\Utils;

if (!defined('ABSPATH')) {
    exit;
}

final class Cache {

    private const CACHE_DIR_NAME = 'tmw-fastest-rocket';
    private const DROPIN_SIGNATURE = 'TMWFR_ADVANCED_CACHE_DROPIN';

    /** @var bool */
    private static $buffering = false;

    /** @var string */
    private static $buffer = '';

    /** @var string */
    private static $cache_file = '';

    /** @var bool */
    private static $cacheable = false;

    public static function init(): void {
        // Serve cached response as early as we can (still inside WP).
        add_action('parse_request', [__CLASS__, 'maybe_serve_cache'], 0);

        // Capture output to save cache.
        add_action('template_redirect', [__CLASS__, 'maybe_start_buffer'], 0);
        add_action('shutdown', [__CLASS__, 'maybe_save_cache'], 0);

        // Purge on content updates (simple + safe for v0.4).
        add_action('save_post', [__CLASS__, 'purge_all_on_change'], 10, 2);
        add_action('deleted_post', [__CLASS__, 'purge_all_on_delete'], 10);

        // Keep config file in sync.
        add_action('update_option_tmwfr_options', [__CLASS__, 'write_config_file'], 10, 0);
    }

    public static function cache_base_dir(): string {
        return WP_CONTENT_DIR . '/cache/' . self::CACHE_DIR_NAME;
    }

    public static function config_file_path(): string {
        return self::cache_base_dir() . '/config.php';
    }

    /**
     * Serve cached HTML if possible.
     *
     * @param \WP $wp
     */
    public static function maybe_serve_cache($wp): void {
        if (is_admin() || Utils::is_cli()) {
            return;
        }

        if (!self::is_cache_enabled()) {
            return;
        }

        if (!self::is_request_cacheable()) {
            return;
        }

        $cache_file = self::cache_file_path_for_request();
        if ($cache_file === '') {
            return;
        }

        $ttl = (int) Options::instance()->get('cache.ttl', 3600);
        if ($ttl < 60) {
            $ttl = 60;
        }

        if (is_readable($cache_file)) {
            $age = time() - (int) @filemtime($cache_file);
            if ($age >= 0 && $age < $ttl) {
                // HIT
                header('X-TMWFR-Cache: HIT');
                header('Content-Type: text/html; charset=' . get_bloginfo('charset'));

                $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
                if ($method === 'HEAD') {
                    exit;
                }

                readfile($cache_file);
                exit;
            }
        }
    }

    public static function maybe_start_buffer(): void {
        if (is_admin() || Utils::is_cli()) {
            return;
        }

        if (!self::is_cache_enabled()) {
            return;
        }

        if (!self::is_request_cacheable()) {
            return;
        }

        self::$cache_file = self::cache_file_path_for_request();
        if (self::$cache_file === '') {
            return;
        }

        self::$cacheable = true;

        // Buffer output; keep page output identical.
        self::$buffer = '';
        self::$buffering = ob_start([__CLASS__, 'buffer_callback']) ? true : false;

        if (self::$buffering) {
            header('X-TMWFR-Cache: MISS');
        }
    }

    /**
     * Output buffer callback. Receives chunk and returns it unchanged.
     */
    public static function buffer_callback(string $chunk): string {
        self::$buffer .= $chunk;
        return $chunk;
    }

    public static function maybe_save_cache(): void {
        if (!self::$cacheable || !self::$buffering) {
            return;
        }

        // If another buffer was opened after ours and got closed first, ours may still be active.
        // Make sure our buffer gets flushed.
        if (ob_get_level() > 0) {
            // End ONLY if our handler is current.
            // We cannot reliably detect handler; so keep it simple and don't force-close other buffers.
        }

        self::$buffering = false;

        // Only cache successful HTML responses.
        $code = function_exists('http_response_code') ? (int) http_response_code() : 200;
        if ($code !== 200) {
            return;
        }

        // Skip if WP thinks it's a 404.
        if (function_exists('is_404') && is_404()) {
            return;
        }

        // Skip if response sets cookies (avoid caching personalized pages).
        foreach (headers_list() as $h) {
            if (stripos($h, 'set-cookie:') === 0) {
                return;
            }
        }

        $html = self::$buffer;
        if (!is_string($html) || trim($html) === '') {
            return;
        }

        // Ensure dir exists.
        $dir = dirname(self::$cache_file);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Atomic write: write to tmp then rename.
        $tmp = self::$cache_file . '.tmp';
        $written = @file_put_contents($tmp, $html, LOCK_EX);
        if ($written === false) {
            return;
        }
        @rename($tmp, self::$cache_file);

        Logger::log('Saved cache file: ' . self::$cache_file, 'CACHE');
    }

    public static function is_cache_enabled(): bool {
        // Cache module must be enabled in dashboard to run.
        return (bool) Options::instance()->is_module_enabled('cache');
    }

    private static function is_request_cacheable(): bool {
        // GET/HEAD only.
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if (!in_array($method, ['GET','HEAD'], true)) {
            return false;
        }

        // Logged-in users are never cached by default.
        if (is_user_logged_in()) {
            return false;
        }

        // Preview pages not cached.
        if (is_preview()) {
            return false;
        }

        // Bypass if query string present (safe default).
        if (Utils::has_query_string()) {
            return false;
        }

        // Bypass on certain cookies (comments, password-protected etc).
        $cookie = isset($_SERVER['HTTP_COOKIE']) ? (string) $_SERVER['HTTP_COOKIE'] : '';
        if ($cookie && preg_match('/wordpress_logged_in_|comment_author_|wp-postpass_/i', $cookie)) {
            return false;
        }

        $path = Utils::current_request_path();

        // Exclude paths configured.
        $exclude = (array) Options::instance()->get('cache.exclude_paths', []);
        foreach ($exclude as $ex) {
            $ex = (string) $ex;
            if ($ex === '') {
                continue;
            }
            if (Utils::starts_with($path, $ex)) {
                return false;
            }
        }

        return true;
    }

    private static function cache_file_path_for_request(): string {
        $host = Utils::site_host();
        $path = Utils::current_request_path();

        $mobile_sep = (bool) Options::instance()->get('cache.mobile_separate', true);
        $variant = 'all';
        if ($mobile_sep) {
            $variant = Utils::is_mobile_request() ? 'mobile' : 'desktop';
        }

        $base = self::cache_base_dir() . '/' . $host . '/' . $variant;

        // Normalize.
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path);

        if ($path === '/' || $path === '') {
            return $base . '/index.html';
        }

        // Trim trailing slash for folder path, but keep nested.
        $path = rtrim($path, '/');

        return $base . $path . '/index.html';
    }

    /* -------------------------------------------------------------------------
     * Purge + drop-in helpers
     * ---------------------------------------------------------------------- */

    public static function purge_all_on_change(int $post_id, $post): void {
        if (!self::is_cache_enabled()) {
            return;
        }

        // Ignore autosaves/revisions.
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        self::purge_all();
    }

    public static function purge_all_on_delete(int $post_id): void {
        if (!self::is_cache_enabled()) {
            return;
        }
        self::purge_all();
    }

    /**
     * Purge all cache files in our cache directory.
     *
     * @return int Number of files deleted.
     */
    public static function purge_all(): int {
        $base = self::cache_base_dir();
        if (!is_dir($base)) {
            return 0;
        }

        $deleted = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                // Keep config.php
                if (basename($file->getRealPath()) === 'config.php') {
                    continue;
                }
                if (@unlink($file->getRealPath())) {
                    $deleted++;
                }
            }
        }

        Logger::log('Purged cache (deleted ' . $deleted . ' files).', 'CACHE');
        return $deleted;
    }

    /**
     * Write config.php used by advanced-cache.php drop-in.
     */
    public static function write_config_file(): void {
        $base = self::cache_base_dir();
        if (!is_dir($base)) {
            wp_mkdir_p($base);
        }

        $opts = Options::instance();
        $data = [
            'generated_at' => gmdate('c'),
            'ttl' => (int) $opts->get('cache.ttl', 3600),
            'mobile_separate' => (bool) $opts->get('cache.mobile_separate', true),
            'exclude_paths' => (array) $opts->get('cache.exclude_paths', []),
            'enabled' => (bool) ($opts->is_module_enabled('cache') && !$opts->safe_mode()),
        ];

        $content = "<?php\n// Auto-generated by TMW Fastest Rocket\nreturn " . var_export($data, true) . ";\n";
        @file_put_contents(self::config_file_path(), $content, LOCK_EX);
    }

    /**
     * Install advanced-cache.php drop-in (does not toggle WP_CACHE).
     *
     * @return true|string True on success, or error message.
     */
    public static function install_dropin() {
        $dropin = WP_CONTENT_DIR . '/advanced-cache.php';

        if (file_exists($dropin)) {
            $existing = (string) @file_get_contents($dropin);
            if (strpos($existing, self::DROPIN_SIGNATURE) === false) {
                return 'advanced-cache.php already exists and is not managed by TMWFR.';
            }
        }

        self::write_config_file();

        $src = self::dropin_contents();
        $ok = @file_put_contents($dropin, $src, LOCK_EX);

        if ($ok === false) {
            return 'Write failed (permissions).';
        }

        return true;
    }

    /**
     * Remove our advanced-cache.php drop-in.
     *
     * @return true|string
     */
    public static function remove_dropin() {
        $dropin = WP_CONTENT_DIR . '/advanced-cache.php';
        if (!file_exists($dropin)) {
            return true;
        }

        $existing = (string) @file_get_contents($dropin);
        if (strpos($existing, self::DROPIN_SIGNATURE) === false) {
            return 'advanced-cache.php exists but does not look like a TMWFR drop-in.';
        }

        $ok = @unlink($dropin);
        if (!$ok) {
            return 'Could not delete advanced-cache.php (permissions).';
        }

        return true;
    }

    private static function dropin_contents(): string {
        $sig = self::DROPIN_SIGNATURE;
        $cache_dir = self::CACHE_DIR_NAME;

        // Keep this file self-contained (no WP functions), but WP_CONTENT_DIR is defined.
        return "<?php\n"
            . "/**\n"
            . " * {$sig}\n"
            . " * advanced-cache.php drop-in generated by TMW Fastest Rocket.\n"
            . " *\n"
            . " * Note: This runs only when WP_CACHE is true in wp-config.php.\n"
            . " */\n\n"
            . "if (defined('{$sig}_LOADED')) { return; }\n"
            . "define('{$sig}_LOADED', true);\n\n"
            . "if (!defined('ABSPATH') || !defined('WP_CONTENT_DIR')) { return; }\n\n"
            . "// Basic request gating\n"
            . "if (php_sapi_name() === 'cli') { return; }\n"
            . "\$method = isset(\$_SERVER['REQUEST_METHOD']) ? strtoupper((string)\$_SERVER['REQUEST_METHOD']) : 'GET';\n"
            . "if (\$method !== 'GET' && \$method !== 'HEAD') { return; }\n"
            . "\$uri = isset(\$_SERVER['REQUEST_URI']) ? (string)\$_SERVER['REQUEST_URI'] : '/';\n"
            . "if (\$uri === '') { return; }\n"
            . "if (strpos(\$uri, '/wp-admin') === 0 || strpos(\$uri, '/wp-json') === 0) { return; }\n"
            . "if (!empty(\$_SERVER['QUERY_STRING'])) { return; }\n"
            . "\$cookie = isset(\$_SERVER['HTTP_COOKIE']) ? (string)\$_SERVER['HTTP_COOKIE'] : '';\n"
            . "if (\$cookie && preg_match('/wordpress_logged_in_|comment_author_|wp-postpass_/i', \$cookie)) { return; }\n\n"
            . "// Load config\n"
            . "\$config_file = WP_CONTENT_DIR . '/cache/{$cache_dir}/config.php';\n"
            . "\$cfg = [];\n"
            . "if (is_readable(\$config_file)) { \$cfg = include \$config_file; }\n"
            . "\$ttl = isset(\$cfg['ttl']) ? (int)\$cfg['ttl'] : 3600;\n"
            . "if (\$ttl < 60) { \$ttl = 60; }\n"
            . "\$mobile_sep = !empty(\$cfg['mobile_separate']);\n"
            . "\$exclude_paths = isset(\$cfg['exclude_paths']) && is_array(\$cfg['exclude_paths']) ? \$cfg['exclude_paths'] : [];\n\n\$enabled = isset(\$cfg['enabled']) ? (bool)\$cfg['enabled'] : true;\nif (!\$enabled) { return; }\n\n"
            . "\$host = isset(\$_SERVER['HTTP_HOST']) ? strtolower((string)\$_SERVER['HTTP_HOST']) : 'localhost';\n"
            . "\$host = preg_replace('/[^a-z0-9\\.\\-]/', '', \$host);\n"
            . "if (!\$host) { \$host = 'localhost'; }\n\n"
            . "\$path = parse_url(\$uri, PHP_URL_PATH);\n"
            . "\$path = is_string(\$path) && \$path !== '' ? \$path : '/';\n"
            . "\$path = '/' . ltrim(\$path, '/');\n"
            . "\$path = preg_replace('#/+#', '/', \$path);\n\n"
            . "foreach (\$exclude_paths as \$ex) {\n"
            . "  \$ex = (string)\$ex;\n"
            . "  if (\$ex !== '' && strpos(\$path, \$ex) === 0) { return; }\n"
            . "}\n\n"
            . "\$variant = 'all';\n"
            . "if (\$mobile_sep) {\n"
            . "  \$ua = isset(\$_SERVER['HTTP_USER_AGENT']) ? (string)\$_SERVER['HTTP_USER_AGENT'] : '';\n"
            . "  \$is_mobile = (bool)preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', \$ua);\n"
            . "  \$variant = \$is_mobile ? 'mobile' : 'desktop';\n"
            . "}\n\n"
            . "\$base = WP_CONTENT_DIR . '/cache/{$cache_dir}/' . \$host . '/' . \$variant;\n"
            . "if (\$path === '/' || \$path === '') {\n"
            . "  \$cache_file = \$base . '/index.html';\n"
            . "} else {\n"
            . "  \$path = rtrim(\$path, '/');\n"
            . "  \$cache_file = \$base . \$path . '/index.html';\n"
            . "}\n\n"
            . "if (is_readable(\$cache_file)) {\n"
            . "  \$age = time() - (int)@filemtime(\$cache_file);\n"
            . "  if (\$age >= 0 && \$age < \$ttl) {\n"
            . "    header('X-TMWFR-Cache: HIT (drop-in)');\n"
            . "    if (\$method === 'HEAD') { exit; }\n"
            . "    readfile(\$cache_file);\n"
            . "    exit;\n"
            . "  }\n"
            . "}\n\n"
            . "header('X-TMWFR-Cache: MISS (drop-in)');\n\n// Miss: allow WordPress to run normally.\n";
    }
}
