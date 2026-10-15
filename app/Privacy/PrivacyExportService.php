<?php

namespace App\Privacy;

use App\Jobs\GeneratePrivacyExport;
use App\Models\Customer;
use App\Models\PrivacyExportArtifact;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class PrivacyExportService
{
    private const MAX_ATTEMPTS = 3;

    private const MAX_ARCHIVE_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private readonly PrivacyRequestService $requests,
        private readonly PrivacyExportPolicy $policy,
        private readonly CustomerPrivacyExportData $data,
        private readonly PrivacyTombstoneJournal $tombstones,
    ) {}

    public function request(Customer $customer, User $actor, string $password): PrivacyExportArtifact
    {
        $this->assertPolicyAvailable();
        $activeCaseExists = $customer->privacyRequests()
            ->where('request_type', PrivacyRequestType::Access->value)
            ->where('active_request_key', 'active')->exists();
        if (! $activeCaseExists && PrivacyExportArtifact::query()->whereIn('status', [
            PrivacyExportStatus::Queued->value,
            PrivacyExportStatus::Generating->value,
            PrivacyExportStatus::Available->value,
        ])->whereHas('privacyRequest', fn ($query) => $query->where('customer_id', $customer->id))->exists()) {
            throw ValidationException::withMessages(['privacy' => 'A temporary export is already active. Use it or wait until it expires before requesting another package.']);
        }
        $case = $this->requests->createCustomerRequest($customer, PrivacyRequestType::Access, 'privacy-export-'.Str::uuid(), $actor, $password);
        if ($case->request_type !== PrivacyRequestType::Access || $case->status !== PrivacyRequestStatus::Verified) {
            throw ValidationException::withMessages(['privacy' => 'The access request is awaiting review and cannot generate an export yet.']);
        }

        $created = false;
        $artifact = DB::transaction(function () use ($case, &$created): PrivacyExportArtifact {
            $lockedCase = PrivacyRequest::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $artifact = PrivacyExportArtifact::query()->where('privacy_request_id', $lockedCase->id)->lockForUpdate()->first();
            if (! $artifact) {
                $artifact = PrivacyExportArtifact::create([
                    'privacy_request_id' => $lockedCase->id,
                    'status' => PrivacyExportStatus::Queued,
                    'coverage_state' => 'partial',
                ]);
                $created = true;
            }

            return $artifact;
        }, attempts: 3);

        if ($artifact->status === PrivacyExportStatus::Failed) {
            if ($artifact->attempts >= self::MAX_ATTEMPTS) {
                throw ValidationException::withMessages(['privacy' => 'The export needs Owner review before another attempt.']);
            }
            $artifact->forceFill(['status' => PrivacyExportStatus::Queued, 'failure_code' => null])->save();
            $created = true;
        }

        if (in_array($artifact->status, [PrivacyExportStatus::Expired, PrivacyExportStatus::Revoked, PrivacyExportStatus::CleanupFailed], true)) {
            throw ValidationException::withMessages(['privacy' => 'This export is no longer available. Submit a new request.']);
        }

        if ($created) {
            $this->requests->recordExportEvent($case, 'export_requested', $actor, ['artifact_reference' => $artifact->uuid]);
            $this->requests->recordExportEvent($case, 'export_queued', $actor, ['artifact_reference' => $artifact->uuid]);
        }
        // A repeated customer submission can safely recover a queued artifact if the
        // original dispatch was interrupted. ShouldBeUnique bounds duplicate jobs.
        if ($artifact->status === PrivacyExportStatus::Queued) {
            GeneratePrivacyExport::dispatch($artifact->uuid)->afterCommit();
        }

        return $artifact->refresh();
    }

    public function generate(string $artifactUuid): void
    {
        $artifact = DB::transaction(function () use ($artifactUuid): ?PrivacyExportArtifact {
            $artifact = PrivacyExportArtifact::query()->where('uuid', $artifactUuid)->lockForUpdate()->first();
            if (! $artifact || ! in_array($artifact->status, [PrivacyExportStatus::Queued, PrivacyExportStatus::Generating], true)) {
                return null;
            }
            $privacyRequest = PrivacyRequest::query()->whereKey($artifact->privacy_request_id)->lockForUpdate()->first();
            if (! $privacyRequest || $privacyRequest->status !== PrivacyRequestStatus::Verified) {
                throw new RuntimeException('The privacy export request is no longer verified.');
            }

            $artifact->forceFill([
                'status' => PrivacyExportStatus::Generating,
                'attempts' => $artifact->attempts + 1,
                'failure_code' => null,
            ])->save();
            $this->requests->recordExportEvent($artifact->privacyRequest, 'export_generating', metadata: ['artifact_reference' => $artifact->uuid]);
            AdminAudit::record(null, 'privacy.export.started', 'privacy_export', $artifact->uuid, ['attempt' => $artifact->attempts]);

            return $artifact->fresh('privacyRequest.customer');
        }, attempts: 3);

        if (! $artifact) {
            return;
        }

        $archivePath = null;
        $encryptedTempPath = null;
        $storagePath = $artifact->expectedStoragePath();
        try {
            $this->assertPolicyAvailable();
            $snapshotAt = Carbon::now('UTC');
            $export = $this->data->build($artifact->privacyRequest->customer, $artifact->privacyRequest, $snapshotAt);
            [$archivePath, $encryptedTempPath, $encrypted] = $this->createEncryptedArchive($artifact, $export);
            if (strlen($encrypted) > self::MAX_ARCHIVE_BYTES) {
                throw new RuntimeException('Export package exceeds its safe size limit.');
            }

            $disk = Storage::disk('local');
            if ($disk->put($storagePath, $encrypted) !== true) {
                throw new RuntimeException('Private export storage write failed.');
            }
            $stored = $disk->get($storagePath);
            if (! is_string($stored) || ! hash_equals(hash('sha256', $encrypted), hash('sha256', $stored))) {
                throw new RuntimeException('Private export storage verification failed.');
            }
            Crypt::decryptString($stored);

            $artifact = DB::transaction(function () use ($artifact, $export, $storagePath, $encrypted, $snapshotAt): PrivacyExportArtifact {
                $locked = PrivacyExportArtifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== PrivacyExportStatus::Generating) {
                    throw new RuntimeException('Export generation was revoked before publication.');
                }
                $linkHours = $this->policy->linkHours();
                $artifactDays = $this->policy->artifactDays();
                if ($linkHours === null || $artifactDays === null) {
                    throw new RuntimeException('Approved export expiry policy is unavailable.');
                }
                $expiresAt = $snapshotAt->copy()->addDays($artifactDays);
                $downloadExpiresAt = $snapshotAt->copy()->addHours($linkHours);
                if ($downloadExpiresAt->greaterThan($expiresAt)) {
                    $downloadExpiresAt = $expiresAt->copy();
                }
                $locked->forceFill([
                    'storage_disk' => 'local',
                    'storage_path' => $storagePath,
                    'status' => PrivacyExportStatus::Available,
                    'included_sections' => CustomerPrivacyExportData::INCLUDED,
                    'excluded_sections' => CustomerPrivacyExportData::EXCLUDED,
                    'coverage_state' => $export['coverage'],
                    'package_size' => strlen($encrypted),
                    'sha256' => hash('sha256', $encrypted),
                    'generated_at' => $snapshotAt,
                    'expires_at' => $expiresAt,
                    'download_expires_at' => $downloadExpiresAt,
                    'failure_code' => null,
                ])->save();

                $this->requests->completeAccessExport($locked->privacyRequest);
                AdminAudit::record(null, 'privacy.export.generated', 'privacy_export', $locked->uuid, [
                    'request' => $locked->privacyRequest->uuid,
                    'coverage' => $locked->coverage_state,
                    'sections' => CustomerPrivacyExportData::INCLUDED,
                ]);

                return $locked->refresh();
            }, attempts: 3);
        } catch (Throwable $exception) {
            $cleanupFailed = isset($disk) && $disk->exists($storagePath) && ! $disk->delete($storagePath);
            DB::transaction(function () use ($artifact, $cleanupFailed, $storagePath): void {
                $locked = PrivacyExportArtifact::query()->whereKey($artifact->id)->lockForUpdate()->first();
                if ($locked && $locked->status === PrivacyExportStatus::Generating) {
                    $locked->forceFill([
                        'status' => $cleanupFailed ? PrivacyExportStatus::CleanupFailed : PrivacyExportStatus::Queued,
                        'storage_disk' => $cleanupFailed ? 'local' : $locked->storage_disk,
                        'storage_path' => $cleanupFailed ? $storagePath : null,
                        'failure_code' => $cleanupFailed ? 'orphan_cleanup_failed' : 'generation_failed',
                    ])->save();
                    if ($cleanupFailed) {
                        $this->requests->recordExportEvent($locked->privacyRequest, 'export_failed', metadata: [
                            'artifact_reference' => $locked->uuid,
                            'failure_code' => 'orphan_cleanup_failed',
                        ]);
                        AdminAudit::record(null, 'privacy.export.cleanup_failed', 'privacy_export', $locked->uuid, ['failure_code' => 'orphan_cleanup_failed']);
                    }
                }
            }, attempts: 3);
            throw $exception;
        } finally {
            foreach ([$archivePath, $encryptedTempPath] as $temporaryPath) {
                if (is_string($temporaryPath) && is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
        }
    }

    public function markFailed(string $artifactUuid): void
    {
        $artifact = PrivacyExportArtifact::query()->where('uuid', $artifactUuid)->first();
        if (! $artifact || ! in_array($artifact->status, [PrivacyExportStatus::Queued, PrivacyExportStatus::Generating], true)) {
            return;
        }

        $artifact->forceFill(['status' => PrivacyExportStatus::Failed, 'failure_code' => 'generation_failed'])->save();
        $this->requests->recordExportEvent($artifact->privacyRequest, 'export_failed', metadata: ['artifact_reference' => $artifact->uuid, 'failure_code' => 'generation_failed']);
        AdminAudit::record(null, 'privacy.export.failed', 'privacy_export', $artifact->uuid, ['failure_code' => 'generation_failed']);
    }

    public function downloadUrl(PrivacyExportArtifact $artifact): ?string
    {
        if ($artifact->status !== PrivacyExportStatus::Available || ! $artifact->download_expires_at?->isFuture() || ! $artifact->expires_at?->isFuture()) {
            return null;
        }

        return URL::temporarySignedRoute('account.privacy.exports.download', $artifact->download_expires_at, ['artifact' => $artifact->uuid]);
    }

    public function renewDownloadLink(PrivacyExportArtifact $artifact, User $actor, string $password): PrivacyExportArtifact
    {
        $customer = $artifact->privacyRequest->customer;
        if (! $actor->customer()->whereKey($customer->id)->exists() || ! Hash::check($password, (string) $actor->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => __('passwords.password')]);
        }
        if ($artifact->status !== PrivacyExportStatus::Available || ! $artifact->expires_at?->isFuture()) {
            throw ValidationException::withMessages(['privacy' => 'The export package is no longer available.']);
        }
        $hours = $this->policy->linkHours();
        if ($hours === null) {
            throw ValidationException::withMessages(['privacy' => 'The approved export-link policy is unavailable.']);
        }

        $expiresAt = now('UTC')->addHours($hours);
        if ($expiresAt->greaterThan($artifact->expires_at)) {
            $expiresAt = $artifact->expires_at->copy();
        }
        $artifact->forceFill(['download_expires_at' => $expiresAt])->save();
        $this->requests->recordExportEvent($artifact->privacyRequest, 'export_link_renewed', $actor, ['artifact_reference' => $artifact->uuid]);

        return $artifact->refresh();
    }

    public function download(PrivacyExportArtifact $artifact, Customer $customer, User $actor): string
    {
        $case = $artifact->privacyRequest;
        if ($case->customer_id !== $customer->id || ! $actor->customer()->whereKey($customer->id)->exists()) {
            throw new AuthorizationException('Export artifact does not belong to this customer.');
        }
        if ($artifact->status !== PrivacyExportStatus::Available
            || ! $artifact->download_expires_at?->isFuture()
            || ! $artifact->expires_at?->isFuture()
            || $artifact->storage_disk !== 'local'
            || $artifact->storage_path !== $artifact->expectedStoragePath()
            || ! $artifact->sha256) {
            throw new RuntimeException('Export artifact is unavailable or expired.');
        }

        $disk = Storage::disk('local');
        $encrypted = $disk->get($artifact->storage_path);
        if (! is_string($encrypted) || ! hash_equals($artifact->sha256, hash('sha256', $encrypted))) {
            throw new RuntimeException('Export artifact integrity check failed.');
        }
        $plain = Crypt::decryptString($encrypted);
        if (! str_starts_with($plain, "PK\x03\x04")) {
            throw new RuntimeException('Export archive format check failed.');
        }

        $updated = DB::transaction(function () use ($artifact): PrivacyExportArtifact {
            $locked = PrivacyExportArtifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PrivacyExportStatus::Available || ! $locked->download_expires_at?->isFuture() || ! $locked->expires_at?->isFuture()) {
                throw new RuntimeException('Export artifact expired while starting the download.');
            }
            $locked->forceFill(['download_count' => $locked->download_count + 1, 'last_downloaded_at' => now('UTC')])->save();

            return $locked->refresh();
        }, attempts: 3);
        $this->requests->recordExportEvent($case, 'export_downloaded', $actor, ['artifact_reference' => $artifact->uuid, 'download_count' => $updated->download_count]);

        return $plain;
    }

    public function retry(PrivacyExportArtifact $artifact, User $owner): void
    {
        if (! $owner->isOwner() || $owner->customer()->exists()) {
            throw new AuthorizationException('This export cannot be retried.');
        }
        $this->assertPolicyAvailable();
        $queued = DB::transaction(function () use ($artifact, $owner): PrivacyExportArtifact {
            $locked = PrivacyExportArtifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PrivacyExportStatus::Failed || $locked->attempts >= self::MAX_ATTEMPTS) {
                throw ValidationException::withMessages(['privacy' => 'This export cannot be retried.']);
            }
            $locked->forceFill(['status' => PrivacyExportStatus::Queued, 'failure_code' => null])->save();
            $this->requests->recordExportEvent($locked->privacyRequest, 'export_queued', $owner, ['artifact_reference' => $locked->uuid]);
            AdminAudit::record($owner, 'privacy.export.owner_retry', 'privacy_export', $locked->uuid, ['attempts' => $locked->attempts]);

            return $locked->refresh();
        }, attempts: 3);
        GeneratePrivacyExport::dispatch($queued->uuid)->afterCommit();
    }

    public function revoke(PrivacyExportArtifact $artifact, User $owner): void
    {
        if (! $owner->isOwner() || $owner->customer()->exists()) {
            throw new AuthorizationException('Owner authorization is required.');
        }
        $lock = Cache::lock(PrivacyTombstoneReplayApplier::SNAPSHOT_LOCK, (int) config('platform_backup.timeout', 3600) + 300);
        $lock->block(30);
        $effectiveAt = now('UTC')->toIso8601String();
        try {
            $revoking = DB::transaction(function () use ($artifact, $owner, $effectiveAt): PrivacyExportArtifact {
                $locked = PrivacyExportArtifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, [PrivacyExportStatus::Available, PrivacyExportStatus::Failed, PrivacyExportStatus::CleanupFailed], true)) {
                    throw ValidationException::withMessages(['privacy' => 'This export cannot be revoked in its current state.']);
                }
                if ($locked->storage_disk !== 'local' || ($locked->storage_path && $locked->storage_path !== $locked->expectedStoragePath())) {
                    throw ValidationException::withMessages(['privacy' => 'The export storage reference needs manual review before cleanup.']);
                }
                $request = $locked->privacyRequest;
                $customer = $request->customer;
                $this->tombstones->append([
                    'event_id' => hash('sha256', 'privacy:export_revoked:'.$locked->uuid),
                    'subject_uuid' => $customer->uuid,
                    'tenant_uuid' => $customer->uuid,
                    'scope' => 'customer_account',
                    'action' => 'privacy_export_revoked',
                    'request_uuid' => $request->uuid,
                    'artifact_uuid' => $locked->uuid,
                    'effective_at' => $effectiveAt,
                ]);
                $locked->forceFill(['status' => PrivacyExportStatus::Revoked, 'download_expires_at' => now('UTC'), 'failure_code' => null])->save();
                $this->requests->recordExportEvent($locked->privacyRequest, 'export_revoked', $owner, ['artifact_reference' => $locked->uuid]);
                AdminAudit::record($owner, 'privacy.export.owner_revoked', 'privacy_export', $locked->uuid, []);

                return $locked->refresh();
            }, attempts: 3);
        } finally {
            $lock->release();
        }

        $cleanupSucceeded = true;
        try {
            if ($revoking->storage_path && Storage::disk('local')->exists($revoking->storage_path)) {
                $cleanupSucceeded = Storage::disk('local')->delete($revoking->storage_path);
            }
        } catch (Throwable) {
            $cleanupSucceeded = false;
        }
        if (! $cleanupSucceeded) {
            $revoking->forceFill(['status' => PrivacyExportStatus::CleanupFailed, 'failure_code' => 'revocation_cleanup_failed'])->save();
            $this->requests->recordExportEvent($revoking->privacyRequest, 'export_cleanup_failed', $owner, [
                'artifact_reference' => $revoking->uuid,
                'failure_code' => 'revocation_cleanup_failed',
            ]);
            AdminAudit::record($owner, 'privacy.export.cleanup_failed', 'privacy_export', $revoking->uuid, ['failure_code' => 'revocation_cleanup_failed']);
            throw new RuntimeException('The export is revoked but encrypted temporary cleanup needs review.');
        }

        $revoking->forceFill(['storage_path' => null, 'sha256' => null])->save();
    }

    public function expireDue(int $limit = 100): int
    {
        $expired = 0;
        PrivacyExportArtifact::query()
            ->whereIn('status', [PrivacyExportStatus::Available, PrivacyExportStatus::CleanupFailed])
            ->whereNotNull('expires_at')->where('expires_at', '<=', now('UTC'))
            ->orderBy('id')->limit(max(1, min($limit, 500)))
            ->get()->each(function (PrivacyExportArtifact $artifact) use (&$expired): void {
                if ($artifact->storage_disk !== 'local' || ($artifact->storage_path && $artifact->storage_path !== $artifact->expectedStoragePath())) {
                    $artifact->forceFill(['status' => PrivacyExportStatus::CleanupFailed, 'failure_code' => 'invalid_storage_reference'])->save();

                    return;
                }
                if ($artifact->storage_path && Storage::disk('local')->exists($artifact->storage_path)
                    && ! Storage::disk('local')->delete($artifact->storage_path)) {
                    $artifact->forceFill(['status' => PrivacyExportStatus::CleanupFailed, 'failure_code' => 'expiry_cleanup_failed'])->save();
                    AdminAudit::record(null, 'privacy.export.cleanup_failed', 'privacy_export', $artifact->uuid, ['failure_code' => 'expiry_cleanup_failed']);

                    return;
                }

                $artifact->forceFill(['status' => PrivacyExportStatus::Expired, 'storage_path' => null, 'sha256' => null, 'failure_code' => null])->save();
                $this->requests->recordExportEvent($artifact->privacyRequest, 'export_expired', metadata: ['artifact_reference' => $artifact->uuid]);
                AdminAudit::record(null, 'privacy.export.expired', 'privacy_export', $artifact->uuid, []);
                $expired++;
            });

        return $expired;
    }

    private function assertPolicyAvailable(): void
    {
        if ($this->policy->linkHours() === null || $this->policy->artifactDays() === null) {
            throw ValidationException::withMessages(['privacy' => 'The approved export expiry policy is unavailable; no package was created.']);
        }
    }

    /** @param array{sections:array<string,mixed>,manifest:array<string,mixed>,coverage:string} $export @return array{string,string,string} */
    private function createEncryptedArchive(PrivacyExportArtifact $artifact, array $export): array
    {
        $directory = storage_path('app/private/privacy-export-tmp');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Private temporary directory could not be created.');
        }
        @chmod($directory, 0700);
        $archivePath = tempnam($directory, 'wpcg-export-');
        if (! is_string($archivePath)) {
            throw new RuntimeException('Private temporary archive could not be created.');
        }
        @chmod($archivePath, 0600);
        $encryptedTempPath = $archivePath.'.enc';

        try {
            $manifestJson = json_encode($export['manifest'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $zip = new ZipArchive;
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Privacy export archive could not be opened.');
            }
            $added = $zip->addFromString('manifest.json', $manifestJson)
                && $zip->addFromString('README.txt', "WP CareGrid privacy export\nSchema: ".CustomerPrivacyExportData::SCHEMA."\nCoverage: partial. See manifest.json for included and excluded sections.\n");
            foreach ($export['sections'] as $name => $contents) {
                $added = $zip->addFromString($name.'.json', json_encode($contents, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) && $added;
            }
            $closed = $zip->close();
            if (! $added || ! $closed || filesize($archivePath) > self::MAX_ARCHIVE_BYTES) {
                throw new RuntimeException('Privacy export archive could not be completely written within its size limit.');
            }

            $verify = new ZipArchive;
            if ($verify->open($archivePath) !== true) {
                throw new RuntimeException('Privacy export archive verification failed.');
            }
            $required = ['manifest.json', 'README.txt', ...array_map(fn ($name) => $name.'.json', array_keys($export['sections']))];
            $valid = $verify->numFiles === count($required) && collect($required)->every(fn ($name) => $verify->locateName($name) !== false);
            $verify->close();
            if (! $valid) {
                throw new RuntimeException('Privacy export archive is incomplete.');
            }

            $bytes = file_get_contents($archivePath);
            if (! is_string($bytes)) {
                throw new RuntimeException('Privacy export archive could not be read for encryption.');
            }
            $encrypted = Crypt::encryptString($bytes);
            if (file_put_contents($encryptedTempPath, $encrypted, LOCK_EX) === false) {
                throw new RuntimeException('Encrypted temporary export could not be written.');
            }
            @chmod($encryptedTempPath, 0600);

            return [$archivePath, $encryptedTempPath, $encrypted];
        } catch (Throwable $exception) {
            if (isset($zip) && $zip instanceof ZipArchive) {
                @$zip->close();
            }
            @unlink($archivePath);
            @unlink($encryptedTempPath);
            throw $exception;
        }
    }
}
