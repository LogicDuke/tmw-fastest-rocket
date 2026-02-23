<?php
namespace TMWFR\Modules\HTML;

use TMWFR\Options;
use TMWFR\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class HTML {

    public static function init(): void {
        add_action('init', [__CLASS__, 'maybe_remove_emoji'], 1);
        add_action('init', [__CLASS__, 'maybe_remove_embeds'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_dequeue_dashicons'], 100);
    }

    public static function maybe_remove_emoji(): void {
        $opts = Options::instance();
        if (!$opts->get('html.remove_emoji', true)) {
            return;
        }

        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

        Logger::log('Emoji assets removed.', 'HTML');
    }

    public static function maybe_remove_embeds(): void {
        $opts = Options::instance();
        if (!$opts->get('html.remove_embeds', true)) {
            return;
        }

        // Remove wp-embed script output. Theme may already deregister it.
        wp_deregister_script('wp-embed');

        Logger::log('wp-embed script deregistered.', 'HTML');
    }

    public static function maybe_dequeue_dashicons(): void {
        $opts = Options::instance();
        if (!$opts->get('html.dequeue_dashicons_for_guests', true)) {
            return;
        }

        if (is_user_logged_in()) {
            return;
        }

        if (wp_style_is('dashicons', 'enqueued')) {
            wp_dequeue_style('dashicons');
            wp_deregister_style('dashicons');
            Logger::log('Dashicons dequeued for guests.', 'HTML');
        }
    }
}
