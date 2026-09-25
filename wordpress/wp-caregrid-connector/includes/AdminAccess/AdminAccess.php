<?php

namespace WPCareGrid\Connector\AdminAccess;

use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Security\Logger;

/**
 * Temporary WordPress sessions for WP CareGrid, one per access level.
 *
 * A session is only created inside the customer's own browser request to
 * wp-login.php (Landing), after WP CareGrid confirmed the one-time token.
 * It is a normal WordPress session (wp_set_auth_cookie + session tokens),
 * limited to the authorised minutes, and it ends — role removed, all
 * sessions of the account destroyed — when:
 *   - it expires (cron event, the next request of that user, the next poll);
 *   - WP CareGrid no longer lists it (revoked there, or connector revoked);
 *   - it is revoked on this site (Settings → WP CareGrid);
 *   - the connector is disconnected, revoked or deactivated.
 */
final class AdminAccess
{
    private const OPTION = 'wp_caregrid_admin_access';

    public const CRON = 'wp_caregrid_admin_access_expire';

    /** Hard caps on this site, whatever the server says (minutes). */
    public const MAX_MINUTES = ['maintenance' => 60, 'full_admin' => 30];

    private static ?Client $api = null;

    public static function use_api(Client $api): void
    {
        self::$api = $api;
    }

    /** Where the browser posts the one-time token. Reported to WP CareGrid. */
    public static function login_url(): string
    {
        return add_query_arg('action', 'wp_caregrid_access', wp_login_url());
    }

    /** @return array<string, array{request_uuid: string, user_id: int, expires_at: int, started_at: int}> */
    public static function state(): array
    {
        $state = get_option(self::OPTION, []);

        return is_array($state) ? $state : [];
    }

    /** Create the authorised session for the current browser. */
    public static function start(string $request_uuid, string $level, int $expires_at): void
    {
        $expires_at = min($expires_at, time() + self::MAX_MINUTES[$level] * MINUTE_IN_SECONDS);
        if ($expires_at <= time()) {
            throw new AccessFailure('ACCESS_EXPIRED', 'This access has already expired.');
        }

        Accounts::ensure_role();
        $user = Accounts::user($level);

        // One session per level: whatever was open for this account ends now.
        if (isset(self::state()[$level])) {
            self::end($level, 'superseded', false);
        }
        Accounts::strip($user);
        $user->set_role(Accounts::role_for($level));

        // A fresh session: drop whatever auth cookies the browser brought (session fixation).
        wp_clear_auth_cookie();

        $lifetime = static fn () => $expires_at - time();
        $describe = static function (array $session) use ($request_uuid) {
            $session['wp_caregrid_request'] = $request_uuid;

            return $session;
        };
        add_filter('auth_cookie_expiration', $lifetime, PHP_INT_MAX);
        add_filter('attach_session_information', $describe);
        try {
            wp_set_auth_cookie($user->ID, false, is_ssl());
        } finally {
            remove_filter('auth_cookie_expiration', $lifetime, PHP_INT_MAX);
            remove_filter('attach_session_information', $describe);
        }
        wp_set_current_user($user->ID);

        $state = self::state();
        $state[$level] = ['request_uuid' => $request_uuid, 'user_id' => $user->ID, 'expires_at' => $expires_at, 'started_at' => time()];
        update_option(self::OPTION, $state, false);

        wp_clear_scheduled_hook(self::CRON, [$level]);
        wp_schedule_single_event($expires_at + 1, self::CRON, [$level]);

        Logger::info('admin_access.started', ['request' => $request_uuid, 'level' => $level, 'expires_at' => gmdate('c', $expires_at)]);
    }

    /** End one level's access: no role, no sessions, no state. */
    public static function end(string $level, string $reason, bool $notify = true): void
    {
        $state = self::state();
        $entry = $state[$level] ?? null;

        if ($user = Accounts::find($level)) {
            Accounts::strip($user);
        }

        unset($state[$level]);
        update_option(self::OPTION, $state, false);
        wp_clear_scheduled_hook(self::CRON, [$level]);

        if ($entry) {
            Logger::info('admin_access.ended', ['request' => $entry['request_uuid'], 'level' => $level, 'reason' => $reason]);

            if ($notify && self::$api && Settings::is_connected()) {
                self::$api->post('admin-access/'.$entry['request_uuid'].'/ended', ['reason' => $reason]);
            }
        }
    }

    public static function end_all(string $reason, bool $notify = true): void
    {
        foreach (array_keys(Accounts::USERS) as $level) {
            self::end($level, $reason, $notify);
        }
    }

    /** Cron and every poll: end what has expired. */
    public static function expire_due(): void
    {
        foreach (self::state() as $level => $entry) {
            if ((int) $entry['expires_at'] <= time()) {
                self::end($level, 'expired');
            }
        }
    }

    /**
     * WP CareGrid's list of access that may continue (poll and heartbeat
     * responses). Anything else is ended here; nothing is started.
     *
     * @param  array<int, array>  $live
     */
    public static function reconcile(array $live): void
    {
        $allowed = [];
        foreach ($live as $item) {
            if (is_array($item) && isset($item['request_uuid'], $item['access_level'])) {
                $allowed[(string) $item['access_level']] = (string) $item['request_uuid'];
            }
        }

        foreach (self::state() as $level => $entry) {
            if (($allowed[$level] ?? null) !== $entry['request_uuid']) {
                self::end($level, 'revoked', false);
            }
        }

        self::expire_due();
    }

    /**
     * On every request of a signed-in WP CareGrid account: the access must
     * still be current, or the user is signed out at once.
     */
    public static function guard(): void
    {
        $user_id = get_current_user_id();
        if (! $user_id || ! Accounts::is_managed($user_id)) {
            return;
        }

        $level = (string) get_user_meta($user_id, Accounts::META, true);
        $entry = self::state()[$level] ?? null;

        if (Settings::is_connected() && $entry && (int) $entry['user_id'] === $user_id && (int) $entry['expires_at'] > time()) {
            return;
        }

        self::end($level, $entry && (int) $entry['expires_at'] <= time() ? 'expired' : 'revoked', (bool) $entry);
        wp_clear_auth_cookie();
        wp_set_current_user(0);
        Landing::refuse(__('Your WP CareGrid access has ended. Open WordPress admin again from your WP CareGrid dashboard.', 'wp-caregrid'), 403);
    }
}
