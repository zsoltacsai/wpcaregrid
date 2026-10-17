<?php

namespace App\Console\Commands\Backups;

use App\Backups\BackupPolicy;
use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Compatibility\CapabilityReadiness;
use App\Connector\Presence;
use App\Models\SubscriptionBackupEntitlement;
use App\Models\Website;
use Illuminate\Console\Command;

/**
 * Queues the scheduled backups that are due. One scheduled backup per
 * website and window (day, ISO week or month, UTC): the unique
 * (website, schedule_window) index makes a second one impossible.
 */
class ScheduleBackups extends Command
{
    protected $signature = 'backup:schedule';

    protected $description = 'Queue scheduled backups that are due (run hourly)';

    public function handle(BackupPolicy $policy, BackupService $backups, CapabilityReadiness $readiness): int
    {
        $queued = 0;
        $skippedUnknownEntitlement = 0;
        $skippedCapability = 0;
        $shadowMismatches = 0;
        $at = now()->utc();

        Website::query()->whereHas('activeConnector')->with(['subscription', 'customer', 'activeConnector', 'capabilitySnapshot'])->chunkById(100, function ($websites) use ($policy, $backups, $readiness, $at, &$queued, &$skippedUnknownEntitlement, &$skippedCapability, &$shadowMismatches) {
            $subscriptionIds = $websites->pluck('subscription_id')->filter()->unique()->values();
            $entitlements = SubscriptionBackupEntitlement::query()
                ->whereIn('subscription_id', $subscriptionIds)
                ->where('resolution', 'known')
                ->where('effective_at', '<=', $at)
                ->orderByDesc('effective_at')
                ->orderByDesc('id')
                ->get()
                ->unique('subscription_id')
                ->keyBy('subscription_id');

            foreach ($websites as $website) {
                $decision = $policy->for($website);
                $entitlement = $entitlements->get($website->subscription_id);
                if (! $entitlement || ! $website->subscription?->status?->grantsAccess()) {
                    $skippedUnknownEntitlement++;

                    continue;
                }

                $window = BackupPolicy::window((string) $entitlement->cadence, $at);

                if (! $decision->eligible || ! $window || $website->presence() !== Presence::Connected
                    || $website->backups()->where('schedule_window', $window)->exists()) {
                    continue;
                }

                // Compare the immutable entitlement cadence with the legacy catalogue-derived
                // cadence during shadow rollout. Any disagreement fails closed.
                if ($decision->frequency !== $entitlement->cadence) {
                    $shadowMismatches++;
                    $this->components->warn("{$website->uuid}: BACKUP_ENTITLEMENT_SCHEDULE_MISMATCH");

                    continue;
                }

                if (($refusal = $readiness->refusal($website, $decision->type->jobType())) !== null) {
                    $skippedCapability++;
                    $this->components->warn("{$website->uuid}: {$refusal}");

                    continue;
                }

                try {
                    $backups->request($website, BackupReason::Scheduled, 'scheduler', null, ['schedule_window' => $window]);
                    $queued++;
                } catch (BackupRefused $e) {
                    $this->components->warn("{$website->uuid}: {$e->errorCode}");
                }
            }
        });

        $this->components->info("Queued {$queued} scheduled backup(s); skipped {$skippedUnknownEntitlement} unknown/inactive entitlement(s), {$skippedCapability} capability-blocked site(s), {$shadowMismatches} shadow schedule mismatch(es).");

        return self::SUCCESS;
    }
}
