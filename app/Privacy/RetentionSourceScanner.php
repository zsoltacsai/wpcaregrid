<?php

namespace App\Privacy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only, aggregate-only source discovery. Unsupported sources stay visibly unknown. */
final class RetentionSourceScanner
{
    /** @return array{coverage:string,watermark:?string,counts:array<string,array<string,int|string>>,unsupported:list<string>} */
    public function scan(array $overrides = []): array
    {
        $counts = [];
        $unsupported = [];
        $watermarks = [];
        $now = now('UTC');
        $activePolicies = [];
        if (Schema::hasTable('privacy_retention_policies')) {
            foreach (DB::table('privacy_retention_policies')->where('enabled', true)->orderBy('policy_key')->orderBy('version')->get(['policy_key', 'version', 'technical_value']) as $policy) {
                $activePolicies[$policy->policy_key] = [
                    'value' => json_decode((string) $policy->technical_value, true),
                    'version' => (int) $policy->version,
                ];
            }
        }
        $activePolicies = array_replace($activePolicies, $overrides);
        $policyWatermark = hash('sha256', CanonicalJson::encode($activePolicies));

        if (Schema::hasTable('backups') && Schema::hasTable('subscriptions')) {
            $query = DB::table('backups as b')->join('websites as w', 'w.id', '=', 'b.website_id')
                ->join('subscriptions as s', 's.id', '=', 'w.subscription_id')->whereNotNull('s.ended_at');
            $watermarks[] = 'backups:'.(clone $query)->count().':'.((clone $query)->max('b.updated_at') ?: 'empty');
            $postServiceDays = $this->durationValue($activePolicies, 'RET-04');
            $eligible = $postServiceDays === null ? 0 : (clone $query)->where('s.ended_at', '<=', $now->copy()->subDays($postServiceDays))->whereNull('b.objects_deleted_at')->count();
            $activeRestore = DB::table('backup_restores as r')->join('backups as b', 'b.id', '=', 'r.backup_id')
                ->join('websites as w', 'w.id', '=', 'b.website_id')->join('subscriptions as s', 's.id', '=', 'w.subscription_id')
                ->whereNotNull('s.ended_at')->whereIn('r.status', ['awaiting_safety_backup', 'queued', 'restoring'])->count();
            $lastRecoveryPoint = (clone $query)->where('b.status', 'verified')->whereNotNull('b.verified_at')->whereNull('b.objects_deleted_at')
                ->whereNotExists(function ($subquery): void {
                    $subquery->selectRaw('1')->from('backups as other')->whereColumn('other.website_id', 'b.website_id')
                        ->whereColumn('other.id', '<>', 'b.id')->where('other.status', 'verified')->whereNotNull('other.verified_at')->whereNull('other.objects_deleted_at');
                })->count();
            $activeOperations = DB::table('website_jobs as j')->join('websites as w', 'w.id', '=', 'j.website_id')
                ->join('subscriptions as s', 's.id', '=', 'w.subscription_id')->whereNotNull('s.ended_at')
                ->whereIn('j.type', ['UPDATE_PLUGIN', 'UPDATE_THEME', 'UPDATE_CORE', 'SECURITY_QUARANTINE', 'SECURITY_RESTORE_QUARANTINE', 'SECURITY_REPAIR_FILE', 'SECURITY_DISABLE_PLUGIN'])
                ->whereIn('j.status', ['queued', 'waiting', 'delivered'])->count();
            $counts['customer_backups'] = [
                'scanned' => (clone $query)->count(), 'technical_candidates_before_blockers' => $eligible,
                'blocked_policy_missing' => $postServiceDays === null ? (clone $query)->count() : 0,
                'blocked_active_restore' => $activeRestore, 'blocked_active_operation' => $activeOperations,
                'blocked_last_recovery_point' => $lastRecoveryPoint, 'blocked_provider_fact' => $eligible,
                'result_codes' => $postServiceDays === null ? 'BLOCKED_POLICY_MISSING' : 'BLOCKED_PROVIDER_FACT;BLOCKED_LEGAL_APPROVAL;BLOCKED_SECURITY_REVIEW',
            ];
        } else {
            $unsupported[] = 'customer_backups';
        }

        if (Schema::hasTable('stripe_webhook_inbox')) {
            $rawDays = $this->durationValue($activePolicies, 'RET-07');
            $rawQuery = DB::table('stripe_webhook_inbox');
            $rawCandidates = $rawDays === null ? 0 : (clone $rawQuery)->where('created_at', '<=', $now->copy()->subDays($rawDays))->count();
            $counts['webhook_raw_payload'] = ['scanned' => (clone $rawQuery)->count(), 'technical_candidates_before_blockers' => $rawCandidates, 'blocked_policy_missing' => $rawDays === null ? (clone $rawQuery)->count() : 0, 'blocked_legal_accounting_security' => $rawCandidates, 'result_codes' => $rawDays === null ? 'BLOCKED_POLICY_MISSING' : 'BLOCKED_LEGAL_APPROVAL;BLOCKED_ACCOUNTING_APPROVAL;BLOCKED_SECURITY_REVIEW'];
            $idempotencyDays = $this->durationValue($activePolicies, 'RET-08');
            $idempotency = $idempotencyDays === null ? 0 : (clone $rawQuery)->where('created_at', '<=', $now->copy()->subDays($idempotencyDays))->count();
            $counts['webhook_idempotency'] = ['scanned' => (clone $rawQuery)->count(), 'technical_candidates_before_blockers' => $idempotency, 'blocked_policy_missing' => $idempotencyDays === null ? (clone $rawQuery)->count() : 0, 'blocked_legal_accounting_security' => $idempotency, 'result_codes' => $idempotencyDays === null ? 'BLOCKED_POLICY_MISSING' : 'BLOCKED_LEGAL_APPROVAL;BLOCKED_ACCOUNTING_APPROVAL;BLOCKED_SECURITY_REVIEW'];
            $watermarks[] = 'webhooks:'.DB::table('stripe_webhook_inbox')->count().':'.(DB::table('stripe_webhook_inbox')->max('updated_at') ?: 'empty');
        } else {
            $unsupported[] = 'webhook_raw_payload';
        }

        if (Schema::hasTable('billing_email_outbox')) {
            $emailDays = $this->durationValue($activePolicies, 'RET-09');
            $emailQuery = DB::table('billing_email_outbox');
            $emailCandidates = $emailDays === null ? 0 : (clone $emailQuery)->where('created_at', '<=', $now->copy()->subDays($emailDays))->count();
            $counts['email_payload_proxy'] = ['scanned' => (clone $emailQuery)->count(), 'technical_candidates_before_classification' => $emailCandidates, 'blocked_policy_missing' => $emailDays === null ? (clone $emailQuery)->count() : 0, 'blocked_manual_review' => $emailCandidates, 'result_codes' => $emailDays === null ? 'BLOCKED_POLICY_MISSING' : 'MANUAL_REVIEW'];
            $metadataDays = $this->durationValue($activePolicies, 'RET-10');
            $metadata = $metadataDays === null ? 0 : (clone $emailQuery)->where('created_at', '<=', $now->copy()->subDays($metadataDays))->count();
            $counts['email_metadata'] = ['scanned' => (clone $emailQuery)->count(), 'technical_candidates_before_blockers' => $metadata, 'blocked_policy_missing' => $metadataDays === null ? (clone $emailQuery)->count() : 0, 'blocked_legal_security_review' => $metadata, 'result_codes' => $metadataDays === null ? 'BLOCKED_POLICY_MISSING' : 'BLOCKED_LEGAL_APPROVAL;BLOCKED_SECURITY_REVIEW'];
            $watermarks[] = 'emails:'.DB::table('billing_email_outbox')->count().':'.(DB::table('billing_email_outbox')->max('updated_at') ?: 'empty');
        } else {
            $unsupported[] = 'email_payload_proxy';
            $unsupported[] = 'email_metadata';
        }

        if (Schema::hasTable('platform_database_backups')) {
            $query = DB::table('platform_database_backups')->whereNotNull('expires_at')->where('expires_at', '<=', $now);
            $counts['platform_backups'] = ['scanned' => DB::table('platform_database_backups')->count(), 'expired_metadata_only' => $query->count(), 'technical_candidates_before_blockers' => 0, 'blocked_policy_missing' => DB::table('platform_database_backups')->count(), 'result_codes' => 'BLOCKED_POLICY_MISSING'];
            $watermarks[] = 'platform_backups:'.DB::table('platform_database_backups')->count().':'.(DB::table('platform_database_backups')->max('updated_at') ?: 'empty');
        } else {
            $unsupported[] = 'platform_backups';
        }

        if (Schema::hasTable('admin_audit_events')) {
            $days = $this->durationValue($activePolicies, 'RET-12');
            // The evaluator's own audit events are excluded to avoid a self-invalidating preview watermark.
            $auditSource = DB::table('admin_audit_events')->whereNotIn('event', [
                'privacy.retention.draft_created', 'privacy.retention.preview_created', 'privacy.retention.reporting_activated',
                'privacy.retention.owner_approval_recorded', 'privacy.retention.locked_change_denied',
            ]);
            $old = $days === null ? 0 : (clone $auditSource)->where('created_at', '<=', $now->copy()->subDays($days))->count();
            $counts['owner_audit_evidence'] = ['scanned' => (clone $auditSource)->count(), 'technical_candidates_before_blockers' => $old, 'blocked_policy_missing' => $days === null ? (clone $auditSource)->count() : 0, 'blocked_legal_accounting_security' => $old, 'result_codes' => $days === null ? 'BLOCKED_POLICY_MISSING' : 'BLOCKED_LEGAL_APPROVAL;BLOCKED_ACCOUNTING_APPROVAL;BLOCKED_SECURITY_REVIEW'];
            $watermarks[] = 'owner_audit:'.(clone $auditSource)->count().':'.((clone $auditSource)->max('created_at') ?: 'empty');
        } else {
            $unsupported[] = 'owner_audit_evidence';
        }

        if (Schema::hasTable('website_events')) {
            $days = $this->durationValue($activePolicies, 'RET-12');
            $eventSource = DB::table('website_events');
            $old = $days === null ? 0 : (clone $eventSource)->where('created_at', '<=', $now->copy()->subDays($days))->count();
            $counts['website_audit_evidence'] = ['scanned' => (clone $eventSource)->count(), 'technical_candidates_before_blockers' => $old, 'blocked_policy_missing' => $days === null ? (clone $eventSource)->count() : 0, 'blocked_legal_accounting_security' => $old, 'result_codes' => $days === null ? 'BLOCKED_POLICY_MISSING' : 'BLOCKED_LEGAL_APPROVAL;BLOCKED_ACCOUNTING_APPROVAL;BLOCKED_SECURITY_REVIEW'];
            $watermarks[] = 'website_events:'.DB::table('website_events')->count().':'.(DB::table('website_events')->max('created_at') ?: 'empty');
        } else {
            $unsupported[] = 'website_audit_evidence';
        }

        $accountingTables = ['billing_invoices', 'invoice_intents', 'invoice_intent_tax_reviews', 'invoice_issuance_attempts', 'billing_tax_configuration_versions', 'customer_billing_identities'];
        $accountingCounts = [];
        foreach ($accountingTables as $table) {
            if (Schema::hasTable($table)) {
                $accountingCounts[$table] = DB::table($table)->count();
                $timestampColumn = Schema::hasColumn($table, 'updated_at') ? 'updated_at' : (Schema::hasColumn($table, 'created_at') ? 'created_at' : null);
                $watermarks[] = $table.':'.$accountingCounts[$table].':'.($timestampColumn === null ? 'no_timestamp' : (DB::table($table)->max($timestampColumn) ?: 'empty'));
            } else {
                $unsupported[] = 'accounting:'.$table;
            }
        }
        $counts['accounting_evidence'] = ['scanned' => array_sum($accountingCounts), 'technical_candidates_before_approval' => 0, 'blocked_accounting_approval' => array_sum($accountingCounts), 'result_codes' => 'BLOCKED_ACCOUNTING_APPROVAL'];

        if (Schema::hasTable('privacy_requests')) {
            $counts['privacy_case_history'] = ['scanned' => DB::table('privacy_requests')->count(), 'technical_candidates_before_legal_review' => 0, 'blocked_legal_accounting_review' => DB::table('privacy_requests')->count(), 'result_codes' => 'BLOCKED_LEGAL_APPROVAL;BLOCKED_ACCOUNTING_APPROVAL'];
            $watermarks[] = 'privacy_requests:'.DB::table('privacy_requests')->count().':'.(DB::table('privacy_requests')->max('updated_at') ?: 'empty');
        } else {
            $unsupported[] = 'privacy_case_history';
        }

        // Customer closure has no authoritative timestamp in the current schema.
        $unsupported[] = 'customer_profile_closure_trigger';
        // Application logs are not represented by an inventoryable local table.
        $unsupported[] = 'application_logs';
        $unsupported[] = 'security_audit_retention';
        $unsupported[] = 'privacy_export_artifacts';
        $unsupported[] = 'privacy_case_completed_trigger';
        $unsupported[] = 'synthetic_staging_accounts';

        return [
            'coverage' => $unsupported === [] ? 'FULL' : ($counts === [] ? 'UNKNOWN' : 'PARTIAL'),
            'watermark' => hash('sha256', implode('|', $watermarks)),
            'policy_watermark' => $policyWatermark,
            'counts' => $counts,
            'unsupported' => array_values(array_unique($unsupported)),
        ];
    }

    /** @param array<string, array{value:mixed,version:int}> $policies */
    private function durationValue(array $policies, string $key): ?int
    {
        $value = $policies[$key]['value'] ?? null;
        if (! is_array($value) || ! is_int($value['value'] ?? null) || $value['value'] < 0 || ($value['unit'] ?? null) !== 'days') {
            return null;
        }

        return $value['value'];
    }
}
