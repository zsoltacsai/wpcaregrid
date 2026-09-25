<?php

namespace WPCareGrid\Connector\Security;

/**
 * Structured log: the last 100 entries in a non-autoloaded option (shown to
 * administrators) and, with WP_DEBUG_LOG, the PHP error log. Every entry is
 * redacted; credentials and pairing codes never reach a log.
 */
final class Logger
{
    private const OPTION = 'wp_caregrid_log';

    private const KEEP = 100;

    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    public static function entries(): array
    {
        $entries = get_option(self::OPTION, []);

        return is_array($entries) ? $entries : [];
    }

    private static function write(string $level, string $event, array $context): void
    {
        $entry = [
            'time' => gmdate('c'),
            'level' => $level,
            'event' => preg_replace('/[^a-z0-9_.]/', '', strtolower($event)),
            'context' => Redactor::redact($context),
        ];

        $entries = self::entries();
        $entries[] = $entry;
        update_option(self::OPTION, array_slice($entries, -self::KEEP), false);

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('wp-caregrid '.wp_json_encode($entry)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        }
    }
}
