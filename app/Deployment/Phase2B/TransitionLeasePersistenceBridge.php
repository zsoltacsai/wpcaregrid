<?php

namespace App\Deployment\Phase2B;

use App\Models\TransitionLeaseReceipt;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Independent, inert typed RPC v2 contract; does not change Package A RPC v1. */
final class TransitionLeasePersistenceBridge
{
    public const PROTOCOL_VERSION = 2;

    private const PAYLOAD_KEYS = [
        'create_lease' => ['execution_id', 'expected_fence_state', 'expires_at', 'fence_tuple', 'generation', 'holder_digest', 'lease_id', 'nonce_digest', 'operation_id'],
        'claim_lease' => ['execution_id', 'expected_fence_state', 'fence_tuple', 'generation', 'holder_digest', 'lease_id', 'nonce_digest', 'operation_id'],
        'reject_lease' => ['execution_id', 'expected_fence_state', 'fence_tuple', 'generation', 'holder_digest', 'lease_id', 'nonce_digest', 'operation_id', 'rejection_code'],
        'read_lease_receipt' => ['fence_tuple', 'request_digest', 'request_id'],
    ];

    public function __construct(private readonly TransitionLeaseStore $store) {}

    /** @param array<string, mixed> $envelope @return array<string, mixed> */
    public function handleRpc(array $envelope): array
    {
        $request = $this->validateEnvelope($envelope);
        if ($request['operation'] === 'read_lease_receipt') {
            $receipt = $this->store->readReceipt(
                $request['payload']['request_id'], $request['payload']['request_digest'], $request['payload']['fence_tuple'],
            );
            $status = 'READ_ONLY';
            $result = ['found' => $receipt !== null, 'receipt' => $receipt];
        } else {
            $receipt = $this->store->mutate($request);
            $status = $receipt['outcome'];
            $result = $receipt;
        }

        return [
            'version' => self::PROTOCOL_VERSION,
            'request_id' => $request['request_id'],
            'request_digest' => $request['request_digest'],
            'status' => $status,
            'result' => $result,
        ];
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function validateEnvelope(array $request): array
    {
        $keys = array_keys($request);
        sort($keys);
        $expected = ['operation', 'payload', 'request_digest', 'request_id', 'version'];
        sort($expected);
        if ($keys !== $expected
            || ($request['version'] ?? null) !== self::PROTOCOL_VERSION
            || ! self::isUuidV7($request['request_id'] ?? null)
            || ! self::isSha256($request['request_digest'] ?? null)
            || ! is_string($request['operation'] ?? null)
            || ! array_key_exists($request['operation'], self::PAYLOAD_KEYS)
            || ! is_array($request['payload'] ?? null)) {
            throw new InvalidArgumentException('The lease RPC v2 envelope is invalid.');
        }

        $payload = $request['payload'];
        $payloadKeys = array_keys($payload);
        sort($payloadKeys);
        $expectedPayloadKeys = self::PAYLOAD_KEYS[$request['operation']];
        sort($expectedPayloadKeys);
        if ($payloadKeys !== $expectedPayloadKeys) {
            throw new InvalidArgumentException('The lease RPC v2 payload shape is invalid.');
        }

        if ($request['operation'] === 'read_lease_receipt') {
            if (! self::isUuidV7($payload['request_id']) || ! self::isSha256($payload['request_digest'])
                || ! self::isFenceTuple($payload['fence_tuple'])) {
                throw new InvalidArgumentException('The lease receipt lookup identity is invalid.');
            }
        } else {
            $this->validateMutationPayload($request['operation'], $payload);
        }

        if (! hash_equals($request['request_digest'], self::digestForRequest(
            $request['request_id'], $request['operation'], $payload,
        ))) {
            throw new InvalidArgumentException('The lease RPC v2 digest does not match its canonical request.');
        }

        return $request;
    }

    private function validateMutationPayload(string $operation, array $payload): void
    {
        $fenceTuple = $payload['fence_tuple'] ?? null;
        if (! self::isUuidV7($payload['operation_id'] ?? null)
            || ! is_int($payload['generation'] ?? null)
            || $payload['generation'] < 0
            || ! self::isUuidV7($payload['execution_id'] ?? null)
            || ! self::isUuidV7($payload['lease_id'] ?? null)
            || ! in_array($payload['expected_fence_state'] ?? null, [
                'OPEN', 'HOLD_REQUESTED', 'CLOSING', 'CLOSED', 'DRAINING', 'QUIESCED', 'READY_PENDING_OWNER',
            ], true)
            || ! self::isFenceTuple($fenceTuple)
            || $fenceTuple['operation_id'] !== $payload['operation_id']
            || $fenceTuple['generation'] !== $payload['generation']
            || $fenceTuple['state'] !== $payload['expected_fence_state']
            || ! self::isUuid($fenceTuple['boot_id'] ?? null)
            || ! is_int($fenceTuple['journal_seq'] ?? null)
            || $fenceTuple['journal_seq'] < 0
            || ! self::isSha256($fenceTuple['journal_head_hash'] ?? null)
            || ! self::isSha256($fenceTuple['evidence_digest'] ?? null)
            || ! self::isSha256($payload['holder_digest'] ?? null)
            || ! self::isSha256($payload['nonce_digest'] ?? null)) {
            throw new InvalidArgumentException('The lease RPC v2 tuple or digest is invalid.');
        }

        if ($operation === 'create_lease') {
            $value = $payload['expires_at'] ?? null;
            if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $value)) {
                throw new InvalidArgumentException('The lease expiry must be canonical UTC with microseconds.');
            }
            try {
                $parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException('The lease expiry is invalid.', previous: $exception);
            }
            if ($parsed->format('Y-m-d\TH:i:s.u\Z') !== $value) {
                throw new InvalidArgumentException('The lease expiry is not canonical.');
            }
        }

        if ($operation === 'reject_lease'
            && ! in_array($payload['rejection_code'] ?? null, ['CANCELLED', 'SUPERSEDED', 'OWNER_REVOKED', 'STALE_BOOT'], true)) {
            throw new InvalidArgumentException('The lease rejection code is not allowlisted.');
        }
    }

    /** @param array<string, mixed> $tuple */
    public static function digestForFenceTuple(array $tuple): string
    {
        return hash('sha256', TransitionLeaseReceipt::canonicalJson($tuple));
    }

    /** @param array<string, mixed> $payload */
    public static function digestForRequest(string $requestId, string $operation, array $payload): string
    {
        return hash('sha256', TransitionLeaseReceipt::canonicalJson([
            'version' => self::PROTOCOL_VERSION,
            'request_id' => $requestId,
            'operation' => $operation,
            'payload' => $payload,
        ]));
    }

    private static function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isFenceTuple(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        $expected = ['boot_id', 'evidence_digest', 'generation', 'journal_head_hash', 'journal_seq', 'operation_id', 'state'];
        if (($value['state'] ?? null) === 'DRAINING') {
            $expected[] = 'permit_manifest_hash';
        }
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected
            && in_array($value['state'] ?? null, [
                'OPEN', 'HOLD_REQUESTED', 'CLOSING', 'CLOSED', 'DRAINING', 'QUIESCED', 'READY_PENDING_OWNER',
            ], true)
            && self::isUuidV7($value['operation_id'] ?? null)
            && is_int($value['generation'] ?? null) && $value['generation'] >= 0
            && self::isUuid($value['boot_id'] ?? null)
            && is_int($value['journal_seq'] ?? null) && $value['journal_seq'] >= 0
            && self::isSha256($value['journal_head_hash'] ?? null)
            && self::isSha256($value['evidence_digest'] ?? null)
            && (($value['state'] === 'DRAINING' && self::isSha256($value['permit_manifest_hash'] ?? null))
                || ($value['state'] !== 'DRAINING' && ! array_key_exists('permit_manifest_hash', $value)));
    }

    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
