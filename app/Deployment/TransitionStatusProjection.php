<?php

namespace App\Deployment;

use JsonException;
use RuntimeException;

/**
 * Reads the root adapter's flat canonical tuple projection.
 *
 * This reader deliberately does not verify ownership, mode, or the journal hash chain. The
 * deployment must provide the trusted root-owned path; only the root adapter can attest the
 * journal. A pending marker blocks every read, regardless of its contents.
 */
final class TransitionStatusProjection
{
    private const TUPLE_FIELDS = [
        'state',
        'operation_id',
        'generation',
        'boot_id',
        'journal_seq',
        'journal_head_hash',
        'evidence_digest',
    ];

    private const DRAIN_FIELDS = ['permit_manifest_hash'];

    private const STATES = [
        'OPEN',
        'HOLD_REQUESTED',
        'CLOSING',
        'CLOSED',
        'DRAINING',
        'QUIESCED',
        'READY_PENDING_OWNER',
        'RECOVERY_REQUIRED',
    ];

    /** @var callable(): string */
    private $bootIdResolver;

    public function __construct(
        private readonly string $statusPath = '/run/wpcaregrid/transition-status.json',
        private readonly string $pendingPath = '/run/wpcaregrid/.transition-status.next',
        ?callable $bootIdResolver = null,
    ) {
        $this->bootIdResolver = $bootIdResolver ?? static function (): string {
            $bootId = @file_get_contents('/proc/sys/kernel/random/boot_id');

            if (! is_string($bootId)) {
                throw new RuntimeException('The current boot ID is unavailable.');
            }

            return trim($bootId);
        };
    }

    /** @return array{state:string,operation_id:string,generation:int,boot_id:string,journal_seq:int,journal_head_hash:string,evidence_digest:string,permit_manifest_hash?:string} */
    public function read(): array
    {
        if (file_exists($this->pendingPath) || is_link($this->pendingPath)) {
            throw new RuntimeException('A transition projection update is pending.');
        }

        if (! is_file($this->statusPath) || ! is_readable($this->statusPath)) {
            throw new RuntimeException('The transition projection is missing or unreadable.');
        }

        $contents = @file_get_contents($this->statusPath);
        if (! is_string($contents)) {
            throw new RuntimeException('The transition projection could not be read.');
        }

        try {
            $tuple = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The transition projection is malformed.', previous: $exception);
        }

        if (! is_array($tuple) || ! $this->hasExactTupleShape($tuple) || ! $this->hasValidTupleValues($tuple)) {
            throw new RuntimeException('The transition projection tuple is invalid.');
        }

        $currentBootId = ($this->bootIdResolver)();
        if (! is_string($currentBootId) || ! self::isUuid($currentBootId) || ! hash_equals($currentBootId, $tuple['boot_id'])) {
            throw new RuntimeException('The transition projection belongs to another or unknown boot.');
        }

        $validated = [
            'state' => $tuple['state'],
            'operation_id' => $tuple['operation_id'],
            'generation' => $tuple['generation'],
            'boot_id' => $tuple['boot_id'],
            'journal_seq' => $tuple['journal_seq'],
            'journal_head_hash' => $tuple['journal_head_hash'],
            'evidence_digest' => $tuple['evidence_digest'],
        ];

        if ($tuple['state'] === 'DRAINING') {
            $validated['permit_manifest_hash'] = $tuple['permit_manifest_hash'];
        }

        return $validated;
    }

    /** @param array<string, mixed> $tuple */
    private function hasExactTupleShape(array $tuple): bool
    {
        $keys = array_keys($tuple);
        sort($keys);
        $expected = ($tuple['state'] ?? null) === 'DRAINING'
            ? array_merge(self::TUPLE_FIELDS, self::DRAIN_FIELDS)
            : self::TUPLE_FIELDS;
        sort($expected);

        return $keys === $expected;
    }

    /** @param array<string, mixed> $tuple */
    private function hasValidTupleValues(array $tuple): bool
    {
        return is_string($tuple['state'])
            && in_array($tuple['state'], self::STATES, true)
            && is_string($tuple['operation_id'])
            && self::isUuidV7($tuple['operation_id'])
            && is_int($tuple['generation'])
            && $tuple['generation'] >= 0
            && is_string($tuple['boot_id'])
            && self::isUuid($tuple['boot_id'])
            && is_int($tuple['journal_seq'])
            && $tuple['journal_seq'] >= 0
            && is_string($tuple['journal_head_hash'])
            && preg_match('/\A[0-9a-f]{64}\z/D', $tuple['journal_head_hash']) === 1
            && is_string($tuple['evidence_digest'])
            && preg_match('/\A[0-9a-f]{64}\z/D', $tuple['evidence_digest']) === 1
            && ($tuple['state'] !== 'DRAINING'
                || (is_string($tuple['permit_manifest_hash'] ?? null)
                    && preg_match('/\A[0-9a-f]{64}\z/D', $tuple['permit_manifest_hash']) === 1));
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isUuidV7(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }
}
