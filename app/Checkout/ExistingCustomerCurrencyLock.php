<?php

namespace App\Checkout;

use App\Billing\SubscriptionStatus;
use App\Models\Customer;
use App\Pricing\Exceptions\AmbiguousCustomerCurrency;
use Illuminate\Support\Facades\Log;

/**
 * Currency of the customer's current Stripe-backed commercial commitments.
 * Internal grants and terminal Stripe subscriptions cannot constrain checkout.
 */
final class ExistingCustomerCurrencyLock
{
    public function forCustomer(Customer $customer): ?string
    {
        $subscriptions = $customer->subscriptions()
            ->whereNotNull('stripe_subscription_id')
            ->whereNull('internal_plan')
            ->whereIn('status', [
                SubscriptionStatus::Pending->value,
                SubscriptionStatus::Incomplete->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
                SubscriptionStatus::Unpaid->value,
                SubscriptionStatus::Paused->value,
            ])
            ->get(['currency', 'stripe_customer_id']);

        if ($subscriptions->isEmpty()) {
            return null;
        }

        // Never create a second Stripe identity to work around an inconsistent
        // local mirror. These rows came from signed Stripe checkout/webhooks.
        if (! $customer->stripe_customer_id || $subscriptions->contains(fn ($subscription) => ! $subscription->stripe_customer_id
            || ! hash_equals($customer->stripe_customer_id, $subscription->stripe_customer_id)
        )) {
            Log::warning('Existing customer billing currency could not be resolved.', ['customer_id' => $customer->id, 'reason' => 'stripe_customer_mismatch']);
            throw new AmbiguousCustomerCurrency;
        }

        $currencies = $subscriptions->pluck('currency')->unique()->values();

        if ($currencies->count() !== 1 || ! is_string($currencies->first()) || ! in_array($currencies->first(), ['HUF', 'EUR', 'USD', 'GBP'], true)) {
            Log::warning('Existing customer billing currency could not be resolved.', ['customer_id' => $customer->id, 'reason' => 'conflicting_currency']);
            throw new AmbiguousCustomerCurrency;
        }

        return $currencies->first();
    }
}
