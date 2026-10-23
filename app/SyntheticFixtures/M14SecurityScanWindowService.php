<?php

namespace App\SyntheticFixtures;

use App\Compatibility\Capability;
use App\Compatibility\CapabilityReadiness;
use App\Compatibility\CapabilityStatus;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Models\M14SecurityScanWindow;
use App\Models\M14SecurityScanIsolation;
use App\Models\SecurityScan;
use App\Models\SyntheticSiteFixture;
use App\Models\Website;
use App\Models\WebsiteInventory;
use App\Models\WebsiteJob;
use App\Owner\AdminAudit;
use App\Security\IncidentStatus;
use App\Security\ScanStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class M14SecurityScanWindowService
{
    public function lockGate(): M14SecurityScanWindow
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('The M14 security scan gate must be locked inside a transaction.');
        }

        return M14SecurityScanWindow::query()->whereKey(1)->lockForUpdate()->firstOrFail();
    }

    public function activeWindow(): ?M14SecurityScanWindow
    {
        $window = M14SecurityScanWindow::query()->whereKey(1)->first();

        return $window?->isLive() ? $window : null;
    }

    public function allowsScan(Website $website): bool
    {
        $window = $this->activeWindow();

        return $window !== null
            && $window->website_id === $website->id
            && (string) config('m14_security_fixture.site_uuid') === (string) $website->uuid
            && app(SyntheticFixturePolicy::class)->m14SecurityFixtureEnabledFor($website)
            && app(SyntheticFixturePolicy::class)->controlledPollingAllowed($website)
            && (bool) config('m14_security_fixture.side_effect_isolation_enabled')
            && $this->privacyGatesDisabled()
            && ! (bool) config('platform.recovery_mode');
    }

    /** Bind the one authorized Integrity scan to this window for retry-safe effect isolation. */
    public function bindScan(Website $website, SecurityScan $scan): void
    {
        $window = $this->lockGate();
        if (! $window->isLive() || $window->website_id !== $website->id || $window->scan_uuid !== null
            || $scan->website_id !== $website->id || $scan->type->value !== 'integrity'
            || ! $this->allowsScan($website)) {
            throw ValidationException::withMessages(['scan_window' => 'M14 scan isolation could not be bound to the exact authorized scan.']);
        }

        $window->forceFill(['scan_uuid' => $scan->uuid, 'side_effect_isolation' => true])->save();
        $this->persistIsolationBinding($window, $website, $scan);
        AdminAudit::record(null, 'm14_security_scan_window.scan_bound', 'm14_security_scan_window', (string) $website->uuid, [
            'website' => $website->uuid,
            'scan' => $scan->uuid,
            'scan_type' => 'integrity',
            'effect_isolation' => 'site_scoped',
        ]);
    }

    /**
     * Only the exact scan bound while its verified M14 window was open receives
     * side-effect suppression. The persisted binding survives expiry/close so a
     * terminal callback or retry cannot turn a test finding into a real incident.
     */
    public function isolatesSideEffectsForScan(SecurityScan $scan): bool
    {
        if ($scan->type->value !== 'integrity') {
            return false;
        }

        $binding = M14SecurityScanIsolation::query()->where('scan_uuid', $scan->uuid)->first();
        if ($binding === null || $binding->website_id !== $scan->website_id || $binding->connector_id === null
            || $binding->window_opened_at === null || $binding->window_expires_at === null || $scan->created_at === null
            || $scan->created_at->lt($binding->window_opened_at) || $scan->created_at->gte($binding->window_expires_at)) {
            return false;
        }

        $website = $scan->website()->first();
        $customerUuid = $website?->customer?->uuid;
        if (! $website || ! is_string($customerUuid)
            || ! hash_equals((string) $binding->website_uuid, (string) $website->uuid)
            || ! hash_equals((string) $binding->customer_uuid, $customerUuid)) {
            return false;
        }

        $job = $scan->job()->first();
        if ($job?->connector_id !== null && $job->connector_id !== $binding->connector_id) {
            return false;
        }

        return $job === null || $job->connector_id === null || $job->connector_id === $binding->connector_id;
    }

    /** Ensure every M14 SECURITY_SCAN job refers to the exact authorized scan and part. */
    public function scanJobAllowed(Website $website, array $payload, ?WebsiteJob $job = null): bool
    {
        $window = $this->activeWindow();
        $scanUuid = $payload['scan_uuid'] ?? null;
        if ($window === null || $window->website_id !== $website->id
            || ! is_string($scanUuid) || ! is_string($window->scan_uuid)
            || ! hash_equals($window->scan_uuid, $scanUuid)) {
            return false;
        }

        $scan = SecurityScan::query()->where('uuid', $scanUuid)->where('website_id', $website->id)->first();
        if ($scan === null || $scan->type->value !== 'integrity'
            || ! in_array($scan->status->value, ['queued', 'running'], true)) {
            return false;
        }

        if ($job !== null) {
            return $scan->job_id === $job->id
                && (int) ($payload['part'] ?? 0) === (int) $scan->parts;
        }

        return (int) ($payload['part'] ?? 0) === ((int) $scan->parts + 1)
            && ! WebsiteJob::query()->where('website_id', $website->id)
                ->where('type', JobType::SecurityScan->value)
                ->where('payload->scan_uuid', $scanUuid)
                ->where('payload->part', (int) ($payload['part'] ?? 0))
                ->whereIn('status', [JobStatus::Queued->value, JobStatus::Delivered->value])
                ->exists();
    }

    private function effectiveSetting(string $key): string
    {
        return match (config($key)) {
            true => 'true',
            false => 'false',
            default => 'unknown',
        };
    }

    public function refusalForScan(Website $website): ?string
    {
        // An expired active row is deliberately still an isolation lock until
        // an operator closes it after verifying that all scan work is terminal.
        $window = M14SecurityScanWindow::query()->whereKey(1)->where('active', true)->first();
        if ($window === null) {
            return null;
        }

        if (! $window->isLive()) {
            return 'M14_SCAN_WINDOW_EXPIRED_REQUIRES_CLOSE';
        }

        return $window->website_id === $website->id && $this->allowsScan($website)
            ? null
            : 'M14_SCAN_WINDOW_ACTIVE';
    }

    /**
     * Open the bounded exclusive staging scan window. The singleton row is locked
     * by scan request/enqueue paths as well, so no other scan can race the preflight.
     */
    public function open(Website $website, string $actor): M14SecurityScanWindow
    {
        return DB::transaction(function () use ($website, $actor) {
            $window = $this->lockGate();

            if ($window->active) {
                throw ValidationException::withMessages(['scan_window' => 'An M14 security scan window is already active.']);
            }

            $policy = app(SyntheticFixturePolicy::class);
            $fixture = $policy->fixture($website);
            if (! $this->privacyGatesDisabled()
                || ! (bool) config('m14_security_fixture.side_effect_isolation_enabled')
                || (bool) config('platform.recovery_mode')
                || ! $policy->m14SecurityFixtureEnabledFor($website)
                || ! $policy->controlledPollingAllowed($website)
                || ! (bool) config('m14_security_fixture.security_scan_enabled')
                || ! $this->currentInventoryAndScanReadinessPass($website)
                || ! $fixture instanceof SyntheticSiteFixture) {
                throw ValidationException::withMessages(['scan_window' => 'M14 scan safety preconditions are not satisfied.']);
            }

            if (SecurityScan::query()->whereIn('status', [ScanStatus::Queued->value, ScanStatus::Running->value])->exists()
                || WebsiteJob::query()->where('type', JobType::SecurityScan->value)
                    ->whereIn('status', [JobStatus::Queued->value, JobStatus::Delivered->value])->exists()) {
                throw ValidationException::withMessages(['scan_window' => 'A scan or undelivered scan job already exists on staging.']);
            }

            if ($website->securityIncidents()->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value])->exists()
                || $website->backups()->whereIn('status', ['queued', 'running', 'verifying'])->exists()) {
                throw ValidationException::withMessages(['scan_window' => 'The M14 fixture has unresolved incident or backup state.']);
            }

            $minutes = max(1, min(15, (int) config('m14_security_fixture.scan_window_minutes', 10)));
            $window->forceFill([
                'active' => true,
                'website_id' => $website->id,
                'opened_by' => substr($actor, 0, 64),
                'opened_at' => now(),
                'expires_at' => now()->addMinutes($minutes),
                'scan_uuid' => null,
                'side_effect_isolation' => true,
                'closed_by' => null,
                'closed_at' => null,
            ])->save();

            AdminAudit::record(null, 'm14_security_scan_window.opened', 'm14_security_scan_window', (string) $website->uuid, [
                'website' => $website->uuid,
                'expires_at' => $window->expires_at?->toIso8601String(),
                'side_effect_isolation' => 'site_scoped',
                'global_auto_open' => $this->effectiveSetting('security.incidents.auto_open'),
                'global_auto_backup' => $this->effectiveSetting('security.incidents.auto_backup'),
            ]);

            return $window->refresh();
        });
    }

    /** Persist the authorized scan binding beyond the reusable singleton window row. */
    private function persistIsolationBinding(M14SecurityScanWindow $window, Website $website, SecurityScan $scan): void
    {
        $connector = $website->activeConnector;
        if (! $connector?->isActive() || $connector->website_id !== $website->id
            || ! is_string(config('m14_security_fixture.connector_uuid'))
            || ! hash_equals((string) config('m14_security_fixture.connector_uuid'), (string) $connector->uuid)) {
            throw ValidationException::withMessages(['scan_window' => 'The M14 scan is not paired to the approved Connector.']);
        }

        M14SecurityScanIsolation::query()->create([
            'scan_uuid' => $scan->uuid,
            'window_id' => $window->id,
            'website_id' => $website->id,
            'website_uuid' => $website->uuid,
            'customer_uuid' => $website->customer?->uuid,
            'connector_id' => $connector->id,
            'connector_uuid' => $connector->uuid,
            'window_opened_at' => $window->opened_at,
            'window_expires_at' => $window->expires_at,
            'authorized_at' => now(),
        ]);
    }

    public function close(string $actor, string $reason): void
    {
        DB::transaction(function () use ($actor, $reason) {
            $window = $this->lockGate();
            if (! $window->active) {
                throw ValidationException::withMessages(['scan_window' => 'There is no active M14 security scan window to close.']);
            }

            if (SecurityScan::query()->where('website_id', $window->website_id)
                ->whereIn('status', [ScanStatus::Queued->value, ScanStatus::Running->value])->exists()
                || WebsiteJob::query()->where('website_id', $window->website_id)
                    ->where('type', JobType::SecurityScan->value)
                    ->whereIn('status', [JobStatus::Queued->value, JobStatus::Delivered->value])->exists()) {
                throw ValidationException::withMessages(['scan_window' => 'The M14 scan still has active work; close is refused.']);
            }

            $website = Website::query()->findOrFail($window->website_id);
            $websiteUuid = (string) $website->uuid;
            $window->forceFill(['active' => false, 'closed_by' => substr($actor, 0, 64), 'closed_at' => now()])->save();
            AdminAudit::record(null, 'm14_security_scan_window.closed', 'm14_security_scan_window', $websiteUuid, [
                'website' => $websiteUuid,
                'reason' => mb_substr(trim($reason), 0, 120),
            ]);
        });
    }

    private function privacyGatesDisabled(): bool
    {
        foreach ([
            'privacy.destructive_retention_enabled',
            'privacy.profile_anonymization_enabled',
            'privacy.backup_deletion_enabled',
            'privacy.provider_deletion_enabled',
            'privacy.bulk_retention_execution_enabled',
            'privacy.synthetic_profile_anonymization_enabled',
        ] as $key) {
            if (config($key) !== false) {
                return false;
            }
        }

        return true;
    }

    private function currentInventoryAndScanReadinessPass(Website $website): bool
    {
        $ttl = (int) config('compatibility.dynamic_ttl_seconds');
        if ($ttl < 1) {
            return false;
        }

        $website->loadMissing(['activeConnector', 'capabilitySnapshot', 'inventory']);
        $connector = $website->activeConnector;
        $inventory = $website->inventory;
        $snapshot = $website->capabilitySnapshot;

        if (! $connector?->isActive()
            || ! $inventory instanceof WebsiteInventory
            || $inventory->connector_id !== $connector->id
            || $inventory->collected_at === null
            || $inventory->collected_at->lessThan(now()->subSeconds($ttl))
            || ! $snapshot
            || ! $snapshot->isFresh($ttl)
            || ! $snapshot->matchesCurrent($website, $connector)
            || $snapshot->status(Capability::SecurityScanReady) !== CapabilityStatus::Pass) {
            return false;
        }

        return app(CapabilityReadiness::class)->refusal(
            $website,
            JobType::SecurityScan,
            ['scan_type' => 'integrity'],
        ) === null;
    }
}
