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
use WPCareGrid\Connector\Security\Remediation\RemediationLog;
use WPCareGrid\Connector\Security\Scan\SitePaths;

/**
 * SECURITY_QUARANTINE: move one file into quarantine (reversible). The file
 * must still have the SHA-256 the finding reported: a changed, stale or
 * replayed request never acts on a different file. Never wp-config.php,
 * the connector itself, or an official WordPress core file (those are
 * repaired instead).
 */
final class SecurityQuarantineHandler implements JobHandler
{
    public function capability(array $payload = []): string
    {
        return 'security_quarantine';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, ['remediation_uuid', 'finding_uuid', 'path', 'sha256'], ['remediation_uuid', 'finding_uuid', 'path', 'sha256']);

        if (! preg_match('/^[a-f0-9]{64}$/', (string) $payload['sha256'])) {
            throw new InvalidPayload('Invalid checksum.');
        }

        try {
            $path = PathGuard::relative((string) $payload['path']);
        } catch (\Throwable) {
            throw new InvalidPayload('Unsafe path.');
        }

        return [
            'remediation_uuid' => InvalidPayload::uuid((string) $payload['remediation_uuid']),
            'finding_uuid' => InvalidPayload::uuid((string) $payload['finding_uuid']),
            'path' => $path,
            'sha256' => $payload['sha256'],
        ];
    }

    public function handle(array $payload): JobResult
    {
        if (RemediationLog::applied($payload['remediation_uuid'])) {
            return JobResult::failed('REMEDIATION_ALREADY_APPLIED', 'This remediation was already applied on this site.');
        }

        try {
            $relative = $payload['path'];
            $absolute = SitePaths::absolute($relative);

            if ($absolute === null || QuarantineStore::protected_path($relative) || SitePaths::through_link($absolute)) {
                throw new QuarantineFailure('UNSAFE_PATH', 'This path cannot be quarantined.');
            }
            if (! is_file($absolute)) {
                throw new QuarantineFailure('FILE_NOT_FOUND', 'The file does not exist any more.');
            }
            $core = Checksums::core(get_bloginfo('version'), get_locale());
            if ($core !== null && isset($core[$relative])) {
                throw new QuarantineFailure('CORE_FILE_USE_REPAIR', 'Official WordPress files are repaired, not quarantined.');
            }
            if (! hash_equals($payload['sha256'], (string) hash_file('sha256', $absolute))) {
                throw new QuarantineFailure('FILE_CHANGED', 'The file changed since it was scanned; scan again first.');
            }

            $record = QuarantineStore::quarantine($relative, $absolute, $payload['remediation_uuid'], [
                'finding_uuid' => $payload['finding_uuid'],
                'remediation_uuid' => $payload['remediation_uuid'],
                'reason' => 'finding',
            ]);
        } catch (QuarantineFailure $e) {
            Logger::warning('security.quarantine_refused', ['remediation' => $payload['remediation_uuid'], 'code' => $e->error_code]);

            return JobResult::failed($e->error_code, $e->getMessage());
        }

        RemediationLog::record($payload['remediation_uuid']);
        Logger::info('security.quarantined', ['remediation' => $payload['remediation_uuid'], 'path' => $relative]);

        return JobResult::success('FILE_QUARANTINED', ['remediation' => [
            'remediation_uuid' => $payload['remediation_uuid'],
            'after' => ['path' => $relative, 'exists' => file_exists($absolute)],
            'quarantine' => $record,
        ]]);
    }
}
