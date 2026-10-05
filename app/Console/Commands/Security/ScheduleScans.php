<?php

namespace App\Console\Commands\Security;

use App\Backups\BackupPolicy;
use App\Connector\Presence;
use App\Models\Website;
use App\Security\ScanStatus;
use App\Security\ScanType;
use App\Security\SecurityRefused;
use App\Security\SecurityScanService;
use Illuminate\Console\Command;

/**
 * Queues due scheduled scans (config security.schedule). Off unless
 * SECURITY_SCHEDULE_ENABLED. "plan" means the plan's own
 * security_check_frequency (config/pricing.php); nothing is invented here.
 */
class ScheduleScans extends Command
{
    protected $signature = 'security:schedule';

    protected $description = 'Queue due scheduled security scans';

    public function handle(SecurityScanService $scans): int
    {
        if (! config('security.schedule.enabled')) {
            $this->components->info('Scheduled security scans are disabled (SECURITY_SCHEDULE_ENABLED).');

            return self::SUCCESS;
        }

        Website::with(['subscription', 'activeConnector'])->whereNotNull('subscription_id')->each(function (Website $website) use ($scans) {
            $subscription = $website->subscription;
            // An internal plan has no public tier, so no plan-driven frequency: no scheduled scans (InternalPlan).
            if (! $website->allowsServiceOperations() || $website->presence() !== Presence::Connected || ! $subscription?->product_type || ! $subscription->plan || $subscription->isInternal()) {
                return;
            }

            foreach ((array) config('security.schedule.scans') as $type => $frequency) {
                $frequency = $frequency === 'plan' ? config("pricing.plans.{$subscription->product_type->value}.{$subscription->plan->value}.security_check_frequency") : $frequency;
                $window = is_string($frequency) ? BackupPolicy::window($frequency, now()) : null;
                $scanType = ScanType::tryFrom((string) $type);

                if (! $window || ! $scanType || $this->alreadyScanned($website, $scanType, $frequency)) {
                    continue;
                }

                try {
                    $scans->request($website, $scanType, 'scheduler');
                    $this->components->info("Queued {$scanType->value} scan for {$website->uuid}");
                } catch (SecurityRefused) {
                    // In progress, not connected or not capable: next run.
                }

                return; // One scan per website per run.
            }
        });

        return self::SUCCESS;
    }

    private function alreadyScanned(Website $website, ScanType $type, string $frequency): bool
    {
        $since = match ($frequency) {
            'daily' => now()->startOfDay(),
            'weekly' => now()->startOfWeek(),
            default => now()->startOfMonth(),
        };

        return $website->securityScans()->where('type', $type)->where('requested_by', 'scheduler')
            ->where('created_at', '>=', $since)->where('status', '!=', ScanStatus::Failed->value)->exists();
    }
}
