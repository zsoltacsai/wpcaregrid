<?php

namespace App\Backups;

use App\Models\BackupOwnerPolicy;
use App\Models\BackupRetentionPolicyPreview;
use App\Models\Subscription;
use App\Models\SubscriptionBackupEntitlement;
use App\Models\User;
use App\Models\Website;
use App\Owner\AdminAudit;
use App\Privacy\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Owner extension/preview workflow. This service has no deletion or provider dependency. */
final class OwnerBackupProtectionService
{
    private const FIELDS = [
        'daily_recovery_points',
        'weekly_recovery_points',
        'monthly_recovery_points',
        'desired_post_service_days',
    ];

    public function __construct(private readonly GfsRetentionEvaluator $evaluator) {}

    /** @param array<string, int> $proposed */
    public function preview(Subscription $subscription, User $actor, array $proposed): BackupRetentionPolicyPreview
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($subscription, $actor, $proposed): BackupRetentionPolicyPreview {
            $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            $website = $locked->website()->firstOrFail();
            $entitlement = $this->currentEntitlement($locked);
            $base = $this->policy($entitlement);
            $current = $this->currentOwnerPolicy($locked, $base);
            $this->validateAboveFloor($proposed, $base);

            $now = CarbonImmutable::now('UTC');
            $currentResult = $this->evaluator->evaluate($website, $now, $current);
            $proposedPolicy = $proposed + $current;
            $proposedResult = $this->evaluator->evaluate($website, $now, $proposedPolicy);
            $impact = $this->impact($currentResult, $proposedResult);
            $sourceFingerprint = $this->sourceFingerprint($locked, $website, $currentResult, $current);
            $expiresAt = now('UTC')->addMinutes(30);
            $body = [
                'subscription_uuid' => $locked->uuid,
                'entitlement_snapshot_id' => $entitlement->id,
                'owner_policy_version' => $current['version'],
                'source_fingerprint' => $sourceFingerprint,
                'proposed_values' => $proposed,
                'impact' => $impact,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
            $hash = hash('sha256', CanonicalJson::encode($body));
            $preview = BackupRetentionPolicyPreview::query()->create([
                'subscription_id' => $locked->id,
                'created_by_user_id' => $actor->id,
                'state' => 'pending',
                'owner_policy_version' => $current['version'],
                'source_fingerprint' => $sourceFingerprint,
                'preview_hash' => $hash,
                'proposed_values' => $proposed,
                'impact' => $impact,
                'expires_at' => $expiresAt,
            ]);
            AdminAudit::record($actor, 'backup_policy.preview_created', 'subscription', $locked->uuid, [
                'preview_uuid' => $preview->uuid,
                'preview_hash' => $hash,
                'reduction' => $this->isReduction($proposed, $current),
                'new_candidate_count' => count($impact['would_become_future_candidates']),
            ]);

            return $preview;
        }, attempts: 3);
    }

    /** @return array{confirmed:bool,stale:bool,policy:?BackupOwnerPolicy} */
    public function confirm(BackupRetentionPolicyPreview $preview, User $actor, string $password, string $previewHash): array
    {
        $this->authorize($actor);
        if (! Hash::check($password, (string) $actor->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => 'Owner password confirmation failed.']);
        }

        return DB::transaction(function () use ($preview, $actor, $previewHash): array {
            $lockedPreview = BackupRetentionPolicyPreview::query()->whereKey($preview->getKey())->lockForUpdate()->firstOrFail();
            $subscription = Subscription::query()->whereKey($lockedPreview->subscription_id)->lockForUpdate()->firstOrFail();
            $website = $subscription->website()->firstOrFail();
            $entitlement = $this->currentEntitlement($subscription);
            $base = $this->policy($entitlement);
            $ownerPolicy = BackupOwnerPolicy::query()->where('subscription_id', $subscription->id)->lockForUpdate()->first();
            $current = $this->currentOwnerPolicy($subscription, $base, $ownerPolicy);
            $proposed = $lockedPreview->proposed_values;

            if ($lockedPreview->state !== 'pending'
                || $lockedPreview->expires_at->isPast()
                || ! hash_equals($lockedPreview->preview_hash, $previewHash)
                || $lockedPreview->owner_policy_version !== $current['version']) {
                $lockedPreview->forceFill(['state' => $lockedPreview->expires_at->isPast() ? 'expired' : 'stale'])->save();
                AdminAudit::record($actor, 'backup_policy.preview_stale', 'subscription', $subscription->uuid, ['preview_uuid' => $lockedPreview->uuid, 'reason_code' => 'preview_state_or_version_changed']);

                return ['confirmed' => false, 'stale' => true, 'policy' => null];
            }

            $expectedHash = hash('sha256', CanonicalJson::encode([
                'subscription_uuid' => $subscription->uuid,
                'entitlement_snapshot_id' => $entitlement->id,
                'owner_policy_version' => $lockedPreview->owner_policy_version,
                'source_fingerprint' => $lockedPreview->source_fingerprint,
                'proposed_values' => $proposed,
                'impact' => $lockedPreview->impact,
                'expires_at' => $lockedPreview->expires_at->toIso8601String(),
            ]));
            if (! hash_equals($lockedPreview->preview_hash, $expectedHash)) {
                $lockedPreview->forceFill(['state' => 'stale'])->save();
                AdminAudit::record($actor, 'backup_policy.preview_stale', 'subscription', $subscription->uuid, ['preview_uuid' => $lockedPreview->uuid, 'reason_code' => 'preview_integrity_mismatch']);

                return ['confirmed' => false, 'stale' => true, 'policy' => null];
            }

            $currentResult = $this->evaluator->evaluate($website, CarbonImmutable::now('UTC'), $current);
            $currentFingerprint = $this->sourceFingerprint($subscription, $website, $currentResult, $current);
            if (! hash_equals($lockedPreview->source_fingerprint, $currentFingerprint)) {
                $lockedPreview->forceFill(['state' => 'stale'])->save();
                AdminAudit::record($actor, 'backup_policy.preview_stale', 'subscription', $subscription->uuid, ['preview_uuid' => $lockedPreview->uuid, 'reason_code' => 'source_changed']);

                return ['confirmed' => false, 'stale' => true, 'policy' => null];
            }

            $this->validateAboveFloor($proposed, $base);
            $newVersion = $current['version'] + 1;
            $attributes = [
                'daily_recovery_points' => $proposed['daily_recovery_points'],
                'weekly_recovery_points' => $proposed['weekly_recovery_points'],
                'monthly_recovery_points' => $proposed['monthly_recovery_points'],
                'desired_post_service_days' => $proposed['desired_post_service_days'],
                'version' => $newVersion,
                'updated_by_user_id' => $actor->id,
            ];
            $ownerPolicy = BackupOwnerPolicy::query()->updateOrCreate(['subscription_id' => $subscription->id], $attributes);
            $lockedPreview->forceFill(['state' => 'confirmed', 'confirmed_at' => now('UTC')])->save();
            AdminAudit::record($actor, 'backup_policy.extension_changed', 'subscription', $subscription->uuid, [
                'policy_version' => $newVersion,
                'preview_uuid' => $lockedPreview->uuid,
                'preview_hash' => $lockedPreview->preview_hash,
                'reduction' => $this->isReduction($proposed, $current),
                'wait_states' => ['WAIT_LEGAL', 'WAIT_ACCOUNTING', 'WAIT_FACT'],
            ]);

            // Refresh the non-destructive shadow report so Owner extension reasons are visible immediately.
            app(BackupGfsShadowStore::class)->evaluate($website->refresh());

            return ['confirmed' => true, 'stale' => false, 'policy' => $ownerPolicy];
        }, attempts: 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isOwner() || $actor->customer()->exists()) {
            throw new AuthorizationException;
        }
    }

    private function currentEntitlement(Subscription $subscription): SubscriptionBackupEntitlement
    {
        $entitlement = $subscription->backupEntitlements()
            ->where('resolution', 'known')
            ->where('effective_at', '<=', now('UTC'))
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        if (! $entitlement) {
            throw ValidationException::withMessages(['subscription' => 'A known effective backup entitlement is required.']);
        }

        return $entitlement;
    }

    /** @return array{daily_recovery_points:int,weekly_recovery_points:int,monthly_recovery_points:int} */
    private function policy(SubscriptionBackupEntitlement $entitlement): array
    {
        return [
            'daily_recovery_points' => (int) $entitlement->daily_recovery_points,
            'weekly_recovery_points' => (int) $entitlement->weekly_recovery_points,
            'monthly_recovery_points' => (int) $entitlement->monthly_recovery_points,
        ];
    }

    /** @return array{daily_recovery_points:int,weekly_recovery_points:int,monthly_recovery_points:int,desired_post_service_days:int,version:int} */
    private function currentOwnerPolicy(Subscription $subscription, array $base, ?BackupOwnerPolicy $policy = null): array
    {
        $policy ??= $subscription->backupOwnerPolicy;

        return [
            'daily_recovery_points' => max($base['daily_recovery_points'], (int) ($policy?->daily_recovery_points ?? $base['daily_recovery_points'])),
            'weekly_recovery_points' => max($base['weekly_recovery_points'], (int) ($policy?->weekly_recovery_points ?? $base['weekly_recovery_points'])),
            'monthly_recovery_points' => max($base['monthly_recovery_points'], (int) ($policy?->monthly_recovery_points ?? $base['monthly_recovery_points'])),
            'desired_post_service_days' => (int) ($policy?->desired_post_service_days ?? 30),
            'version' => (int) ($policy?->version ?? 0),
        ];
    }

    private function validateAboveFloor(array $proposed, array $base): void
    {
        foreach (['daily_recovery_points', 'weekly_recovery_points', 'monthly_recovery_points'] as $field) {
            if (! isset($proposed[$field]) || ! is_int($proposed[$field]) || $proposed[$field] < $base[$field]) {
                throw ValidationException::withMessages([$field => 'Owner protection cannot be lower than the purchased entitlement.']);
            }
        }
        foreach (['daily_recovery_points' => 365, 'weekly_recovery_points' => 520, 'monthly_recovery_points' => 240] as $field => $maximum) {
            if ($proposed[$field] > $maximum) {
                throw ValidationException::withMessages([$field => 'The requested protection exceeds the supported policy bound.']);
            }
        }
        if (! isset($proposed['desired_post_service_days']) || ! is_int($proposed['desired_post_service_days'])
            || $proposed['desired_post_service_days'] < 0 || $proposed['desired_post_service_days'] > 3650) {
            throw ValidationException::withMessages(['desired_post_service_days' => 'Choose a desired duration between 0 and 3650 days.']);
        }
    }

    private function sourceFingerprint(Subscription $subscription, Website $website, array $evaluation, array $ownerPolicy): string
    {
        return hash('sha256', CanonicalJson::encode([
            'subscription' => [$subscription->id, $subscription->product_type?->value, $subscription->plan?->value, $subscription->status?->value, $subscription->updated_at?->utc()->toIso8601String()],
            'website_uuid' => $website->uuid,
            'evaluation_fingerprint' => $evaluation['evaluation_fingerprint'],
            'owner_policy' => $ownerPolicy,
        ]));
    }

    private function isReduction(array $proposed, array $current): bool
    {
        foreach (['daily_recovery_points', 'weekly_recovery_points', 'monthly_recovery_points', 'desired_post_service_days'] as $field) {
            if (($proposed[$field] ?? 0) < ($current[$field] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    private function impact(array $current, array $proposed): array
    {
        $currentProtected = collect($current['protected_backups'])->keyBy('backup_uuid');
        $proposedProtected = collect($proposed['protected_backups'])->keyBy('backup_uuid');
        $currentCandidates = $current['potential_candidates'] ?? [];
        $proposedCandidates = $proposed['potential_candidates'] ?? [];
        $lost = [];
        $stillProtected = [];
        foreach ($currentProtected as $uuid => $point) {
            if (! in_array(BackupProtectionReason::OwnerExtension->value, $point['reasons'], true)) {
                continue;
            }
            $after = $proposedProtected->get($uuid);
            if ($after && in_array(BackupProtectionReason::OwnerExtension->value, $after['reasons'], true)) {
                continue;
            }
            if ($after) {
                $stillProtected[] = ['backup_uuid' => $uuid, 'reasons' => $after['reasons']];
            } else {
                $lost[] = $uuid;
            }
        }

        return [
            'base_entitlement' => $current['policy'],
            'current_effective_policy' => $current['effective_policy'] ?? $current['policy'],
            'proposed_effective_policy' => $proposed['effective_policy'] ?? $proposed['policy'],
            'currently_protected_points' => $current['protected_backups'] ?? [],
            'recovery_gaps' => $proposed['missing_slots'] ?? [],
            'points_losing_only_owner_extension' => $lost,
            'points_still_protected_for_other_reasons' => $stillProtected,
            'would_become_future_candidates' => array_values(array_diff($proposedCandidates, $currentCandidates)),
            'wait_states' => ['WAIT_LEGAL', 'WAIT_ACCOUNTING', 'WAIT_FACT'],
            'provider_deletion_authorized' => false,
        ];
    }
}
