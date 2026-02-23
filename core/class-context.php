<?php
namespace TMWFR;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight render context tracker.
 *
 * Used to apply safe image attribute tweaks inside known shortcodes/templates.
 */
final class Context {

    /** @var array<int,string> */
    private static $stack = [];

    public static function push(string $name): void {
        $name = strtolower(trim($name));
        if ($name === '') {
            return;
        }
        self::$stack[] = $name;
    }

    public static function pop(string $name): void {
        $name = strtolower(trim($name));
        if ($name === '' || empty(self::$stack)) {
            return;
        }

        // Pop the most recent matching name (stack behavior).
        for ($i = count(self::$stack) - 1; $i >= 0; $i--) {
            if (self::$stack[$i] === $name) {
                array_splice(self::$stack, $i, 1);
                return;
            }
        }
    }

    public static function has(string $name): bool {
        $name = strtolower(trim($name));
        if ($name === '') {
            return false;
        }
        return in_array($name, self::$stack, true);
    }

    /** @return array<int,string> */
    public static function stack(): array {
        return self::$stack;
    }
}
