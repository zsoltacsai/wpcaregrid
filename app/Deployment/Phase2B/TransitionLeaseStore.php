<?php

namespace App\Deployment\Phase2B;

use App\Models\DeploymentTransitionFence;
use App\Models\TransitionLeaseReceipt;
use App\Models\TransitionLeaseState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** SQL-authoritative, append-only lease state store. Callers must already hold root EXCLUSIVE. */
final class TransitionLeaseStore
{
    private const FENCE_STATES = [
        'OPEN', 'HOLD_REQUESTED', 'CLOSING', 'CLOSED', 'DRAINING', 'QUIESCED', 'READY_PENDING_OWNER',
    ];

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function mutate(array $request): array
    {
        return DB::transaction(function () use ($request): array {
            // Lock ordering is root coordinator EXCLUSIVE (caller) -> this global SQL fence row.
            $fence = $this->lockedFence();
            $rootTuple = $fence->completeTuple();
            $tuple = (new TransitionV2RequestStore())->readTuple();
            if ($tuple === null) {
                throw new RuntimeException('The authoritative Phase 2B HOLD tuple is absent; lease operations remain HOLD.');
            }
            $prior = TransitionLeaseReceipt::query()->where('request_id', $request['request_id'])->lockForUpdate()->first();
            if ($prior) {
                $receipt = $prior->verifiedReceipt($request['request_digest']);
                if (! $receipt) {
                    throw new RuntimeException('Lease request identity conflicts with or corrupts a durable receipt.');
                }

                $this->assertReceiptState($receipt);
                if (! hash_equals(
                    TransitionLeasePersistenceBridge::digestForFenceTuple($rootTuple),
                    (string) ($receipt['fence_tuple_digest'] ?? ''),
                ) || $tuple['operation_id'] !== $receipt['operation_id']
                    || $tuple['generation'] !== $receipt['generation']) {
                    throw new RuntimeException('An exact lease mutation replay belongs to a stale root fence epoch.');
                }

                return $receipt;
            }

            $payload = $request['payload'];
            if ($tuple['operation_id'] !== $payload['operation_id']
                || $tuple['generation'] !== $payload['generation']) {
                return $this->persistRefusal($request, 'STALE_GENERATION');
            }
            if (! hash_equals(TransitionLeasePersistenceBridge::digestForFenceTuple($rootTuple),
                TransitionLeasePersistenceBridge::digestForFenceTuple($payload['fence_tuple']))) {
                return $this->persistRefusal($request, 'STALE_FENCE_TUPLE');
            }
            if ($fence->state !== $payload['expected_fence_state']
                || ! in_array($fence->state, self::FENCE_STATES, true)) {
                return $this->persistRefusal($request, 'FENCE_STATE_MISMATCH');
            }

            $nonceUsed = TransitionLeaseReceipt::query()->where('nonce_digest', $payload['nonce_digest'])->exists();
            if ($nonceUsed) {
                return $this->persistRefusal($request, 'NONCE_REPLAY', consumeNonce: false);
            }

            return match ($request['operation']) {
                'create_lease' => $this->createLease($request),
                'claim_lease' => $this->claimLease($request),
                'reject_lease' => $this->rejectLease($request),
                default => throw new RuntimeException('Lease mutation operation is unsupported.'),
            };
        }, 1);
    }

    /** @return array<string, mixed>|null */
    public function readReceipt(string $requestId, string $requestDigest, array $expectedFenceTuple): ?array
    {
        return DB::transaction(function () use ($requestId, $requestDigest, $expectedFenceTuple): ?array {
            $fence = $this->lockedFence();
            if (! hash_equals(
                TransitionLeasePersistenceBridge::digestForFenceTuple($fence->completeTuple()),
                TransitionLeasePersistenceBridge::digestForFenceTuple($expectedFenceTuple),
            )) {
                throw new RuntimeException('Lease receipt reconciliation belongs to a stale root fence epoch.');
            }
            if ((new TransitionV2RequestStore())->readTuple() === null) {
                throw new RuntimeException('The authoritative Phase 2B HOLD tuple is absent; receipt lookup remains HOLD.');
            }
            $receipt = TransitionLeaseReceipt::query()->where('request_id', $requestId)->lockForUpdate()->first();
            if (! $receipt) {
                return null;
            }

            $verified = $receipt->verifiedReceipt($requestDigest);
            if (! $verified) {
                throw new RuntimeException('Lease receipt readback is absent, corrupt, or bound to another request digest.');
            }
            $this->assertReceiptState($verified);

            return $verified;
        }, 1);
    }

    private function createLease(array $request): array
    {
        $payload = $request['payload'];
        $duplicate = TransitionLeaseState::query()
            ->where('operation_id', $payload['operation_id'])
            ->where('generation', $payload['generation'])
            ->where('execution_id', $payload['execution_id'])
            ->exists();
        $leaseExists = TransitionLeaseState::query()->where('lease_id', $payload['lease_id'])->exists();
        if ($duplicate || $leaseExists) {
            return $this->persistRefusal($request, 'EXECUTION_ALREADY_LEASED');
        }

        $expiresAt = CarbonImmutable::parse($payload['expires_at'])->utc();
        $now = CarbonImmutable::now('UTC');
        if (! $expiresAt->greaterThan($now) || $expiresAt->greaterThan($now->addHour())) {
            return $this->persistRefusal($request, 'LEASE_EXPIRED');
        }

        $state = $this->stateProjection($payload, 1, 'ISSUED', 'CREATED', null);
        $receipt = $this->receiptProjection($request, 'COMMITTED', 'CREATED', $state, null);
        $this->persistReceipt($request, $receipt, 'COMMITTED', $payload['nonce_digest']);
        $this->appendState($request, $state);

        return $receipt;
    }

    private function claimLease(array $request): array
    {
        $payload = $request['payload'];
        $current = $this->currentState($payload);
        if (! $current) {
            return $this->persistRefusal($request, 'LEASE_NOT_FOUND');
        }
        if (! hash_equals($current['fence_tuple_digest'], TransitionLeasePersistenceBridge::digestForFenceTuple($payload['fence_tuple']))) {
            return $this->persistRefusal($request, 'STALE_FENCE_TUPLE');
        }
        if ($current['state'] !== 'ISSUED') {
            return $this->persistRefusal($request, 'LEASE_NOT_CLAIMABLE');
        }
        if (! hash_equals($current['holder_digest'], $payload['holder_digest'])) {
            return $this->persistRefusal($request, 'HOLDER_MISMATCH');
        }
        if (! CarbonImmutable::parse($current['expires_at'])->utc()->greaterThan(CarbonImmutable::now('UTC'))) {
            return $this->persistRefusal($request, 'LEASE_EXPIRED');
        }

        $state = $this->stateProjection($payload, 2, 'CLAIMED', 'CLAIMED', $current['state_digest'], $current['expires_at']);
        $receipt = $this->receiptProjection($request, 'COMMITTED', 'CLAIMED', $state, $current['state_digest']);
        $this->persistReceipt($request, $receipt, 'COMMITTED', $payload['nonce_digest']);
        $this->appendState($request, $state);

        return $receipt;
    }

    private function rejectLease(array $request): array
    {
        $payload = $request['payload'];
        $current = $this->currentState($payload);
        if (! $current) {
            return $this->persistRefusal($request, 'LEASE_NOT_FOUND');
        }
        if (! hash_equals($current['fence_tuple_digest'], TransitionLeasePersistenceBridge::digestForFenceTuple($payload['fence_tuple']))) {
            return $this->persistRefusal($request, 'STALE_FENCE_TUPLE');
        }
        if ($current['state'] !== 'ISSUED') {
            return $this->persistRefusal($request, 'LEASE_NOT_CLAIMABLE');
        }
        if (! hash_equals($current['holder_digest'], $payload['holder_digest'])) {
            return $this->persistRefusal($request, 'HOLDER_MISMATCH');
        }

        $state = $this->stateProjection($payload, 2, 'REJECTED', 'REJECTED', $current['state_digest'], $current['expires_at']);
        $receipt = $this->receiptProjection($request, 'COMMITTED', $payload['rejection_code'], $state, $current['state_digest']);
        $this->persistReceipt($request, $receipt, 'COMMITTED', $payload['nonce_digest']);
        $this->appendState($request, $state);

        return $receipt;
    }

    /** @return array<string, mixed>|null */
    private function currentState(array $payload): ?array
    {
        $rows = TransitionLeaseState::query()
            ->where('lease_id', $payload['lease_id'])
            ->where('operation_id', $payload['operation_id'])
            ->where('generation', $payload['generation'])
            ->where('execution_id', $payload['execution_id'])
            ->orderBy('state_version')
            ->lockForUpdate()
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $previousDigest = null;
        foreach ($rows as $index => $row) {
            if ((int) $row->state_version !== $index + 1
                || ($index === 0 && ($row->state !== 'ISSUED' || $row->source_state_digest !== null))
                || ($index === 1 && ($row->source_state_digest !== $previousDigest
                    || ! in_array($row->state, ['CLAIMED', 'REJECTED'], true)
                    || $row->holder_digest !== $rows[0]->holder_digest
                    || CarbonImmutable::parse($row->expires_at)->utc()->format('Y-m-d\\TH:i:s.u\\Z')
                        !== CarbonImmutable::parse($rows[0]->expires_at)->utc()->format('Y-m-d\\TH:i:s.u\\Z')
                    || $row->fence_tuple_digest !== $rows[0]->fence_tuple_digest
                    || $row->fence_state !== $rows[0]->fence_state
                    || $row->fence_boot_id !== $rows[0]->fence_boot_id
                    || (int) $row->fence_journal_seq !== (int) $rows[0]->fence_journal_seq
                    || $row->fence_journal_head_hash !== $rows[0]->fence_journal_head_hash
                    || $row->fence_evidence_digest !== $rows[0]->fence_evidence_digest
                    || $row->fence_permit_manifest_hash !== $rows[0]->fence_permit_manifest_hash))
                || $index > 1
                || ! $row->hasValidFenceBinding()
                || ! hash_equals((string) $row->state_digest, TransitionLeaseState::digestForState($this->rowProjection($row)))) {
                throw new RuntimeException('The persisted lease state sequence is incomplete or corrupt.');
            }
            $receipt = TransitionLeaseReceipt::query()->where('request_id', $row->request_id)->first();
            $verified = $receipt?->verifiedReceipt((string) $receipt->request_digest);
            if (! $verified || ! $this->receiptMatchesState($verified, $row)) {
                throw new RuntimeException('The persisted lease state lacks its exact immutable request receipt.');
            }
            $previousDigest = (string) $row->state_digest;
        }

        return $this->rowProjection($rows->last());
    }

    /** @param array<string, mixed> $receipt */
    private function assertReceiptState(array $receipt): void
    {
        if (($receipt['outcome'] ?? null) !== 'COMMITTED') {
            return;
        }

        $state = TransitionLeaseState::query()->where('request_id', $receipt['request_id'])->lockForUpdate()->first();
        if (! $state || ! $this->receiptMatchesState($receipt, $state)) {
            throw new RuntimeException('The committed lease receipt has no matching durable state event.');
        }
    }

    /** @param array<string, mixed> $receipt */
    private function receiptMatchesState(array $receipt, TransitionLeaseState $state): bool
    {
        $event = match ($receipt['action'] ?? null) {
            'create_lease' => 'CREATED',
            'claim_lease' => 'CLAIMED',
            'reject_lease' => 'REJECTED',
            default => null,
        };

        return $event !== null
            && ($receipt['request_id'] ?? null) === $state->request_id
            && ($receipt['request_digest'] ?? null) === $state->request_digest
            && ($receipt['lease_id'] ?? null) === $state->lease_id
            && ($receipt['operation_id'] ?? null) === $state->operation_id
            && ($receipt['generation'] ?? null) === (int) $state->generation
            && ($receipt['execution_id'] ?? null) === $state->execution_id
            && ($receipt['fence_tuple_digest'] ?? null) === $state->fence_tuple_digest
            && ($receipt['fence_state'] ?? null) === $state->fence_state
            && ($receipt['fence_boot_id'] ?? null) === $state->fence_boot_id
            && ($receipt['fence_journal_seq'] ?? null) === (int) $state->fence_journal_seq
            && ($receipt['fence_journal_head_hash'] ?? null) === $state->fence_journal_head_hash
            && ($receipt['fence_evidence_digest'] ?? null) === $state->fence_evidence_digest
            && ($receipt['fence_permit_manifest_hash'] ?? null) === $state->fence_permit_manifest_hash
            && ($receipt['state'] ?? null) === $state->state
            && ($receipt['state_version'] ?? null) === (int) $state->state_version
            && ($receipt['state_digest'] ?? null) === $state->state_digest
            && ($receipt['source_state_digest'] ?? null) === $state->source_state_digest
            && ($state->event_type === $event)
            && hash_equals((string) $state->state_digest, TransitionLeaseState::digestForState($this->rowProjection($state)));
    }

    private function persistRefusal(array $request, string $code, bool $consumeNonce = true): array
    {
        $nonceDigest = $consumeNonce ? $request['payload']['nonce_digest'] : null;
        $receipt = $this->receiptProjection($request, 'REFUSED', $code, null, null, $nonceDigest);
        if (! $consumeNonce) {
            $receipt['nonce_digest'] = null;
        }
        $this->persistReceipt($request, $receipt, 'REFUSED', $nonceDigest);

        return $receipt;
    }

    private function persistReceipt(array $request, array $receipt, string $outcome, ?string $nonceDigest): TransitionLeaseReceipt
    {
        $payload = $request['payload'];
        $json = TransitionLeaseReceipt::canonicalJson($receipt);

        return TransitionLeaseReceipt::query()->create([
            'request_id' => $request['request_id'],
            'request_digest' => $request['request_digest'],
            'action' => $request['operation'],
            'operation_id' => $payload['operation_id'],
            'generation' => $payload['generation'],
            'execution_id' => $payload['execution_id'],
            'fence_tuple_digest' => TransitionLeasePersistenceBridge::digestForFenceTuple($payload['fence_tuple']),
            'fence_state' => $payload['fence_tuple']['state'],
            'fence_boot_id' => $payload['fence_tuple']['boot_id'],
            'fence_journal_seq' => $payload['fence_tuple']['journal_seq'],
            'fence_journal_head_hash' => $payload['fence_tuple']['journal_head_hash'],
            'fence_evidence_digest' => $payload['fence_tuple']['evidence_digest'],
            'fence_permit_manifest_hash' => $payload['fence_tuple']['permit_manifest_hash'] ?? null,
            'lease_id' => $payload['lease_id'],
            'nonce_digest' => $nonceDigest,
            'outcome' => $outcome,
            'result_code' => $receipt['result_code'],
            'receipt_payload' => $json,
            'receipt_digest' => hash('sha256', $json),
            'committed_at' => now()->utc(),
        ]);
    }

    private function appendState(array $request, array $state): void
    {
        $model = new TransitionLeaseState([
            ...$state,
            'request_id' => $request['request_id'],
            'request_digest' => $request['request_digest'],
            'occurred_at' => now()->utc(),
        ]);
        $model->setAppendOnlyDiagnosticStage(match ($request['operation']) {
            'create_lease' => 'CREATE_STATE_SAVE',
            'claim_lease' => 'CLAIM_STATE_SAVE',
            'reject_lease' => 'REJECT_STATE_SAVE',
            default => 'DIRECT_STATE_SAVE',
        });
        $model->save();
    }

    private function stateProjection(array $payload, int $version, string $state, string $event, ?string $sourceDigest, ?string $existingExpiry = null): array
    {
        $projection = [
            'lease_id' => $payload['lease_id'],
            'operation_id' => $payload['operation_id'],
            'generation' => $payload['generation'],
            'execution_id' => $payload['execution_id'],
            'fence_tuple_digest' => TransitionLeasePersistenceBridge::digestForFenceTuple($payload['fence_tuple']),
            'fence_state' => $payload['fence_tuple']['state'],
            'fence_boot_id' => $payload['fence_tuple']['boot_id'],
            'fence_journal_seq' => $payload['fence_tuple']['journal_seq'],
            'fence_journal_head_hash' => $payload['fence_tuple']['journal_head_hash'],
            'fence_evidence_digest' => $payload['fence_tuple']['evidence_digest'],
            'fence_permit_manifest_hash' => $payload['fence_tuple']['permit_manifest_hash'] ?? null,
            'state_version' => $version,
            'state' => $state,
            'event_type' => $event,
            'holder_digest' => $payload['holder_digest'],
            'expires_at' => $existingExpiry ?? CarbonImmutable::parse($payload['expires_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'source_state_digest' => $sourceDigest,
        ];
        $projection['state_digest'] = TransitionLeaseState::digestForState($projection);

        return $projection;
    }

    private function receiptProjection(array $request, string $outcome, string $code, ?array $state, ?string $sourceDigest, ?string $nonceDigest = null): array
    {
        $payload = $request['payload'];

        return [
            'action' => $request['operation'],
            'committed' => $outcome === 'COMMITTED',
            'execution_id' => $payload['execution_id'],
            'fence_tuple_digest' => TransitionLeasePersistenceBridge::digestForFenceTuple($payload['fence_tuple']),
            'fence_state' => $payload['fence_tuple']['state'],
            'fence_boot_id' => $payload['fence_tuple']['boot_id'],
            'fence_journal_seq' => $payload['fence_tuple']['journal_seq'],
            'fence_journal_head_hash' => $payload['fence_tuple']['journal_head_hash'],
            'fence_evidence_digest' => $payload['fence_tuple']['evidence_digest'],
            'fence_permit_manifest_hash' => $payload['fence_tuple']['permit_manifest_hash'] ?? null,
            'generation' => $payload['generation'],
            'lease_id' => $payload['lease_id'],
            'nonce_digest' => $nonceDigest ?? $payload['nonce_digest'],
            'operation_id' => $payload['operation_id'],
            'outcome' => $outcome,
            'request_digest' => $request['request_digest'],
            'request_id' => $request['request_id'],
            'result_code' => $code,
            'source_state_digest' => $sourceDigest,
            'state' => $state['state'] ?? null,
            'state_digest' => $state['state_digest'] ?? null,
            'state_version' => $state['state_version'] ?? null,
        ];
    }

    private function rowProjection(TransitionLeaseState $row): array
    {
        return [
            'lease_id' => (string) $row->lease_id,
            'operation_id' => (string) $row->operation_id,
            'generation' => (int) $row->generation,
            'execution_id' => (string) $row->execution_id,
            'fence_tuple_digest' => (string) $row->fence_tuple_digest,
            'fence_state' => (string) $row->fence_state,
            'fence_boot_id' => (string) $row->fence_boot_id,
            'fence_journal_seq' => (int) $row->fence_journal_seq,
            'fence_journal_head_hash' => (string) $row->fence_journal_head_hash,
            'fence_evidence_digest' => (string) $row->fence_evidence_digest,
            'fence_permit_manifest_hash' => $row->fence_permit_manifest_hash,
            'state_version' => (int) $row->state_version,
            'state' => (string) $row->state,
            'event_type' => (string) $row->event_type,
            'holder_digest' => (string) $row->holder_digest,
            'expires_at' => CarbonImmutable::parse($row->expires_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'source_state_digest' => $row->source_state_digest,
            'state_digest' => (string) $row->state_digest,
            'request_digest' => (string) $row->request_digest,
        ];
    }

    private function lockedFence(): DeploymentTransitionFence
    {
        $fence = DeploymentTransitionFence::query()
            ->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
            ->lockForUpdate()
            ->first();
        if (! $fence || ! $fence->hasValidTuple()) {
            throw new RuntimeException('The authoritative transition fence is absent or invalid; lease operations remain HOLD.');
        }

        return $fence;
    }
}
