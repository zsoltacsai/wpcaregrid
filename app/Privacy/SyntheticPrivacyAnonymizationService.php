<?php

namespace App\Privacy;

use App\Models\PrivacyErasureExecutionAction;
use App\Models\PrivacyErasureExecutionPlan;
use App\Models\PrivacyRequest;
use App\Models\SyntheticSiteFixture;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Test-harness-only irreversible profile mutation; hard-requires isolated testing fixtures. */
final class SyntheticPrivacyAnonymizationService
{
    public function __construct(
        private readonly PrivacyTombstoneJournal $journal,
        private readonly PrivacyProfileAnonymizer $anonymizer,
        private readonly ErasureExecutionPlanService $plans,
    ) {}

    public function execute(PrivacyErasureExecutionPlan $plan, PrivacyErasureExecutionAction $action, User $actor, string $previewHash): PrivacyErasureExecutionAction
    {
        if (! app()->environment('testing') || config('app.env') !== 'testing'
            || config('privacy.synthetic_profile_anonymization_enabled', false) !== true) {
            throw new RuntimeException('Synthetic profile anonymization is available only in an explicitly enabled testing environment.');
        }
        if (config('platform.recovery_mode', false)) {
            throw new RuntimeException('Synthetic profile anonymization is blocked during recovery quarantine.');
        }
        if (! $actor->isOwner() || $actor->customer()->exists()) {
            throw new AuthorizationException('Synthetic execution requires an Owner actor.');
        }
        $breaker = app(PrivacyErasureCircuitBreakerService::class)->status();
        if (! $breaker['available'] || $breaker['is_open']) {
            throw new RuntimeException('Synthetic execution is blocked by the privacy circuit breaker.');
        }

        return DB::transaction(function () use ($plan, $action, $actor, $previewHash): PrivacyErasureExecutionAction {
            $lockedPlan = PrivacyErasureExecutionPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $lockedAction = PrivacyErasureExecutionAction::query()->whereKey($action->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedAction->execution_plan_id !== $lockedPlan->id || $lockedAction->action_code !== 'ANONYMIZE_CURRENT_PROFILE') {
                throw new RuntimeException('Synthetic action scope mismatch.');
            }
            if ($lockedAction->state === ErasureActionState::Complete && $lockedAction->reconciliation_state === 'verified') {
                return $lockedAction;
            }
            if ($lockedPlan->approved_at === null || ! hash_equals($lockedPlan->preview_hash, $previewHash)
                || ! hash_equals((string) $lockedPlan->approved_preview_hash, $previewHash)) {
                throw new RuntimeException('Synthetic execution requires current approval of the exact preview.');
            }
            $case = PrivacyRequest::query()->whereKey($lockedPlan->privacy_request_id)->lockForUpdate()->firstOrFail();
            if ($case->request_type !== PrivacyRequestType::Erasure || $case->customer_id !== $lockedPlan->customer_id) {
                throw new RuntimeException('Synthetic execution requires the matching erasure case.');
            }
            $customer = $case->customer()->lockForUpdate()->firstOrFail();
            if ($lockedAction->target_reference !== $customer->uuid) {
                throw new RuntimeException('Synthetic execution target does not match the approved subject.');
            }
            $fixture = SyntheticSiteFixture::query()
                ->where('customer_id', $customer->id)->where('environment', 'testing')->where('enabled', true)->first();
            if (! $fixture || ! $fixture->isActive() || $fixture->website_id === null) {
                throw new RuntimeException('A server-created, active testing fixture is required.');
            }
            if ($customer->activeSubscriptions()->exists()) {
                throw new RuntimeException('Synthetic anonymization is blocked while an active subscription exists.');
            }
            $impact = app(AccountClosureImpactPlanner::class)->preview($customer);
            if (! hash_equals($lockedPlan->source_fingerprint, $this->plans->sourceFingerprint($case, $customer, $impact))) {
                $lockedPlan->forceFill(['state' => ErasurePlanState::Invalidated, 'invalidated_at' => now('UTC')])->save();
                AdminAudit::record($actor, 'privacy.erasure.preview_invalidated', 'privacy_erasure_plan', $lockedPlan->uuid, ['reason_code' => 'source_changed_before_synthetic_run']);

                return $lockedAction->refresh();
            }
            if (! in_array($lockedAction->state, [ErasureActionState::Blocked, ErasureActionState::Approved], true)) {
                throw new RuntimeException('Synthetic action is not in a retryable state.');
            }

            $eventId = hash('sha256', 'privacy:profile_anonymized:'.$lockedAction->uuid);
            $event = $this->journal->append([
                'event_id' => $eventId,
                'subject_uuid' => $customer->uuid,
                'tenant_uuid' => $customer->uuid,
                'scope' => 'customer_account',
                'action' => 'profile_anonymized',
                'request_uuid' => $case->uuid,
                // Approval time is stable across retries; the journal identity remains idempotent after a DB rollback.
                'effective_at' => $lockedPlan->approved_at->copy()->utc()->toIso8601String(),
                'policy_version' => $lockedPlan->policy_version ?? 'synthetic-test-policy',
            ]);
            $readiness = $this->journal->readiness();
            $readback = false;
            foreach ($this->journal->eventsAfter(null) as $stored) {
                if (($stored['event_id'] ?? null) === $eventId && ($stored['sequence'] ?? null) === ($event['sequence'] ?? null)) {
                    $readback = true;
                    break;
                }
            }
            if (($readiness['state'] ?? null) !== 'ready' || ! $readback) {
                throw new RuntimeException('Independent tombstone journal commit/readback verification failed.');
            }

            $this->anonymizer->apply($customer);
            $lockedAction->forceFill(['state' => ErasureActionState::Complete, 'reconciliation_state' => 'verified'])->save();
            $lockedPlan->forceFill(['state' => ErasurePlanState::Partial, 'executed_at' => now('UTC')])->save();
            AdminAudit::record($actor, 'privacy.erasure.synthetic_executed', 'privacy_erasure_plan', $lockedPlan->uuid, [
                'action_code' => $lockedAction->action_code,
                'event_id' => $eventId,
                'environment' => 'testing',
                'remaining_blockers' => array_values(array_unique(array_merge($lockedPlan->blocker_codes ?? [], ['WAIT_LEGAL', 'WAIT_ACCOUNTING', 'WAIT_FACT']))),
            ]);
            AdminAudit::record($actor, 'privacy.erasure.partial', 'privacy_erasure_plan', $lockedPlan->uuid, ['reason_code' => 'external_gates_unresolved']);

            return $lockedAction->refresh();
        }, attempts: 3);
    }
}
