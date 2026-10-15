<?php

namespace App\Privacy;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Aggregate-only impact planning. This class deliberately has no mutation or provider dependencies. */
final class AccountClosureImpactPlanner
{
    /** @return array<string, mixed> */
    public function preview(Customer $customer, ?Carbon $evaluatedAt = null): array
    {
        $evaluatedAt = ($evaluatedAt ?? now())->copy()->utc();
        $erasure = $customer->privacyRequests()
            ->where('request_type', PrivacyRequestType::Erasure->value)
            ->where('active_request_key', 'active')
            ->latest('requested_at')
            ->first();

        $coolingDays = $this->duration('RET-16');
        $profileEligibleAt = $erasure && $coolingDays !== null
            ? $erasure->requested_at?->copy()->utc()->addDays($coolingDays)
            : null;
        $profileResult = 'NOT_ELIGIBLE';

        $counts = [
            'users' => $customer->user()->count(),
            'subscriptions_retained' => $customer->subscriptions()->count(),
            'websites_retained' => $customer->websites()->count(),
            'billing_invoices_retained' => $customer->billingInvoices()->count(),
            'billing_identity_versions_retained' => $customer->billingIdentities()->count(),
        ];

        $backup = $this->backupSummary($customer, $evaluatedAt);
        $blockers = [];
        if (! $erasure) {
            $blockers[] = 'SEPARATE_ERASURE_REQUEST_REQUIRED';
        } elseif ($coolingDays === null) {
            $blockers[] = 'BLOCKED_POLICY_MISSING';
        } else {
            $blockers[] = 'BLOCKED_LEGAL_APPROVAL';
            $blockers[] = 'BLOCKED_ACCOUNTING_APPROVAL';
            $blockers[] = 'BLOCKED_SECURITY_REVIEW';
            if ($profileEligibleAt && $profileEligibleAt->gt($evaluatedAt)) {
                $blockers[] = 'COOLING_OFF_ACTIVE';
            } elseif ($profileEligibleAt) {
                $profileResult = 'TECHNICAL_CANDIDATE_ONLY';
            }
        }

        return [
            'evaluated_at' => $evaluatedAt->toIso8601String(),
            'customer_reference' => (string) $customer->uuid,
            'closure_model' => 'HYBRID',
            'closure_does_not_start_erasure' => true,
            'erasure_request_reference' => $erasure?->uuid,
            'profile_eligible_at' => $profileEligibleAt?->toIso8601String(),
            'profile_result' => $profileResult,
            'profile_blockers' => $blockers,
            'anonymization_plan' => $this->plan(),
            'retained_relations' => $counts,
            'customer_site_data' => 'NOT_MODIFIED_OR_PREVIEWED_AS_ERASABLE',
            'customer_backups' => $backup,
            'provider_actions' => ['state' => 'NOT_REQUESTED', 'capability' => 'WAIT_FACT'],
            'accounting_evidence' => ['state' => 'RETENTION_EXCEPTION', 'approval' => 'WAIT_ACCOUNTING'],
            'audit_security_evidence' => ['state' => 'SEPARATE_POLICY', 'approval' => 'WAIT_LEGAL_SECURITY'],
            'destructive_retention' => 'DISABLED',
            'coverage' => 'PARTIAL',
        ];
    }

    /** @return list<array{category:string,field:string,action:string,approval_state:string}> */
    private function plan(): array
    {
        return array_map(static fn (AnonymizationPlanItem $item) => $item->toArray(), [
            new AnonymizationPlanItem('USER_PROFILE', 'name', AnonymizationAction::ClearIfApproved, 'WAIT_LEGAL'),
            new AnonymizationPlanItem('USER_PROFILE', 'email', AnonymizationAction::ReplaceWithNonDeliverable, 'WAIT_LEGAL_SECURITY'),
            new AnonymizationPlanItem('CUSTOMER_PROFILE', 'name', AnonymizationAction::ClearIfApproved, 'WAIT_LEGAL_ACCOUNTING'),
            new AnonymizationPlanItem('CUSTOMER_PROFILE', 'email', AnonymizationAction::ReplaceWithNonDeliverable, 'WAIT_LEGAL_ACCOUNTING'),
            new AnonymizationPlanItem('CUSTOMER_PROFILE', 'billing_address', AnonymizationAction::ClearIfApproved, 'WAIT_ACCOUNTING'),
            new AnonymizationPlanItem('CUSTOMER_PROFILE', 'tax_identifier', AnonymizationAction::SeparateRetentionReview, 'WAIT_ACCOUNTING'),
            new AnonymizationPlanItem('RELATIONAL_HISTORY', 'internal_ids_and_foreign_keys', AnonymizationAction::RetainRelationalReference, 'RETAIN'),
            new AnonymizationPlanItem('ACCOUNTING_EVIDENCE', 'invoice_and_billing_snapshots', AnonymizationAction::RetainImmutableEvidence, 'WAIT_ACCOUNTING'),
            new AnonymizationPlanItem('CUSTOMER_SITE_CONTENT', 'wordpress_database_and_files', AnonymizationAction::NoSiteMutation, 'SEPARATE_AUTHORIZATION_REQUIRED'),
        ]);
    }

    /** @return array<string, mixed> */
    private function backupSummary(Customer $customer, Carbon $evaluatedAt): array
    {
        if (! Schema::hasTable('backups') || ! Schema::hasTable('websites') || ! Schema::hasTable('subscriptions')) {
            return ['coverage' => 'UNKNOWN', 'scanned' => 0, 'technical_candidates' => 0, 'blockers' => ['SOURCE_UNAVAILABLE']];
        }

        $query = DB::table('backups as b')
            ->join('websites as w', 'w.id', '=', 'b.website_id')
            ->leftJoin('subscriptions as s', 's.id', '=', 'w.subscription_id')
            ->where('w.customer_id', $customer->id)
            ->whereNull('b.objects_deleted_at')->where(fn ($q) => $q->whereNull('b.deletion_state')->orWhere('b.deletion_state', 'not_requested'));
        $days = $this->duration('RET-04');
        $candidateQuery = clone $query;
        $candidateQuery->whereNotNull('s.ended_at')->whereIn('b.status', ['verified', 'expired']);
        $retentionOrigin = 'CASE WHEN COALESCE(b.completed_at, b.verified_at, b.created_at) > s.ended_at THEN COALESCE(b.completed_at, b.verified_at, b.created_at) ELSE s.ended_at END';
        if ($days === null) {
            $candidates = 0;
            $nextEligibilityAt = null;
        } else {
            $candidateQuery->whereRaw($retentionOrigin.' <= ?', [$evaluatedAt->copy()->subDays($days)->format('Y-m-d H:i:s')]);
            $candidates = $candidateQuery->count();
            $nextEligibilityAt = (clone $query)
                ->whereNotNull('s.ended_at')
                ->whereIn('b.status', ['verified', 'expired'])
                ->selectRaw('MIN('.$retentionOrigin.') as first_retention_origin')
                ->value('first_retention_origin');
            $nextEligibilityAt = $nextEligibilityAt !== null
                ? Carbon::parse($nextEligibilityAt, 'UTC')->utc()->addDays($days)
                : null;
        }

        $scanned = (clone $query)->count();
        $manualReviewStates = (clone $query)->whereNotIn('b.status', ['verified', 'expired'])->count();
        $restoreCount = 0;
        $activeOperations = 0;
        $lastRecoveryPoints = 0;
        if (Schema::hasTable('backup_restores')) {
            $restoreCount = DB::table('backup_restores as r')
                ->join('backups as b', 'b.id', '=', 'r.backup_id')
                ->join('websites as w', 'w.id', '=', 'b.website_id')
                ->where('w.customer_id', $customer->id)
                ->whereIn('r.status', ['awaiting_safety_backup', 'queued', 'restoring'])->count();
        }
        if (Schema::hasTable('website_jobs')) {
            $activeOperations = DB::table('website_jobs as j')
                ->join('websites as w', 'w.id', '=', 'j.website_id')
                ->where('w.customer_id', $customer->id)
                ->whereIn('j.type', ['UPDATE_PLUGIN', 'UPDATE_THEME', 'UPDATE_CORE', 'SECURITY_QUARANTINE', 'SECURITY_RESTORE_QUARANTINE', 'SECURITY_REPAIR_FILE', 'SECURITY_DISABLE_PLUGIN'])
                ->whereIn('j.status', ['queued', 'waiting', 'delivered'])->count();
        }
        if ($scanned > 0) {
            $lastRecoveryPoints = (clone $query)->where('b.status', 'verified')->whereNotNull('b.verified_at')
                ->whereNotExists(function ($subquery) use ($customer): void {
                    $subquery->selectRaw('1')->from('backups as other')
                        ->join('websites as other_websites', 'other_websites.id', '=', 'other.website_id')
                        ->where('other_websites.customer_id', $customer->id)
                        ->whereColumn('other.website_id', 'b.website_id')->whereColumn('other.id', '<>', 'b.id')
                        ->where('other.status', 'verified')->whereNotNull('other.verified_at')->whereNull('other.objects_deleted_at')
                        ->where(fn ($q) => $q->whereNull('other.deletion_state')->orWhere('other.deletion_state', 'not_requested'));
                })->count();
        }

        $blockers = [];
        if ($days === null) {
            $blockers[] = 'BLOCKED_POLICY_MISSING';
        }
        if ($restoreCount > 0) {
            $blockers[] = 'BLOCKED_ACTIVE_RESTORE';
        }
        if ($activeOperations > 0) {
            $blockers[] = 'BLOCKED_ACTIVE_OPERATION';
        }
        if ($lastRecoveryPoints > 0) {
            $blockers[] = 'BLOCKED_LAST_RECOVERY_POINT';
        }
        if ($manualReviewStates > 0) {
            $blockers[] = 'MANUAL_REVIEW';
        }
        if ($candidates > 0) {
            $blockers = array_merge($blockers, ['BLOCKED_PROVIDER_FACT', 'BLOCKED_LEGAL_APPROVAL', 'BLOCKED_SECURITY_REVIEW']);
        }

        return [
            'coverage' => 'PARTIAL', 'active_plan_retention_separate' => true,
            'post_service_retention_days' => $days, 'scanned' => $scanned,
            'next_technical_eligibility_at' => $nextEligibilityAt?->toIso8601String(),
            'technical_candidates' => $candidates, 'blocked_active_restore' => $restoreCount,
            'blocked_active_operation' => $activeOperations, 'blocked_last_recovery_point' => $lastRecoveryPoints,
            'manual_review_backup_states' => $manualReviewStates,
            'blockers' => array_values(array_unique($blockers)),
            'deleted' => 0,
        ];
    }

    private function duration(string $policyKey): ?int
    {
        if (! Schema::hasTable('privacy_retention_policies')) {
            return null;
        }
        $policy = DB::table('privacy_retention_policies')->where('policy_key', $policyKey)->where('enabled', true)->orderByDesc('version')->first(['technical_value']);
        $value = $policy ? json_decode((string) $policy->technical_value, true) : RetentionPolicyDefaults::all()[$policyKey]['value'];
        if (! is_array($value) || ! is_numeric($value['value'] ?? null) || ($value['unit'] ?? null) !== 'days') {
            return null;
        }

        return max(0, (int) $value['value']);
    }
}
