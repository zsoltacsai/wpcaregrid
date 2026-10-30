<?php

namespace App\Deployment;

use App\Models\DeploymentTransitionFence;
use App\Models\TransitionAdmission as DrainPermit;
use App\Models\TransitionExecution;
use RuntimeException;

/**
 * Inert Phase 1 policy seam for a later admission/worker adapter.
 *
 * Callers must acquire the stable root lock in SHARED mode before opening a DB transaction,
 * lock the fence row before domain rows, and keep SHARED held through the bounded operation.
 * This class never acquires a root lock and is not registered in application request/worker
 * flows. It trusts the projection as root journal attestation and compares the complete tuple.
 */
final class TransitionAdmission
{
    /** @var callable(): bool */
    private $sharedLockHeld;

    public function __construct(
        private readonly TransitionStatusProjection $projection,
        callable $sharedLockHeld,
    ) {
        $this->sharedLockHeld = $sharedLockHeld;
    }

    /** @return array<string, int|string> The validated OPEN tuple. */
    public function assertOpen(DeploymentTransitionFence $fence): array
    {
        $this->requireSharedLock();
        $tuple = $this->projection->read();

        if ($tuple['state'] !== 'OPEN' || ! $fence->hasValidTuple() || $tuple !== $fence->tuple()) {
            throw new RuntimeException('Transition admission is closed or the complete fence tuple does not match.');
        }

        return $tuple;
    }

    /**
     * Validate one exact, not-yet-started step from a finite DRAINING permit.
     * The caller must persist an INTENT before performing the external effect while retaining
     * the SHARED lock. Existing intent/outcome rows are never treated as permission to replay.
     *
     * @param  array{execution_id:string,work_id:string,payload_hash:string,tenant_id:string,site_id:string,worker_identity:string,step_index:int,idempotency_key:string}  $binding
     */
    public function assertDrainPermit(
        DeploymentTransitionFence $fence,
        DrainPermit $permit,
        array $binding,
    ): void {
        $this->requireSharedLock();
        $tuple = $this->projection->read();

        $tupleOnly = array_intersect_key($tuple, array_flip([
            'state', 'operation_id', 'generation', 'boot_id', 'journal_seq', 'journal_head_hash', 'evidence_digest',
        ]));
        if ($tuple['state'] !== 'DRAINING' || ! $fence->hasValidTuple() || $tupleOnly !== $fence->tuple()) {
            throw new RuntimeException('Drain execution is closed or the complete fence tuple does not match.');
        }

        if (! is_string($fence->permit_manifest_hash)
            || preg_match('/\A[0-9a-f]{64}\z/D', $fence->permit_manifest_hash) !== 1
            || ! hash_equals($tuple['permit_manifest_hash'], $fence->permit_manifest_hash)
            || ! hash_equals($tuple['permit_manifest_hash'], DrainPermit::manifestHashForScope(
                $tuple['operation_id'],
                $tuple['generation'],
            ) ?? '')) {
            throw new RuntimeException('The DRAINING permit manifest does not exactly match the projection and database.');
        }

        if (! $permit->isUsableFor($tuple, $binding)) {
            throw new RuntimeException('The drain permit is absent, expired, mismatched, or exhausted.');
        }

        $stepIndex = $binding['step_index'];
        if (TransitionExecution::query()
            ->where('transition_admission_id', $permit->getKey())
            ->where('effect_step_index', $stepIndex)
            ->exists()) {
            throw new RuntimeException('The drain effect step has already been attempted and cannot be replayed.');
        }
    }

    private function requireSharedLock(): void
    {
        if (! ($this->sharedLockHeld)()) {
            throw new RuntimeException('The stable transition lock must be held in SHARED mode before admission.');
        }
    }
}
