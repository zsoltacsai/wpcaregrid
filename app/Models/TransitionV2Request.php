<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use LogicException;

/** Immutable SQL receipt for a HOLD-preserving Phase 2B fence compare-and-set. */
class TransitionV2Request extends Model
{
    public const OPERATION = 'compare_and_set_hold';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            if ($request->exists) {
                throw new LogicException('A committed Phase 2B request receipt is immutable.');
            }

            if (! self::isUuidV7($request->request_id)
                || ! self::isSha256($request->request_digest)
                || $request->operation !== self::OPERATION
                || ! self::isUuidV7($request->operation_id)
                || ! is_int($request->generation)
                || $request->generation < 0
                || ($request->source_tuple_digest !== null && ! self::isSha256($request->source_tuple_digest))
                || ! self::isSha256($request->target_tuple_digest)
                || ! is_int($request->history_sequence)
                || $request->history_sequence < 1
                || ! is_string($request->receipt_payload)
                || strlen($request->receipt_payload) > 65536
                || ! self::isSha256($request->receipt_digest)
                || ! hash_equals(hash('sha256', $request->receipt_payload), $request->receipt_digest)
                || ! self::validReceiptPayload(
                    $request->receipt_payload,
                    (string) $request->request_id,
                    (string) $request->request_digest,
                    (string) $request->operation_id,
                    (int) $request->generation,
                    $request->source_tuple_digest,
                    (string) $request->target_tuple_digest,
                    (int) $request->history_sequence,
                )) {
                throw new InvalidArgumentException('Invalid Phase 2B transition request receipt.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A committed Phase 2B request receipt cannot be deleted.');
        });
    }

    /** @return array<string, mixed>|null */
    public function verifiedReceipt(string $expectedDigest): ?array
    {
        if (! self::isSha256($expectedDigest)
            || ! is_string($this->request_digest)
            || ! hash_equals($this->request_digest, $expectedDigest)
            || $this->operation !== self::OPERATION
            || ! is_string($this->receipt_payload)
            || ! self::isSha256($this->receipt_digest)
            || ! hash_equals(hash('sha256', $this->receipt_payload), $this->receipt_digest)
            || ! self::validReceiptPayload(
                $this->receipt_payload,
                (string) $this->request_id,
                $expectedDigest,
                (string) $this->operation_id,
                (int) $this->generation,
                $this->source_tuple_digest,
                (string) $this->target_tuple_digest,
                (int) $this->history_sequence,
            )) {
            return null;
        }

        try {
            $receipt = json_decode($this->receipt_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($receipt)
            || ! isset($receipt['target']['operation_id'], $receipt['target']['generation'])
            || $receipt['target']['operation_id'] !== $this->operation_id
            || $receipt['target']['generation'] !== (int) $this->generation) {
            return null;
        }

        return $receipt;
    }

    /** @param array<string, mixed>|null $source @param array<string, mixed> $target */
    public static function digestForRequest(string $requestId, ?array $source, array $target): string
    {
        return hash('sha256', self::canonicalJsonValue([
            'operation' => self::OPERATION,
            'request_id' => $requestId,
            'source' => $source,
            'target' => $target,
        ]));
    }

    /** @param array<string, mixed> $tuple */
    public static function digestForTuple(array $tuple): string
    {
        return hash('sha256', self::canonicalJsonValue($tuple));
    }

    /** @param array<string, mixed> $value */
    public static function canonicalJson(array $value): string
    {
        return self::canonicalJsonValue($value);
    }

    public static function canonicalJsonValue(mixed $value): string
    {
        return json_encode(
            self::sortRecursively($value),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'history_sequence' => 'integer',
            'generation' => 'integer',
            'committed_at' => 'immutable_datetime',
        ];
    }

    private static function validReceiptPayload(
        string $payload,
        string $requestId,
        string $requestDigest,
        string $operationId,
        int $generation,
        mixed $sourceTupleDigest,
        string $targetTupleDigest,
        int $historySequence,
    ): bool {
        try {
            $receipt = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($receipt)) {
                return false;
            }
            $keys = array_keys($receipt);
            sort($keys);
            $expectedKeys = ['committed', 'history_sequence', 'operation', 'request_digest', 'request_id', 'source', 'target'];
            sort($expectedKeys);
        } catch (JsonException) {
            return false;
        }

        $source = $receipt['source'] ?? null;
        $target = $receipt['target'] ?? null;
        if (! is_array($target)
            || ! self::validTuple($target)
            || ($source !== null && (! is_array($source) || ! self::validTuple($source)))) {
            return false;
        }

        return $keys === $expectedKeys
            && hash_equals($payload, self::canonicalJsonValue($receipt))
            && ($receipt['committed'] ?? null) === true
            && ($receipt['operation'] ?? null) === self::OPERATION
            && ($receipt['request_id'] ?? null) === $requestId
            && ($receipt['request_digest'] ?? null) === $requestDigest
            && ($receipt['history_sequence'] ?? null) === $historySequence
            && hash_equals($requestDigest, self::digestForRequest($requestId, $source, $target))
            && $target['operation_id'] === $operationId
            && $target['generation'] === $generation
            && (($source === null && $sourceTupleDigest === null)
                || ($source !== null
                    && is_string($sourceTupleDigest)
                    && self::isSha256($sourceTupleDigest)
                    && hash_equals(self::digestForTuple($source), $sourceTupleDigest)))
            && self::isSha256($targetTupleDigest)
            && hash_equals(self::digestForTuple($target), $targetTupleDigest);
    }

    /** @param array<string, mixed> $tuple */
    public static function validTuple(array $tuple): bool
    {
        $keys = array_keys($tuple);
        sort($keys);
        $expected = ['boot_id', 'evidence_digest', 'generation', 'journal_head_hash', 'journal_seq', 'operation_id', 'source_sha', 'state'];
        sort($expected);

        return $keys === $expected
            && ($tuple['state'] ?? null) === 'HOLD'
            && self::isUuidV7($tuple['operation_id'] ?? null)
            && is_int($tuple['generation'] ?? null)
            && $tuple['generation'] >= 0
            && self::isUuid($tuple['boot_id'] ?? null)
            && self::isSha1($tuple['source_sha'] ?? null)
            && is_int($tuple['journal_seq'] ?? null)
            && $tuple['journal_seq'] >= 0
            && self::isSha256($tuple['journal_head_hash'] ?? null)
            && self::isSha256($tuple['evidence_digest'] ?? null);
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

    private static function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isSha1(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{40}\z/D', $value) === 1;
    }

    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
