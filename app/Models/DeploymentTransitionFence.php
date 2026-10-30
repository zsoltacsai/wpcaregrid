<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** The transactional Laravel copy of the current canonical fencing tuple. */
class DeploymentTransitionFence extends Model
{
    public const GLOBAL_KEY = 'global';

    public const STATES = [
        'OPEN',
        'HOLD_REQUESTED',
        'CLOSING',
        'CLOSED',
        'DRAINING',
        'QUIESCED',
        'READY_PENDING_OWNER',
        'RECOVERY_REQUIRED',
    ];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('The deployment transition fence is durable and cannot be deleted.');
        });
    }

    /** @return array{state:string,operation_id:string,generation:int,boot_id:string,journal_seq:int,journal_head_hash:string,evidence_digest:string} */
    public function tuple(): array
    {
        return [
            'state' => (string) $this->state,
            'operation_id' => (string) $this->operation_id,
            'generation' => (int) $this->generation,
            'boot_id' => (string) $this->boot_id,
            'journal_seq' => (int) $this->journal_seq,
            'journal_head_hash' => (string) $this->journal_head_hash,
            'evidence_digest' => (string) $this->evidence_digest,
        ];
    }

    public function hasValidTuple(): bool
    {
        $tuple = $this->tuple();
        $attributes = $this->getAttributes();
        $rawGeneration = $attributes['generation'] ?? null;
        $rawJournalSequence = $attributes['journal_seq'] ?? null;

        return $this->exists
            && $this->fence_key === self::GLOBAL_KEY
            && in_array($tuple['state'], self::STATES, true)
            && self::isUuidV7($tuple['operation_id'])
            && self::isNonNegativeInteger($rawGeneration)
            && self::isUuid($tuple['boot_id'])
            && self::isNonNegativeInteger($rawJournalSequence)
            && preg_match('/\A[0-9a-f]{64}\z/D', $tuple['journal_head_hash']) === 1
            && preg_match('/\A[0-9a-f]{64}\z/D', $tuple['evidence_digest']) === 1;
    }

    public function hasValidDrainManifest(): bool
    {
        return is_string($this->permit_manifest_hash)
            && preg_match('/\A[0-9a-f]{64}\z/D', $this->permit_manifest_hash) === 1;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'journal_seq' => 'integer',
        ];
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isUuidV7(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isNonNegativeInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_INT) !== false
            && (int) $value >= 0;
    }
}
