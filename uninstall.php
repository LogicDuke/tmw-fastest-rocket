<?php
/**
 * Uninstall TMW Fastest Rocket
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$opt_key = 'tmwfr_options';
delete_option($opt_key);
if (is_multisite()) {
    delete_site_option($opt_key);
}

// Best-effort: remove drop-in if it's ours.
$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
if (file_exists($dropin)) {
    $contents = (string) @file_get_contents($dropin);
    if (strpos($contents, 'TMWFR_ADVANCED_CACHE_DROPIN') !== false) {
        @unlink($dropin);
    }
}

// Best-effort: purge cache directory.
$base = WP_CONTENT_DIR . '/cache/tmw-fastest-rocket';
if (is_dir($base)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($base);
}
