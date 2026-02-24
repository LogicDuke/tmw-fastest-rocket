<?php
namespace TMWFR;

if (!defined('ABSPATH')) {
    exit;
}

final class TMW_Performance_Advisor {

    /**
     * @var array<string,string>
     */
    private static $audit_to_module_map = [
        'uses-long-cache-ttl' => 'Cache Module',
        'render-blocking-resources' => 'JS Delay Module',
        'unused-javascript' => 'Delay Third Party',
        'preload-lcp-image' => 'Media Module',
    ];

    public static function is_available(): bool {
        return class_exists('\\TMW\\SEO\\Lighthouse\\Advisor');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_lighthouse_issues(string $strategy = 'mobile'): array {
        if (!self::is_available()) {
            return [];
        }

        $issues = \TMW\SEO\Lighthouse\Advisor::get_systemic_issues($strategy);
        if (!is_array($issues)) {
            return [];
        }

        $normalized = [];
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $audit_id = isset($issue['audit_id']) ? sanitize_key((string) $issue['audit_id']) : '';
            if ($audit_id === '' && isset($issue['id'])) {
                $audit_id = sanitize_key((string) $issue['id']);
            }

            if ($audit_id === '') {
                continue;
            }

            $affected = 0;
            if (isset($issue['affected_urls'])) {
                if (is_numeric($issue['affected_urls'])) {
                    $affected = absint($issue['affected_urls']);
                } elseif (is_array($issue['affected_urls'])) {
                    $affected = count($issue['affected_urls']);
                }
            } elseif (isset($issue['affected'])) {
                $affected = absint((int) $issue['affected']);
            } elseif (isset($issue['count'])) {
                $affected = absint((int) $issue['count']);
            }

            $normalized[] = [
                'audit_id' => $audit_id,
                'affected_urls' => $affected,
                'suggested_module' => self::get_suggested_module($audit_id),
            ];
        }

        usort(
            $normalized,
            static function (array $a, array $b): int {
                return $b['affected_urls'] <=> $a['affected_urls'];
            }
        );

        return $normalized;
    }

    public static function get_suggested_module(string $audit_id): string {
        return self::$audit_to_module_map[$audit_id] ?? '';
    }
}
