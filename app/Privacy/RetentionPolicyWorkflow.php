<?php

namespace App\Privacy;

use App\Models\PrivacyRetentionPolicy;
use App\Models\PrivacyRetentionPreview;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Owner policy drafts and reporting previews only. No deletion or provider service is injected. */
final class RetentionPolicyWorkflow
{
    public function __construct(private readonly RetentionSourceScanner $scanner) {}

    public function createDraft(string $key, mixed $value, User $actor): PrivacyRetentionPolicy
    {
        $definition = RetentionPolicyDefaults::all()[$key] ?? null;
        if ($definition === null || ! $definition['configurable'] || $definition['compliance_lock']) {
            AdminAudit::record($actor, 'privacy.retention.locked_change_denied', 'retention_policy', $key, ['result' => 'denied']);
            throw ValidationException::withMessages(['policy' => 'This retention policy is locked or not Owner-configurable.']);
        }
        $value = $this->validateValue($definition, $value);

        return DB::transaction(function () use ($key, $value, $definition, $actor): PrivacyRetentionPolicy {
            $latest = PrivacyRetentionPolicy::query()->where('policy_key', $key)->lockForUpdate()->orderByDesc('version')->first();
            $version = ($latest?->version ?? 0) + 1;
            $draft = PrivacyRetentionPolicy::query()->create([
                'policy_key' => $key, 'version' => $version, 'category_code' => $definition['category'],
                'trigger_code' => $definition['trigger'], 'setting_type' => $definition['type'],
                'technical_value' => $value, 'duration_value' => is_array($value) ? ($value['value'] ?? null) : null,
                'duration_unit' => is_array($value) ? ($value['unit'] ?? null) : null,
                'action' => 'report_only', 'approval_status' => 'draft', 'owner_approval_status' => 'owner_approved',
                'legal_review_status' => $definition['legal'] ? 'wait_legal' : 'not_required',
                'accounting_review_status' => $definition['accounting'] ? 'wait_accounting' : 'not_required',
                'security_review_status' => $definition['security'] ? 'wait_security' : 'not_required',
                'owner_decision_reference' => 'Owner-approved M12 RET defaults, 2026-10-14',
                'compliance_lock' => false, 'previous_version_id' => $latest?->id,
                'effective_at' => now('UTC'), 'owner_approved_by' => $actor->id, 'owner_approved_at' => now('UTC'),
                'last_changed_by' => $actor->id, 'enabled' => false,
            ]);
            AdminAudit::record($actor, 'privacy.retention.draft_created', 'retention_policy', $key, ['version' => $version]);

            return $draft;
        });
    }

    public function preview(PrivacyRetentionPolicy $policy, User $actor): PrivacyRetentionPreview
    {
        if ($policy->activated_at !== null || $policy->enabled) {
            throw ValidationException::withMessages(['policy' => 'Create a new draft version before generating an activation preview.']);
        }
        $scan = $this->safeScan([$policy->policy_key => ['value' => (array) $policy->technical_value, 'version' => (int) $policy->version]]);
        $summary = $this->summary($scan);
        $sourceWatermark = hash('sha256', ($scan['watermark'] ?? 'unknown').'|'.($scan['policy_watermark'] ?? 'unknown'));
        $evaluatedAt = now('UTC');
        $hash = hash('sha256', implode('|', [$policy->uuid, $policy->version, CanonicalJson::encode($policy->technical_value), $policy->effective_at?->utc()->toIso8601String(), $evaluatedAt->toIso8601String(), $sourceWatermark, CanonicalJson::encode($summary)]));
        $preview = PrivacyRetentionPreview::query()->firstOrCreate(['preview_hash' => $hash], [
            'uuid' => (string) Str::uuid(), 'policy_id' => $policy->id, 'policy_version' => $policy->version,
            'evaluated_at' => $evaluatedAt, 'engine_version' => RetentionEligibilityEvaluator::ENGINE_VERSION,
            'coverage_state' => $scan['coverage'], 'source_watermark' => $sourceWatermark, 'summary' => $summary,
            'preview_hash' => $hash, 'created_by' => $actor->id,
        ]);
        AdminAudit::record($actor, 'privacy.retention.preview_created', 'retention_policy', $policy->policy_key, ['version' => $policy->version, 'preview' => $preview->uuid, 'coverage' => $scan['coverage']]);

        return $preview;
    }

    public function activateForReporting(PrivacyRetentionPolicy $policy, string $previewUuid, User $actor): PrivacyRetentionPolicy
    {
        return DB::transaction(function () use ($policy, $previewUuid, $actor): PrivacyRetentionPolicy {
            // Serialize reporting activations across categories so another active
            // policy cannot change after this preview is checked but before commit.
            PrivacyRetentionPolicy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $locked = PrivacyRetentionPolicy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            if ($locked->activated_at !== null || $locked->enabled || $locked->approval_status?->value !== 'draft') {
                throw ValidationException::withMessages(['policy' => 'Only a draft policy version can be activated for reporting.']);
            }
            $preview = PrivacyRetentionPreview::query()->where('uuid', $previewUuid)->where('policy_id', $locked->id)->lockForUpdate()->first();
            if ($preview === null || $preview->policy_version !== $locked->version || $preview->activated_at !== null) {
                throw ValidationException::withMessages(['preview' => 'The preview is missing, stale, or already consumed. Generate a new preview.']);
            }
            $scan = $this->safeScan([$locked->policy_key => ['value' => (array) $locked->technical_value, 'version' => (int) $locked->version]]);
            $sourceWatermark = hash('sha256', ($scan['watermark'] ?? 'unknown').'|'.($scan['policy_watermark'] ?? 'unknown'));
            if (! hash_equals((string) $preview->source_watermark, $sourceWatermark)) {
                throw ValidationException::withMessages(['preview' => 'Source data changed after preview. Generate a fresh preview.']);
            }
            $expectedHash = hash('sha256', implode('|', [$locked->uuid, $locked->version, CanonicalJson::encode($locked->technical_value), $locked->effective_at?->utc()->toIso8601String(), $preview->evaluated_at->utc()->toIso8601String(), $sourceWatermark, CanonicalJson::encode($preview->summary)]));
            if (! hash_equals((string) $preview->preview_hash, $expectedHash)) {
                throw ValidationException::withMessages(['preview' => 'Preview does not match this immutable policy version. Generate a fresh preview.']);
            }
            $latestEffective = PrivacyRetentionPolicy::query()->where('policy_key', $locked->policy_key)->where('enabled', true)->where('id', '<>', $locked->id)->lockForUpdate()->get();
            foreach ($latestEffective as $old) {
                $old->forceFill(['enabled' => false, 'approval_status' => 'superseded'])->save();
            }
            $locked->forceFill([
                'action' => 'report_only', 'enabled' => true, 'approval_status' => 'pending_review',
                'activated_at' => now('UTC'), 'active_preview_hash' => $preview->preview_hash,
                'last_changed_by' => $actor->id,
            ])->save();
            $preview->forceFill(['activated_at' => now('UTC')])->save();
            AdminAudit::record($actor, 'privacy.retention.reporting_activated', 'retention_policy', $locked->policy_key, ['version' => $locked->version, 'preview' => $preview->uuid]);

            return $locked->refresh();
        });
    }

    /** @param array<string,mixed> $definition */
    private function validateValue(array $definition, mixed $value): mixed
    {
        if ($definition['type'] === 'duration') {
            $data = is_array($value) ? $value : [];
            $number = filter_var($data['value'] ?? null, FILTER_VALIDATE_INT);
            if ($number === false || $number < 0 || $number > 36500 || ($data['unit'] ?? null) !== $definition['unit']) {
                throw ValidationException::withMessages(['value' => 'Enter a non-negative duration within the supported technical bounds.']);
            }

            return ['value' => $number, 'unit' => $definition['unit']];
        }
        throw ValidationException::withMessages(['value' => 'This policy value type is not editable through the Owner form.']);
    }

    /** @param array<string,mixed> $scan @return array<string,mixed> */
    private function summary(array $scan): array
    {
        $categories = [];
        foreach ($scan['counts'] as $category => $counts) {
            $categories[$category] = $counts;
        }
        $categories['accounting_evidence'] ??= ['scanned' => 0, 'technical_candidates_before_approval' => 0, 'blocked_accounting_approval' => 0, 'coverage' => 'UNKNOWN'];

        return [
            'categories' => $categories,
            'unsupported_sources' => $scan['unsupported'],
            'coverage' => $scan['coverage'],
            'actually_deleted' => 0,
            'provider_calls' => 0,
            'source_watermark' => $scan['watermark'],
            'policy_watermark' => $scan['policy_watermark'] ?? 'unknown',
        ];
    }

    /** @param array<string,array<string,mixed>> $overrides @return array<string,mixed> */
    private function safeScan(array $overrides): array
    {
        try {
            return $this->scanner->scan($overrides);
        } catch (Throwable) {
            return ['coverage' => 'UNKNOWN', 'watermark' => hash('sha256', 'retention-source-scan-unavailable'), 'policy_watermark' => 'unknown', 'counts' => [], 'unsupported' => ['source_scan_failed']];
        }
    }
}
