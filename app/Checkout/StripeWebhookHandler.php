<?php

namespace App\Checkout;

use App\Billing\InvoiceEvents;
use App\Billing\ProvisionCustomer;
use App\Billing\SubscriptionSync;
use App\Models\CheckoutIntent;
use App\Models\Promotion;
use App\Models\StripeEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

/**
 * Applies verified Stripe events. The only path by which a checkout is
 * completed, a customer is provisioned or a subscription gains access.
 *
 * Each event ID is processed once: it is recorded in the same transaction
 * as its effects, so a failure rolls both back (Stripe retries) and a
 * redelivery is skipped. Handlers do not assume delivery order.
 */
final class StripeWebhookHandler
{
    public function __construct(
        private readonly ProvisionCustomer $provision,
        private readonly SubscriptionSync $subscriptions,
        private readonly InvoiceEvents $invoices,
    ) {}

    /** @return bool False when the event had already been processed. */
    public function handle(Event $event): bool
    {
        if (StripeEvent::where('stripe_event_id', $event->id)->exists()) {
            return false;
        }

        try {
            DB::transaction(function () use ($event) {
                StripeEvent::create([
                    'stripe_event_id' => $event->id,
                    'type' => $event->type,
                    'object_id' => $event->data->object->id ?? null,
                    'stripe_created_at' => isset($event->created) ? Carbon::createFromTimestamp($event->created) : null,
                    'processed_at' => now(),
                ]);

                $this->dispatch($event);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same event was processed concurrently by another request.
            if (str_contains($e->getMessage(), 'stripe_events')) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    private function dispatch(Event $event): void
    {
        $object = $event->data->object;

        match ($event->type) {
            'checkout.session.completed' => $this->checkoutCompleted($object, paid: in_array($object->payment_status ?? null, ['paid', 'no_payment_required'], true)),
            'checkout.session.async_payment_succeeded' => $this->checkoutCompleted($object, paid: true),
            'checkout.session.async_payment_failed' => $this->checkoutTransition($object, CheckoutStatus::Failed, ['failure_reason' => 'async_payment_failed']),
            'checkout.session.expired' => $this->checkoutTransition($object, CheckoutStatus::Expired),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->subscriptions->apply($object, (int) ($event->created ?? time())),
            'invoice.paid' => $this->invoices->paid($object),
            'invoice.payment_failed' => $this->invoices->failed($object),
            default => null,
        };
    }

    private function checkoutCompleted(object $session, bool $paid): void
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
        // nothing is provisioned until Stripe reports it paid.
        if ($paid && $intent->canMoveTo(CheckoutStatus::Completed)) {
            $intent->status = CheckoutStatus::Completed;
            $intent->completed_at = now();

            // Counted once, when the intent completes, so max_redemptions applies to later checkouts.
            if ($intent->promotion_id) {
                Promotion::whereKey($intent->promotion_id)->increment('times_redeemed');
            }
        }

        $intent->save();

        if ($paid) {
            $customer = $this->provision->forIntent($intent, $intent->stripe_customer_id);
            $this->subscriptions->attachCheckout($intent, $customer, $intent->stripe_subscription_id);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function checkoutTransition(object $session, CheckoutStatus $status, array $attributes = []): void
    {
        $intent = $this->intentFor($session);

        if ($intent?->canMoveTo($status)) {
            $intent->update(['status' => $status, ...$attributes]);
        }
    }

    /**
     * The intent named by the session's client_reference_id, which the server
     * set when creating the session. It must also match the session ID.
     */
    private function intentFor(object $session): ?CheckoutIntent
    {
        $intent = is_string($session->client_reference_id ?? null)
            ? CheckoutIntent::where('uuid', $session->client_reference_id)->first()
            : null;

        $intent ??= isset($session->id) ? CheckoutIntent::where('stripe_checkout_session_id', $session->id)->first() : null;

        if ($intent && $intent->stripe_checkout_session_id && $intent->stripe_checkout_session_id !== ($session->id ?? null)) {
            return null;
        }

        return $intent;
    }
}
