<?php

namespace App\Checkout;

use App\Billing\CheckoutDiscountSnapshotSync;
use App\Billing\InvoiceEvents;
use App\Billing\ProvisionCustomer;
use App\Billing\SubscriptionSync;
use App\Models\CheckoutIntent;
use App\Models\StripeEvent;
use App\Offers\RecoveryOffers;
use App\Owner\AdminAudit;
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
        private readonly RecoveryOffers $offers,
        private readonly PromotionReservations $reservations,
        private readonly CheckoutDiscountSnapshotSync $discountSnapshots,
    ) {}

    /** @return bool False when the event had already been processed. */
    public function handle(Event $event): bool
    {
        if (StripeEvent::where('stripe_event_id', $event->id)->exists()) {
            return false;
        }

        try {
            // A deadlock aborts the entire transaction, including the event
            // receipt and DB-backed queue writes. Retry only this bounded,
            // transactional webhook unit; other errors still fail closed.
            $attempts = in_array($event->type, [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed',
                'checkout.session.expired',
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted',
                'invoice.paid',
                'invoice.payment_failed',
                'invoice.finalized',
            ], true) ? 3 : 1;

            DB::transaction(function () use ($event) {
                StripeEvent::create([
                    'stripe_event_id' => $event->id,
                    'type' => $event->type,
                    'object_id' => $event->data->object->id ?? null,
                    'stripe_created_at' => isset($event->created) ? Carbon::createFromTimestamp($event->created) : null,
                    'processed_at' => now(),
                ]);

                $this->dispatch($event);
            }, attempts: $attempts);
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
            'customer.subscription.deleted' => $this->subscriptions->apply($object, (int) ($event->created ?? time()), $event->id),
            'invoice.paid' => $this->invoices->paid($object, (int) ($event->created ?? time()), $event->id),
            'invoice.payment_failed' => $this->invoices->failed($object, (int) ($event->created ?? time()), $event->id),
            'invoice.finalized' => $this->invoices->record($object, (int) ($event->created ?? time()), $event->id),
            default => null,
        };
    }

    private function checkoutCompleted(object $session, bool $paid): void
    {
        // A one-time recovery offer payment is not a subscription checkout.
        if (($session->metadata->kind ?? null) === 'recovery_offer') {
            $this->offers->markPaid($session);

            return;
        }

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

        }

        $intent->save();

        if ($paid) {
            $customer = $this->provision->forIntent($intent, $intent->stripe_customer_id);
            $subscription = $this->subscriptions->attachCheckout($intent, $customer, $intent->stripe_subscription_id);
            if ($subscription) {
                $this->discountSnapshots->apply($session, $intent);
            }

            // Keep the graph lock order consistent: intent, customer/user,
            // subscription, then promotion reservation.
            if ($intent->promotion_id && $this->reservations->consume($intent)) {
                AdminAudit::record(null, 'coupon.redeemed', 'coupon', (string) $intent->promotion_code, ['checkout_intent' => $intent->uuid]);
            }
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function checkoutTransition(object $session, CheckoutStatus $status, array $attributes = []): void
    {
        $intent = $this->intentFor($session);

        if ($intent?->canMoveTo($status)) {
            $intent->update(['status' => $status, ...$attributes]);
        }

        // A signed Stripe expiry is definitive even if the browser already
        // marked the intent cancelled; a late paid completion instead consumes
        // the still-held reservation.
        if ($intent && $status === CheckoutStatus::Expired) {
            $this->reservations->release($intent);
        } elseif ($intent && $status === CheckoutStatus::Failed && $intent->status === CheckoutStatus::Failed) {
            $this->reservations->release($intent);
        }
    }

    /**
     * The intent named by the session's client_reference_id, which the server
     * set when creating the session. It must also match the session ID.
     */
    private function intentFor(object $session): ?CheckoutIntent
    {
        $intent = is_string($session->client_reference_id ?? null)
            ? CheckoutIntent::where('uuid', $session->client_reference_id)->lockForUpdate()->first()
            : null;

        $intent ??= isset($session->id) ? CheckoutIntent::where('stripe_checkout_session_id', $session->id)->lockForUpdate()->first() : null;

        if ($intent && $intent->stripe_checkout_session_id && $intent->stripe_checkout_session_id !== ($session->id ?? null)) {
            return null;
        }

        return $intent;
    }
}
