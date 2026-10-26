<?php

namespace App\Backups\CustomerDownloads;

use App\Backups\BackupObjectName;
use App\Backups\BackupStatus;
use App\Backups\Storage\ObjectKey;
use App\Backups\Storage\StorageManager;
use App\Backups\Storage\StreamableBackupStorage;
use App\Models\Backup;
use App\Models\BackupDownloadGrant;
use App\Models\BackupObject;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Owns the customer download grant lifecycle and its integrity boundary. */
final class BackupDownloadGrants
{
    public function __construct(private readonly StorageManager $storages) {}

    public function enabled(): bool
    {
        return (bool) config('backup.downloads.enabled', false);
    }

    /** @throws BackupDownloadRefused */
    public function create(User $user, Website $website, Backup $backup, BackupObject $object): BackupDownloadGrant
    {
        $this->requireEnabled();

        return DB::transaction(function () use ($user, $website, $backup, $object): BackupDownloadGrant {
            $lockedWebsite = Website::query()->whereKey($website->id)->lockForUpdate()->firstOrFail();
            $lockedBackup = Backup::query()->whereKey($backup->id)->where('website_id', $lockedWebsite->id)->lockForUpdate()->firstOrFail();
            $lockedObject = BackupObject::query()->whereKey($object->id)->where('backup_id', $lockedBackup->id)->lockForUpdate()->firstOrFail();
            $customer = $this->assertEligible($user, $lockedWebsite, $lockedBackup, $lockedObject);

            $active = BackupDownloadGrant::query()->where('user_id', $user->id)
                ->where('backup_object_id', $lockedObject->id)
                ->whereIn('status', [BackupDownloadGrant::PREPARING, BackupDownloadGrant::READY])
                ->where('expires_at', '>', now())->lockForUpdate()->first();

            if ($active && $this->snapshotMatches($active, $lockedWebsite, $lockedBackup, $lockedObject)) {
                return $active;
            }

            $grant = BackupDownloadGrant::query()->create([
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'website_id' => $lockedWebsite->id,
                'backup_id' => $lockedBackup->id,
                'backup_object_id' => $lockedObject->id,
                'object_name' => $lockedObject->name,
                'storage_provider' => $lockedBackup->storage_provider,
                'object_key' => $lockedBackup->objectKey($lockedObject->objectName()),
                'object_sha256' => strtolower($lockedObject->sha256),
                'object_size' => $lockedObject->size,
                'fingerprint_block_size' => (int) config('backup.downloads.fingerprint_block_size'),
                'status' => BackupDownloadGrant::PREPARING,
                'expires_at' => now()->addSeconds((int) config('backup.downloads.preparation_ttl_seconds', 3600)),
            ]);

            $this->audit('preparation_requested', $user, $lockedWebsite, $lockedBackup, $lockedObject->name);
            PrepareBackupDownloadGrant::dispatch($grant->id)->afterCommit();

            return $grant;
        }, attempts: 3);
    }

    /** Validate the complete ownership and backup/object state on every use. */
    public function assertEligible(User $user, Website $website, Backup $backup, BackupObject $object, ?BackupDownloadGrant $grant = null): Customer
    {
        $this->requireEnabled();
        $customer = $user->customer;
        if ($customer instanceof Customer === false || $user->isOwner() || $user->isOperator() || (int) $website->customer_id !== (int) $customer->id
            || (int) $backup->website_id !== (int) $website->id || (int) $object->backup_id !== (int) $backup->id) {
            throw new BackupDownloadRefused('NOT_FOUND');
        }

        if ($backup->status !== BackupStatus::Verified || $backup->isRestorable() === false) {
            throw new BackupDownloadRefused('BACKUP_UNAVAILABLE');
        }

        $name = BackupObjectName::tryFrom((string) $object->name);
        if ($name === null || ($name !== BackupObjectName::Manifest && in_array($name->value, $backup->type->objects(), true) === false)) {
            throw new BackupDownloadRefused('OBJECT_UNAVAILABLE');
        }

        if ($object->status !== BackupObject::VERIFIED || $object->verified_at === null || $object->size < 1
            || $object->size > (int) config('backup.upload.max_object_size', 50 * 1024 * 1024 * 1024)
            || is_string($object->sha256) === false || preg_match('/\A[a-f0-9]{64}\z/i', $object->sha256) !== 1
            || ($object->remote_size !== null && $object->remote_size !== $object->size)) {
            throw new BackupDownloadRefused('OBJECT_UNAVAILABLE');
        }

        if ($backup->objectDeletions()->where('object_name', $name->value)->exists()) {
            throw new BackupDownloadRefused('OBJECT_UNAVAILABLE');
        }

        $expectedKey = $backup->objectKey($name);
        try {
            ObjectKey::assert($expectedKey);
        } catch (Throwable) {
            throw new BackupDownloadRefused('OBJECT_UNAVAILABLE');
        }

        if ($grant && ((int) $grant->user_id !== (int) $user->id || (int) $grant->customer_id !== (int) $customer->id
            || (int) $grant->website_id !== (int) $website->id || (int) $grant->backup_id !== (int) $backup->id
            || (int) $grant->backup_object_id !== (int) $object->id || $this->snapshotMatches($grant, $website, $backup, $object) === false)) {
            throw new BackupDownloadRefused('GRANT_REVOKED');
        }

        return $customer;
    }

    public function markReady(int $grantId, int $blockSize, string $encodedHashes): void
    {
        DB::transaction(function () use ($grantId, $blockSize, $encodedHashes): void {
            $grant = BackupDownloadGrant::query()->whereKey($grantId)->lockForUpdate()->first();
            if ($grant === null || $grant->status !== BackupDownloadGrant::PREPARING || $grant->revoked_at !== null || $grant->expires_at?->isPast()) {
                return;
            }
            if ($blockSize < 1024 * 1024 || $blockSize > 8 * 1024 * 1024) {
                $grant->forceFill(['status' => BackupDownloadGrant::FAILED, 'failure_code' => 'FINGERPRINT_CONFIGURATION_INVALID'])->save();
                $this->audit('failed', $grant->user, $grant->website, $grant->backup, $grant->object_name, 'FINGERPRINT_CONFIGURATION_INVALID');
                return;
            }
            $decoded = base64_decode($encodedHashes, true);
            $expectedCount = (int) ceil($grant->object_size / $blockSize);
            if (is_string($decoded) === false || strlen($decoded) !== $expectedCount * 32) {
                $grant->forceFill(['status' => BackupDownloadGrant::FAILED, 'failure_code' => 'FINGERPRINT_INVALID'])->save();

                return;
            }
            try {
                $website = Website::query()->findOrFail($grant->website_id);
                $backup = Backup::query()->findOrFail($grant->backup_id);
                $object = BackupObject::query()->findOrFail($grant->backup_object_id);
                $this->assertEligible($grant->user, $website, $backup, $object, $grant);
            } catch (Throwable) {
                $grant->forceFill(['status' => BackupDownloadGrant::FAILED, 'failure_code' => 'PREPARATION_FAILED'])->save();
                $this->audit('failed', $grant->user, $grant->website, $grant->backup, $grant->object_name, 'PREPARATION_FAILED');

                return;
            }
            $grant->forceFill([
                'fingerprint_block_size' => $blockSize,
                'block_hashes' => $encodedHashes,
                'status' => BackupDownloadGrant::READY,
                'failure_code' => null,
                'expires_at' => now()->addSeconds((int) config('backup.downloads.grant_ttl_seconds', 600)),
            ])->save();
            $this->audit('grant_ready', $grant->user, $grant->website, $grant->backup, $grant->object_name);
        }, attempts: 3);
    }

    public function failPreparation(int $grantId, string $code): void
    {
        $allowed = ['PREPARATION_EXPIRED', 'OBJECT_SIZE_MISMATCH', 'OBJECT_INTEGRITY_MISMATCH', 'STORAGE_UNAVAILABLE', 'STORAGE_UNSUPPORTED', 'FINGERPRINT_CONFIGURATION_INVALID', 'FINGERPRINT_INVALID', 'PREPARATION_FAILED'];
        $code = in_array($code, $allowed, true) ? $code : 'PREPARATION_FAILED';
        $grant = BackupDownloadGrant::query()->whereKey($grantId)->first();
        if ($grant === null || $grant->status !== BackupDownloadGrant::PREPARING || $grant->revoked_at !== null) {
            return;
        }
        $grant->forceFill(['status' => BackupDownloadGrant::FAILED, 'failure_code' => $code, 'block_hashes' => null])->save();
        $this->audit('failed', $grant->user, $grant->website, $grant->backup, $grant->object_name, $code);
    }

    public function status(User $user, BackupDownloadGrant $grant): BackupDownloadGrant
    {
        $this->requireEnabled();
        $this->assertGrantOwner($user, $grant);
        if ($grant->status === BackupDownloadGrant::READY && ($grant->expires_at === null || $grant->expires_at->isPast())) {
            throw new BackupDownloadRefused('GRANT_EXPIRED');
        }

        return $grant;
    }

    public function revoke(User $user, BackupDownloadGrant $grant): void
    {
        $this->requireEnabled();
        $this->assertGrantOwner($user, $grant);
        DB::transaction(function () use ($grant, $user): void {
            $locked = BackupDownloadGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === BackupDownloadGrant::REVOKED) {
                return;
            }
            $locked->forceFill(['status' => BackupDownloadGrant::REVOKED, 'revoked_at' => now(), 'block_hashes' => null])->save();
            $this->audit('revoked', $user, $locked->website, $locked->backup, $locked->object_name);
        }, attempts: 3);
    }

    /** @throws BackupDownloadRefused */
    public function readRange(User $user, BackupDownloadGrant $grant, string $rangeHeader): DownloadRange
    {
        $this->requireEnabled();
        $this->assertGrantOwner($user, $grant);
        if ($grant->status !== BackupDownloadGrant::READY || $grant->revoked_at !== null || $grant->expires_at === null || $grant->expires_at->isPast()) {
            throw new BackupDownloadRefused($grant->status === BackupDownloadGrant::REVOKED ? 'GRANT_REVOKED' : 'GRANT_UNAVAILABLE');
        }

        $website = Website::query()->find($grant->website_id);
        $backup = Backup::query()->find($grant->backup_id);
        $object = BackupObject::query()->find($grant->backup_object_id);
        if ($website === null || $backup === null || $object === null) {
            throw new BackupDownloadRefused('GRANT_REVOKED');
        }
        $this->assertEligible($user, $website, $backup, $object, $grant);
        $range = $this->parseRange($rangeHeader, $grant->object_size);
        $blockSize = $grant->fingerprint_block_size;
        if ($blockSize < 1 || $blockSize > 8 * 1024 * 1024) {
            throw new BackupDownloadRefused('FINGERPRINT_INVALID');
        }
        try {
            $hashes = base64_decode((string) $grant->block_hashes, true);
        } catch (Throwable) {
            $hashes = false;
        }
        $count = (int) ceil($grant->object_size / $blockSize);
        if (is_string($hashes) === false || strlen($hashes) !== $count * 32) {
            throw new BackupDownloadRefused('FINGERPRINT_INVALID');
        }

        try {
            $storage = $this->storages->provider($grant->storage_provider);
            if ($storage instanceof StreamableBackupStorage === false || $storage->name() !== $grant->storage_provider) {
                throw new BackupDownloadRefused('STORAGE_UNSUPPORTED');
            }
            $firstBlock = intdiv($range->offset, $blockSize);
            $lastBlock = intdiv($range->offset + $range->length - 1, $blockSize);
            $alignedOffset = $firstBlock * $blockSize;
            $alignedEnd = min($grant->object_size, ($lastBlock + 1) * $blockSize);
            $bytes = $storage->readRange($grant->object_key, $alignedOffset, $alignedEnd - $alignedOffset);
            if (strlen($bytes) !== $alignedEnd - $alignedOffset) {
                throw new BackupDownloadRefused('OBJECT_SIZE_MISMATCH');
            }
            for ($block = $firstBlock; $block <= $lastBlock; $block++) {
                $relativeOffset = ($block - $firstBlock) * $blockSize;
                $blockLength = min($blockSize, $grant->object_size - ($block * $blockSize));
                $expectedHash = substr($hashes, $block * 32, 32);
                if (hash_equals($expectedHash, hash('sha256', substr($bytes, $relativeOffset, $blockLength), true)) === false) {
                    throw new BackupDownloadRefused('OBJECT_INTEGRITY_MISMATCH');
                }
            }
        } catch (BackupDownloadRefused $e) {
            $this->recordFailure($grant, $e->errorCode);
            throw $e;
        } catch (Throwable) {
            $this->recordFailure($grant, 'STORAGE_UNAVAILABLE');
            throw new BackupDownloadRefused('STORAGE_UNAVAILABLE');
        }

        $slice = substr($bytes, $range->offset - $alignedOffset, $range->length);
        if (strlen($slice) !== $range->length) {
            throw new BackupDownloadRefused('OBJECT_SIZE_MISMATCH');
        }
        // Re-read all eligibility state after the storage operation. A deletion,
        // expiry or entitlement transition that began during the provider read
        // must fail closed before any response bytes are handed to the client.
        $website = Website::query()->find($grant->website_id);
        $backup = Backup::query()->find($grant->backup_id);
        $object = BackupObject::query()->find($grant->backup_object_id);
        if ($website === null || $backup === null || $object === null) {
            throw new BackupDownloadRefused('GRANT_REVOKED');
        }
        $this->assertEligible($user, $website, $backup, $object, $grant);

        // A revoke racing the storage read wins before response bytes are emitted.
        $grant = BackupDownloadGrant::query()->find($grant->id);
        if ($grant === null || $grant->status !== BackupDownloadGrant::READY || $grant->revoked_at !== null || $grant->expires_at?->isPast()) {
            throw new BackupDownloadRefused('GRANT_REVOKED');
        }
        $grant->forceFill(['last_range_at' => now(), 'range_responses' => $grant->range_responses + 1])->save();
        return new DownloadRange($range->offset, $range->length, $range->total, $slice);
    }

    public function recordResponse(BackupDownloadGrant $grant, int $bytes, bool $completed): void
    {
        $attributes = [
            'bytes_served' => $grant->bytes_served + max(0, min($bytes, (int) config('backup.downloads.max_range_size', 8 * 1024 * 1024))),
        ];
        $attributes[$completed ? 'last_response_completed_at' : 'last_response_interrupted_at'] = now();
        $grant->forceFill($attributes)->save();
        $this->audit($completed ? 'range_completed' : 'range_interrupted', $grant->user, $grant->website, $grant->backup, $grant->object_name, bytes: min($bytes, (int) config('backup.downloads.max_range_size', 8 * 1024 * 1024)));
    }

    /** @throws BackupDownloadRefused */
    private function parseRange(string $header, int $total): DownloadRange
    {
        $max = (int) config('backup.downloads.max_range_size', 8 * 1024 * 1024);
        if ($max < 1 || $max > 8 * 1024 * 1024) {
            throw new BackupDownloadRefused('RANGE_CONFIGURATION_INVALID');
        }
        if ($total < 1 || strlen($header) > 100 || str_contains($header, ',') || preg_match('/\Abytes=(\d*)-(\d*)\z/i', trim($header), $match) !== 1) {
            throw new BackupDownloadRefused('RANGE_INVALID');
        }
        if ($match[1] === '' && $match[2] === '') {
            throw new BackupDownloadRefused('RANGE_INVALID');
        }
        if ($match[1] === '') {
            $suffix = (int) $match[2];
            if ($suffix < 1) {
                throw new BackupDownloadRefused('RANGE_INVALID');
            }
            $length = min($suffix, $total);
            $start = $total - $length;
        } else {
            $start = (int) $match[1];
            $end = $match[2] === '' ? $total - 1 : min((int) $match[2], $total - 1);
            if ($start >= $total || $end < $start) {
                throw new BackupDownloadRefused('RANGE_INVALID');
            }
            $length = $end - $start + 1;
        }
        if ($length > $max) {
            throw new BackupDownloadRefused('RANGE_TOO_LARGE');
        }
        return new DownloadRange($start, $length, $total, '');
    }

    private function snapshotMatches(BackupDownloadGrant $grant, Website $website, Backup $backup, BackupObject $object): bool
    {
        try {
            $key = ObjectKey::assert($backup->objectKey($object->objectName()));
        } catch (Throwable) {
            return false;
        }

        return (int) $grant->website_id === (int) $website->id && (int) $grant->backup_id === (int) $backup->id
            && (int) $grant->backup_object_id === (int) $object->id && $grant->storage_provider === $backup->storage_provider
            && $grant->object_key === $key && hash_equals((string) $grant->object_sha256, strtolower((string) $object->sha256))
            && (int) $grant->object_size === (int) $object->size && $grant->object_name === $object->name;
    }

    private function assertGrantOwner(User $user, BackupDownloadGrant $grant): void
    {
        if ((int) $grant->user_id !== (int) $user->id || (int) $user->customer?->id !== (int) $grant->customer_id || $user->isOwner() || $user->isOperator()) {
            throw new BackupDownloadRefused('NOT_FOUND');
        }
    }

    private function requireEnabled(): void
    {
        if ($this->enabled() === false) {
            throw new BackupDownloadRefused('NOT_FOUND');
        }
    }

    private function recordFailure(BackupDownloadGrant $grant, string $code): void
    {
        $this->audit('failed', $grant->user, $grant->website, $grant->backup, $grant->object_name, $code);
    }

    private function audit(string $event, User $user, Website $website, Backup $backup, string $object, ?string $code = null, ?int $bytes = null): void
    {
        Log::info('Customer backup download event.', array_filter([
            'event' => $event,
            'actor_user_id' => $user->id,
            'website_uuid' => $website->uuid,
            'backup_uuid' => $backup->uuid,
            'object_name' => BackupObjectName::tryFrom($object)?->value,
            'code' => $code,
            'bytes' => $bytes,
        ], static fn ($value) => $value !== null));
    }
}
