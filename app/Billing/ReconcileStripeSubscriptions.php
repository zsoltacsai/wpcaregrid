<?php

namespace App\Billing;

use App\Events\Platform\PlatformRecoverySignal;
use App\Models\Subscription;
use App\Models\SubscriptionOperation;
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
                $snapshotPending = [
                    $candidate->pending_change_status,
                    $candidate->pending_stripe_price_id,
                    $candidate->pending_change_effective_at?->timestamp,
                ];
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

                    $providerScheduleConfirmed = $this->hasConfirmedPendingSchedule($candidate, $remote);
                    $outcome = DB::transaction(function () use ($candidate, $snapshotSyncedAt, $snapshotPending, $remote, $providerScheduleConfirmed): string {
                        $locked = Subscription::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                        $lockedPending = [
                            $locked->pending_change_status,
                            $locked->pending_stripe_price_id,
                            $locked->pending_change_effective_at?->timestamp,
                        ];
                        if ($locked->stripe_synced_at?->toISOString() !== $snapshotSyncedAt || $lockedPending !== $snapshotPending) {
                            $locked->forceFill([
                                'reconciliation_status' => 'skipped_concurrent_update',
                                'last_reconciled_at' => now(),
                                'reconciliation_error_category' => null,
                            ])->save();

                            return 'skipped';
                        }

                        $before = [$locked->status?->value, $locked->stripe_price_id, $locked->cancel_at_period_end, $locked->current_period_end?->timestamp];
                        $eventTime = max(now()->timestamp, ($locked->stripe_synced_at?->timestamp ?? 0) + 1);
                        $this->sync->apply($remote, $eventTime, 'reconcile_'.bin2hex(random_bytes(12)), $providerScheduleConfirmed);
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

    /**
     * A scheduled local downgrade is provider-confirmed only when the attached
     * Stripe schedule identifies the operation, target price and renewal phase.
     */
    private function hasConfirmedPendingSchedule(Subscription $subscription, object $remote): ?bool
    {
        if (! in_array($subscription->pending_change_status, ['scheduled', 'reconciliation_required'], true)) {
            return null;
        }

        $operation = SubscriptionOperation::query()
            ->where('subscription_id', $subscription->id)
            ->where('operation', 'downgrade')
            ->whereIn('status', ['awaiting_confirmation', 'confirmed', 'reconciliation_required'])
            ->whereJsonContains('request_snapshot->target_stripe_price_id', (string) $subscription->pending_stripe_price_id)
            ->latest('id')
            ->first();
        $schedule = $remote->schedule ?? null;
        if (! $operation || data_get($operation->request_snapshot, 'scheduled') !== true) {
            return null;
        }
        if (! is_object($schedule) || ! is_string($schedule->id ?? null)
            || ($schedule->status ?? null) !== 'active') {
            return false;
        }

        $scheduleSubscription = $schedule->subscription ?? null;
        $scheduleSubscriptionId = is_string($scheduleSubscription)
            ? $scheduleSubscription
            : (is_object($scheduleSubscription) ? ($scheduleSubscription->id ?? null) : null);
        $metadata = $this->objectArray($schedule->metadata ?? null);
        $storedScheduleId = data_get($operation->request_snapshot, 'provider_schedule_id');
        if (! is_string($scheduleSubscriptionId) || ! hash_equals((string) $subscription->stripe_subscription_id, $scheduleSubscriptionId)
            || ($metadata['caregrid_operation_uuid'] ?? null) !== $operation->uuid
            || ($metadata['caregrid_target_price_id'] ?? null) !== $subscription->pending_stripe_price_id
            || (is_string($storedScheduleId) && ! hash_equals($storedScheduleId, $schedule->id))) {
            return false;
        }

        foreach ($this->collectionItems($schedule->phases ?? null) as $phase) {
            if ((int) ($phase->start_date ?? 0) !== $subscription->pending_change_effective_at?->timestamp) {
                continue;
            }
            foreach ($this->collectionItems($phase->items ?? null) as $item) {
                $price = $item->price ?? null;
                $priceId = is_string($price) ? $price : (is_object($price) ? ($price->id ?? null) : null);
                if (is_string($priceId) && hash_equals((string) $subscription->pending_stripe_price_id, $priceId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function objectArray(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }

        return is_array($value) ? $value : (is_object($value) ? get_object_vars($value) : []);
    }

    /** @return list<object> */
    private function collectionItems(mixed $collection): array
    {
        if (is_object($collection) && method_exists($collection, 'toArray')) {
            $collection = $collection->toArray();
        }
        if (is_array($collection) && isset($collection['data']) && is_array($collection['data'])) {
            $collection = $collection['data'];
        }
        if (! is_array($collection)) {
            return [];
        }

        return array_map(static fn ($item): object => is_object($item) ? $item : (object) $item, array_values($collection));
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
