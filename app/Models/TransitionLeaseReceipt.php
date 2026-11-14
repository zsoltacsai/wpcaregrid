<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use LogicException;

/** Immutable request/response receipt for the inert Package B lease protocol. */
class TransitionLeaseReceipt extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $receipt): void {
            $actions = ['create_lease', 'claim_lease', 'reject_lease'];
            if ($receipt->exists
                || ! self::isUuidV7($receipt->request_id)
                || ! self::isSha256($receipt->request_digest)
                || ! in_array($receipt->action, $actions, true)
                || ! self::isUuidV7($receipt->operation_id)
                || ! is_int($receipt->generation)
                || $receipt->generation < 0
                || ! self::isUuidV7($receipt->execution_id)
                || ! self::isUuidV7($receipt->lease_id)
                || ! self::isSha256($receipt->fence_tuple_digest)
                || ! in_array($receipt->fence_state, ['OPEN', 'HOLD_REQUESTED', 'CLOSING', 'CLOSED', 'DRAINING', 'QUIESCED', 'READY_PENDING_OWNER'], true)
                || ! self::isUuid($receipt->fence_boot_id)
                || ! is_int($receipt->fence_journal_seq)
                || $receipt->fence_journal_seq < 0
                || ! self::isSha256($receipt->fence_journal_head_hash)
                || ! self::isSha256($receipt->fence_evidence_digest)
                || (($receipt->fence_state === 'DRAINING') !== self::isSha256($receipt->fence_permit_manifest_hash))
                || ! self::hasValidFenceBinding($receipt)
                || ($receipt->nonce_digest !== null && ! self::isSha256($receipt->nonce_digest))
                || ! in_array($receipt->outcome, ['COMMITTED', 'REFUSED'], true)
                || ! self::validResultCode($receipt->result_code)
                || ! is_string($receipt->receipt_payload)
                || strlen($receipt->receipt_payload) > 16384
                || ! self::isSha256($receipt->receipt_digest)
                || ! hash_equals(hash('sha256', $receipt->receipt_payload), $receipt->receipt_digest)
                || ! self::validReceiptPayload($receipt)) {
                throw new InvalidArgumentException('Invalid Package B lease receipt.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A committed Package B lease receipt cannot be deleted.');
        });
    }

    /** @return array<string, mixed>|null */
    public function verifiedReceipt(string $expectedDigest): ?array
    {
        if (! self::isSha256($expectedDigest)
            || ! hash_equals((string) $this->request_digest, $expectedDigest)
            || ! hash_equals(hash('sha256', (string) $this->receipt_payload), (string) $this->receipt_digest)
            || ! self::validReceiptPayload($this)) {
            return null;
        }

        try {
            $receipt = json_decode((string) $this->receipt_payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($receipt) ? $receipt : null;
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sortRecursively($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['generation' => 'integer', 'fence_journal_seq' => 'integer', 'committed_at' => 'immutable_datetime'];
    }

    private static function validReceiptPayload(self $row): bool
    {
        try {
            $receipt = json_decode((string) $row->receipt_payload, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($receipt)) {
                return false;
            }
            $keys = array_keys($receipt);
            sort($keys);
            $expected = [
                'action', 'committed', 'execution_id', 'fence_tuple_digest', 'fence_state', 'fence_boot_id', 'fence_journal_seq',
                'fence_journal_head_hash', 'fence_evidence_digest', 'fence_permit_manifest_hash', 'generation', 'lease_id', 'nonce_digest',
                'operation_id', 'outcome', 'request_digest', 'request_id', 'source_state_digest',
                'result_code', 'state', 'state_digest', 'state_version',
            ];
            sort($expected);
        } catch (JsonException) {
            return false;
        }

        if ($keys !== $expected
            || ! hash_equals((string) $row->receipt_payload, self::canonicalJson($receipt))
            || ($receipt['request_id'] ?? null) !== $row->request_id
            || ($receipt['request_digest'] ?? null) !== $row->request_digest
            || ($receipt['action'] ?? null) !== $row->action
            || ($receipt['operation_id'] ?? null) !== $row->operation_id
            || ($receipt['generation'] ?? null) !== (int) $row->generation
            || ($receipt['execution_id'] ?? null) !== $row->execution_id
            || ($receipt['fence_tuple_digest'] ?? null) !== $row->fence_tuple_digest
            || ($receipt['fence_state'] ?? null) !== $row->fence_state
            || ($receipt['fence_boot_id'] ?? null) !== $row->fence_boot_id
            || ($receipt['fence_journal_seq'] ?? null) !== (int) $row->fence_journal_seq
            || ($receipt['fence_journal_head_hash'] ?? null) !== $row->fence_journal_head_hash
            || ($receipt['fence_evidence_digest'] ?? null) !== $row->fence_evidence_digest
            || ($receipt['fence_permit_manifest_hash'] ?? null) !== $row->fence_permit_manifest_hash
            || ($receipt['lease_id'] ?? null) !== $row->lease_id
            || ($receipt['nonce_digest'] ?? null) !== $row->nonce_digest
            || ($receipt['outcome'] ?? null) !== $row->outcome
            || ($receipt['result_code'] ?? null) !== $row->result_code
            || (($receipt['committed'] ?? null) !== ($row->outcome === 'COMMITTED'))
            || ! self::hasValidFenceBinding($row)) {
            return false;
        }

        if ($row->outcome === 'REFUSED') {
            return self::validRefusal($row, $receipt);
        }

        return self::validCommittedState($row, $receipt);
    }

    /** @param array<string, mixed> $receipt */
    private static function validCommittedState(self $row, array $receipt): bool
    {
        if ($row->outcome !== 'COMMITTED'
            || ! self::isSha256($receipt['state_digest'] ?? null)
            || ! is_int($receipt['state_version'] ?? null)
            || (($receipt['source_state_digest'] ?? null) !== null && ! self::isSha256($receipt['source_state_digest']))) {
            return false;
        }

        return match ($row->action) {
            'create_lease' => $row->result_code === 'CREATED' && ($receipt['state'] ?? null) === 'ISSUED'
                && $receipt['state_version'] === 1 && ($receipt['source_state_digest'] ?? null) === null,
            'claim_lease' => $row->result_code === 'CLAIMED' && ($receipt['state'] ?? null) === 'CLAIMED'
                && $receipt['state_version'] === 2 && self::isSha256($receipt['source_state_digest'] ?? null),
            'reject_lease' => in_array($row->result_code, ['CANCELLED', 'SUPERSEDED', 'OWNER_REVOKED', 'STALE_BOOT'], true)
                && ($receipt['state'] ?? null) === 'REJECTED' && $receipt['state_version'] === 2
                && self::isSha256($receipt['source_state_digest'] ?? null),
            default => false,
        };
    }

    /** @param array<string, mixed> $receipt */
    private static function validRefusal(self $row, array $receipt): bool
    {
        $codes = match ($row->action) {
            'create_lease' => ['STALE_GENERATION', 'STALE_FENCE_TUPLE', 'FENCE_STATE_MISMATCH', 'EXECUTION_ALREADY_LEASED', 'LEASE_EXPIRED', 'NONCE_REPLAY'],
            'claim_lease' => ['STALE_GENERATION', 'STALE_FENCE_TUPLE', 'FENCE_STATE_MISMATCH', 'LEASE_NOT_FOUND', 'LEASE_EXPIRED', 'LEASE_NOT_CLAIMABLE', 'HOLDER_MISMATCH', 'NONCE_REPLAY'],
            'reject_lease' => ['STALE_GENERATION', 'STALE_FENCE_TUPLE', 'FENCE_STATE_MISMATCH', 'LEASE_NOT_FOUND', 'LEASE_NOT_CLAIMABLE', 'HOLDER_MISMATCH', 'NONCE_REPLAY'],
            default => [],
        };

        return in_array($row->result_code, $codes, true)
            && ($receipt['state'] ?? null) === null
            && ($receipt['state_digest'] ?? null) === null
            && ($receipt['state_version'] ?? null) === null
            && ($receipt['source_state_digest'] ?? null) === null
            && (($row->result_code === 'NONCE_REPLAY') === ($row->nonce_digest === null));
    }

    private static function validResultCode(mixed $code): bool
    {
        return $code === null || in_array($code, [
            'CREATED', 'CLAIMED', 'REJECTED', 'STALE_GENERATION', 'STALE_FENCE_TUPLE', 'FENCE_STATE_MISMATCH',
            'EXECUTION_ALREADY_LEASED', 'LEASE_NOT_FOUND', 'LEASE_EXPIRED', 'LEASE_NOT_CLAIMABLE',
            'HOLDER_MISMATCH', 'NONCE_REPLAY', 'CANCELLED', 'SUPERSEDED', 'OWNER_REVOKED', 'STALE_BOOT',
        ], true);
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            foreach ($properties as $key => $nested) {
                $properties[$key] = self::sortRecursively($nested);
            }

            return (object) $properties;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = self::sortRecursively($nested);
        }

        return $value;
    }

    private static function isUuidV7(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }

    private static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function hasValidFenceBinding(self $row): bool
    {
        $tuple = [
            'state' => $row->fence_state,
            'operation_id' => $row->operation_id,
            'generation' => (int) $row->generation,
            'boot_id' => $row->fence_boot_id,
            'journal_seq' => (int) $row->fence_journal_seq,
            'journal_head_hash' => $row->fence_journal_head_hash,
            'evidence_digest' => $row->fence_evidence_digest,
        ];
        if ($row->fence_state === 'DRAINING') {
            $tuple['permit_manifest_hash'] = $row->fence_permit_manifest_hash;
        } elseif ($row->fence_permit_manifest_hash !== null) {
            return false;
        }

        return hash_equals((string) $row->fence_tuple_digest, hash('sha256', self::canonicalJson($tuple)));
    }
}
