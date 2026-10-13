<?php

namespace App\Console\Commands;

use App\Billing\QueueBillingEmail;
use App\Billing\SubscriptionStatus;
use App\Models\Subscription;
use App\Owner\AdminAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireSubscriptionPaymentGrace extends Command
{
    protected $signature = 'billing:subscriptions:expire-grace {--limit=500} {--subscription= : Restrict expiry to one subscription UUID}';

    protected $description = 'Record subscription payment grace expiry without deleting customer data';

    public function handle(QueueBillingEmail $emails): int
    {
        $limit = max(1, min(2000, (int) $this->option('limit')));
        $subscriptionUuid = $this->option('subscription');
        $ids = Subscription::query()->where('status', SubscriptionStatus::PastDue)
            ->whereNotNull('grace_ends_at')->where('grace_ends_at', '<=', now())
            ->whereNull('grace_expired_at')
            ->when(is_string($subscriptionUuid) && $subscriptionUuid !== '', fn ($query) => $query->where('uuid', $subscriptionUuid))
            ->orderBy('id')->limit($limit)->pluck('id');
        $expired = 0;

        foreach ($ids as $id) {
            $didExpire = DB::transaction(function () use ($id, $emails): bool {
                $subscription = Subscription::query()->with('customer')->whereKey($id)->lockForUpdate()->first();
                if (! $subscription || $subscription->status !== SubscriptionStatus::PastDue
                    || ! $subscription->grace_ends_at || $subscription->grace_ends_at->isFuture()
                    || $subscription->grace_expired_at) {
                    return false;
                }

                $subscription->forceFill(['grace_expired_at' => now()])->save();
                AdminAudit::record(null, 'billing.grace_expired', 'subscription', $subscription->uuid, [
                    'grace_ended_at' => $subscription->grace_ends_at->toIso8601String(),
                ]);
                if ($subscription->customer) {
                    $emails->enqueue(
                        'subscription:'.$subscription->uuid.':grace-expired:'.$subscription->grace_ends_at->utc()->format('YmdHis'),
                        'billing_action_required',
                        $subscription->customer,
                        $subscription,
                    );
                }

                return true;
            }, attempts: 3);
            $expired += $didExpire ? 1 : 0;
        }

        $this->info('Recorded '.$expired.' expired subscription grace periods.');

        return self::SUCCESS;
    }
}
