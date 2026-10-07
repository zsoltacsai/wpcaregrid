<?php

namespace App\Billing;

use App\Checkout\CheckoutStatus;
use App\Checkout\PromotionReservations;
use App\Mail\PaymentConfirmation;
use App\Mail\PaymentFailed;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\Subscription;
use App\Owner\AdminAudit;
use App\Pricing\Currency;
use App\Pricing\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * invoice.paid and invoice.payment_failed. These record the payment and
 * notify the customer; the subscription status itself only changes through
 * customer.subscription.* events.
 */
final class InvoiceEvents
{
    public function __construct(
        private readonly SubscriptionSync $subscriptions,
        private readonly ProvisionCustomer $provision,
        private readonly PromotionReservations $reservations,
    ) {}

    public function paid(object $invoice, int $eventCreated = 0, ?string $eventId = null): void
    {
        $subscription = $this->subscriptionFor($invoice, paymentConfirmed: true);

        [$snapshot, $applied, $notifyPayment] = $this->persist($invoice, $subscription, $eventCreated ?: time(), 'paid', $eventId);
        if (! $subscription || ! $applied) {
            return;
        }

        $subscription->fill(['last_payment_status' => 'paid', 'last_payment_at' => $snapshot->paid_at ?? now()]);

        // A completed renewal invoice is the best Stripe-authoritative record
        // of the amount billed for the current cycle (exclusive of Stripe tax).
        if ($snapshot->billing_reason === 'subscription_cycle'
            && is_int($snapshot->total)
            && is_int($snapshot->tax_total)
            && $snapshot->currency === strtoupper((string) ($subscription->current_recurring_currency ?? $subscription->currency))) {
            $subscription->current_recurring_amount = max(0, $snapshot->total - $snapshot->tax_total);
            $subscription->current_recurring_currency = $snapshot->currency;
            $subscription->billing_snapshot_confirmed_at = $snapshot->paid_at ?? now();

            if ($snapshot->discount_total === 0) {
                $subscription->fill([
                    'discount_type' => null,
                    'discount_percent' => null,
                    'discount_amount' => null,
                    'discount_currency' => null,
                    'discount_duration' => null,
                    'discount_duration_months' => null,
                    'stripe_coupon_id' => null,
                    'stripe_promotion_code_id' => null,
                    'stripe_promotion_code' => null,
                ]);
            }
        }

        // A paid invoice confirms payment even if the checkout event is late.
        if (! $subscription->customer_id && ($intent = $subscription->checkoutIntent)) {
            $subscription->customer()->associate($this->provision->forIntent($intent, $invoice->customer ?? null));
        }

        $subscription->save();

        if ($intent = $subscription->checkoutIntent) {
            if ($intent->canMoveTo(CheckoutStatus::Completed)) {
                $intent->update(['status' => CheckoutStatus::Completed, 'completed_at' => now(), 'stripe_payment_status' => 'paid']);
            }
            // Only the initial subscription invoice can consume a legacy
            // checkout reservation. Renewal invoices must never count a
            // coupon redemption again.
            if ($intent->promotion_id && $snapshot->billing_reason === 'subscription_create') {
                if ($this->reservations->consume($intent)) {
                    AdminAudit::record(null, 'coupon.redeemed', 'coupon', (string) $intent->promotion_code, ['checkout_intent' => $intent->uuid]);
                }
            }
        }

        // A zero invoice (100% discount) is not a payment: no "payment received" email.
        if ($notifyPayment && $subscription->customer && (int) ($invoice->amount_paid ?? 0) > 0) {
            Mail::to($subscription->customer->email)->queue(new PaymentConfirmation($subscription, $this->money($invoice, 'amount_paid')));
        }
    }

    public function failed(object $invoice, int $eventCreated = 0, ?string $eventId = null): void
    {
        $subscription = $this->subscriptionFor($invoice, paymentConfirmed: false);

        [, $applied, $notifyFailure] = $this->persist($invoice, $subscription, $eventCreated ?: time(), 'open', $eventId);

        if (! $subscription || ! $applied) {
            return;
        }

        $subscription->update(['last_payment_status' => 'failed']);

        if ($notifyFailure && $subscription->customer) {
            Mail::to($subscription->customer->email)->queue(new PaymentFailed($subscription, $this->money($invoice, 'amount_due')));
        }
    }

    /** Persist Stripe invoice totals even when the related subscription event has not arrived yet. */
    public function record(object $invoice, int $eventCreated = 0, ?string $eventId = null): void
    {
        $subscriptionId = $this->idOf($this->subscriptionId($invoice));
        $subscription = $subscriptionId
            ? Subscription::where('stripe_subscription_id', $subscriptionId)->first()
            : null;

        $this->persist($invoice, $subscription, $eventCreated ?: time(), eventId: $eventId);
    }

    /** @return array{BillingInvoice, bool, bool} row, whether applied, and whether a customer notice is new */
    private function persist(object $invoice, ?Subscription $subscription, int $eventCreated, ?string $statusOverride = null, ?string $eventId = null): array
    {
        $id = $invoice->id ?? null;
        if (! is_string($id) || $id === '') {
            throw new \UnexpectedValueException('Stripe invoice event has no invoice ID.');
        }

        $eventTime = Carbon::createFromTimestamp($eventCreated);
        $stripeCustomerId = $this->idOf($invoice->customer ?? null);
        $stripeSubscriptionId = $this->idOf($this->subscriptionId($invoice));
        $subscription ??= $stripeSubscriptionId
            ? Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first()
            : null;
        if ($subscription && $stripeCustomerId && $subscription->stripe_customer_id
            && ! hash_equals($subscription->stripe_customer_id, $stripeCustomerId)) {
            Log::warning('Stripe invoice customer does not match its local subscription.', [
                'stripe_invoice' => $id,
                'stripe_subscription' => $stripeSubscriptionId,
                'stripe_customer' => $stripeCustomerId,
            ]);

            return [new BillingInvoice, false, false];
        }

        $row = BillingInvoice::query()->where('stripe_invoice_id', $id)->lockForUpdate()->first();
        $incomingStatus = $statusOverride ?? ($invoice->status ?? null);
        if ($row && (($row->stripe_customer_id && $stripeCustomerId && ! hash_equals($row->stripe_customer_id, $stripeCustomerId))
            || ($row->stripe_subscription_id && $stripeSubscriptionId && ! hash_equals($row->stripe_subscription_id, $stripeSubscriptionId)))) {
            Log::warning('Stripe invoice identity does not match its local billing record.', ['stripe_invoice' => $id]);

            return [$row, false, false];
        }
        if ($row && $this->eventIsOlder($eventTime, $eventId, $row->last_stripe_event_at, $row->last_stripe_event_id, $incomingStatus, $row->invoice_status)) {
            return [$row, false, false];
        }
        if ($row?->invoice_status === 'paid' && $incomingStatus !== 'paid') {
            return [$row, false, false];
        }

        $paidAt = $this->time($invoice->status_transitions->paid_at ?? null)
            ?? ($statusOverride === 'paid' ? now() : null);
        $attributes = [
            'subscription_id' => $subscription?->id,
            'customer_id' => $subscription?->customer_id ?? Customer::where('stripe_customer_id', $stripeCustomerId ?? '')->value('id'),
            'stripe_customer_id' => $stripeCustomerId,
            'stripe_payment_intent_id' => $this->idOf($invoice->payment_intent ?? null),
            'stripe_subscription_id' => $stripeSubscriptionId,
            'subtotal' => $this->integer($invoice->subtotal ?? $invoice->subtotal_excluding_tax ?? null),
            'discount_total' => $this->sumAmounts($invoice->total_discount_amounts ?? $invoice->total_discounts ?? []),
            'tax_total' => $this->sumAmounts($invoice->total_taxes ?? $invoice->total_tax_amounts ?? []),
            'total' => $this->integer($invoice->total ?? null),
            'amount_paid' => $this->integer($invoice->amount_paid ?? null),
            'amount_due' => $this->integer($invoice->amount_due ?? null),
            'currency' => isset($invoice->currency) ? strtoupper((string) $invoice->currency) : null,
            'invoice_status' => $incomingStatus,
            'billing_reason' => $invoice->billing_reason ?? null,
            'stripe_created_at' => $this->time($invoice->created ?? null),
            'finalized_at' => $this->time($invoice->status_transitions->finalized_at ?? null),
            'paid_at' => $paidAt,
            'last_stripe_event_at' => $eventTime,
            'last_stripe_event_id' => $eventId,
        ];

        $notifyPayment = $statusOverride === 'paid'
            && $paidAt !== null
            && $subscription !== null
            && ! $row?->payment_confirmation_notified_at;
        $notifyFailure = $statusOverride === 'open' && $subscription !== null && ! $row?->payment_failure_notified_at;
        if ($notifyPayment) {
            $attributes['payment_confirmation_notified_at'] = now();
        }
        if ($notifyFailure) {
            $attributes['payment_failure_notified_at'] = now();
        }
        if ($row) {
            $row->fill($attributes)->save();
        } else {
            $row = BillingInvoice::create(['stripe_invoice_id' => $id, ...$attributes]);
        }

        return [$row, true, $notifyPayment || $notifyFailure];
    }

    /**
     * Find the subscription an invoice belongs to. For a paid invoice whose
     * subscription event has not arrived yet, create it from the checkout;
     * a failed invoice never creates or provisions anything.
     */
    private function subscriptionFor(object $invoice, bool $paymentConfirmed): ?Subscription
    {
        $details = $invoice->parent->subscription_details ?? null;
        $stripeSubscriptionId = $this->subscriptionId($invoice);

        if (! is_string($stripeSubscriptionId)) {
            return null;
        }

        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

        if (! $subscription && $paymentConfirmed && ($intent = $this->subscriptions->intentFrom($details->metadata ?? null))) {
            $customer = $this->provision->forIntent($intent, $invoice->customer ?? null);
            $subscription = $this->subscriptions->attachCheckout($intent, $customer, $stripeSubscriptionId);
        }

        return $subscription;
    }

    private function subscriptionId(object $invoice): mixed
    {
        return $invoice->parent->subscription_details->subscription ?? $invoice->subscription ?? null;
    }

    private function idOf(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_object($value) && is_string($value->id ?? null) ? $value->id : null);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $normalized = ltrim($value, '0') ?: '0';
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int) $normalized;
    }

    private function sumAmounts(mixed $items): ?int
    {
        if (! is_iterable($items)) {
            return null;
        }

        $sum = 0;
        $found = false;
        foreach ($items as $item) {
            $amount = is_array($item) ? ($item['amount'] ?? null) : ($item->amount ?? null);
            $amount = $this->integer($amount);
            if ($amount === null) {
                return null;
            }
            if ($amount > PHP_INT_MAX - $sum) {
                return null;
            }
            $sum += $amount;
            $found = true;
        }

        return $found ? $sum : 0;
    }

    private function time(mixed $timestamp): ?Carbon
    {
        return is_numeric($timestamp) && (int) $timestamp > 0 ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    private function eventIsOlder(Carbon $incomingAt, ?string $incomingId, ?Carbon $currentAt, ?string $currentId, mixed $incomingStatus, mixed $currentStatus): bool
    {
        if (! $currentAt) {
            return false;
        }

        if ($incomingAt->lt($currentAt)) {
            return true;
        }

        if ($incomingAt->equalTo($currentAt)) {
            if ($currentStatus === 'paid' && $incomingStatus !== 'paid') {
                return true;
            }
            if ($incomingStatus === 'paid' && $currentStatus !== 'paid') {
                return false;
            }
            if ($currentId !== null && $incomingId !== null) {
                // Timestamps have second precision. IDs provide only a stable
                // tie-break; Stripe event IDs do not encode causal order.
                return strcmp($incomingId, $currentId) <= 0;
            }
        }

        return false;
    }

    private function money(object $invoice, string $field): Money
    {
        $code = strtoupper($invoice->currency ?? 'HUF');

        return new Money((int) ($invoice->{$field} ?? 0), Currency::fromConfig($code, config("pricing.currencies.{$code}", []), app()->getLocale()));
    }
}
