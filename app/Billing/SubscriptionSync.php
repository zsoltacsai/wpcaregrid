<?php

namespace App\Billing;

use App\Mail\SubscriptionActivated;
use App\Mail\SubscriptionCanceled;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Pricing\PricingCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Keeps local subscriptions in line with Stripe. Events may arrive in any
 * order and more than once; every method is safe to repeat.
 */
final class SubscriptionSync
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly ProvisionCustomer $provision,
    ) {}

    /**
     * Apply a Stripe subscription object from a customer.subscription.* event.
     * An event older than the last one applied is ignored.
     */
    public function apply(object $stripe, int $eventCreated): ?Subscription
    {
        $status = SubscriptionStatus::fromStripe($stripe->status ?? null);

        if (! $status) {
            Log::warning('Unknown Stripe subscription status ignored.', ['stripe_subscription' => $stripe->id ?? null, 'status' => $stripe->status ?? null]);

            return null;
        }

        $eventTime = Carbon::createFromTimestamp($eventCreated);
        $subscription = Subscription::where('stripe_subscription_id', $stripe->id)->first();

        if ($subscription?->stripe_synced_at && $eventTime->lt($subscription->stripe_synced_at)) {
            return $subscription;
        }

        $intent = $this->intentFrom($stripe->metadata ?? null);
        $item = $stripe->items->data[0] ?? null;
        $stripePriceId = $item->price->id ?? $subscription?->stripe_price_id;

        $subscription ??= $this->newFromIntentOrPrice($stripe->id, $intent, $stripePriceId);

        if (! $subscription) {
            Log::warning('Stripe subscription with an unknown price and no checkout ignored.', ['stripe_subscription' => $stripe->id]);

            return null;
        }

        // Plan changes made in Stripe (e.g. a later billing portal) follow the price.
        if ($stripePriceId && ($price = $this->catalog->priceByStripeId($stripePriceId))) {
            $subscription->fill([
                'product_type' => $price->productType,
                'plan' => $price->tier,
                'interval' => $price->interval,
                'market' => $price->market,
            ]);
        }

        $previousStatus = $subscription->exists ? $subscription->status : null;

        $subscription->fill([
            'stripe_customer_id' => $stripe->customer ?? $subscription->stripe_customer_id,
            'stripe_price_id' => $stripePriceId,
            'amount' => $item->price->unit_amount ?? $subscription->amount,
            'currency' => isset($item->price->currency) ? strtoupper($item->price->currency) : $subscription->currency,
            'status' => $status,
            // Since Stripe API 2025-03-31 periods live on subscription items.
            'current_period_start' => $this->time($item->current_period_start ?? $stripe->current_period_start ?? null),
            'current_period_end' => $this->time($item->current_period_end ?? $stripe->current_period_end ?? null),
            'cancel_at_period_end' => (bool) ($stripe->cancel_at_period_end ?? false),
            'canceled_at' => $this->time($stripe->canceled_at ?? null),
            'ended_at' => $this->time($stripe->ended_at ?? null),
            'stripe_synced_at' => $eventTime,
        ]);

        // Stripe reporting the subscription active/trialing confirms payment,
        // so the customer can be resolved even if the checkout event is late.
        if (! $subscription->customer_id && $status->grantsAccess()) {
            $customer = $intent
                ? $this->provision->forIntent($intent, $stripe->customer ?? null)
                : Customer::where('stripe_customer_id', $stripe->customer ?? '')->first();
            $subscription->customer()->associate($customer);
        }

        $this->recordActivation($subscription);
        $subscription->save();

        if ($previousStatus && $previousStatus !== SubscriptionStatus::Canceled && $status === SubscriptionStatus::Canceled && $subscription->customer) {
            Mail::to($subscription->customer->email)->queue(new SubscriptionCanceled($subscription));
        }

        $this->notifyActivation($subscription);

        return $subscription;
    }

    /**
     * After a confirmed checkout: make sure a subscription row exists and
     * belongs to the customer. Status stays whatever Stripe last reported
     * (`pending` until the subscription event arrives).
     */
    public function attachCheckout(CheckoutIntent $intent, Customer $customer, ?string $stripeSubscriptionId): ?Subscription
    {
        if (! $stripeSubscriptionId) {
            return null;
        }

        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first()
            ?? $this->newFromIntentOrPrice($stripeSubscriptionId, $intent, $intent->stripe_price_id);

        $subscription->checkout_intent_id ??= $intent->id;
        $subscription->stripe_customer_id ??= $customer->stripe_customer_id;

        if (! $subscription->customer_id) {
            $subscription->customer()->associate($customer);
        }

        $this->recordActivation($subscription);
        $subscription->save();
        $this->notifyActivation($subscription);

        return $subscription;
    }

    private function newFromIntentOrPrice(string $stripeSubscriptionId, ?CheckoutIntent $intent, ?string $stripePriceId): ?Subscription
    {
        if ($intent) {
            return new Subscription([
                'stripe_subscription_id' => $stripeSubscriptionId,
                'checkout_intent_id' => $intent->id,
                'stripe_price_id' => $intent->stripe_price_id,
                'product_type' => $intent->product_type,
                'plan' => $intent->plan,
                'interval' => $intent->interval,
                'market' => $intent->market,
                'currency' => $intent->currency,
                'amount' => $intent->amount,
                'status' => SubscriptionStatus::Pending,
            ]);
        }

        $price = $stripePriceId ? $this->catalog->priceByStripeId($stripePriceId) : null;

        return $price ? new Subscription([
            'stripe_subscription_id' => $stripeSubscriptionId,
            'stripe_price_id' => $stripePriceId,
            'product_type' => $price->productType,
            'plan' => $price->tier,
            'interval' => $price->interval,
            'market' => $price->market,
            'currency' => $price->money->currency->code,
            'amount' => $price->money->amount,
            'status' => SubscriptionStatus::Pending,
        ]) : null;
    }

    public function intentFrom(?object $metadata): ?CheckoutIntent
    {
        $uuid = $metadata->checkout_intent ?? null;

        return is_string($uuid) ? CheckoutIntent::where('uuid', $uuid)->first() : null;
    }

    private function recordActivation(Subscription $subscription): void
    {
        if ($subscription->grantsAccess() && ! $subscription->activated_at) {
            $subscription->activated_at = now();
        }
    }

    /** Announce activation once, when both access and the customer are known. */
    private function notifyActivation(Subscription $subscription): void
    {
        if ($subscription->activated_at && ! $subscription->activation_notified_at && $subscription->customer) {
            $subscription->forceFill(['activation_notified_at' => now()])->save();
            Mail::to($subscription->customer->email)->queue(new SubscriptionActivated($subscription));
        }
    }

    private function time(?int $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
