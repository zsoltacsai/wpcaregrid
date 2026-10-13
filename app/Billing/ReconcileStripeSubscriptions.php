<?php

namespace App\Billing;

use App\Events\Platform\PlatformRecoverySignal;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Pulls provider-authoritative state when webhook delivery may have been missed. */
final class ReconcileStripeSubscriptions
{
    public function __construct(
        private readonly StripeSubscriptionReader $reader,
        private readonly SubscriptionSync $sync,
        private readonly InvoiceEvents $invoices,
    ) {}

    /** @return array{checked:int,reconciled:int,changed:int,failed:int,skipped:int} */
    public function run(int $limit = 100, ?int $subscriptionId = null): array
    {
        $result = ['checked' => 0, 'reconciled' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0];
        $limit = max(1, min(500, $limit));

        Subscription::query()
            ->where('billing_source', 'stripe')
            ->whereNotNull('stripe_subscription_id')
            ->when($subscriptionId !== null, fn ($query) => $query->whereKey($subscriptionId))
            // Prioritize never-reconciled and least-recently-reconciled rows.
            // Ordering only by ID permanently starves rows beyond the limit.
            ->orderBy('last_reconciled_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Subscription $candidate) use (&$result): void {
                $result['checked']++;
                $snapshotSyncedAt = $candidate->stripe_synced_at?->toISOString();
                try {
                    $remote = $this->reader->retrieve((string) $candidate->stripe_subscription_id);
                    if (($remote->id ?? null) !== $candidate->stripe_subscription_id) {
                        throw new \UnexpectedValueException('Stripe returned a different subscription identity.');
                    }
                    $remoteCustomer = is_string($remote->customer ?? null)
                        ? $remote->customer
                        : ($remote->customer->id ?? null);
                    if (! is_string($remoteCustomer) || ! hash_equals((string) $candidate->stripe_customer_id, $remoteCustomer)) {
                        throw new \UnexpectedValueException('Stripe subscription customer identity does not match the local billing record.');
                    }

                    $outcome = DB::transaction(function () use ($candidate, $snapshotSyncedAt, $remote): string {
                        $locked = Subscription::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                        if ($locked->stripe_synced_at?->toISOString() !== $snapshotSyncedAt) {
                            $locked->forceFill([
                                'reconciliation_status' => 'skipped_concurrent_update',
                                'last_reconciled_at' => now(),
                                'reconciliation_error_category' => null,
                            ])->save();

                            return 'skipped';
                        }

                        $before = [$locked->status?->value, $locked->stripe_price_id, $locked->cancel_at_period_end, $locked->current_period_end?->timestamp];
                        $eventTime = max(now()->timestamp, ($locked->stripe_synced_at?->timestamp ?? 0) + 1);
                        $this->sync->apply($remote, $eventTime, 'reconcile_'.bin2hex(random_bytes(12)));
                        $locked->refresh();
                        $after = [$locked->status?->value, $locked->stripe_price_id, $locked->cancel_at_period_end, $locked->current_period_end?->timestamp];
                        $locked->forceFill([
                            'reconciliation_status' => 'healthy',
                            'last_reconciled_at' => now(),
                            'reconciliation_error_category' => null,
                        ])->save();

                        return $before === $after ? 'reconciled' : 'changed';
                    }, attempts: 3);

                    if ($outcome !== 'skipped') {
                        $this->reconcileLatestInvoice($remote, $candidate);
                    }
                    $result[$outcome]++;
                } catch (Throwable $exception) {
                    $category = class_basename($exception);
                    Subscription::query()->whereKey($candidate->id)->update([
                        'reconciliation_status' => 'failed',
                        'last_reconciled_at' => now(),
                        'reconciliation_error_category' => mb_substr($category, 0, 80),
                        'updated_at' => now(),
                    ]);
                    Log::warning('Stripe subscription reconciliation failed.', [
                        'subscription_uuid' => $candidate->uuid,
                        'category' => $category,
                    ]);
                    event(new PlatformRecoverySignal('billing.reconciliation_failed', [
                        'subscription_uuid' => $candidate->uuid,
                        'category' => $category,
                    ]));
                    $result['failed']++;
                }
            });

        return $result;
    }

    /** Apply a missed payment event through the same idempotent invoice lifecycle path as webhooks. */
    private function reconcileLatestInvoice(object $remote, Subscription $subscription): void
    {
        $invoice = $remote->latest_invoice ?? null;
        if (! is_object($invoice) || ! is_string($invoice->id ?? null)) {
            return;
        }

        $invoiceSubscription = $invoice->parent->subscription_details->subscription
            ?? $invoice->subscription
            ?? null;
        $invoiceSubscriptionId = is_string($invoiceSubscription)
            ? $invoiceSubscription
            : (is_object($invoiceSubscription) ? ($invoiceSubscription->id ?? null) : null);
        $invoiceCustomer = $invoice->customer ?? null;
        $invoiceCustomerId = is_string($invoiceCustomer)
            ? $invoiceCustomer
            : (is_object($invoiceCustomer) ? ($invoiceCustomer->id ?? null) : null);

        if (! is_string($invoiceSubscriptionId)
            || ! hash_equals((string) $subscription->stripe_subscription_id, $invoiceSubscriptionId)
            || ! is_string($invoiceCustomerId)
            || ! hash_equals((string) $subscription->stripe_customer_id, $invoiceCustomerId)) {
            throw new \UnexpectedValueException('Stripe latest invoice identity does not match its subscription.');
        }

        if (($invoice->status ?? null) === 'paid') {
            $paidAt = $invoice->status_transitions->paid_at ?? $invoice->created ?? now()->timestamp;
            $this->invoices->paid($invoice, is_numeric($paidAt) ? (int) $paidAt : now()->timestamp, 'reconcile_invoice_'.$invoice->id.'_paid');

            return;
        }

        // An open invoice alone is not proof of a failed charge: only project a
        // payment failure when Stripe records an attempted, non-zero collection.
        if (($invoice->status ?? null) === 'open'
            && ($invoice->attempted ?? false) === true
            && (int) ($invoice->attempt_count ?? 0) > 0
            && (int) ($invoice->amount_due ?? 0) > 0) {
            $this->invoices->failed($invoice, now()->timestamp, 'reconcile_invoice_'.$invoice->id.'_payment_failed');
        }
    }
}
