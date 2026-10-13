<?php

namespace App\Billing;

use App\Models\Subscription;
use App\Models\SubscriptionOperation;
use App\Owner\AdminAudit;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use Illuminate\Support\Facades\DB;

/** Applies an immediate plan change only after its Stripe invoice is paid. */
final class SubscriptionPlanChangeConfirmation
{
    public function confirmPaidInvoice(Subscription $subscription, object $invoice): bool
    {
        if (($invoice->billing_reason ?? null) !== 'subscription_update') {
            return false;
        }
        $invoiceId = $invoice->id ?? null;
        if (! is_string($invoiceId) || $invoiceId === '') {
            return false;
        }
        $priceIds = $this->invoicePriceIds($invoice);
        if ($priceIds === []) {
            return false;
        }

        return DB::transaction(function () use ($subscription, $invoiceId, $priceIds): bool {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if ($locked->pending_change_status !== 'awaiting_payment' || ! $locked->pending_stripe_price_id
                || ! in_array($locked->pending_stripe_price_id, $priceIds, true)) {
                return false;
            }

            $operation = SubscriptionOperation::query()
                ->where('subscription_id', $locked->id)
                ->whereIn('operation', ['upgrade', 'billing_period_switch'])
                ->whereIn('status', ['requested', 'processing', 'awaiting_confirmation', 'reconciliation_required'])
                ->whereJsonContains('request_snapshot->target_stripe_price_id', $locked->pending_stripe_price_id)
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if (! $operation) {
                return false;
            }
            $expectedInvoice = data_get($operation->request_snapshot, 'provider_invoice_id');
            if (is_string($expectedInvoice) && $expectedInvoice !== '' && ! hash_equals($expectedInvoice, $invoiceId)) {
                return false;
            }

            $snapshot = $operation->request_snapshot;
            $locked->forceFill([
                'plan' => PlanTier::from($snapshot['target_plan']),
                'interval' => BillingInterval::from($snapshot['target_interval']),
                'stripe_price_id' => $snapshot['target_stripe_price_id'],
                'pricing_version' => $snapshot['target_pricing_version'],
                'amount' => $snapshot['target_amount'],
                'currency' => $snapshot['target_currency'],
                'pending_plan' => null,
                'pending_interval' => null,
                'pending_stripe_price_id' => null,
                'pending_pricing_version' => null,
                'pending_change_effective_at' => null,
                'pending_change_status' => null,
            ])->save();
            $operation->forceFill(['status' => 'confirmed', 'confirmed_at' => now(), 'lease_expires_at' => null])->save();
            AdminAudit::record(null, 'billing.subscription_change_confirmed', 'subscription', $locked->uuid, [
                'operation' => $operation->operation,
                'stripe_invoice' => $invoiceId,
            ]);

            return true;
        }, attempts: 3);
    }

    /** @return list<string> */
    private function invoicePriceIds(object $invoice): array
    {
        $ids = [];
        foreach (($invoice->lines->data ?? []) as $line) {
            $price = $line->price ?? $line->pricing->price_details->price ?? null;
            $id = is_string($price) ? $price : (is_object($price) ? ($price->id ?? null) : null);
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
