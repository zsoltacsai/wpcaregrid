<?php

namespace App\Console\Commands\Backups;

use App\Backups\SubscriptionBackupEntitlementRecorder;
use App\Models\Subscription;
use Illuminate\Console\Command;

class BackfillBackupEntitlements extends Command
{
    protected $signature = 'backup:entitlements:backfill {--apply : Record a bounded snapshot for each listed active subscription} {--after=0 : Continue after this subscription ID} {--limit=100 : Maximum rows to inspect (1-500)}';

    protected $description = 'Preview or record missing versioned backup entitlement snapshots without touching backups or providers';

    public function handle(SubscriptionBackupEntitlementRecorder $recorder): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500 || $after === false || $after < 0) {
            $this->components->error('Use a limit from 1 to 500 and a non-negative after ID.');

            return self::INVALID;
        }

        $subscriptions = Subscription::query()
            ->whereIn('status', ['active', 'trialing'])
            ->where('id', '>', $after)
            ->whereDoesntHave('backupEntitlements')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if (! $this->option('apply')) {
            $this->components->info("Preview: {$subscriptions->count()} active subscription(s) lack a backup entitlement snapshot; no data changed.");

            return self::SUCCESS;
        }

        $known = 0;
        $unknown = 0;
        foreach ($subscriptions as $subscription) {
            $snapshot = $recorder->record($subscription, null, now(), 'legacy_backfill');
            if ($snapshot?->resolution === 'known') {
                $known++;
            } elseif ($snapshot?->resolution === 'unknown') {
                $unknown++;
            }
        }

        $this->components->info("Recorded {$known} known and {$unknown} unknown backup entitlement snapshot(s); no backup or provider state changed.");

        return self::SUCCESS;
    }
}
