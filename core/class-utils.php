<?php
namespace TMWFR;

if (!defined('ABSPATH')) {
    exit;
}

final class Utils {

    public static function is_cli(): bool {
        return (defined('WP_CLI') && WP_CLI) || (php_sapi_name() === 'cli');
    }

    public static function current_request_path(): string {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        $path = '/' . ltrim($path, '/');
        // Collapse duplicate slashes.
        $path = preg_replace('#/+#', '/', $path);
        return $path ?: '/';
    }

    public static function has_query_string(): bool {
        $qs = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        return trim($qs) !== '';
    }

    public static function is_https(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return false;
    }

    public static function site_host(): string {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $host = strtolower($host);
        // Very defensive: keep only host-safe chars.
        $host = preg_replace('/[^a-z0-9\.\-]/', '', $host);
        return $host ?: 'localhost';
    }

    public static function is_mobile_request(): bool {
        // Use WP helper if available.
        if (function_exists('wp_is_mobile')) {
            return (bool) wp_is_mobile();
        }

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if ($ua === '') {
            return false;
        }

        return (bool) preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua);
    }

    public static function starts_with(string $haystack, string $needle): bool {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, 0, strlen($needle)) === $needle;
    }

    public static function tail_file(string $file, int $lines = 200): string {
        if ($lines < 1) {
            $lines = 1;
        }

        if (!is_readable($file)) {
            return '';
        }

        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return '';
        }

        $buffer = '';
        $chunkSize = 4096;
        $pos = -1;
        $lineCount = 0;

        fseek($fh, 0, SEEK_END);
        $fileSize = ftell($fh);
        if ($fileSize === 0) {
            fclose($fh);
            return '';
        }

        while ($lineCount < $lines && -$pos < $fileSize) {
            $seek = max(-$chunkSize, -$pos - $chunkSize);
            fseek($fh, $seek, SEEK_END);
            $chunk = fread($fh, $chunkSize);
            if ($chunk === false) {
                break;
            }
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
            $pos += $chunkSize;
        }

        fclose($fh);

        // Return last N lines.
        $allLines = preg_split("/\r\n|\n|\r/", $buffer);
        if (!is_array($allLines)) {
            return $buffer;
        }
        $slice = array_slice($allLines, -$lines);
        return implode("\n", $slice);
    }
}
