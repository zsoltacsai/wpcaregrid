<?php

namespace App\Privacy;

use App\Platform\DisasterRecovery\PlatformBackupCryptography;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Uid\Ulid;
use Throwable;

/** Encrypted append-only events and commit checkpoints; never mutates application data. */
final class ObjectStoragePrivacyTombstoneJournal implements PrivacyTombstoneJournal
{
    private const ACTIONS = ['account_access_restricted', 'privacy_export_revoked', 'profile_anonymized'];

    public function __construct(
        private readonly PrivacyTombstoneObjectStore $storage,
        private readonly PlatformBackupCryptography $cryptography,
    ) {}

    public function readiness(): array
    {
        try {
            $this->assertIndependentStorage();
            $journal = $this->readJournal();

            return [
                'state' => 'ready',
                'reason' => 'journal_integrity_verified',
                'watermark' => $journal['watermark'],
                'replay_verified' => false,
                'verified_events' => count($journal['events']),
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
            $journal = $this->readJournal(allowUncommitted: true);
            if (isset($journal['committed_event_ids'][$event['event_id']])) {
                return $existing;
            }
            if (count($journal['uncommitted_events']) !== 1
                || ! isset($journal['uncommitted_events'][$event['event_id']])
                || ($journal['watermark'] !== null && strcmp($existing['sequence'], $journal['watermark']) <= 0)) {
                throw new RuntimeException('privacy_journal_uncommitted_event');
            }
            $this->writeCheckpoint($existing, $disk->get($path), $journal['watermark']);
            $this->readJournal();

            return $existing;
        }

        $journal = $this->readJournal();
        $event['sequence'] = $this->nextSequence($journal['watermark']);
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
        $this->writeCheckpoint($verified, $readback, $journal['watermark']);
        $this->readJournal();

        return $verified;
    }

    public function watermark(): ?string
    {
        return $this->readJournal()['watermark'];
    }

    public function eventsAfter(?string $snapshotWatermark): iterable
    {
        if ($snapshotWatermark !== null && ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $snapshotWatermark)) {
            throw new RuntimeException('privacy_journal_unknown_watermark');
        }
        $journal = $this->readJournal();
        $events = [];
        foreach ($journal['events'] as $event) {
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
    private function nextSequence(?string $latest): string
    {
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

    private function checkpointsPath(): string
    {
        return trim((string) config('platform_backup.prefix', 'platform-database'), '/').'/privacy-tombstones/'.basename(dirname($this->eventsPath())).'/commits';
    }

    /** @return array{watermark:?string,events:list<array<string,mixed>>,committed_event_ids:array<string,true>,uncommitted_events:array<string,array<string,mixed>>} */
    private function readJournal(bool $allowUncommitted = false): array
    {
        $disk = $this->disk();
        $eventFiles = $disk->allFiles($this->eventsPath());
        $checkpointFiles = $disk->allFiles($this->checkpointsPath());
        $eventsById = [];
        foreach ($eventFiles as $file) {
            $event = $this->readEvent($file);
            if (isset($eventsById[$event['event_id']]) || isset($eventsById['sequence:'.$event['sequence']])) {
                throw new RuntimeException('privacy_journal_integrity_failed');
            }
            $eventsById[$event['event_id']] = $event;
            $eventsById['sequence:'.$event['sequence']] = $event;
        }

        $checkpoints = [];
        foreach ($checkpointFiles as $file) {
            $checkpoint = $this->readCheckpoint($file);
            if (isset($checkpoints[$checkpoint['sequence']])) {
                throw new RuntimeException('privacy_journal_integrity_failed');
            }
            $checkpoints[$checkpoint['sequence']] = $checkpoint;
        }
        ksort($checkpoints, SORT_STRING);

        $watermark = null;
        $committed = [];
        $committedIds = [];
        foreach ($checkpoints as $sequence => $checkpoint) {
            if ($checkpoint['previous_sequence'] !== $watermark) {
                throw new RuntimeException('privacy_journal_ordering_ambiguous');
            }
            $event = $eventsById[$checkpoint['event_id']] ?? null;
            if (! is_array($event)) {
                throw new RuntimeException('privacy_journal_object_unavailable');
            }
            $eventPath = $this->eventsPath().'/'.$checkpoint['event_id'].'.json.enc';
            $eventBytes = $disk->get($eventPath);
            if (! is_string($eventBytes)
                || $event['sequence'] !== $sequence
                || ! hash_equals($checkpoint['event_hash'], hash('sha256', $eventBytes))
                || isset($committedIds[$checkpoint['event_id']])) {
                throw new RuntimeException('privacy_journal_integrity_failed');
            }
            $committed[] = $event;
            $committedIds[$checkpoint['event_id']] = true;
            $watermark = $sequence;
        }

        $uncommitted = [];
        foreach ($eventFiles as $file) {
            $event = $this->readEvent($file);
            if (! isset($committedIds[$event['event_id']])) {
                $uncommitted[$event['event_id']] = $event;
            }
        }
        if ($uncommitted !== [] && ! $allowUncommitted) {
            throw new RuntimeException('privacy_journal_uncommitted_event');
        }

        return [
            'watermark' => $watermark,
            'events' => $committed,
            'committed_event_ids' => $committedIds,
            'uncommitted_events' => $uncommitted,
        ];
    }

    private function writeCheckpoint(array $event, mixed $eventBytes, ?string $previousSequence): void
    {
        if (! is_string($eventBytes) || ! is_string($event['sequence'] ?? null)) {
            throw new RuntimeException('privacy_journal_integrity_failed');
        }
        $checkpoint = [
            'schema_version' => 1,
            'sequence' => $event['sequence'],
            'previous_sequence' => $previousSequence,
            'event_id' => $event['event_id'],
            'event_hash' => hash('sha256', $eventBytes),
        ];
        $path = $this->checkpointsPath().'/'.$event['sequence'].'.json.enc';
        $disk = $this->disk();
        if ($disk->exists($path)) {
            throw new RuntimeException('privacy_journal_integrity_failed');
        }
        $keyId = (string) config('platform_backup.encryption.current_key_id');
        $envelope = $this->cryptography->encryptJournalRecord(json_encode($checkpoint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $keyId);
        $serialized = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (! $disk->put($path, $serialized, ['visibility' => 'private'])) {
            throw new RuntimeException('privacy_journal_write_failed');
        }
        $readback = $disk->get($path);
        if (! is_string($readback) || ! hash_equals(hash('sha256', $serialized), hash('sha256', $readback))) {
            throw new RuntimeException('privacy_journal_readback_failed');
        }
        $this->readCheckpoint($path);
    }

    private function readCheckpoint(string $path): array
    {
        $bytes = $this->disk()->get($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('privacy_journal_object_unavailable');
        }
        $checkpoint = $this->decodeEnvelope($bytes);
        $sequence = $checkpoint['sequence'] ?? null;
        $previousSequence = $checkpoint['previous_sequence'] ?? null;
        if (($checkpoint['schema_version'] ?? null) !== 1
            || ! is_string($sequence)
            || ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $sequence)
            || ($previousSequence !== null && (! is_string($previousSequence) || ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $previousSequence)))
            || ! is_string($checkpoint['event_id'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $checkpoint['event_id'])
            || ! is_string($checkpoint['event_hash'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $checkpoint['event_hash'])
            || basename($path) !== $sequence.'.json.enc') {
            throw new RuntimeException('privacy_journal_unsupported_event');
        }

        return $checkpoint;
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
