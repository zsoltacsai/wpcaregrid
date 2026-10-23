<?php

namespace App\Websites;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssignWebsiteToSubscription
{
    public function __construct(private readonly NormalizeWebsiteUrl $normalize) {}

    public function __invoke(Customer $customer, string $subscriptionUuid, WebsitePurchaseTarget $target): Website
    {
        return DB::transaction(function () use ($customer, $subscriptionUuid, $target) {
            $subscription = Subscription::query()->where('uuid', $subscriptionUuid)->lockForUpdate()->first();
            abort_unless($subscription && $subscription->customer_id === $customer->id, 404);

            if (! $subscription->grantsAccess()
                || $subscription->product_type !== $target->type
                || $subscription->website()->exists()) {
                throw ValidationException::withMessages(['subscription' => __('account.websites.slot_unavailable')]);
            }

            return $customer->websites()->create([
                'url' => $this->normalize->__invoke($target->url),
                'type' => $target->type,
                'subscription_id' => $subscription->id,
                'status' => WebsiteStatus::Pending,
                'connector_status' => ConnectorStatus::NotInstalled,
            ]);
        }, attempts: 3);
    }
}
