<?php

namespace App\Billing;

use App\Mail\SubscriptionActivated;
use App\Mail\SubscriptionCanceled;
use App\Models\BillingInvoice;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Pricing\PricingCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use UnexpectedValueException;

/**
 * Keeps local subscriptions in line with Stripe. Events may arrive in any
 * order and more than once; every method is safe to repeat.
 */
final class SubscriptionSync
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly ProvisionCustomer $provision,
        private readonly SubscriptionBillingSnapshot $billingSnapshot,
    ) {}

    /**
     * Apply a Stripe subscription object from a customer.subscription.* event.
     * An event older than the last one applied is ignored.
     */
    public function apply(object $stripe, int $eventCreated, ?string $eventId = null): ?Subscription
    {
        $status = SubscriptionStatus::fromStripe($stripe->status ?? null);

        if (! $status) {
            Log::warning('Unknown Stripe subscription status ignored.', ['stripe_subscription' => $stripe->id ?? null, 'status' => $stripe->status ?? null]);

            return null;
        }

        $eventTime = Carbon::createFromTimestamp($eventCreated);
        $intent = $this->intentFrom($stripe->metadata ?? null);
        $item = $stripe->items->data[0] ?? null;
        $stripePriceId = $item->price->id ?? null;
        $existing = Subscription::where('stripe_subscription_id', $stripe->id)->first();

        if ($existing) {
            $sameSecondCancellation = $existing->stripe_synced_at
                && $status === SubscriptionStatus::Canceled
                && $existing->status !== SubscriptionStatus::Canceled
                && $eventTime->equalTo($existing->stripe_synced_at);
            if (! $sameSecondCancellation && $this->eventIsOlder($eventTime, $eventId, $existing->stripe_synced_at, $existing->stripe_synced_event_id)) {
                return $existing;
            }
            if ($existing->status === SubscriptionStatus::Canceled && $status !== SubscriptionStatus::Canceled
                && $existing->stripe_synced_at?->equalTo($eventTime)) {
                return $existing;
            }
            if ($this->identityConflicts($existing, $stripe, $intent)) {
                Log::warning('Stripe subscription identity does not match its local billing record.', [
                    'stripe_subscription' => $stripe->id ?? null,
                    'stripe_customer' => $stripe->customer ?? null,
                ]);

                return $existing;
            }
        }

        // A newly active subscription needs its Customer before the Subscription
        // row is locked/created; this keeps webhook lock order consistent.
        $resolvedCustomer = null;
        if ($status->grantsAccess()) {
            if ($intent && ! $existing?->customer_id) {
                $resolvedCustomer = $this->provision->forIntent($intent, $stripe->customer ?? null);
            } elseif ($existing?->customer_id) {
                $resolvedCustomer = Customer::query()->find($existing->customer_id);
            }
        }

        $subscription = $this->subscriptionForUpdateOrCreate($stripe->id, $intent, $stripePriceId);

        if (! $subscription) {
            Log::warning('Stripe subscription with an unknown price and no checkout ignored.', ['stripe_subscription' => $stripe->id]);

            return null;
        }

        $sameSecondCancellation = $subscription
            && $subscription->stripe_synced_at
            && $status === SubscriptionStatus::Canceled
            && $subscription->status !== SubscriptionStatus::Canceled
            && $eventTime->equalTo($subscription->stripe_synced_at);
        if ($subscription && ! $sameSecondCancellation
            && $this->eventIsOlder($eventTime, $eventId, $subscription->stripe_synced_at, $subscription->stripe_synced_event_id)) {
            return $subscription;
        }

        if ($subscription?->status === SubscriptionStatus::Canceled && $status !== SubscriptionStatus::Canceled
            && $subscription->stripe_synced_at?->equalTo($eventTime)) {
            return $subscription;
        }

        if ($subscription && $this->identityConflicts($subscription, $stripe, $intent)) {
            Log::warning('Stripe subscription identity does not match its local billing record.', [
                'stripe_subscription' => $stripe->id ?? null,
                'stripe_customer' => $stripe->customer ?? null,
            ]);

            return $subscription;
        }

        $stripePriceId ??= $subscription->stripe_price_id;

        // Plan changes made in Stripe (e.g. a later billing portal) follow the price.
        if ($stripePriceId && ($price = $this->catalog->priceByStripeId($stripePriceId))) {
            $subscription->fill([
                'product_type' => $price->productType,
                'plan' => $price->tier,
                'interval' => $price->interval,
                'market' => $price->marketGroup,
                'pricing_market' => $price->marketGroup,
            ]);
        }

        $previousStatus = $subscription->wasRecentlyCreated ? null : $subscription->status;

        $subscription->fill([
            'billing_source' => 'stripe',
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
            'stripe_synced_event_id' => $eventId,
            'billing_snapshot_confirmed_at' => $eventTime,
        ]);
        $subscription->fill($this->billingSnapshot->fromStripe($stripe, $subscription, $item->price ?? null));

        // A selected local promotion is only an expectation until Stripe returns its discount object.
        if ($intent?->promotion_id && ! $this->hasDiscountState($stripe) && ! $subscription->stripe_coupon_id) {
            $subscription->current_recurring_amount = null;
        }

        // Stripe reporting the subscription active/trialing confirms payment,
        // so the customer can be resolved even if the checkout event is late.
        if (! $subscription->customer_id && $status->grantsAccess()) {
            $customer = $resolvedCustomer ?? ($intent
                ? $this->provision->forIntent($intent, $stripe->customer ?? null)
                : Customer::where('stripe_customer_id', $stripe->customer ?? '')->first());
            $subscription->customer()->associate($customer);
        } elseif ($resolvedCustomer && $subscription->customer_id === $resolvedCustomer->id) {
            $subscription->setRelation('customer', $resolvedCustomer);
        }

        $this->recordActivation($subscription);
        $subscription->save();

        // An invoice event can arrive before the subscription event. Attach
        // those Stripe-authoritative rows once the local subscription exists.
        $invoiceUpdate = ['subscription_id' => $subscription->id];
        if ($subscription->customer_id) {
            $invoiceUpdate['customer_id'] = $subscription->customer_id;
        }
        BillingInvoice::query()
            ->where('stripe_subscription_id', $subscription->stripe_subscription_id)
            ->whereNull('subscription_id')
            ->where(function ($query) use ($subscription) {
                $query->whereNull('stripe_customer_id')
                    ->orWhere('stripe_customer_id', $subscription->stripe_customer_id);
            })
            ->update($invoiceUpdate);

        if ($previousStatus && $previousStatus !== SubscriptionStatus::Canceled && $status === SubscriptionStatus::Canceled && $subscription->customer) {
            Mail::to($subscription->customer->email)->queue((new SubscriptionCanceled($subscription))->afterCommit());
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

        $subscription = $this->subscriptionForUpdateOrCreate($stripeSubscriptionId, $intent, $intent->stripe_price_id);

        if (! $subscription) {
            return null;
        }

        if ($subscription->exists && (
            ($subscription->customer_id && $subscription->customer_id !== $customer->id)
            || ($subscription->checkout_intent_id && $subscription->checkout_intent_id !== $intent->id)
            || ($subscription->stripe_customer_id && $customer->stripe_customer_id
                && ! hash_equals($subscription->stripe_customer_id, $customer->stripe_customer_id))
        )) {
            Log::warning('Stripe checkout subscription identity conflicts with its existing local record.', [
                'stripe_subscription' => $stripeSubscriptionId,
                'checkout_intent' => $intent->uuid,
            ]);

            throw new UnexpectedValueException('Stripe subscription identity conflict.');
        }

        $subscription->checkout_intent_id ??= $intent->id;
        $subscription->stripe_customer_id ??= $customer->stripe_customer_id;
        $subscription->billing_source ??= 'stripe';

        if (! $subscription->customer_id) {
            $subscription->customer()->associate($customer);
        } else {
            $subscription->setRelation('customer', $customer);
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
                'billing_source' => 'stripe',
                'checkout_intent_id' => $intent->id,
                'stripe_price_id' => $intent->stripe_price_id,
                'product_type' => $intent->product_type,
                'plan' => $intent->plan,
                'interval' => $intent->interval,
                'market' => $intent->market,
                'currency' => $intent->currency,
                'amount' => $intent->amount,
                'base_price_amount' => $intent->amount,
                'base_price_currency' => $intent->currency,
                'pricing_market' => $intent->market,
                'current_recurring_amount' => null,
                'current_recurring_currency' => $intent->currency,
                'status' => SubscriptionStatus::Pending,
            ]);
        }

        $price = $stripePriceId ? $this->catalog->priceByStripeId($stripePriceId) : null;

        return $price ? new Subscription([
            'stripe_subscription_id' => $stripeSubscriptionId,
            'billing_source' => 'stripe',
            'stripe_price_id' => $stripePriceId,
            'product_type' => $price->productType,
            'plan' => $price->tier,
            'interval' => $price->interval,
            'market' => $price->marketGroup,
            'currency' => $price->money->currency->code,
            'amount' => $price->money->amount,
            'base_price_amount' => $price->money->amount,
            'base_price_currency' => $price->money->currency->code,
            'pricing_market' => $price->marketGroup,
            'current_recurring_amount' => null,
            'current_recurring_currency' => $price->money->currency->code,
            'status' => SubscriptionStatus::Pending,
        ]) : null;
    }

    public function intentFrom(?object $metadata): ?CheckoutIntent
    {
        $uuid = $metadata->checkout_intent ?? null;

        // Every event for a checkout locks the same intent before touching its
        // Customer, Subscription, or Invoice. This serializes valid Stripe
        // event orderings under MySQL REPEATABLE READ.
        return is_string($uuid) ? CheckoutIntent::where('uuid', $uuid)->lockForUpdate()->first() : null;
    }

    /**
     * Resolve or materialize the one local row for a Stripe subscription.
     * The caller's signed-webhook transaction is expected to be active. The
     * lock is a current read, and the unique-key fallback handles contenders
     * that do not share a CheckoutIntent (or reach this method outside the
     * normal serialized checkout path).
     */
    private function subscriptionForUpdateOrCreate(string $stripeSubscriptionId, ?CheckoutIntent $intent, ?string $stripePriceId): ?Subscription
    {
        $subscription = Subscription::query()
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->lockForUpdate()
            ->first();

        if ($subscription) {
            return $subscription;
        }

        $subscription = $this->newFromIntentOrPrice($stripeSubscriptionId, $intent, $stripePriceId);

        if (! $subscription) {
            return null;
        }

        try {
            $subscription->save();

            return $subscription;
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isStripeSubscriptionIdCollision($exception)) {
                throw $exception;
            }

            // A plain SELECT can be pinned to an old REPEATABLE READ snapshot.
            // FOR UPDATE is a current read and sees the committed winner.
            return Subscription::query()
                ->where('stripe_subscription_id', $stripeSubscriptionId)
                ->lockForUpdate()
                ->first() ?? throw $exception;
        }
    }

    private function isStripeSubscriptionIdCollision(UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'subscriptions_stripe_subscription_id_unique')
            || str_contains($message, 'subscriptions.stripe_subscription_id');
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
        $customer = $subscription->customer;

        if ($subscription->activated_at && ! $subscription->activation_notified_at && $customer) {
            $subscription->forceFill(['activation_notified_at' => now()])->save();
            Mail::to($customer->email)->queue((new SubscriptionActivated($subscription))->afterCommit());
        }
    }

    private function time(?int $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }

    private function hasDiscountState(object $stripe): bool
    {
        $data = method_exists($stripe, 'toArray') ? $stripe->toArray() : get_object_vars($stripe);

        return array_key_exists('discounts', $data) || array_key_exists('discount', $data);
    }

    private function eventIsOlder(Carbon $incomingAt, ?string $incomingId, ?Carbon $currentAt, ?string $currentId): bool
    {
        if (! $currentAt) {
            return false;
        }

        if ($incomingAt->lt($currentAt)) {
            return true;
        }

        if ($incomingAt->equalTo($currentAt) && $currentId !== null && $incomingId !== null) {
            // Stripe event timestamps have second precision. A stable ID
            // tie-break makes same-second deliveries converge regardless of
            // arrival order; the ID is not treated as a causal sequence.
            return strcmp($incomingId, $currentId) <= 0;
        }

        return false;
    }

    private function identityConflicts(Subscription $subscription, object $stripe, ?CheckoutIntent $intent): bool
    {
        $incomingCustomerId = $stripe->customer ?? null;

        return (is_string($incomingCustomerId)
                && is_string($subscription->stripe_customer_id)
                && ! hash_equals($subscription->stripe_customer_id, $incomingCustomerId))
            || ($intent && $subscription->checkout_intent_id && $subscription->checkout_intent_id !== $intent->id)
            || ($intent && $subscription->customer_id && $intent->customer_id && $subscription->customer_id !== $intent->customer_id);
    }
}
