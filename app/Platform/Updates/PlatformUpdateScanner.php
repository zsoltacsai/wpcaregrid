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
        $recent = PlatformUpdateCheck::query()->whereIn('status', ['succeeded', 'partial', 'failed'])
            ->where('started_at', '>', now()->subSeconds((int) config('platform_updates.min_interval_seconds', 300)))->exists();
        if ($recent) {
            return $this->refused($trigger, 'checked_recently');
        }
        $lock = Cache::lock(self::LOCK, (int) config('platform_updates.lock_seconds', 600));
        if (! $lock->get()) {
            return $this->refused($trigger, 'check_already_running');
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

    private function execute(PlatformUpdateCheck $check): void
    {
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
                if (! $advisory->exists || $advisory->affected_version !== $component->version || $advisory->severity === 'unknown') {
                    $details = null;
                    if ($budget > 0) {
                        $budget--;
                        try {
                            $details = $this->osv->details($id, $component);
                        } catch (OsvUnavailable $exception) {
                            $partial = $exception->getMessage();
                        }
                    } else {
                        $partial = 'advisory_detail_budget_exhausted';
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
                    ]);
                    if (! $advisory->exists) {
                        $advisory->first_seen_check_id = $check->id;
                    }
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
        $open = PlatformAdvisory::query()->where('state', PlatformAdvisory::OPEN);

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
