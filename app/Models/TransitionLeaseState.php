<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/** Append-only versioned lease state; the latest version is the current projection. */
class TransitionLeaseState extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $state): void {
            if ($state->exists
                || ! self::isUuidV7($state->lease_id)
                || ! self::isUuidV7($state->operation_id)
                || ! is_int($state->generation)
                || $state->generation < 0
                || ! self::isUuidV7($state->execution_id)
                || ! self::isSha256($state->fence_tuple_digest)
                || ! in_array($state->fence_state, ['OPEN', 'HOLD_REQUESTED', 'CLOSING', 'CLOSED', 'DRAINING', 'QUIESCED', 'READY_PENDING_OWNER'], true)
                || ! self::isUuid($state->fence_boot_id)
                || ! is_int($state->fence_journal_seq)
                || $state->fence_journal_seq < 0
                || ! self::isSha256($state->fence_journal_head_hash)
                || ! self::isSha256($state->fence_evidence_digest)
                || (($state->fence_state === 'DRAINING') !== self::isSha256($state->fence_permit_manifest_hash))
                || ! is_int($state->state_version)
                || ! in_array($state->state_version, [1, 2], true)
                || ! in_array($state->state, ['ISSUED', 'CLAIMED', 'REJECTED'], true)
                || ! in_array($state->event_type, ['CREATED', 'CLAIMED', 'REJECTED'], true)
                || ! self::isSha256($state->holder_digest)
                || ! self::isSha256($state->state_digest)
                || ! self::isSha256($state->request_digest)
                || ($state->source_state_digest !== null && ! self::isSha256($state->source_state_digest))
                || ! self::isUuidV7($state->request_id)
                || ! $state->expires_at
                || ! $state->occurred_at
                || ! self::validVersionStatePair($state)
                || ! $state->hasValidFenceBinding()
                || ! hash_equals((string) $state->state_digest, self::digestForState([
                    'lease_id' => $state->lease_id,
                    'operation_id' => $state->operation_id,
                    'generation' => $state->generation,
                    'execution_id' => $state->execution_id,
                    'fence_tuple_digest' => $state->fence_tuple_digest,
                    'fence_state' => $state->fence_state,
                    'fence_boot_id' => $state->fence_boot_id,
                    'fence_journal_seq' => $state->fence_journal_seq,
                    'fence_journal_head_hash' => $state->fence_journal_head_hash,
                    'fence_evidence_digest' => $state->fence_evidence_digest,
                    'fence_permit_manifest_hash' => $state->fence_permit_manifest_hash,
                    'state_version' => $state->state_version,
                    'state' => $state->state,
                    'event_type' => $state->event_type,
                    'holder_digest' => $state->holder_digest,
                    'expires_at' => $state->expires_at instanceof \DateTimeInterface
                        ? $state->expires_at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')
                        : (string) $state->expires_at,
                    'source_state_digest' => $state->source_state_digest,
                ]))) {
                throw new InvalidArgumentException('Invalid append-only lease state.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A committed Package B lease state cannot be deleted.');
        });
    }

    public static function digestForState(array $state): string
    {
        unset($state['state_digest'], $state['request_digest']);

        return hash('sha256', TransitionLeaseReceipt::canonicalJson($state));
    }

    public function hasValidFenceBinding(): bool
    {
        $tuple = [
            'state' => $this->fence_state,
            'operation_id' => $this->operation_id,
            'generation' => (int) $this->generation,
            'boot_id' => $this->fence_boot_id,
            'journal_seq' => (int) $this->fence_journal_seq,
            'journal_head_hash' => $this->fence_journal_head_hash,
            'evidence_digest' => $this->fence_evidence_digest,
        ];
        if ($this->fence_state === 'DRAINING') {
            $tuple['permit_manifest_hash'] = $this->fence_permit_manifest_hash;
        } elseif ($this->fence_permit_manifest_hash !== null) {
            return false;
        }

        return hash_equals((string) $this->fence_tuple_digest, hash('sha256', TransitionLeaseReceipt::canonicalJson($tuple)));
    }

    /** @return array<string, string|int> */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'state_version' => 'integer',
            'fence_journal_seq' => 'integer',
            'expires_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    private static function validVersionStatePair(self $state): bool
    {
        if ($state->state_version === 1) {
            return $state->event_type === 'CREATED' && $state->state === 'ISSUED' && $state->source_state_digest === null;
        }

        return $state->source_state_digest !== null
            && (($state->event_type === 'CLAIMED' && $state->state === 'CLAIMED')
                || ($state->event_type === 'REJECTED' && $state->state === 'REJECTED'));
    }

    private static function isUuidV7(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
