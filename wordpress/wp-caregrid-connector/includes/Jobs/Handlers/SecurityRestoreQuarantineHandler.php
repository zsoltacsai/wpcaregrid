<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Backups\PathGuard;
use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Security\Logger;
use WPCareGrid\Connector\Security\Quarantine\QuarantineFailure;
use WPCareGrid\Connector\Security\Quarantine\QuarantineStore;
use WPCareGrid\Connector\Security\Remediation\RemediationLog;

/** SECURITY_RESTORE_QUARANTINE: undo a quarantine. Only the quarantined copy with this hash, only to its original path, never over a file. */
final class SecurityRestoreQuarantineHandler implements JobHandler
{
    public function capability(array $payload = []): string
    {
        return 'security_quarantine';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, ['remediation_uuid', 'quarantine_uuid', 'path', 'sha256'], ['remediation_uuid', 'quarantine_uuid', 'path', 'sha256']);

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
            'quarantine_uuid' => InvalidPayload::uuid((string) $payload['quarantine_uuid']),
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
            $restored = QuarantineStore::restore($payload['path'], $payload['sha256']);
        } catch (QuarantineFailure $e) {
            Logger::warning('security.quarantine_restore_refused', ['remediation' => $payload['remediation_uuid'], 'code' => $e->error_code]);

            return JobResult::failed($e->error_code, $e->getMessage());
        }

        RemediationLog::record($payload['remediation_uuid']);
        Logger::info('security.quarantine_restored', ['remediation' => $payload['remediation_uuid'], 'path' => $payload['path']]);

        return JobResult::success('QUARANTINE_RESTORED', ['remediation' => [
            'remediation_uuid' => $payload['remediation_uuid'],
            'after' => ['path' => $restored['path'], 'sha256' => $restored['sha256'], 'exists' => true],
        ]]);
    }
}
