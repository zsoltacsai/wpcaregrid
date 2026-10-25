<?php

namespace WPCareGrid\Connector\Core;

/**
 * One poll at a time per site. The option value contains a fixed-width lease
 * expiry and a random owner token. Changes use conditional SQL so an expired
 * lease can be reclaimed by only one contender.
 */
final class Lock
{
    private const OPTION = 'wp_caregrid_poll_lock';

    public const TTL = 900;

    private static ?string $ownedValue = null;

    private static bool $leaseRequired = false;

    private static int $refreshedAt = 0;

    public static function acquire(): bool
    {
        global $wpdb;

        if (self::$ownedValue !== null || ! isset($wpdb->options)) {
            return false;
        }

        $now = time();
        $candidate = self::newValue($now);

        // WordPress add_option() uses ON DUPLICATE KEY UPDATE on current core,
        // which can overwrite a concurrent contender and report success to both.
        // Use an insert-ignore primitive and trust only the affected-row count.
        $insertVerb = $wpdb->is_mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $insert = $wpdb->prepare(
            "{$insertVerb} INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            self::OPTION,
            $candidate,
            'no'
        );
        $inserted = $wpdb->query($insert);
        if ($inserted === 1) {
            self::forgetOptionCache();
            self::own($candidate, $now);

            return true;
        }
        if ($inserted !== 0) {
            return false;
        }

        // Bypass WordPress's per-process option cache: another poller may have
        // inserted or replaced this row after this process populated notoptions.
        $observed = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::OPTION
        ));
        if (! is_string($observed) && ! is_int($observed)) {
            return false;
        }

        $observed = (string) $observed;
        $lease = self::lease($observed);
        if ($lease === null || $lease['expires_at'] > $now) {
            return false;
        }

        // The expiry predicate and exact observed value are checked by the same
        // UPDATE that changes ownership. A racing contender then affects zero rows.
        $expiryPredicate = $lease['legacy']
            ? 'option_value <= %s'
            : 'SUBSTR(option_value, 1, 10) <= %s';
        $expiryBoundary = sprintf('%010d', $lease['legacy'] ? $now - self::TTL : $now);
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s AND {$expiryPredicate}",
            $candidate,
            self::OPTION,
            $observed,
            $expiryBoundary
        );
        $updated = $wpdb->query($sql);

        if ($updated !== 1) {
            return false;
        }

        self::forgetOptionCache();
        self::own($candidate, $now);

        return true;
    }

    /** Called during long work; only the current owner may extend an unexpired lease. */
    public static function refresh(): bool
    {
        global $wpdb;

        $now = time();
        if (self::$ownedValue === null || ! isset($wpdb->options)) {
            return false;
        }

        $lease = self::lease(self::$ownedValue);
        if ($lease === null || $lease['legacy']) {
            self::forgetOwnership();

            return false;
        }

        if ($lease['expires_at'] <= $now) {
            self::forgetOwnership();

            return false;
        }

        if ($now - self::$refreshedAt < 60) {
            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                self::OPTION
            ));
            if (! is_string($current) || ! hash_equals(self::$ownedValue, $current)) {
                self::forgetOwnership();

                return false;
            }

            return true;
        }

        $refreshed = sprintf('%010d:%s', $now + self::TTL, $lease['owner_token']);
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s AND SUBSTR(option_value, 1, 10) > %s",
            $refreshed,
            self::OPTION,
            self::$ownedValue,
            sprintf('%010d', $now)
        );

        if ($wpdb->query($sql) !== 1) {
            self::forgetOwnership();

            return false;
        }

        self::forgetOptionCache();
        self::own($refreshed, $now);

        return true;
    }

    /**
     * Refresh a lease during work and stop immediately if this poll lost it.
     * Direct component tests may call backup helpers outside JobRunner; in
     * that unmanaged context there is no lease to refresh.
     *
     * @throws LeaseLost when an owned lease expired or was replaced.
     */
    public static function refreshOrFail(): void
    {
        if (! self::$leaseRequired) {
            return;
        }

        if (self::$ownedValue === null || ! self::refresh()) {
            throw new LeaseLost('The Connector poll lease is no longer owned by this worker.');
        }
    }

    /** Release is conditional: an expired owner cannot delete its successor's lease. */
    public static function release(): bool
    {
        global $wpdb;

        $ownedValue = self::$ownedValue;
        self::forgetOwnership();
        self::$leaseRequired = false;
        if ($ownedValue === null || ! isset($wpdb->options)) {
            return false;
        }

        $deleted = $wpdb->delete(
            $wpdb->options,
            ['option_name' => self::OPTION, 'option_value' => $ownedValue],
            ['%s', '%s']
        );
        if ($deleted === 1) {
            self::forgetOptionCache();

            return true;
        }

        return false;
    }

    /** Read-only snapshot for diagnostics/tests; the token is intentionally omitted. */
    public static function inspect(): ?array
    {
        global $wpdb;
        if (! isset($wpdb->options)) {
            return null;
        }
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::OPTION
        ));
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $lease = self::lease((string) $value);
        if ($lease === null) {
            return null;
        }

        return ['expires_at' => $lease['expires_at'], 'legacy' => $lease['legacy']];
    }

    private static function newValue(int $now): string
    {
        return sprintf('%010d:%s', $now + self::TTL, bin2hex(random_bytes(32)));
    }

    /** @return array{expires_at: int, owner_token: ?string, legacy: bool}|null */
    private static function lease(string $value): ?array
    {
        if (preg_match('/^(\d{10}):([a-f0-9]{64})$/D', $value, $matches) === 1) {
            return ['expires_at' => (int) $matches[1], 'owner_token' => $matches[2], 'legacy' => false];
        }

        // Compatibility for a lock written by the prior timestamp-only version.
        if (preg_match('/^\d{10}$/D', $value) === 1) {
            return ['expires_at' => (int) $value + self::TTL, 'owner_token' => null, 'legacy' => true];
        }

        return null;
    }

    private static function own(string $value, int $now): void
    {
        self::$ownedValue = $value;
        self::$leaseRequired = true;
        self::$refreshedAt = $now;
    }

    private static function forgetOwnership(): void
    {
        self::$ownedValue = null;
        self::$refreshedAt = 0;
    }

    private static function forgetOptionCache(): void
    {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
