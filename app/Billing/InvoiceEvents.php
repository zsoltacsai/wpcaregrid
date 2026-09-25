<?php

namespace App\Billing;

use App\Mail\PaymentConfirmation;
use App\Mail\PaymentFailed;
use App\Models\Subscription;
use App\Pricing\Currency;
use App\Pricing\Money;
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
    ) {}

    public function paid(object $invoice): void
    {
        $subscription = $this->subscriptionFor($invoice, paymentConfirmed: true);

        if (! $subscription) {
            return;
        }

        $subscription->fill(['last_payment_status' => 'paid', 'last_payment_at' => now()]);

        // A paid invoice confirms payment even if the checkout event is late.
        if (! $subscription->customer_id && ($intent = $subscription->checkoutIntent)) {
            $subscription->customer()->associate($this->provision->forIntent($intent, $invoice->customer ?? null));
        }

        $subscription->save();

        if ($subscription->customer) {
            Mail::to($subscription->customer->email)->queue(new PaymentConfirmation($subscription, $this->money($invoice, 'amount_paid')));
        }
    }

    public function failed(object $invoice): void
    {
        $subscription = $this->subscriptionFor($invoice, paymentConfirmed: false);

        if (! $subscription) {
            return;
        }

        $subscription->update(['last_payment_status' => 'failed']);

        if ($subscription->customer) {
            Mail::to($subscription->customer->email)->queue(new PaymentFailed($subscription, $this->money($invoice, 'amount_due')));
        }
    }

    /**
     * Find the subscription an invoice belongs to. For a paid invoice whose
     * subscription event has not arrived yet, create it from the checkout;
     * a failed invoice never creates or provisions anything.
     */
    private function subscriptionFor(object $invoice, bool $paymentConfirmed): ?Subscription
    {
        // Since Stripe API 2025-03-31 the subscription is under parent.subscription_details.
        $details = $invoice->parent->subscription_details ?? null;
        $stripeSubscriptionId = $details->subscription ?? $invoice->subscription ?? null;

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

    private function money(object $invoice, string $field): Money
    {
        $code = strtoupper($invoice->currency ?? 'HUF');

        return new Money((int) ($invoice->{$field} ?? 0), Currency::fromConfig($code, config("pricing.currencies.{$code}", []), app()->getLocale()));
    }
}
