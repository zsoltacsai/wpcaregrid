<?php

namespace WPCareGrid\Connector\Backups;

use WPCareGrid\Connector\Core\LeaseLost;

/** Resolves the optional host gate and fails closed when destructive isolation is unavailable. */
final class RestoreIsolationGate
{
    public static function acquire(string $restore_uuid, string $scope, ?RestoreIsolationLease $previous = null): RestoreIsolationLease
    {
        $provider = self::provider();
        if ($provider === null) {
            throw new BackupFailure('RESTORE_ISOLATION_UNAVAILABLE', 'This host does not provide the required restore isolation gate. No live data was changed.');
        }

        try {
            $lease = new RestoreIsolationLease($provider->provider_id(), $restore_uuid, $scope, bin2hex(random_bytes(32)));
        } catch (\Throwable) {
            throw new BackupFailure('RESTORE_ISOLATION_UNAVAILABLE', 'The host restore isolation provider is invalid. No live data was changed.');
        }

        if ($previous !== null && $previous->provider !== $lease->provider) {
            throw new BackupFailure('RESTORE_ISOLATION_UNAVAILABLE', 'The prior restore gate belongs to a different provider. Operator recovery is required.');
        }

        try {
            $acquired = $provider->acquire($lease, $previous);
            if (! ($acquired instanceof RestoreIsolationLease)
                || $acquired->provider !== $lease->provider
                || $acquired->restore_uuid !== $lease->restore_uuid
                || $acquired->scope !== $lease->scope
                || ! $provider->assert_active($acquired)
                || ($previous !== null && ! $provider->assert_active($previous))) {
                throw new BackupFailure('RESTORE_ISOLATION_UNAVAILABLE', 'The host could not establish and verify writer isolation. No live data was changed.');
            }
            $lease = $acquired;
        } catch (BackupFailure $error) {
            throw $error;
        } catch (\Throwable) {
            throw new BackupFailure('RESTORE_ISOLATION_UNAVAILABLE', 'The host could not establish and verify writer isolation. No live data was changed.');
        }

        return $lease;
    }

    public static function assert_active(RestoreIsolationLease $lease): void
    {
        $provider = self::provider();
        try {
            $active = $provider !== null
                && $provider->provider_id() === $lease->provider
                && $provider->assert_active($lease);
        } catch (\Throwable) {
            $active = false;
        }
        if (! $active) {
            throw new LeaseLost('The host restore isolation gate was lost.');
        }
    }

    public static function release(RestoreIsolationLease $lease): bool
    {
        $provider = self::provider();

        try {
            return $provider !== null
                && $provider->provider_id() === $lease->provider
                && $provider->release($lease);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function commit(RestoreIsolationLease $current, RestoreIsolationLease $previous): bool
    {
        $provider = self::provider();

        try {
            return $provider !== null
                && $provider->provider_id() === $current->provider
                && $current->provider === $previous->provider
                && $provider->assert_active($current)
                && $provider->commit($current, $previous);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function provider(): ?RestoreIsolationGateProvider
    {
        if (! function_exists('apply_filters')) {
            return null;
        }

        $provider = apply_filters('wp_caregrid_restore_isolation_gate', null);

        return $provider instanceof RestoreIsolationGateProvider ? $provider : null;
    }
}
