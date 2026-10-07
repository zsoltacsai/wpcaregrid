<?php

namespace App\Billing;

use App\Models\BillingInvoice;
use App\Models\CheckoutIntent;
use App\Models\Promotion;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Applies only Stripe-confirmed checkout promotion terms to billing snapshots. */
final class CheckoutDiscountSnapshotSync
{
    public function __construct(private readonly CheckoutDiscountSnapshot $snapshot) {}

    /**
     * The session must be the one created for this intent and must be paid.
     * Monetary invoice values remain sourced from signed invoice events.
     */
    public function apply(object $session, CheckoutIntent $intent): bool
    {
        $sessionId = $session->id ?? null;
        $subscriptionId = $this->idOf($session->subscription ?? null);
        $customerId = $this->idOf($session->customer ?? null);
        $invoiceId = $this->idOf($session->invoice ?? null);

        if (! is_string($sessionId)
            || ! is_string($intent->stripe_checkout_session_id)
            || ! hash_equals($intent->stripe_checkout_session_id, $sessionId)
            || ! is_string($session->client_reference_id ?? null)
            || ! hash_equals($intent->uuid, $session->client_reference_id)
            || ! is_string($session->currency ?? null)
            || strtoupper($session->currency) !== strtoupper((string) $intent->currency)
            || ($session->status ?? null) !== 'complete'
            || ! in_array($session->payment_status ?? null, ['paid', 'no_payment_required'], true)
            || ! is_string($subscriptionId)) {
            return false;
        }

        $snapshot = $this->snapshot->fromSession($session, $intent->promotion_id
            ? Promotion::query()->find($intent->promotion_id)
            : null);
        if ($snapshot === null) {
            return false;
        }

        $apply = function () use ($intent, $subscriptionId, $customerId, $invoiceId, $snapshot): bool {
            $lockedIntent = CheckoutIntent::query()->whereKey($intent->id)->lockForUpdate()->first();
            if (! $lockedIntent
                || $lockedIntent->uuid !== $intent->uuid
                || $lockedIntent->stripe_checkout_session_id !== $intent->stripe_checkout_session_id
                || ($lockedIntent->stripe_subscription_id && $lockedIntent->stripe_subscription_id !== $subscriptionId)
                || ($lockedIntent->stripe_customer_id && $customerId && ! hash_equals($lockedIntent->stripe_customer_id, $customerId))) {
                return false;
            }

            $subscription = Subscription::query()
                ->where('stripe_subscription_id', $subscriptionId)
                ->lockForUpdate()
                ->first();
            if (! $subscription
                || $subscription->checkout_intent_id !== $lockedIntent->id
                || ($subscription->stripe_customer_id && $customerId && ! hash_equals($subscription->stripe_customer_id, $customerId))) {
                return false;
            }

            if ($subscription->initial_discount_snapshot_confirmed_at
                && ($subscription->initial_stripe_promotion_code_id !== ($snapshot['initial_stripe_promotion_code_id'] ?? null)
                    || $subscription->initial_stripe_coupon_id !== ($snapshot['initial_stripe_coupon_id'] ?? null))) {
                Log::warning('Stripe checkout discount does not match the saved initial billing snapshot.', [
                    'stripe_subscription' => $subscriptionId,
                    'checkout_intent' => $lockedIntent->uuid,
                ]);

                return false;
            }

            $invoiceQuery = BillingInvoice::query()
                ->where('stripe_subscription_id', $subscriptionId)
                ->where('billing_reason', 'subscription_create')
                ->lockForUpdate();
            if ($invoiceId) {
                $invoiceQuery->where('stripe_invoice_id', $invoiceId);
            }
            $invoices = $invoiceQuery->get();
            if ($invoices->count() === 1) {
                $invoice = $invoices->sole();
                if ($invoice->stripe_customer_id && $customerId && ! hash_equals($invoice->stripe_customer_id, $customerId)) {
                    return false;
                }
                if ($invoice->discount_total !== null && $invoice->discount_total !== $snapshot['initial_invoice_discount_total']) {
                    Log::warning('Stripe Checkout and initial invoice discount totals do not match.', [
                        'stripe_invoice' => $invoice->stripe_invoice_id,
                        'stripe_subscription' => $subscriptionId,
                    ]);

                    return false;
                }
            } else {
                $invoice = null;
            }

            $subscription->fill($snapshot)->save();
            if ($invoice) {
                $invoice->fill($this->invoiceAttributes($snapshot))->save();
            }

            return true;
        };

        return DB::transactionLevel() > 0 ? $apply() : DB::transaction($apply, attempts: 3);
    }

    /** @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function invoiceAttributes(array $snapshot): array
    {
        return [
            'applied_discount_type' => $snapshot['initial_discount_type'],
            'applied_discount_percent' => $snapshot['initial_discount_percent'],
            'applied_discount_amount' => $snapshot['initial_discount_amount'],
            'applied_discount_currency' => $snapshot['initial_discount_currency'],
            'applied_discount_duration' => $snapshot['initial_discount_duration'],
            'applied_discount_duration_months' => $snapshot['initial_discount_duration_months'],
            'stripe_coupon_id' => $snapshot['initial_stripe_coupon_id'],
            'stripe_promotion_code_id' => $snapshot['initial_stripe_promotion_code_id'],
            'stripe_promotion_code' => $snapshot['initial_stripe_promotion_code'],
            'discount_snapshot_confirmed_at' => $snapshot['initial_discount_snapshot_confirmed_at'],
        ];
    }

    private function idOf(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_object($value)) {
            $id = $value->id ?? null;

            return is_string($id) ? $id : null;
        }

        return null;
    }
}
