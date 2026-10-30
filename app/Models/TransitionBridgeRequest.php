<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use LogicException;

/** Immutable proof that one bridge transition and its fence CAS committed together. */
class TransitionBridgeRequest extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            if ($request->exists) {
                throw new LogicException('A committed transition bridge receipt is immutable.');
            }

            if (! self::isUuidV7($request->request_id)
                || ! self::isSha256($request->request_digest)
                || $request->action !== 'compare_and_set'
                || ! is_string($request->receipt_payload)
                || strlen($request->receipt_payload) > 65536
                || ! self::isSha256($request->receipt_digest)
                || ! hash_equals(hash('sha256', $request->receipt_payload), $request->receipt_digest)
                || ! self::validReceiptPayload($request->receipt_payload, $request->request_id, $request->request_digest, $request->release_nonce_digest)) {
                throw new InvalidArgumentException('Invalid transition bridge receipt.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A committed transition bridge receipt cannot be deleted.');
        });
    }

    /** @return array<string, mixed>|null */
    public function verifiedReceipt(string $expectedDigest): ?array
    {
        if (! self::isSha256($expectedDigest)
            || ! hash_equals((string) $this->request_digest, $expectedDigest)
            || ! is_string($this->receipt_payload)
            || ! self::isSha256($this->receipt_digest)
            || ! hash_equals(hash('sha256', $this->receipt_payload), $this->receipt_digest)
            || ! self::validReceiptPayload($this->receipt_payload, (string) $this->request_id, $expectedDigest, $this->release_nonce_digest)) {
            return null;
        }

        try {
            $receipt = json_decode($this->receipt_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($receipt) ? $receipt : null;
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['committed_at' => 'immutable_datetime'];
    }

    private static function validReceiptPayload(
        string $payload,
        string $requestId,
        string $requestDigest,
        mixed $releaseNonceDigest,
    ): bool {
        try {
            $receipt = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($receipt)) {
                return false;
            }
            $keys = is_array($receipt) ? array_keys($receipt) : [];
            sort($keys);
            $expectedKeys = ['committed', 'release_binding', 'request_digest', 'request_id', 'source', 'target'];
            sort($expectedKeys);
            $canonical = self::canonicalJson($receipt);
        } catch (JsonException|InvalidArgumentException) {
            return false;
        }

        return is_array($receipt)
            && $keys === $expectedKeys
            && hash_equals($payload, $canonical)
            && ($receipt['committed'] ?? null) === true
            && ($receipt['request_id'] ?? null) === $requestId
            && ($receipt['request_digest'] ?? null) === $requestDigest
            && self::validTuple($receipt['source'] ?? null)
            && self::validTuple($receipt['target'] ?? null)
            && (($receipt['release_binding'] ?? null) === null || is_array($receipt['release_binding']))
            && (($releaseNonceDigest === null && ($receipt['release_binding'] ?? null) === null)
                || (is_string($releaseNonceDigest)
                    && self::isSha256($releaseNonceDigest)
                    && is_array($receipt['release_binding'] ?? null)
                    && ($receipt['release_binding']['nonce_digest'] ?? null) === $releaseNonceDigest
                    && ! array_key_exists('nonce', $receipt['release_binding'])));
    }

    private static function validTuple(mixed $tuple): bool
    {
        if (! is_array($tuple)) {
            return false;
        }

        $states = ['OPEN', 'HOLD_REQUESTED', 'CLOSING', 'CLOSED', 'DRAINING', 'QUIESCED', 'READY_PENDING_OWNER', 'RECOVERY_REQUIRED'];
        $expected = ['state', 'operation_id', 'generation', 'boot_id', 'journal_seq', 'journal_head_hash', 'evidence_digest'];
        if (($tuple['state'] ?? null) === 'DRAINING') {
            $expected[] = 'permit_manifest_hash';
        }
        $keys = array_keys($tuple);
        sort($keys);
        sort($expected);

        return $keys === $expected
            && is_string($tuple['state'] ?? null)
            && in_array($tuple['state'], $states, true)
            && is_string($tuple['operation_id'] ?? null)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $tuple['operation_id']) === 1
            && is_int($tuple['generation'] ?? null)
            && $tuple['generation'] >= 0
            && is_string($tuple['boot_id'] ?? null)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $tuple['boot_id']) === 1
            && is_int($tuple['journal_seq'] ?? null)
            && $tuple['journal_seq'] >= 0
            && self::isSha256($tuple['journal_head_hash'] ?? null)
            && self::isSha256($tuple['evidence_digest'] ?? null)
            && (($tuple['state'] !== 'DRAINING') || self::isSha256($tuple['permit_manifest_hash'] ?? null));
    }

    /** @param array<string, mixed> $value */
    public static function canonicalJson(array $value): string
    {
        return self::canonicalJsonValue($value);
    }

    public static function canonicalJsonValue(mixed $value): string
    {
        $normalized = self::sortRecursively($value);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public static function canonicalRpcJson(mixed $value): string
    {
        return self::canonicalJsonValue($value);
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
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
