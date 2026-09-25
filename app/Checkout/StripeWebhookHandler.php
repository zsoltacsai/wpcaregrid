<?php

namespace App\Checkout;

use App\Models\CheckoutIntent;
use Stripe\Event;

/**
 * Applies verified Stripe events to checkout intents. This is the only place
 * an intent becomes `completed`. Subscription lifecycle events (renewals,
 * cancellations, failed renewals) are acknowledged and left for the billing
 * milestone.
 */
final class StripeWebhookHandler
{
    public function handle(Event $event): void
    {
        $session = $event->data->object;

        match ($event->type) {
            'checkout.session.completed' => $this->completed($session, paid: in_array($session->payment_status ?? null, ['paid', 'no_payment_required'], true)),
            'checkout.session.async_payment_succeeded' => $this->completed($session, paid: true),
            'checkout.session.async_payment_failed' => $this->transition($session, CheckoutStatus::Failed, ['failure_reason' => 'async_payment_failed']),
            'checkout.session.expired' => $this->transition($session, CheckoutStatus::Expired),
            default => null,
        };
    }

    private function completed(object $session, bool $paid): void
    {
        $intent = $this->intentFor($session);

        if (! $intent) {
            return;
        }

        $intent->fill([
            'stripe_payment_status' => $session->payment_status ?? null,
            'stripe_customer_id' => $session->customer ?? $intent->stripe_customer_id,
            'stripe_subscription_id' => $session->subscription ?? $intent->stripe_subscription_id,
        ]);

        // A session can complete with a delayed payment method still pending;
        // it only counts once Stripe reports it paid.
        if ($paid && $intent->canMoveTo(CheckoutStatus::Completed)) {
            $intent->status = CheckoutStatus::Completed;
            $intent->completed_at = now();
        }

        $intent->save();
    }

    /** @param  array<string, mixed>  $attributes */
    private function transition(object $session, CheckoutStatus $status, array $attributes = []): void
    {
        $intent = $this->intentFor($session);

        if ($intent?->canMoveTo($status)) {
            $intent->update(['status' => $status, ...$attributes]);
        }
    }

    /** Match by session ID, the reference we stored when creating it. */
    private function intentFor(object $session): ?CheckoutIntent
    {
        return isset($session->id)
            ? CheckoutIntent::where('stripe_checkout_session_id', $session->id)->first()
            : null;
    }
}
