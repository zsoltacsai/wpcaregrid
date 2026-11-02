<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/** Append-only versioned lease state; the latest version is the current projection. */
class TransitionLeaseState extends Model
{
    protected $guarded = ['id'];

    /** Preserve the lease-state schema's microsecond timestamp precision. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    private const DIAGNOSTIC_STAGES = [
        'CREATE_STATE_SAVE', 'CLAIM_STATE_SAVE', 'REJECT_STATE_SAVE', 'DIRECT_STATE_SAVE',
    ];

    private string $diagnosticStage = 'DIRECT_STATE_SAVE';

    protected static function booted(): void
    {
        static::saving(function (self $state): void {
            $failure = $state->appendOnlyGuardFailureCode();
            if ($failure !== null) {
                throw new InvalidArgumentException("Invalid append-only lease state. [{$state->diagnosticStage}:{$failure}]");
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

    /** Assigns a fixed operation label used only if the append guard rejects this row. */
    public function setAppendOnlyDiagnosticStage(string $stage): void
    {
        if (! in_array($stage, self::DIAGNOSTIC_STAGES, true)) {
            throw new InvalidArgumentException('Unsupported append-only lease diagnostic stage.');
        }

        $this->diagnosticStage = $stage;
    }

    /** Returns the first failed guard predicate as a fixed, value-free code. */
    public function appendOnlyGuardFailureCode(): ?string
    {
        $checks = [
            'MODEL_ALREADY_EXISTS' => fn (): bool => ! $this->exists,
            'LEASE_ID_NOT_UUID_V7' => fn (): bool => self::isUuidV7($this->lease_id),
            'OPERATION_ID_NOT_UUID_V7' => fn (): bool => self::isUuidV7($this->operation_id),
            'GENERATION_NOT_INTEGER' => fn (): bool => is_int($this->generation),
            'GENERATION_NOT_NONNEGATIVE' => fn (): bool => $this->generation >= 0,
            'EXECUTION_ID_NOT_UUID_V7' => fn (): bool => self::isUuidV7($this->execution_id),
            'FENCE_TUPLE_DIGEST_INVALID' => fn (): bool => self::isSha256($this->fence_tuple_digest),
            'FENCE_STATE_UNSUPPORTED' => fn (): bool => in_array($this->fence_state, ['OPEN', 'HOLD_REQUESTED', 'CLOSING', 'CLOSED', 'DRAINING', 'QUIESCED', 'READY_PENDING_OWNER'], true),
            'FENCE_BOOT_ID_INVALID' => fn (): bool => self::isUuid($this->fence_boot_id),
            'FENCE_JOURNAL_SEQ_NOT_INTEGER' => fn (): bool => is_int($this->fence_journal_seq),
            'FENCE_JOURNAL_SEQ_NOT_NONNEGATIVE' => fn (): bool => $this->fence_journal_seq >= 0,
            'FENCE_JOURNAL_HEAD_HASH_INVALID' => fn (): bool => self::isSha256($this->fence_journal_head_hash),
            'FENCE_EVIDENCE_DIGEST_INVALID' => fn (): bool => self::isSha256($this->fence_evidence_digest),
            'FENCE_MANIFEST_MISMATCH' => fn (): bool => (($this->fence_state === 'DRAINING') === self::isSha256($this->fence_permit_manifest_hash)),
            'STATE_VERSION_NOT_INTEGER' => fn (): bool => is_int($this->state_version),
            'STATE_VERSION_UNSUPPORTED' => fn (): bool => in_array($this->state_version, [1, 2], true),
            'STATE_UNSUPPORTED' => fn (): bool => in_array($this->state, ['ISSUED', 'CLAIMED', 'REJECTED'], true),
            'EVENT_TYPE_UNSUPPORTED' => fn (): bool => in_array($this->event_type, ['CREATED', 'CLAIMED', 'REJECTED'], true),
            'HOLDER_DIGEST_INVALID' => fn (): bool => self::isSha256($this->holder_digest),
            'STATE_DIGEST_INVALID' => fn (): bool => self::isSha256($this->state_digest),
            'REQUEST_DIGEST_INVALID' => fn (): bool => self::isSha256($this->request_digest),
            'SOURCE_STATE_DIGEST_INVALID' => fn (): bool => $this->source_state_digest === null || self::isSha256($this->source_state_digest),
            'REQUEST_ID_NOT_UUID_V7' => fn (): bool => self::isUuidV7($this->request_id),
            'EXPIRY_MISSING' => fn (): bool => (bool) $this->expires_at,
            'OCCURRED_AT_MISSING' => fn (): bool => (bool) $this->occurred_at,
            'VERSION_STATE_PAIR_INVALID' => fn (): bool => self::validVersionStatePair($this),
            'FENCE_BINDING_INVALID' => fn (): bool => $this->hasValidFenceBinding(),
            'STATE_DIGEST_MISMATCH' => fn (): bool => self::stateDigestMatches($this),
        ];

        foreach ($checks as $code => $check) {
            if (! $check()) {
                return $code;
            }
        }

        return null;
    }

    private static function stateDigestMatches(self $state): bool
    {
        return hash_equals((string) $state->state_digest, self::digestForState([
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
        ]));
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
