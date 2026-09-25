<?php

namespace WPCareGrid\Connector\Core;

/**
 * WP-Cron schedule for the heartbeat and job polling. Intervals come from
 * WP CareGrid but never go below Settings::MIN_*.
 */
final class Scheduler
{
    public const HEARTBEAT = 'wp_caregrid_heartbeat';

    public const POLL = 'wp_caregrid_poll';

    public static function register(): void
    {
        add_filter('cron_schedules', [self::class, 'schedules']);
    }

    public static function schedules(array $schedules): array
    {
        $schedules['wp_caregrid_heartbeat'] = [
            'interval' => Settings::heartbeat_interval(),
            'display' => 'WP CareGrid heartbeat',
        ];
        $schedules['wp_caregrid_poll'] = [
            'interval' => Settings::poll_interval(),
            'display' => 'WP CareGrid job polling',
        ];

        return $schedules;
    }

    public static function schedule(): void
    {
        self::unschedule();
        wp_schedule_event(time() + 60, 'wp_caregrid_heartbeat', self::HEARTBEAT);
        wp_schedule_event(time() + 30, 'wp_caregrid_poll', self::POLL);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HEARTBEAT);
        wp_clear_scheduled_hook(self::POLL);
    }

    public static function next_poll(): ?int
    {
        $next = wp_next_scheduled(self::POLL);

        return $next ? (int) $next : null;
    }
}
