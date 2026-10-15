<?php

namespace App\Privacy;

use App\Models\Customer;
use App\Models\PrivacyErasureExecutionAction;
use App\Models\PrivacyErasureExecutionPlan;
use App\Models\PrivacyRequest;
use Illuminate\Support\Facades\Schema;

/** Single fail-closed policy decision point. Wave 7 deliberately has no real execution caller. */
final class PrivacyExecutionAuthorization
{
    /** @return list<string> */
    public function blockers(PrivacyErasureExecutionPlan $plan, PrivacyErasureExecutionAction $action): array
    {
        $blockers = [];
        if (! $plan->exists || $action->execution_plan_id !== $plan->id) {
            return ['PLAN_ACTION_MISMATCH'];
        }
        if (! config('privacy.destructive_retention_enabled', false)) {
            $blockers[] = 'GLOBAL_DESTRUCTIVE_GATE_OFF';
        }
        $breaker = app(PrivacyErasureCircuitBreakerService::class)->status();
        if (! $breaker['available']) {
            $blockers[] = 'CIRCUIT_BREAKER_STATE_UNAVAILABLE';
        } elseif ($breaker['is_open']) {
            $blockers[] = 'CIRCUIT_BREAKER_OPEN';
        }
        if (app()->environment('production')) {
            $blockers[] = 'PRODUCTION_EXECUTION_PROHIBITED_IN_WAVE_7';
        }
        if (in_array($plan->state, [ErasurePlanState::Invalidated, ErasurePlanState::Cancelled, ErasurePlanState::Partial, ErasurePlanState::ManualReview], true)) {
            $blockers[] = 'PLAN_STATE_NOT_EXECUTABLE';
        }
        if ($plan->executed_at !== null || $action->state === ErasureActionState::Complete) {
            $blockers[] = 'ACTION_ALREADY_RECONCILED';
        }
        $case = PrivacyRequest::query()->find($plan->privacy_request_id);
        $customer = Customer::query()->find($plan->customer_id);
        if (! $case || ! $customer || $case->customer_id !== $customer->id) {
            $blockers[] = 'BLOCKED_SUBJECT_MAPPING';
        } else {
            $impact = app(AccountClosureImpactPlanner::class)->preview($customer);
            if (! hash_equals($plan->source_fingerprint, app(ErasureExecutionPlanService::class)->sourceFingerprint($case, $customer, $impact))) {
                $blockers[] = 'PREVIEW_STALE';
            }
        }
        $journal = app(PrivacyTombstoneJournal::class)->readiness();
        if (($journal['state'] ?? null) !== 'ready') {
            $blockers[] = 'BLOCKED_JOURNAL_UNAVAILABLE';
        }
        if (config('platform.recovery_mode', false)) {
            $blockers[] = 'RECOVERY_QUARANTINE_ACTIVE';
        }
        $gate = match ($action->action_code) {
            'ANONYMIZE_CURRENT_PROFILE', 'REVOKE_CREDENTIALS' => 'profile_anonymization_enabled',
            'PREPARE_BACKUP_RECONCILIATION', 'REQUEST_BACKUP_DELETION' => 'backup_deletion_enabled',
            'PROVIDER_DELETE_TASK' => 'provider_deletion_enabled',
            default => 'unsupported_action',
        };
        if ($gate === 'unsupported_action' || ! config('privacy.'.$gate, false)) {
            $blockers[] = strtoupper($gate === 'unsupported_action' ? 'UNSUPPORTED_ACTION' : 'ACTION_GATE_OFF');
        }
        if ($plan->approved_at === null || ! hash_equals((string) $plan->preview_hash, (string) $plan->approved_preview_hash)) {
            $blockers[] = 'OWNER_APPROVAL_MISSING_OR_STALE';
        }
        if (! Schema::hasTable('privacy_retention_policies')) {
            $blockers[] = 'BLOCKED_POLICY_MISSING';
        }
        foreach ($action->blocker_codes ?? [] as $code) {
            if (str_starts_with($code, 'WAIT_') || str_starts_with($code, 'BLOCKED_')) {
                $blockers[] = $code;
            }
        }

        return array_values(array_unique($blockers));
    }
}
