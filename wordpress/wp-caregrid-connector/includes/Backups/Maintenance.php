<?php

namespace WPCareGrid\Connector\Backups;

use WPCareGrid\Connector\Jobs\JobStore;

/** Persistent, fail-closed WordPress maintenance protection for restore recovery. */
final class Maintenance
{
    private static ?string $marker = null;

    public static function file(): string
    {
        return ABSPATH.'.maintenance';
    }

    /**
     * Start maintenance, or adopt this Connector's unresolved restore gate for
     * an explicitly delivered recovery restore whose scope covers the old one.
     */
    public static function enable(string $restore_uuid, JobStore $jobs): void
    {
        self::$marker = TempDir::ensure().'/maintenance.json';
        $existing = self::read_marker();

        if (is_file(self::file()) || is_file(self::marker())) {
            if ($existing === null || ! self::file_matches_marker($existing)) {
                throw new BackupFailure('MAINTENANCE_ACTIVE', 'The site is already in maintenance mode.');
            }

            $oldUuid = (string) ($existing['restore'] ?? '');
            $oldState = $jobs->restore_maintenance_state($oldUuid);
            $currentState = $jobs->restore_maintenance_state($restore_uuid);
            $oldScope = $existing['required_scope'] ?? $jobs->restore_scope($oldUuid);
            $newScope = $jobs->restore_scope($restore_uuid);
            if (! in_array($oldState, ['recovery_required', 'unknown'], true)
                || $currentState !== 'pre_mutation_running'
                || ! self::scope_covers($newScope, $oldScope)) {
                throw new BackupFailure('MAINTENANCE_ACTIVE', 'An unresolved restore requires a validated recovery operation.');
            }

            $existing['recovery_for'] = $existing['recovery_for'] ?? $oldUuid;
            $existing['restore'] = $restore_uuid;
            $existing['required_scope'] = $newScope;
            $existing['refreshed_at'] = time();
            $existing['version'] = 2;
            $legacy = ! is_string($existing['token'] ?? null);
            $existing['token'] = is_string($existing['token'] ?? null) ? $existing['token'] : bin2hex(random_bytes(16));
            if ($legacy) {
                $existing['legacy_compat'] = true;
            }
            if (! self::write_marker($existing) || ($legacy && ! self::write_maintenance_file($existing))) {
                throw new BackupFailure('MAINTENANCE_FAILED', 'The existing restore protection could not be safely adopted.');
            }
            if ($legacy) {
                unset($existing['legacy_compat']);
                self::write_marker($existing);
            }
            return;
        }

        $scope = $jobs->restore_scope($restore_uuid);
        if ($scope === null || $jobs->restore_maintenance_state($restore_uuid) !== 'pre_mutation_running') {
            throw new BackupFailure('MAINTENANCE_FAILED', 'The restore state could not be verified before maintenance mode.');
        }

        $marker = [
            'version' => 2,
            'restore' => $restore_uuid,
            'recovery_for' => null,
            'required_scope' => $scope,
            'since' => time(),
            'refreshed_at' => time(),
            'token' => bin2hex(random_bytes(16)),
        ];
        if (! self::write_marker($marker)) {
            throw new BackupFailure('MAINTENANCE_FAILED', 'Maintenance mode could not be enabled.');
        }
        if (! self::write_maintenance_file($marker)) {
            @unlink(self::marker());
            throw new BackupFailure('MAINTENANCE_FAILED', 'Maintenance mode could not be enabled.');
        }

    }

    /** Release only after durable proof that mutation never began or recovery succeeded. */
    private static function disable(string $restore_uuid, JobStore $jobs): void
    {
        $marker = self::read_marker();
        if ($marker === null || ($marker['restore'] ?? null) !== $restore_uuid) {
            return;
        }

        $state = $jobs->restore_maintenance_state($restore_uuid);
        $recoveringPriorState = is_string($marker['recovery_for'] ?? null);
        if ($state !== 'completed_success' && ! ($state === 'safe_to_release' && ! $recoveringPriorState)) {
            return;
        }
        if (is_file(self::file()) && ! self::file_matches_marker($marker)) {
            return;
        }

        if (is_file(self::file())) {
            @unlink(self::file());
        }
        @unlink(self::marker());
        self::$marker = null;
    }

    public static function is_ours(): bool
    {
        $marker = self::read_marker();

        return $marker !== null && self::file_matches_marker($marker);
    }

    /**
     * Reconcile only from durable restore state. Running, destructive, or
     * ambiguous restores keep (and refresh) the maintenance gate.
     */
    public static function cleanup_stale(JobStore $jobs): void
    {
        $marker = self::read_marker();
        if ($marker === null) {
            return;
        }

        $restore = $marker['restore'] ?? null;
        if (! is_string($restore) || ! preg_match('/^[0-9a-f-]{36}$/i', $restore)) {
            return;
        }

        $state = $jobs->restore_maintenance_state($restore);
        $recoveringPriorState = is_string($marker['recovery_for'] ?? null);
        $mayRelease = $state === 'completed_success'
            || ($state === 'safe_to_release' && ! $recoveringPriorState);

        if ($mayRelease) {
            self::disable($restore, $jobs);

            return;
        }

        // If the WordPress marker disappeared or aged out, recreate/refresh
        // only when it is absent or still exactly ours. Never overwrite a
        // different component's .maintenance file.
        if (is_file(self::file()) && ! self::file_matches_marker($marker)) {
            return;
        }
        if (is_string($marker['token'] ?? null) && self::file_matches_marker($marker)) {
            return;
        }

        $marker['refreshed_at'] = time();
        $marker['version'] = 2;
        $marker['legacy_compat'] = true;
        $marker['token'] = is_string($marker['token'] ?? null) ? $marker['token'] : bin2hex(random_bytes(16));
        if (! self::write_marker($marker) || ! self::write_maintenance_file($marker)) {
            return;
        }
        unset($marker['legacy_compat']);
        self::write_marker($marker);
    }

    /** Clear process-local path state only; never touch durable protection. */
    public static function preserve(): void
    {
        self::$marker = null;
    }

    private static function scope_covers(?string $newScope, ?string $oldScope): bool
    {
        $scopes = ['files' => ['files'], 'database' => ['database'], 'full' => ['files', 'database']];

        return $newScope !== null && $oldScope !== null
            && array_diff($scopes[$oldScope] ?? [], $scopes[$newScope] ?? []) === [];
    }

    private static function write_marker(array $marker): bool
    {
        $path = self::marker();
        $json = wp_json_encode($marker);
        if (! is_string($json)) {
            return false;
        }
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    private static function write_maintenance_file(array $marker): bool
    {
        // WordPress ignores maintenance files older than ten minutes. A far
        // future timestamp keeps a confirmed recovery gate active even if the
        // Connector is offline; only the state-aware release path removes it.
        $timestamp = 2147483647;
        $token = $marker['token'] ?? null;
        $content = '<?php $upgrading = '.$timestamp.';'.(is_string($token) ? ' /* wp-caregrid:'.$token.' */' : '');

        $handle = @fopen(self::file(), 'x');
        if ($handle === false) {
            $handle = @fopen(self::file(), 'r+');
            if ($handle === false || ! @flock($handle, LOCK_EX)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }

                return false;
            }
            rewind($handle);
            $current = stream_get_contents($handle);
            if (! is_string($current) || ! self::content_matches_marker($marker, $current)) {
                flock($handle, LOCK_UN);
                fclose($handle);

                return false;
            }
            rewind($handle);
            if (! ftruncate($handle, 0)) {
                flock($handle, LOCK_UN);
                fclose($handle);

                return false;
            }
        } else {
            @chmod(self::file(), 0600);
        }

        $written = 0;
        $length = strlen($content);
        while ($written < $length) {
            $count = @fwrite($handle, substr($content, $written));
            if (! is_int($count) || $count < 1) {
                flock($handle, LOCK_UN);
                fclose($handle);

                return false;
            }
            $written += $count;
        }
        $ok = fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $ok;
    }

    private static function file_matches_marker(array $marker): bool
    {
        if (! is_file(self::file())) {
            return false;
        }
        $content = @file_get_contents(self::file());
        return is_string($content) && self::content_matches_marker($marker, $content);
    }

    private static function content_matches_marker(array $marker, string $content): bool
    {
        $token = $marker['token'] ?? null;
        if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
            // The ownership token remains stable when the WordPress expiry
            // timestamp is refreshed. This also makes a crash between the
            // marker and .maintenance writes recoverable on the next poll.
            if (preg_match('/^<\?php \$upgrading = [0-9]+; \/\* wp-caregrid:'.preg_quote($token, '/').' \*\/$/', $content) === 1) {
                return true;
            }
            if (($marker['legacy_compat'] ?? false) !== true) {
                return false;
            }
        }

        // Read-only compatibility with markers created by Connector 0.4.8.
        $timestamps = array_unique([
            (int) ($marker['refreshed_at'] ?? 0),
            (int) ($marker['since'] ?? 0),
        ]);
        foreach ($timestamps as $timestamp) {
            if ($timestamp > 0 && hash_equals('<?php $upgrading = '.$timestamp.';', $content)) {
                return true;
            }
        }

        return hash_equals('<?php $upgrading = 2147483647;', $content);
    }

    private static function read_marker(): ?array
    {
        $path = self::marker();
        if (! is_file($path)) {
            return null;
        }
        $json = @file_get_contents($path);
        $marker = is_string($json) ? json_decode($json, true) : null;

        return is_array($marker) ? $marker : null;
    }

    private static function marker(): string
    {
        return self::$marker ?? TempDir::path().'/maintenance.json';
    }
}
