<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Backups\PathGuard;
use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Security\Integrity\Checksums;
use WPCareGrid\Connector\Security\Logger;
use WPCareGrid\Connector\Security\Quarantine\QuarantineFailure;
use WPCareGrid\Connector\Security\Quarantine\QuarantineStore;
use WPCareGrid\Connector\Security\Remediation\CleanSource;
use WPCareGrid\Connector\Security\Remediation\RemediationLog;
use WPCareGrid\Connector\Security\Scan\SitePaths;

/**
 * SECURITY_REPAIR_FILE: replace one official file (WordPress core, or a
 * plugin from WordPress.org) with its clean version for the installed
 * version.
 *
 *   the file is officially listed → the current copy still has the reported
 *   hash (or is missing, when reported missing) → the clean copy is
 *   downloaded and verified against the official checksum → the current
 *   copy is moved into quarantine (reversible) → the clean copy is written
 *   atomically → verified again
 */
final class SecurityRepairFileHandler implements JobHandler
{
    public function capability(array $payload = []): string
    {
        return 'security_remediate';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, ['remediation_uuid', 'source', 'plugin', 'path', 'sha256'], ['remediation_uuid', 'source', 'path']);

        if (! in_array($payload['source'], ['core', 'plugin'], true)) {
            throw new InvalidPayload('Invalid source.');
        }
        if (isset($payload['sha256']) && ! preg_match('/^[a-f0-9]{64}$/', (string) $payload['sha256'])) {
            throw new InvalidPayload('Invalid checksum.');
        }
        if ($payload['source'] === 'plugin' && ! preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+\.php$/', (string) ($payload['plugin'] ?? ''))) {
            throw new InvalidPayload('Invalid plugin.');
        }

        try {
            $path = PathGuard::relative((string) $payload['path']);
        } catch (\Throwable) {
            throw new InvalidPayload('Unsafe path.');
        }

        return [
            'remediation_uuid' => InvalidPayload::uuid((string) $payload['remediation_uuid']),
            'source' => $payload['source'],
            'plugin' => $payload['plugin'] ?? null,
            'path' => $path,
            'sha256' => $payload['sha256'] ?? null,
        ];
    }

    public function handle(array $payload): JobResult
    {
        if (RemediationLog::applied($payload['remediation_uuid'])) {
            return JobResult::failed('REMEDIATION_ALREADY_APPLIED', 'This remediation was already applied on this site.');
        }

        try {
            [$clean, $check] = $payload['source'] === 'core' ? $this->core($payload['path']) : $this->plugin((string) $payload['plugin'], $payload['path']);
            $result = $this->replace($payload, $clean, $check);
        } catch (QuarantineFailure $e) {
            Logger::warning('security.repair_refused', ['remediation' => $payload['remediation_uuid'], 'code' => $e->error_code]);

            return JobResult::failed($e->error_code, $e->getMessage());
        }

        RemediationLog::record($payload['remediation_uuid']);
        Logger::info('security.file_repaired', ['remediation' => $payload['remediation_uuid'], 'path' => $payload['path']]);

        return JobResult::success('FILE_REPAIRED', ['remediation' => ['remediation_uuid' => $payload['remediation_uuid']] + $result]);
    }

    /** @return array{0: string, 1: callable(string): bool} */
    private function core(string $path): array
    {
        $version = get_bloginfo('version');
        $sums = Checksums::core($version, get_locale()) ?? throw new QuarantineFailure('SOURCE_UNAVAILABLE', 'Official checksums are not available for this WordPress version.');
        $md5 = $sums[$path] ?? throw new QuarantineFailure('NOT_AN_OFFICIAL_FILE', 'This is not an official WordPress core file.');

        if (str_starts_with($path, 'wp-content/')) {
            throw new QuarantineFailure('NOT_AN_OFFICIAL_FILE', 'Files in wp-content are not repaired from core.');
        }

        $clean = CleanSource::core($version, $path);

        return [$clean, fn (string $bytes) => hash_equals($md5, md5($bytes))];
    }

    /** @return array{0: string, 1: callable(string): bool} */
    private function plugin(string $plugin, string $path): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $slug = dirname($plugin);
        $prefix = 'wp-content/plugins/'.$slug.'/';

        if (! isset($plugins[$plugin]) || $slug === 'wp-caregrid-connector' || ! str_starts_with($path, $prefix)) {
            throw new QuarantineFailure('NOT_AN_OFFICIAL_FILE', 'This file does not belong to that plugin.');
        }

        $version = (string) $plugins[$plugin]['Version'];
        $relative = substr($path, strlen($prefix));
        $sums = Checksums::plugin($slug, $version) ?? throw new QuarantineFailure('SOURCE_UNAVAILABLE', 'WordPress.org has no checksums for this plugin version.');
        $hashes = $sums[$relative] ?? throw new QuarantineFailure('NOT_AN_OFFICIAL_FILE', 'This file is not part of the official plugin.');

        $clean = CleanSource::plugin($slug, $version, $relative);

        return [$clean, fn (string $bytes) => Checksums::matches($hashes['sha256'], hash('sha256', $bytes)) || ($hashes['sha256'] === null && Checksums::matches($hashes['md5'], md5($bytes)))];
    }

    private function replace(array $payload, string $clean, callable $check): array
    {
        if (! $check($clean)) {
            throw new QuarantineFailure('SOURCE_VERIFICATION_FAILED', 'The downloaded file does not match the official checksum; nothing was changed.');
        }

        $relative = $payload['path'];
        $absolute = SitePaths::absolute($relative);
        if ($absolute === null || QuarantineStore::protected_path($relative) || SitePaths::through_link($absolute)) {
            throw new QuarantineFailure('UNSAFE_PATH', 'This path cannot be repaired.');
        }

        $exists = is_file($absolute);
        // Reported modified: it must still be that file. Reported missing: it must still be missing.
        if ($exists !== ($payload['sha256'] !== null) || ($exists && ! hash_equals((string) $payload['sha256'], (string) hash_file('sha256', $absolute)))) {
            throw new QuarantineFailure('FILE_CHANGED', 'The file changed since it was scanned; scan again first.');
        }

        $quarantine = $exists ? QuarantineStore::quarantine($relative, $absolute, $payload['remediation_uuid'], ['remediation_uuid' => $payload['remediation_uuid'], 'reason' => 'replaced_by_repair']) : null;

        if (! is_dir(dirname($absolute)) && ! wp_mkdir_p(dirname($absolute))) {
            throw new QuarantineFailure('REPAIR_FAILED', 'The directory cannot be created.');
        }

        $temporary = dirname($absolute).'/.wp-caregrid-repair-'.wp_generate_password(12, false);
        if (file_put_contents($temporary, $clean) !== strlen($clean) || ! @rename($temporary, $absolute)) {
            @unlink($temporary);
            throw new QuarantineFailure('REPAIR_FAILED', 'The clean file could not be written.');
        }
        @chmod($absolute, 0644);

        if (! $check((string) file_get_contents($absolute))) {
            throw new QuarantineFailure('REPAIR_FAILED', 'The written file does not match the official checksum.');
        }

        return array_filter([
            'after' => ['path' => $relative, 'sha256' => hash_file('sha256', $absolute), 'exists' => true],
            'quarantine' => $quarantine,
        ]);
    }
}
