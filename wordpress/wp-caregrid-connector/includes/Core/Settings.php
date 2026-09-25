<?php

namespace WPCareGrid\Connector\Core;

/**
 * Connection state, stored in one non-autoloaded option. Holds identifiers
 * and timestamps only; the credential lives in Auth\CredentialStore.
 */
final class Settings
{
    public const OPTION = 'wp_caregrid_state';

    public const OPTIONS = [self::OPTION, 'wp_caregrid_credential', 'wp_caregrid_jobs', 'wp_caregrid_log', 'wp_caregrid_health', 'wp_caregrid_installation_id', 'wp_caregrid_poll_lock'];

    public const DISCONNECTED = 'disconnected';

    public const CONNECTED = 'connected';

    public const REVOKED = 'revoked';

    /** Server-provided intervals are never allowed below these. */
    public const MIN_HEARTBEAT = 300;

    public const MIN_POLL = 120;

    private const DEFAULTS = [
        'status' => self::DISCONNECTED,
        'website_uuid' => null,
        'connector_uuid' => null,
        'connected_at' => null,
        'last_success_at' => null,
        'last_heartbeat_at' => null,
        'last_poll_at' => null,
        'last_error' => null,
        'heartbeat_interval' => 900,
        'poll_interval' => 300,
        'capabilities' => [],
        'latest_version' => null,
    ];

    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    public static function update(array $values): void
    {
        update_option(self::OPTION, array_merge(self::all(), $values), false);
    }

    public static function is_connected(): bool
    {
        return self::get('status') === self::CONNECTED && self::get('website_uuid');
    }

    public static function heartbeat_interval(): int
    {
        return max(self::MIN_HEARTBEAT, (int) self::get('heartbeat_interval'));
    }

    public static function poll_interval(): int
    {
        return max(self::MIN_POLL, (int) self::get('poll_interval'));
    }

    /** Reset the connection, keeping the installation ID and the local job history. */
    public static function reset(string $status): void
    {
        update_option(self::OPTION, array_merge(self::DEFAULTS, ['status' => $status]), false);
    }

    public static function delete_all(): void
    {
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
    }
}
