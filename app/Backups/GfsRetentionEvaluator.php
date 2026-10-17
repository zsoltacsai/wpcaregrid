<?php

namespace App\Backups;

use App\Connector\JobStatus;
use App\Models\Backup;
use App\Models\BackupProtectionHold;
use App\Models\BackupRestore;
use App\Models\SubscriptionBackupEntitlement;
use App\Models\Website;
use App\Models\WebsiteJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/** Calculates GFS coverage and future eligibility candidates; it never mutates backups or storage. */
final class GfsRetentionEvaluator
{
    private const MAX_BACKUPS_PER_SITE = 5000;

    private const CLASSES = [
        'DAILY' => ['field' => 'daily_recovery_points', 'reason' => 'GFS_DAILY'],
        'WEEKLY' => ['field' => 'weekly_recovery_points', 'reason' => 'GFS_WEEKLY'],
        'MONTHLY' => ['field' => 'monthly_recovery_points', 'reason' => 'GFS_MONTHLY'],
    ];

    /** @return array<string, mixed> */
    public function evaluate(Website $website, ?CarbonImmutable $at = null, ?array $ownerPolicyOverride = null): array
    {
        $at = ($at ?? CarbonImmutable::now())->utc();
        $snapshot = $website->subscription?->backupEntitlements()
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        if (! $snapshot || $snapshot->resolution !== 'known' || ! $this->hasCompletePolicy($snapshot)) {
            return $this->unknownResult($website, $snapshot, $at, 'BACKUP_ENTITLEMENT_UNKNOWN');
        }

        $policy = [];
        foreach (self::CLASSES as $class => $definition) {
            $policy[$class] = (int) $snapshot->{$definition['field']};
        }

        $required = [];
        $satisfied = [];
        $missing = [];
        $reasons = [];
        $points = $website->backups()->with(['objects', 'website'])->orderBy('id')->limit(self::MAX_BACKUPS_PER_SITE + 1)->get();
        if ($points->count() > self::MAX_BACKUPS_PER_SITE) {
            return $this->unknownResult($website, $snapshot, $at, 'GFS_EVALUATION_LIMIT');
        }
        $usable = $points->filter(fn (Backup $backup) => $this->usable($backup))->values();

        $ownerPolicy = $ownerPolicyOverride ?? [
            'daily_recovery_points' => $website->subscription?->backupOwnerPolicy?->daily_recovery_points,
            'weekly_recovery_points' => $website->subscription?->backupOwnerPolicy?->weekly_recovery_points,
            'monthly_recovery_points' => $website->subscription?->backupOwnerPolicy?->monthly_recovery_points,
            'version' => $website->subscription?->backupOwnerPolicy?->version ?? 0,
        ];
        $effective = [];
        foreach (self::CLASSES as $class => $definition) {
            $count = $policy[$class];
            $effective[$class] = max($count, (int) ($ownerPolicy[$definition['field']] ?? $count));
            $count = $effective[$class];
            $required[$class] = $count === 0 ? [] : $this->slots($class, $count, $at);
            foreach ($required[$class] as $slotIndex => $slot) {
                $point = $usable
                    ->filter(fn (Backup $backup) => $this->slotFor($class, CarbonImmutable::instance($backup->verified_at)->utc()) === $slot)
                    ->sort(function (Backup $left, Backup $right): int {
                        $time = $right->verified_at <=> $left->verified_at;

                        return $time !== 0 ? $time : ((int) $right->id <=> (int) $left->id);
                    })
                    ->first();

                if (! $point) {
                    $missing[] = ['class' => $class, 'slot' => $slot, 'reason' => 'RECOVERY_GAP'];

                    continue;
                }

                $satisfied[$class][$slot] = $point->uuid;
                $reasons[$point->uuid][] = $definition['reason'];
                if ($slotIndex >= (int) $snapshot->{$definition['field']}) {
                    $reasons[$point->uuid][] = BackupProtectionReason::OwnerExtension->value;
                }
            }
            $satisfied[$class] ??= [];
        }

        $latest = $usable->sort(function (Backup $left, Backup $right): int {
            $time = $right->verified_at <=> $left->verified_at;

            return $time !== 0 ? $time : ((int) $right->id <=> (int) $left->id);
        })->first();
        if ($latest) {
            $reasons[$latest->uuid][] = BackupProtectionReason::LastKnownGood->value;
        }

        $activeRestores = BackupRestore::query()->where('website_id', $website->id)->get()
            ->filter(fn (BackupRestore $restore) => $restore->status->needsRecoveryArtifacts());
        foreach ($activeRestores as $restore) {
            $source = $points->firstWhere('id', $restore->backup_id);
            if ($source) {
                $reasons[$source->uuid][] = $restore->status->value === 'recovery_failed'
                    || $restore->status->value === 'manual_recovery_required'
                    ? BackupProtectionReason::Compensation->value
                    : BackupProtectionReason::ActiveRestore->value;
            }

            $safety = $points->firstWhere('id', $restore->safety_backup_id);
            if ($safety) {
                $reasons[$safety->uuid][] = BackupProtectionReason::SafetyBackup->value;
            }
        }

        $activeJobs = WebsiteJob::query()->where('website_id', $website->id)
            ->whereNotNull('backup_id')
            ->whereIn('status', [JobStatus::Waiting->value, JobStatus::Queued->value, JobStatus::Delivered->value])
            ->get(['backup_id', 'type', 'status', 'updated_at']);
        foreach ($activeJobs as $job) {
            $backup = $points->firstWhere('id', $job->backup_id);
            if ($backup) {
                $reason = str_starts_with($job->type->value, 'SECURITY_')
                    ? BackupProtectionReason::SecurityRemediationDependency->value
                    : BackupProtectionReason::UpdateDependency->value;
                $reasons[$backup->uuid][] = $reason;
                if ($backup->reason === BackupReason::PreUpdate) {
                    $reasons[$backup->uuid][] = BackupProtectionReason::SafetyBackup->value;
                }
            }
        }

        $activeHolds = BackupProtectionHold::query()
            ->where('website_id', $website->id)
            ->whereIn('backup_id', $points->pluck('id'))
            ->whereNull('released_at')
            ->orderBy('id')
            ->get(['id', 'backup_id', 'reason_code', 'source_code', 'held_at', 'updated_at']);
        foreach ($activeHolds as $hold) {
            $backup = $points->firstWhere('id', $hold->backup_id);
            if ($backup) {
                $reasons[$backup->uuid][] = $hold->reason_code;
            }
        }

        // Unresolved external requirements never produce an eligible-to-delete state.
        $waitReasons = ['WAIT_LEGAL', 'WAIT_ACCOUNTING', 'WAIT_FACT'];
        $protected = [];
        $potentialCandidates = [];
        $blocked = [];
        foreach ($usable as $backup) {
            $pointReasons = array_values(array_unique($reasons[$backup->uuid] ?? []));
            if ($pointReasons !== []) {
                sort($pointReasons, SORT_STRING);
                $protected[] = ['backup_uuid' => $backup->uuid, 'reasons' => $pointReasons];

                continue;
            }

            $potentialCandidates[] = $backup->uuid;
            $blocked[] = ['backup_uuid' => $backup->uuid, 'state' => 'BLOCKED', 'reasons' => $waitReasons];
        }

        $fingerprintSource = [
            'website_id' => $website->id,
            'entitlement_snapshot_id' => $snapshot->id,
            'catalogue_version' => $snapshot->catalogue_version,
            'policy' => [
                $snapshot->cadence,
                $snapshot->daily_recovery_points,
                $snapshot->weekly_recovery_points,
                $snapshot->monthly_recovery_points,
                $snapshot->effective_at?->utc()->toIso8601String(),
            ],
            'owner_policy' => [$ownerPolicy['version'] ?? 0, $effective],
            'at_slots' => $required,
            'points' => $points->map(fn (Backup $backup) => [
                $backup->id,
                $backup->type->value,
                $backup->reason->value,
                $backup->status?->value,
                $backup->completed_at?->utc()->toIso8601String(),
                $backup->verified_at?->utc()->toIso8601String(),
                $backup->expires_at?->utc()->toIso8601String(),
                $backup->deletion_state,
                $backup->objects_deleted_at?->utc()->toIso8601String(),
                hash('sha256', json_encode($backup->manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                $backup->objects->sortBy('name')->map(fn ($object) => [
                    $object->name,
                    $object->status,
                    $object->verified_at?->utc()->toIso8601String(),
                    $object->sha256,
                    $object->updated_at?->utc()->toIso8601String(),
                ])->values()->all(),
            ])->all(),
            'restore_dependencies' => $activeRestores->map(fn (BackupRestore $restore) => [$restore->id, $restore->backup_id, $restore->safety_backup_id, $restore->status->value, $restore->updated_at?->utc()->toIso8601String()])->all(),
            'active_jobs' => $activeJobs->map(fn (WebsiteJob $job) => [$job->backup_id, $job->type->value, $job->status->value, $job->updated_at?->utc()->toIso8601String()])->all(),
            'active_holds' => $activeHolds->map(fn (BackupProtectionHold $hold) => [$hold->id, $hold->backup_id, $hold->reason_code, $hold->source_code, $hold->held_at?->utc()->toIso8601String(), $hold->updated_at?->utc()->toIso8601String()])->all(),
        ];

        return [
            'status' => $missing === [] ? 'CURRENT' : 'RECOVERY_GAP',
            'website_uuid' => $website->uuid,
            'catalogue_version' => $snapshot->catalogue_version,
            'entitlement_snapshot_id' => $snapshot->id,
            'policy' => ['daily' => $policy['DAILY'], 'weekly' => $policy['WEEKLY'], 'monthly' => $policy['MONTHLY']],
            'effective_policy' => ['daily' => $effective['DAILY'], 'weekly' => $effective['WEEKLY'], 'monthly' => $effective['MONTHLY']],
            'evaluated_at' => $at->toIso8601String(),
            'required_slots' => $required,
            'satisfied_slots' => $satisfied,
            'missing_slots' => $missing,
            'protected_backups' => $protected,
            'potential_candidates' => $potentialCandidates,
            'blocked_candidates' => $blocked,
            'eligible_candidates' => [],
            'eligibility_blockers' => $waitReasons,
            'provider_deletion_authorized' => false,
            'evaluation_fingerprint' => hash('sha256', json_encode($fingerprintSource, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** @return list<string> */
    private function slots(string $class, int $count, CarbonImmutable $at): array
    {
        $slots = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $slots[] = $this->slotFor($class, $this->slotStart($class, $at)->sub($this->unit($class, $offset)));
        }

        return $slots;
    }

    private function slotFor(string $class, CarbonImmutable $at): string
    {
        return match ($class) {
            'DAILY' => $at->format('Y-m-d'),
            'WEEKLY' => $at->format('o-\\WW'),
            'MONTHLY' => $at->format('Y-m'),
        };
    }

    private function slotStart(string $class, CarbonImmutable $at): CarbonImmutable
    {
        return match ($class) {
            'DAILY' => $at->startOfDay(),
            'WEEKLY' => $at->startOfWeek(Carbon::MONDAY),
            'MONTHLY' => $at->startOfMonth(),
        };
    }

    private function unit(string $class, int $offset): string
    {
        return match ($class) {
            'DAILY' => $offset.' days',
            'WEEKLY' => $offset.' weeks',
            'MONTHLY' => $offset.' months',
        };
    }

    private function usable(Backup $backup): bool
    {
        if (! $backup->isRestorable() || ! $backup->verified_at || ! $backup->completed_at) {
            return false;
        }

        $manifest = $backup->manifest;
        if (! is_array($manifest)
            || ($manifest['format'] ?? null) !== BackupManifest::FORMAT
            || ($manifest['backup_uuid'] ?? null) !== $backup->uuid
            || ($manifest['website_uuid'] ?? null) !== $backup->website->uuid
            || ($manifest['type'] ?? null) !== $backup->type->value
            || data_get($manifest, 'checksums.algorithm') !== 'sha256') {
            return false;
        }

        $objects = $backup->objects->keyBy('name');
        foreach ($backup->type->objects() as $name) {
            $object = $objects->get($name);
            if (! $object
                || $object->status !== 'verified'
                || $object->verified_at === null
                || ! is_string($object->sha256)
                || preg_match('/^[a-f0-9]{64}$/', $object->sha256) !== 1
                || ! isset($manifest['checksums'][$object->filename])
                || ! hash_equals($object->sha256, (string) $manifest['checksums'][$object->filename])) {
                return false;
            }
        }

        return true;
    }

    private function hasCompletePolicy(SubscriptionBackupEntitlement $snapshot): bool
    {
        foreach (self::CLASSES as $definition) {
            if (! is_int($snapshot->{$definition['field']}) || $snapshot->{$definition['field']} < 0) {
                return false;
            }
        }

        return in_array($snapshot->cadence, ['daily', 'weekly', 'monthly'], true)
            && is_string($snapshot->catalogue_version)
            && $snapshot->catalogue_version !== '';
    }

    /** @return array<string, mixed> */
    private function unknownResult(Website $website, ?SubscriptionBackupEntitlement $snapshot, CarbonImmutable $at, string $reason): array
    {
        return [
            'status' => 'UNKNOWN',
            'website_uuid' => $website->uuid,
            'catalogue_version' => $snapshot?->catalogue_version,
            'entitlement_snapshot_id' => $snapshot?->id,
            'evaluated_at' => $at->toIso8601String(),
            'required_slots' => [],
            'satisfied_slots' => [],
            'missing_slots' => [],
            'protected_backups' => [],
            'potential_candidates' => [],
            'blocked_candidates' => [],
            'eligible_candidates' => [],
            'eligibility_blockers' => [$reason, 'WAIT_LEGAL', 'WAIT_ACCOUNTING', 'WAIT_FACT'],
            'provider_deletion_authorized' => false,
            'evaluation_fingerprint' => hash('sha256', implode('|', [$website->id, $snapshot?->id, $snapshot?->resolution, $reason, $at->toDateString()])),
        ];
    }
}
