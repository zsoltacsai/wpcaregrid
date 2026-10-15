<?php

namespace App\Privacy;

use App\Models\Customer;
use App\Models\PrivacyErasureExecutionAction;
use App\Models\PrivacyErasureExecutionPlan;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Builds immutable, PII-minimal erasure previews. This service has no executor or provider dependency. */
final class ErasureExecutionPlanService
{
    public function __construct(
        private readonly AccountClosureImpactPlanner $impactPlanner,
        private readonly PrivacyTombstoneJournal $journal,
    ) {}

    public function createPreview(PrivacyRequest $request, User $actor): PrivacyErasureExecutionPlan
    {
        $this->authorizeOwner($actor);

        return DB::transaction(function () use ($request, $actor): PrivacyErasureExecutionPlan {
            $case = PrivacyRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($case->request_type !== PrivacyRequestType::Erasure) {
                throw new LogicException('Execution plans require a separate erasure request.');
            }
            $customer = Customer::query()->whereKey($case->customer_id)->lockForUpdate()->firstOrFail();
            $impact = $this->impactPlanner->preview($customer);
            $sourceFingerprint = $this->sourceFingerprint($case, $customer, $impact);
            $policyVersion = $this->policyVersion();
            $actions = $this->proposedActions($case, $customer, $impact);
            $blockers = array_values(array_unique(array_merge(
                ['WAIT_LEGAL', 'WAIT_ACCOUNTING', 'PROFILE_ACTION_GATE_OFF', 'GLOBAL_DESTRUCTIVE_GATE_OFF'],
                ($this->journal->readiness()['state'] ?? 'blocked') === 'ready' ? [] : ['BLOCKED_JOURNAL_UNAVAILABLE'],
                ...array_map(static fn (array $action): array => $action['blockers'], $actions),
            )));
            $preview = [
                'request_uuid' => $case->uuid,
                'customer_reference' => $customer->uuid,
                'policy_version' => $policyVersion,
                'source_fingerprint' => $sourceFingerprint,
                'evaluated_at' => now('UTC')->toIso8601String(),
                'impact' => $impact,
                'actions' => $actions,
                'blockers' => $blockers,
                'destructive_execution' => 'DISABLED',
                'customer_site_content' => 'OUT_OF_SCOPE',
            ];
            $hash = hash('sha256', CanonicalJson::encode($preview));
            $version = ((int) PrivacyErasureExecutionPlan::query()->where('privacy_request_id', $case->id)->max('version')) + 1;
            $plan = PrivacyErasureExecutionPlan::query()->create([
                'privacy_request_id' => $case->id,
                'customer_id' => $customer->id,
                'version' => $version,
                'state' => ErasurePlanState::Blocked,
                'policy_version' => $policyVersion,
                'source_fingerprint' => $sourceFingerprint,
                'preview_hash' => $hash,
                'preview' => $preview,
                'blocker_codes' => $blockers,
            ]);
            foreach ($actions as $action) {
                PrivacyErasureExecutionAction::query()->create([
                    'execution_plan_id' => $plan->id,
                    'action_code' => $action['code'],
                    'scope_code' => $action['scope'],
                    'target_type' => $action['target_type'],
                    'target_reference' => $action['target_reference'],
                    'state' => ErasureActionState::Blocked,
                    'reconciliation_state' => 'not_started',
                    'blocker_codes' => $action['blockers'],
                    'idempotency_key' => hash('sha256', $plan->uuid.'|'.$action['code'].'|'.$action['target_reference']),
                ]);
            }

            AdminAudit::record($actor, 'privacy.erasure.preview_created', 'privacy_erasure_plan', $plan->uuid, [
                'version' => $version,
                'preview_hash' => $hash,
                'action_count' => count($actions),
                'blocker_codes' => $blockers,
            ]);

            return $plan->load('actions');
        }, attempts: 3);
    }

    /** Owner review records approval of this exact preview; external blockers remain blocking. */
    public function approve(PrivacyErasureExecutionPlan $plan, User $actor, string $password, string $typedConfirmation, string $previewHash): PrivacyErasureExecutionPlan
    {
        $this->authorizeOwner($actor);
        if (! Hash::check($password, (string) $actor->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => 'Owner password confirmation failed.']);
        }

        return DB::transaction(function () use ($plan, $actor, $typedConfirmation, $previewHash): PrivacyErasureExecutionPlan {
            $locked = PrivacyErasureExecutionPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->state === ErasurePlanState::Invalidated || $locked->state === ErasurePlanState::Cancelled || $locked->approved_at !== null) {
                throw new LogicException('This execution plan cannot be approved in its current state.');
            }
            if (! hash_equals($locked->preview_hash, $previewHash)
                || ! hash_equals($locked->preview_hash, hash('sha256', CanonicalJson::encode($locked->preview)))
                || $typedConfirmation !== 'APPROVE '.$locked->uuid.' '.$locked->preview_hash) {
                throw ValidationException::withMessages(['confirmation' => 'The confirmation must exactly match this plan version and preview hash.']);
            }
            $case = PrivacyRequest::query()->whereKey($locked->privacy_request_id)->lockForUpdate()->firstOrFail();
            $customer = Customer::query()->whereKey($locked->customer_id)->lockForUpdate()->firstOrFail();
            $impact = $this->impactPlanner->preview($customer);
            $currentFingerprint = $this->sourceFingerprint($case, $customer, $impact);
            if (! hash_equals($locked->source_fingerprint, $currentFingerprint)) {
                $locked->forceFill(['state' => ErasurePlanState::Invalidated, 'invalidated_at' => now('UTC')])->save();
                AdminAudit::record($actor, 'privacy.erasure.preview_invalidated', 'privacy_erasure_plan', $locked->uuid, ['reason_code' => 'source_changed']);

                return $locked->refresh();
            }
            $locked->forceFill([
                'owner_approval_status' => 'approved',
                'approved_preview_hash' => $locked->preview_hash,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now('UTC'),
                'state' => ErasurePlanState::ApprovedBlocked,
            ])->save();
            AdminAudit::record($actor, 'privacy.erasure.plan.owner_approved', 'privacy_erasure_plan', $locked->uuid, [
                'version' => $locked->version,
                'preview_hash' => $locked->preview_hash,
                'execution_state' => 'BLOCKED_EXTERNAL_GATES',
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function cancel(PrivacyErasureExecutionPlan $plan, User $actor): PrivacyErasureExecutionPlan
    {
        $this->authorizeOwner($actor);

        return DB::transaction(function () use ($plan, $actor): PrivacyErasureExecutionPlan {
            $locked = PrivacyErasureExecutionPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->executed_at !== null || in_array($locked->state, [ErasurePlanState::Partial, ErasurePlanState::ManualReview], true)) {
                throw new LogicException('Cancellation is unavailable after an irreversible action has started.');
            }
            $locked->forceFill(['state' => ErasurePlanState::Cancelled])->save();
            $locked->actions()->whereNotIn('state', [ErasureActionState::Complete->value, ErasureActionState::Retained->value])->update(['state' => ErasureActionState::Cancelled->value]);
            AdminAudit::record($actor, 'privacy.erasure.plan.cancelled', 'privacy_erasure_plan', $locked->uuid, ['version' => $locked->version]);

            return $locked->refresh();
        }, attempts: 3);
    }

    /** Preview freshness includes privacy case, profile version, policy versions, provider facts and journal health. */
    public function sourceFingerprint(PrivacyRequest $case, Customer $customer, ?array $impact = null): string
    {
        $policyVersions = [];
        if (Schema::hasTable('privacy_retention_policies')) {
            $policyVersions = DB::table('privacy_retention_policies')->where('enabled', true)->orderBy('policy_key')->get(['policy_key', 'version', 'updated_at'])->toArray();
        }
        $readiness = $this->journal->readiness();

        return hash('sha256', CanonicalJson::encode([
            'customer_uuid' => $customer->uuid,
            'customer_updated_at' => $customer->updated_at?->copy()->utc()->toIso8601String(),
            'customer_active' => (bool) $customer->active,
            'user_id' => $customer->user_id,
            'request_uuid' => $case->uuid,
            'request_status' => $case->status->value,
            'request_lock_version' => (int) $case->lock_version,
            'request_updated_at' => $case->updated_at?->copy()->utc()->toIso8601String(),
            'policy_versions' => $policyVersions,
            'provider_facts' => PrivacyProviderFactRegistry::all(),
            'journal' => ['state' => $readiness['state'] ?? 'blocked', 'watermark' => $readiness['watermark'] ?? null],
            'impact_counts' => $impact['retained_relations'] ?? [],
        ]));
    }

    /** @return list<array{code:string,scope:string,target_type:string,target_reference:string,blockers:list<string>}> */
    private function proposedActions(PrivacyRequest $case, Customer $customer, array $impact): array
    {
        $actions = [[
            'code' => 'ANONYMIZE_CURRENT_PROFILE', 'scope' => 'current_profile', 'target_type' => 'customer',
            'target_reference' => $customer->uuid, 'blockers' => ['WAIT_LEGAL', 'WAIT_ACCOUNTING', 'GLOBAL_DESTRUCTIVE_GATE_OFF', 'PROFILE_ACTION_GATE_OFF'],
        ], [
            'code' => 'REVOKE_CREDENTIALS', 'scope' => 'customer_credentials', 'target_type' => 'customer',
            'target_reference' => $customer->uuid, 'blockers' => ['WAIT_LEGAL', 'PROFILE_ACTION_GATE_OFF'],
        ]];
        if ($case->exportArtifact()->exists()) {
            $artifact = $case->exportArtifact()->first();
            $actions[] = [
                'code' => 'REVOKE_PRIVACY_EXPORT', 'scope' => 'privacy_export', 'target_type' => 'privacy_export',
                'target_reference' => (string) $artifact->uuid, 'blockers' => ['WAIT_LEGAL', 'EXPORT_ACTION_GATE_OFF'],
            ];
        }
        if (($impact['customer_backups']['technical_candidates'] ?? 0) > 0) {
            $actions[] = [
                'code' => 'PREPARE_BACKUP_RECONCILIATION', 'scope' => 'customer_backup', 'target_type' => 'customer',
                'target_reference' => $customer->uuid, 'blockers' => ['WAIT_LEGAL', 'WAIT_ACCOUNTING', 'WAIT_FACT', 'BACKUP_ACTION_GATE_OFF'],
            ];
        }
        if (($impact['retained_relations']['billing_invoices_retained'] ?? 0) > 0 || $customer->billingIdentities()->exists()) {
            $actions[] = [
                'code' => 'RETAIN_ACCOUNTING_EVIDENCE', 'scope' => 'accounting_evidence', 'target_type' => 'customer',
                'target_reference' => $customer->uuid, 'blockers' => ['WAIT_ACCOUNTING'],
            ];
        }

        return $actions;
    }

    private function policyVersion(): ?string
    {
        if (! Schema::hasTable('privacy_retention_policies')) {
            return null;
        }
        $versions = DB::table('privacy_retention_policies')->where('enabled', true)->orderBy('policy_key')->get(['policy_key', 'version']);

        return $versions->isEmpty() ? null : hash('sha256', CanonicalJson::encode($versions->toArray()));
    }

    private function authorizeOwner(User $actor): void
    {
        if (! $actor->isOwner() || $actor->customer()->exists()) {
            throw new AuthorizationException('Erasure execution-plan review is restricted to Owner accounts.');
        }
    }
}
