<?php

namespace App\Platform\Updates;

use App\Models\PlatformAdvisory;
use App\Models\PlatformComponent;
use App\Models\PlatformUpdateCheck;
use App\Release\StagingActiveProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * One platform update check: read the installed components, ask the advisory source which
 * published advisories affect exactly those versions, and store the result. It reports; it
 * never updates, installs, restarts or executes anything.
 *
 * Fail-closed and idempotent: when the source cannot be read nothing is marked resolved and
 * the check is recorded as failed; a repeated check updates the same rows.
 */
final class PlatformUpdateScanner
{
    public const LOCK = 'platform-updates:check';

    public function __construct(private readonly ComponentInventory $inventory, private readonly OsvClient $osv) {}

    public function run(string $trigger = 'scheduled'): PlatformUpdateCheck
    {
        $trigger = $trigger === 'manual' ? 'manual' : 'scheduled';

        if (StagingActiveProfile::externalContactClosed()) {
            // staging-next without the Owner's external-services opt-in: no outbound request at all.
            return $this->refused($trigger, 'staging_external_contact_closed', persist: true);
        }
        if ($this->checkedRecently()) {
            return $this->refused($trigger, 'checked_recently');
        }
        $lock = Cache::lock(self::LOCK, (int) config('platform_updates.lock_seconds', 1800));
        if (! $lock->get()) {
            return $this->refused($trigger, 'check_already_running');
        }
        if ($this->checkedRecently()) {   // re-checked inside the lock: a run that finished while we waited counts
            $lock->release();

            return $this->refused($trigger, 'checked_recently');
        }

        $check = PlatformUpdateCheck::create(['uuid' => (string) Str::uuid(), 'trigger' => $trigger, 'status' => 'running', 'started_at' => now()]);
        try {
            $this->execute($check);
        } catch (Throwable $exception) {
            report($exception);
            $check->forceFill(['status' => 'failed', 'reason_code' => 'internal_error'])->save();
        } finally {
            $check->forceFill(['finished_at' => now()] + $this->counts())->save();
            $lock->release();
        }

        return $check;
    }

    private function checkedRecently(): bool
    {
        return PlatformUpdateCheck::query()->whereIn('status', ['succeeded', 'partial', 'failed'])
            ->where('started_at', '>', now()->subSeconds((int) config('platform_updates.min_interval_seconds', 300)))->exists();
    }

    private function execute(PlatformUpdateCheck $check): void
    {
        $deadline = microtime(true) + (int) config('platform_updates.run_budget_seconds', 900);
        $detected = $this->inventory->collect();
        if (count($detected) > (int) config('platform_updates.max_components', 1500)) {
            $check->forceFill(['status' => 'failed', 'reason_code' => 'inventory_too_large'])->save();

            return;
        }
        $components = $this->storeInventory($detected);
        $checkable = array_values(array_filter($detected, fn (DetectedComponent $c) => $c->checkable()));
        $results = ['inventory' => 'ok'];

        try {
            $hits = $this->osv->affecting($checkable);
        } catch (OsvUnavailable $exception) {
            // Nothing is resolved and no component is called CHECKED on the strength of a failed source.
            $check->forceFill(['status' => 'failed', 'reason_code' => $exception->getMessage(), 'source_results' => $results + ['osv' => $exception->getMessage()]])->save();

            return;
        }
        $results['osv'] = 'ok';

        $budget = (int) config('platform_updates.max_advisory_details', 100);
        $partial = null;
        foreach ($checkable as $component) {
            $row = $components[$component->key];
            $ids = $hits[$component->key] ?? [];
            foreach ($ids as $id) {
                $advisory = PlatformAdvisory::firstOrNew(['platform_component_id' => $row->id, 'advisory_id' => $id]);
                $withdrawn = false;
                if (! $advisory->exists || $advisory->state === PlatformAdvisory::RESOLVED || $advisory->affected_version !== $component->version
                    || $advisory->severity === 'unknown' || $advisory->details_checked_at === null || $advisory->details_checked_at->lt(now()->subHours(20))) {
                    // New, back from resolved, a different installed version, unknown severity or not re-read for a day:
                    // the text is read again from the source (it may have been corrected or withdrawn).
                    $details = null;
                    if ($budget > 0 && microtime(true) < $deadline) {
                        $budget--;
                        try {
                            $details = $this->osv->details($id, $component);
                        } catch (OsvUnavailable $exception) {
                            $partial = $exception->getMessage();
                        }
                    } else {
                        $partial = 'advisory_detail_budget_exhausted';   // detail count or the run's time budget
                    }
                    $withdrawn = $details['withdrawn'] ?? false;
                    if ($details === null && $advisory->exists) {
                        // The source could not be read now: what is already known (severity, fix, text) is kept, never downgraded,
                        // and a resolved advisory stays resolved until its text can be read again.
                        if ($advisory->state === PlatformAdvisory::RESOLVED) {
                            continue;
                        }
                        $changes = ['last_seen_check_id' => $check->id, 'state' => PlatformAdvisory::OPEN];
                        if ($advisory->affected_version !== $component->version) {
                            // A fix is proven for one installed version only. The stored one was proven for the previous version
                            // and cannot be shown for this one until the text is read again (affected_version stays as it was, so
                            // the next check reads it again). details_checked_at is untouched: nothing was freshly verified.
                            $changes['fixed_version'] = null;
                        }
                        $advisory->fill($changes)->save();

                        continue;
                    }
                    $advisory->fill([
                        'aliases' => $details['aliases'] ?? ($advisory->aliases ?? []),
                        'severity' => $details['severity'] ?? 'unknown',
                        'summary' => $details['summary'] ?? $advisory->summary,
                        'published_at' => $details['published_at'] ?? $advisory->published_at,
                        'fixed_version' => $details === null ? null : $this->provenFix($component->version, $details['fixed_versions']),
                        'affected_version' => $component->version,
                        'source' => 'osv',
                        'source_url' => OsvClient::PUBLIC_URL.$id,
                        'details_checked_at' => $details === null ? $advisory->details_checked_at : now(),
                    ]);
                    if (! $advisory->exists) {
                        $advisory->first_seen_check_id = $check->id;
                    }
                }
                if ($withdrawn) {
                    // Withdrawn by its publisher: it is not an advisory any more. Kept for history, never open.
                    if ($advisory->exists) {
                        $advisory->fill(['state' => PlatformAdvisory::RESOLVED, 'resolved_at' => now(), 'last_seen_check_id' => $check->id])->save();
                    }

                    continue;
                }
                if ($advisory->state === PlatformAdvisory::RESOLVED) {
                    $advisory->notified_at = null;   // it is back: a new notification is justified
                }
                $advisory->fill(['state' => PlatformAdvisory::OPEN, 'resolved_at' => null, 'last_seen_check_id' => $check->id])->save();
            }
            // Only a component that was answered in THIS check can have advisories resolved.
            PlatformAdvisory::query()->where('platform_component_id', $row->id)->where('state', PlatformAdvisory::OPEN)
                ->whereNotIn('advisory_id', $ids)->update(['state' => PlatformAdvisory::RESOLVED, 'resolved_at' => now()]);
            $row->forceFill(['advisory_state' => 'CHECKED', 'advisories_checked_at' => now()])->save();
        }

        $check->forceFill([
            'status' => $partial === null ? 'succeeded' : 'partial',
            'reason_code' => $partial,
            'source_results' => $results + ($partial === null ? [] : ['osv_details' => $partial]),
        ])->save();
    }

    /**
     * @param  list<DetectedComponent>  $detected
     * @return array<string, PlatformComponent>
     */
    private function storeInventory(array $detected): array
    {
        $rows = [];
        $readableKinds = [];
        foreach ($detected as $component) {
            if ($component->version !== null) {
                $readableKinds[$component->kind] = true;
            }
            $row = PlatformComponent::firstOrNew(['component_key' => $component->key]);
            $versionChanged = $row->exists && $row->installed_version !== $component->version;
            $row->fill([
                'kind' => $component->kind,
                'name' => $component->name,
                'ecosystem' => $component->ecosystem,
                'installed_version' => $component->version,
                'version_state' => $component->version === null ? 'UNKNOWN' : 'VERIFIED',
                'evidence_source' => $component->evidenceSource,
                'development_only' => $component->developmentOnly,
                'present' => true,
                'last_seen_at' => now(),
            ]);
            if (! $row->exists || $versionChanged || ! $component->checkable()) {
                // Not (yet) checked for this exact version: UNVERIFIED = no advisory source for this kind, UNKNOWN = not answered.
                $row->advisory_state = $component->ecosystem === null ? 'UNVERIFIED' : 'UNKNOWN';
                $row->advisories_checked_at = null;
            }
            $row->save();
            $rows[$component->key] = $row;
        }
        // A component is "gone" only when its own source was readable in this run (an unreadable lock file proves nothing).
        foreach (array_keys($readableKinds) as $kind) {
            $gone = PlatformComponent::query()->where('kind', $kind)->where('present', true)->whereNotIn('component_key', array_keys($rows))->pluck('id');
            if ($gone->isNotEmpty()) {
                PlatformComponent::query()->whereIn('id', $gone)->update(['present' => false]);
                PlatformAdvisory::query()->whereIn('platform_component_id', $gone)->where('state', PlatformAdvisory::OPEN)
                    ->update(['state' => PlatformAdvisory::RESOLVED, 'resolved_at' => now()]);
            }
        }

        return $rows;
    }

    /** The lowest version the source names as fixed that is newer than the installed one; null when none is proven. */
    private function provenFix(string $installed, array $fixed): ?string
    {
        $newer = array_values(array_filter($fixed, fn (string $v) => version_compare($v, $installed, '>')));
        usort($newer, 'version_compare');

        return $newer[0] ?? null;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        // Development-only packages (composer require-dev, npm devDependencies) are not part of a production release.
        $open = PlatformAdvisory::query()->where('state', PlatformAdvisory::OPEN)
            ->whereHas('component', fn ($q) => $q->where('development_only', false));

        return [
            'components_total' => PlatformComponent::query()->where('present', true)->count(),
            'components_unknown' => PlatformComponent::query()->where('present', true)->where('version_state', 'UNKNOWN')->count(),
            'advisories_open' => (clone $open)->count(),
            'advisories_critical' => (clone $open)->where('severity', 'critical')->count(),
        ];
    }

    private function refused(string $trigger, string $reason, bool $persist = false): PlatformUpdateCheck
    {
        $check = new PlatformUpdateCheck(['uuid' => (string) Str::uuid(), 'trigger' => $trigger, 'status' => 'refused', 'reason_code' => $reason, 'started_at' => now(), 'finished_at' => now()]);
        if ($persist && ! PlatformUpdateCheck::query()->where('status', 'refused')->where('reason_code', $reason)->where('started_at', '>', now()->subDay())->exists()) {
            $check->save();   // at most one refusal row per reason and day
        }

        return $check;
    }
}
