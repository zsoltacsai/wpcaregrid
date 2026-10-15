<?php

namespace App\Privacy;

use App\Platform\DisasterRecovery\PlatformBackupCryptography;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Uid\Ulid;
use Throwable;

/** Encrypted, independently stored append-only event objects; never mutates application data. */
final class ObjectStoragePrivacyTombstoneJournal implements PrivacyTombstoneJournal
{
    private const ACTIONS = ['account_access_restricted', 'privacy_export_revoked'];

    public function __construct(
        private readonly PrivacyTombstoneObjectStore $storage,
        private readonly PlatformBackupCryptography $cryptography,
    ) {}

    public function readiness(): array
    {
        try {
            $this->assertIndependentStorage();
            $files = $this->disk()->allFiles($this->eventsPath());
            $verified = 0;
            foreach ($files as $file) {
                $this->readEvent($file);
                $verified++;
            }

            return [
                'state' => 'ready',
                'reason' => 'journal_integrity_verified',
                'watermark' => $this->watermark(),
                'replay_verified' => false,
                'verified_events' => $verified,
            ];
        } catch (Throwable $exception) {
            return [
                'state' => 'blocked',
                'reason' => $this->safeReason($exception),
                'watermark' => null,
                'replay_verified' => false,
                'verified_events' => 0,
            ];
        }
    }

    public function append(array $event): array
    {
        $this->assertIndependentStorage();
        $event = $this->validateEvent($event);
        $path = $this->eventsPath().'/'.$event['event_id'].'.json.enc';
        $disk = $this->disk();
        if ($disk->exists($path)) {
            $existing = $this->readEvent($path);
            $this->assertSameAction($existing, $event);

            return $existing;
        }

        $event['sequence'] = $this->nextSequence();
        $event['schema_version'] = 1;
        $event['journaled_at'] = now('UTC')->toIso8601String();
        $keyId = (string) config('platform_backup.encryption.current_key_id');
        $envelope = $this->cryptography->encryptJournalRecord(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $keyId);
        $serialized = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (! $disk->put($path, $serialized, ['visibility' => 'private'])) {
            throw new RuntimeException('privacy_journal_write_failed');
        }
        $readback = $disk->get($path);
        if (! is_string($readback) || ! hash_equals(hash('sha256', $serialized), hash('sha256', $readback))) {
            throw new RuntimeException('privacy_journal_readback_failed');
        }
        $verified = $this->decodeEnvelope($readback);
        $this->assertSameAction($verified, $event);

        return $verified;
    }

    public function watermark(): ?string
    {
        $latest = null;
        foreach ($this->disk()->allFiles($this->eventsPath()) as $file) {
            $event = $this->readEvent($file);
            $sequence = $event['sequence'] ?? null;
            if (! is_string($sequence) || ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $sequence)) {
                throw new RuntimeException('privacy_journal_unknown_watermark');
            }
            if ($latest === null || strcmp($sequence, $latest) > 0) {
                $latest = $sequence;
            }
        }

        return $latest;
    }

    public function eventsAfter(?string $snapshotWatermark): iterable
    {
        if ($snapshotWatermark !== null && ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $snapshotWatermark)) {
            throw new RuntimeException('privacy_journal_unknown_watermark');
        }
        $events = [];
        foreach ($this->disk()->allFiles($this->eventsPath()) as $file) {
            $event = $this->readEvent($file);
            if ($snapshotWatermark === null || strcmp($event['sequence'], $snapshotWatermark) > 0) {
                $events[] = $event;
            }
        }
        usort($events, static fn (array $left, array $right): int => [$left['sequence'], $left['event_id']] <=> [$right['sequence'], $right['event_id']]);

        return $events;
    }

    private function assertIndependentStorage(): void
    {
        if (! $this->cryptography->journalKeyAvailable()) {
            throw new RuntimeException('privacy_journal_key_unavailable');
        }
        $testLocal = app()->environment('testing') && $this->storage->driver() === 'local';
        if (! $testLocal && (! $this->storage->configured() || $this->storage->driver() !== 's3')) {
            throw new RuntimeException('privacy_journal_independent_storage_unavailable');
        }
    }

    /** Generate a lexically monotonic ULID against the durable watermark. */
    private function nextSequence(): string
    {
        $latest = $this->watermark();
        $timeHex = sprintf('%012x', (int) now('UTC')->format('Uv'));
        $latestBinary = $latest === null ? null : Ulid::fromString($latest)->toBinary();
        $latestTimeHex = $latestBinary === null ? null : bin2hex(substr($latestBinary, 0, 6));

        if ($latestBinary !== null && strcmp($timeHex, $latestTimeHex) <= 0) {
            $timeHex = $latestTimeHex;
            $entropyHex = bin2hex(substr($latestBinary, 6));
            for ($index = 19; $index >= 0; $index--) {
                $nibble = hexdec($entropyHex[$index]);
                if ($nibble < 15) {
                    $entropyHex[$index] = dechex($nibble + 1);
                    break;
                }
                $entropyHex[$index] = '0';
            }
            if ($index < 0) {
                $timeHex = sprintf('%012x', hexdec($timeHex) + 1);
                $entropyHex = bin2hex(random_bytes(10));
            }
        } else {
            $entropyHex = bin2hex(random_bytes(10));
        }

        return Ulid::fromString(hex2bin($timeHex.$entropyHex))->toBase32();
    }

    private function disk(): Filesystem
    {
        return $this->storage->disk();
    }

    private function eventsPath(): string
    {
        $environment = (string) config('platform.deployment_environment', app()->environment());
        if (! in_array($environment, ['local', 'testing', 'staging', 'production'], true)) {
            throw new RuntimeException('privacy_journal_environment_unknown');
        }

        return trim((string) config('platform_backup.prefix', 'platform-database'), '/').'/privacy-tombstones/'.$environment.'/events';
    }

    private function validateEvent(array $event): array
    {
        $required = ['event_id', 'subject_uuid', 'tenant_uuid', 'scope', 'action', 'request_uuid', 'effective_at'];
        foreach ($required as $field) {
            if (! is_string($event[$field] ?? null) || trim($event[$field]) === '') {
                throw new RuntimeException('privacy_journal_event_invalid');
            }
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/', $event['event_id'])
            || ! Str::isUuid($event['subject_uuid'])
            || ! Str::isUuid($event['tenant_uuid'])
            || ! Str::isUuid($event['request_uuid'])
            || $event['subject_uuid'] !== $event['tenant_uuid']
            || $event['scope'] !== 'customer_account'
            || ! in_array($event['action'], self::ACTIONS, true)
            || ! strtotime($event['effective_at'])) {
            throw new RuntimeException('privacy_journal_event_invalid');
        }

        if (isset($event['artifact_uuid']) && (! is_string($event['artifact_uuid']) || ! Str::isUuid($event['artifact_uuid']))) {
            throw new RuntimeException('privacy_journal_event_invalid');
        }
        $allowed = array_flip([...$required, 'artifact_uuid', 'policy_version']);

        return array_intersect_key($event, $allowed);
    }

    private function readEvent(string $path): array
    {
        $bytes = $this->disk()->get($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('privacy_journal_object_unavailable');
        }
        $event = $this->decodeEnvelope($bytes);
        $this->validateEvent($event + ['sequence' => $event['sequence'] ?? null]);
        if (($event['schema_version'] ?? null) !== 1
            || ! is_string($event['sequence'] ?? null)
            || ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $event['sequence'])
            || ! is_string($event['journaled_at'] ?? null)) {
            throw new RuntimeException('privacy_journal_unsupported_event');
        }
        if (basename($path) !== $event['event_id'].'.json.enc') {
            throw new RuntimeException('privacy_journal_integrity_failed');
        }

        return $event;
    }

    private function decodeEnvelope(string $bytes): array
    {
        try {
            $envelope = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($envelope)) {
                throw new RuntimeException('privacy_journal_integrity_failed');
            }
            $event = json_decode($this->cryptography->decryptJournalRecord($envelope), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($event)) {
                throw new RuntimeException('privacy_journal_integrity_failed');
            }

            return $event;
        } catch (Throwable $exception) {
            throw new RuntimeException('privacy_journal_integrity_failed', previous: $exception);
        }
    }

    private function assertSameAction(array $existing, array $requested): void
    {
        foreach (['event_id', 'subject_uuid', 'tenant_uuid', 'scope', 'action', 'request_uuid', 'artifact_uuid', 'effective_at', 'policy_version'] as $field) {
            if (($existing[$field] ?? null) !== ($requested[$field] ?? null)) {
                throw new RuntimeException('privacy_journal_idempotency_conflict');
            }
        }
    }

    private function safeReason(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/\Aprivacy_journal_[a-z0-9_]+\z/', $message) ? $message : 'privacy_journal_unavailable';
    }
}
