<?php

namespace App\Deployment\Phase2B;

use App\Models\DeploymentTransitionFence;
use App\Models\TransitionHistoryEvent;
use App\Models\TransitionV2Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** SQL-authoritative, HOLD-only V2 fence tuple persistence. */
final class TransitionV2RequestStore
{
    private const TUPLE_TO_COLUMN = [
        'state' => 'phase2b_state',
        'operation_id' => 'phase2b_operation_id',
        'generation' => 'phase2b_generation',
        'boot_id' => 'phase2b_boot_id',
        'source_sha' => 'phase2b_source_sha',
        'journal_seq' => 'phase2b_journal_seq',
        'journal_head_hash' => 'phase2b_journal_head_hash',
        'evidence_digest' => 'phase2b_evidence_digest',
    ];

    /** @return array<string, mixed>|null */
    public function readTuple(): ?array
    {
        return DB::transaction(function (): ?array {
            $fence = $this->lockedFence();

            return $this->currentTuple($fence);
        }, 1);
    }

    /**
     * Persist one HOLD-preserving compare-and-set, receipt and ordered history event atomically.
     * A null source is accepted only for the all-null, initial Phase 2B tuple.
     *
     * @param  array<string, mixed>|null  $source
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public function compareAndSetHoldTuple(
        string $requestId,
        string $requestDigest,
        ?array $source,
        array $target,
    ): array {
        if (! self::isUuidV7($requestId)
            || ! self::isSha256($requestDigest)
            || ($source !== null && ! TransitionV2Request::validTuple($source))
            || ! TransitionV2Request::validTuple($target)) {
            throw new InvalidArgumentException('Invalid Phase 2B HOLD tuple request.');
        }

        if ($source !== null
            && ($source['operation_id'] !== $target['operation_id']
                || $source['generation'] !== $target['generation'])) {
            throw new RuntimeException('A Phase 2B operation/generation boundary requires a separately authorized boundary operation.');
        }

        $expectedDigest = TransitionV2Request::digestForRequest($requestId, $source, $target);
        if (! hash_equals($expectedDigest, $requestDigest)) {
            throw new InvalidArgumentException('The Phase 2B request digest does not match its canonical tuple request.');
        }

        return DB::transaction(function () use ($requestId, $requestDigest, $source, $target): array {
            // All V2 writes serialize on the same global Phase 2A fence row.
            $fence = $this->lockedFence();
            $this->assertFenceIdentity($fence, $target);
            $current = $this->currentTuple($fence);
            $prior = TransitionV2Request::query()
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->first();

            if ($prior) {
                return $this->verifiedPriorReceipt($prior, $requestDigest);
            }

            if (($source === null && $current !== null)
                || ($source !== null && ($current === null
                    || ! hash_equals(self::tupleDigest($source), self::tupleDigest($current))))) {
                throw new RuntimeException('The Phase 2B fence tuple does not match the exact CAS source.');
            }

            $currentEventSequence = self::integerFromDatabase($fence->phase2b_event_sequence ?? null);
            if ($currentEventSequence === null || $currentEventSequence < 0 || $currentEventSequence >= PHP_INT_MAX) {
                throw new RuntimeException('The Phase 2B history sequence is invalid or exhausted.');
            }
            if ($source === null && $currentEventSequence !== 0) {
                throw new RuntimeException('An incomplete Phase 2B tuple has prior history and remains HOLD.');
            }
            if ($source !== null && $currentEventSequence < 1) {
                throw new RuntimeException('The Phase 2B tuple has no committed history and remains HOLD.');
            }

            $nextSequence = $currentEventSequence + 1;
            $sourceDigest = $source === null ? null : self::tupleDigest($source);
            $targetDigest = self::tupleDigest($target);
            $receipt = [
                'committed' => true,
                'history_sequence' => $nextSequence,
                'operation' => TransitionV2Request::OPERATION,
                'request_digest' => $requestDigest,
                'request_id' => $requestId,
                'source' => $source,
                'target' => $target,
            ];
            $receiptPayload = TransitionV2Request::canonicalJson($receipt);
            $now = now()->utc();

            $query = DB::table('deployment_transition_fences')
                ->where('id', $fence->id)
                ->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
                ->where('operation_id', $target['operation_id'])
                ->where('generation', $target['generation'])
                ->where('phase2b_state', 'HOLD')
                ->where('phase2b_event_sequence', $currentEventSequence);

            if ($source === null) {
                $query->whereNull('phase2b_tuple_digest');
                foreach (self::TUPLE_TO_COLUMN as $column) {
                    if ($column !== 'state') {
                        $query->whereNull($column);
                    }
                }
            } else {
                $query->where('phase2b_tuple_digest', $sourceDigest);
                foreach (self::TUPLE_TO_COLUMN as $field => $column) {
                    $query->where($column, $source[$field]);
                }
            }

            $updated = $query->update([
                'phase2b_state' => 'HOLD',
                'phase2b_operation_id' => $target['operation_id'],
                'phase2b_generation' => $target['generation'],
                'phase2b_boot_id' => $target['boot_id'],
                'phase2b_source_sha' => $target['source_sha'],
                'phase2b_journal_seq' => $target['journal_seq'],
                'phase2b_journal_head_hash' => $target['journal_head_hash'],
                'phase2b_evidence_digest' => $target['evidence_digest'],
                'phase2b_tuple_digest' => $targetDigest,
                'phase2b_event_sequence' => $nextSequence,
                'updated_at' => $now,
            ]);

            if ($updated !== 1) {
                throw new RuntimeException('The Phase 2B fence tuple compare-and-set did not update exactly one row.');
            }

            $request = TransitionV2Request::query()->create([
                'request_id' => $requestId,
                'request_digest' => $requestDigest,
                'operation' => TransitionV2Request::OPERATION,
                'operation_id' => $target['operation_id'],
                'generation' => $target['generation'],
                'source_tuple_digest' => $sourceDigest,
                'target_tuple_digest' => $targetDigest,
                'history_sequence' => $nextSequence,
                'receipt_payload' => $receiptPayload,
                'receipt_digest' => hash('sha256', $receiptPayload),
                'committed_at' => $now,
            ]);

            $this->appendHistoryEvent(
                $request,
                $source,
                $target,
                $nextSequence,
                $now->toDateTimeString(),
            );

            return $receipt;
        }, 1);
    }

    /** @return array<string, mixed>|null */
    public function readReceipt(string $requestId, string $requestDigest): ?array
    {
        if (! self::isUuidV7($requestId) || ! self::isSha256($requestDigest)) {
            throw new InvalidArgumentException('Invalid Phase 2B receipt lookup identity.');
        }

        return DB::transaction(function () use ($requestId, $requestDigest): ?array {
            $fence = $this->lockedFence();
            $this->currentTuple($fence);
            $request = TransitionV2Request::query()
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                return null;
            }

            return $this->verifiedPriorReceipt($request, $requestDigest);
        }, 1);
    }

    /** @param array<string, mixed> $tuple */
    public static function tupleDigest(array $tuple): string
    {
        return TransitionV2Request::digestForTuple($tuple);
    }

    private function lockedFence(): object
    {
        $fence = DB::table('deployment_transition_fences')
            ->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
            ->lockForUpdate()
            ->first();

        if (! $fence) {
            throw new RuntimeException('The authoritative global transition fence is absent; Phase 2B remains HOLD.');
        }

        return $fence;
    }

    /** @return array<string, mixed>|null */
    private function currentTuple(object $fence): ?array
    {
        $eventSequence = self::integerFromDatabase($fence->phase2b_event_sequence ?? null);
        if (($fence->phase2b_state ?? null) !== 'HOLD'
            || $eventSequence === null
            || $eventSequence < 0) {
            throw new RuntimeException('The Phase 2B fence is incomplete or outside HOLD.');
        }

        $allTupleNull = $fence->phase2b_tuple_digest === null;
        foreach (self::TUPLE_TO_COLUMN as $field => $column) {
            if ($field !== 'state' && $fence->{$column} !== null) {
                $allTupleNull = false;
            }
        }

        if ($allTupleNull) {
            if ($eventSequence !== 0
                || TransitionV2Request::query()->exists()
                || TransitionHistoryEvent::query()->exists()) {
                throw new RuntimeException('An incomplete Phase 2B tuple has prior history and remains HOLD.');
            }

            return null;
        }

        $tuple = [
            'state' => 'HOLD',
            'operation_id' => $fence->phase2b_operation_id,
            'generation' => self::integerFromDatabase($fence->phase2b_generation ?? null),
            'boot_id' => $fence->phase2b_boot_id,
            'source_sha' => $fence->phase2b_source_sha,
            'journal_seq' => self::integerFromDatabase($fence->phase2b_journal_seq ?? null),
            'journal_head_hash' => $fence->phase2b_journal_head_hash,
            'evidence_digest' => $fence->phase2b_evidence_digest,
        ];

        if (! TransitionV2Request::validTuple($tuple)
            || ! is_string($fence->phase2b_tuple_digest)
            || ! hash_equals(self::tupleDigest($tuple), $fence->phase2b_tuple_digest)
            || $eventSequence < 1) {
            throw new RuntimeException('The Phase 2B fence tuple is incomplete or corrupt and remains HOLD.');
        }

        $this->assertFenceIdentity($fence, $tuple);

        $events = TransitionHistoryEvent::query()->orderBy('event_sequence')->get();
        if ($events->count() !== $eventSequence
            || TransitionV2Request::query()->count() !== $eventSequence) {
            throw new RuntimeException('The Phase 2B immutable history sequence is incomplete.');
        }

        $previousTupleDigest = null;
        foreach ($events as $index => $event) {
            if ((int) $event->event_sequence !== $index + 1
                || $event->operation_id !== $tuple['operation_id']
                || (int) $event->generation !== $tuple['generation']
                || $event->event_type !== TransitionHistoryEvent::HOLD_TUPLE_COMMITTED
                || $event->source_tuple_digest !== $previousTupleDigest) {
                throw new RuntimeException('The Phase 2B immutable history chain is mismatched.');
            }

            $request = TransitionV2Request::query()
                ->where('request_id', $event->request_id)
                ->where('history_sequence', $event->event_sequence)
                ->first();
            if (! $request) {
                throw new RuntimeException('The Phase 2B fence tuple has no matching immutable request receipt.');
            }

            $receipt = $this->verifiedPriorReceipt($request, (string) $request->request_digest);
            if (! hash_equals(self::tupleDigest($receipt['target']), $event->target_tuple_digest)) {
                throw new RuntimeException('The Phase 2B fence tuple does not match its committed receipt.');
            }
            $previousTupleDigest = $event->target_tuple_digest;
        }

        if ($previousTupleDigest === null || ! hash_equals($previousTupleDigest, $fence->phase2b_tuple_digest)) {
            throw new RuntimeException('The Phase 2B fence tuple has no matching immutable history event.');
        }

        return $tuple;
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function verifiedPriorReceipt(TransitionV2Request $request, string $expectedDigest): array
    {
        if ($request->operation !== TransitionV2Request::OPERATION) {
            throw new RuntimeException('The persisted Phase 2B request operation does not match its receipt contract.');
        }
        if (! hash_equals((string) $request->request_digest, $expectedDigest)) {
            throw new RuntimeException('The Phase 2B request ID is already bound to a different digest.');
        }

        $receipt = $request->verifiedReceipt($expectedDigest);
        if (! $receipt) {
            throw new RuntimeException('The committed Phase 2B request receipt is absent or corrupt.');
        }

        if ($request->operation_id !== $receipt['target']['operation_id']
            || (int) $request->generation !== $receipt['target']['generation']
            || ($receipt['source'] !== null
                && ($receipt['source']['operation_id'] !== $receipt['target']['operation_id']
                    || $receipt['source']['generation'] !== $receipt['target']['generation']))) {
            throw new RuntimeException('The committed Phase 2B request identity does not match its receipt.');
        }

        $event = TransitionHistoryEvent::query()
            ->where('request_id', $request->request_id)
            ->where('event_sequence', $request->history_sequence)
            ->first();
        if (! $event
            || $event->request_digest !== $request->request_digest
            || $event->event_type !== TransitionHistoryEvent::HOLD_TUPLE_COMMITTED
            || $event->operation_id !== $request->operation_id
            || (int) $event->generation !== (int) $request->generation
            || $event->operation_id !== $receipt['target']['operation_id']
            || (int) $event->generation !== $receipt['target']['generation']
            || $event->target_tuple_digest !== $request->target_tuple_digest
            || $event->source_tuple_digest !== $request->source_tuple_digest) {
            throw new RuntimeException('The committed Phase 2B receipt has no matching immutable history event.');
        }

        return $receipt;
    }

    /** @param array<string, mixed> $tuple */
    private function assertFenceIdentity(object $fence, array $tuple): void
    {
        if ($fence->operation_id !== $tuple['operation_id']
            || self::integerFromDatabase($fence->generation ?? null) !== $tuple['generation']) {
            throw new RuntimeException('The Phase 2B operation/generation does not match the authoritative global fence.');
        }
    }

    /** @param array<string, mixed>|null $source @param array<string, mixed> $target */
    private function appendHistoryEvent(
        TransitionV2Request $request,
        ?array $source,
        array $target,
        int $sequence,
        string $occurredAt,
    ): TransitionHistoryEvent {
        if (DB::transactionLevel() < 1
            || ! $request->exists
            || (int) $request->history_sequence !== $sequence
            || $request->operation_id !== $target['operation_id']
            || (int) $request->generation !== $target['generation']
            || ($source !== null
                && ($source['operation_id'] !== $target['operation_id']
                    || $source['generation'] !== $target['generation']))
            || ! hash_equals((string) $request->target_tuple_digest, self::tupleDigest($target))
            || (($source === null && $request->source_tuple_digest !== null)
                || ($source !== null && ! hash_equals(
                    (string) $request->source_tuple_digest,
                    self::tupleDigest($source),
                )))
            || ! $request->verifiedReceipt((string) $request->request_digest)) {
            throw new RuntimeException('The immutable history event must match a committed receipt in the fence transaction.');
        }

        return TransitionHistoryEvent::query()->create([
            'event_sequence' => $sequence,
            'operation_id' => $target['operation_id'],
            'generation' => $target['generation'],
            'request_id' => $request->request_id,
            'request_digest' => $request->request_digest,
            'event_type' => TransitionHistoryEvent::HOLD_TUPLE_COMMITTED,
            'source_tuple_digest' => $source === null ? null : self::tupleDigest($source),
            'target_tuple_digest' => self::tupleDigest($target),
            'occurred_at' => $occurredAt,
        ]);
    }

    private static function integerFromDatabase(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false || $integer < 0 ? null : $integer;
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
