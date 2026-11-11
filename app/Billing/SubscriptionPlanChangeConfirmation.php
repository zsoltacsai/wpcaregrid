<?php

namespace App\Billing;

use App\Models\Subscription;
use App\Models\SubscriptionOperation;
use App\Owner\AdminAudit;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use Illuminate\Support\Facades\DB;

/**
 * Settles an immediate (paid) plan change. Invariant: the higher plan is granted only by an authentic paid invoice for exactly
 * the pending target price; a failed or expired payment never grants it and never blocks the account; an unknown outcome is
 * neither paid nor failed until Stripe shows which (docs/STRIPE_SUBSCRIPTION_STATE_MACHINE.md).
 */
final class SubscriptionPlanChangeConfirmation
{
    /** Pending states of an immediate change on the subscription row. */
    public const OPEN_PENDING = ['awaiting_payment', 'reconciliation_required'];

    /** Operation kinds that charge now (a downgrade is a schedule and is settled elsewhere). */
    public const IMMEDIATE_OPERATIONS = ['upgrade', 'billing_period_switch'];

    /** Operation states in which a paid invoice still settles the change. */
    private const SETTLEABLE = ['requested', 'processing', 'awaiting_confirmation', 'reconciliation_required'];

    /** Closed without payment: a failure redelivered later is recognised as the same failure. */
    private const CLOSED_UNPAID = ['payment_failed', 'expired'];

    /**
     * Stripe discards the pending update of an unpaid immediate change after 23 hours. Paying the invoice inside that window still
     * applies the change at Stripe (so a late payment of a failed attempt applies it here too); after it, paying the invoice
     * does not change the Stripe subscription and so is never treated as the upgrade. Our own expiry waits two hours longer.
     */
    public const STRIPE_PENDING_WINDOW_HOURS = 23;

    public const PENDING_WINDOW_HOURS = 25;

    public function confirmPaidInvoice(Subscription $subscription, object $invoice): bool
    {
        [$invoiceId, $priceIds] = $this->identify($invoice);
        if ($invoiceId === null) {
            return false;
        }
        $paidAt = $this->providerPaidAt($invoice);

        return DB::transaction(function () use ($subscription, $invoiceId, $priceIds, $paidAt): bool {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            // An invoice that an earlier, closed attempt recorded belongs to that attempt, never to an open one that has no invoice id yet.
            $late = $this->closedOperationFor($locked, $priceIds, $invoiceId, self::CLOSED_UNPAID);

            // 'reconciliation_required': the Stripe response to the change request was lost, but this paid invoice is for exactly
            // the pending target price (checked below); refusing it would charge the customer for a plan they never get.
            if (! $late && in_array($locked->pending_change_status, self::OPEN_PENDING, true) && $locked->pending_stripe_price_id
                && in_array($locked->pending_stripe_price_id, $priceIds, true)) {
                $operation = $this->operation($locked, (string) $locked->pending_stripe_price_id, self::SETTLEABLE);
                if ($operation && $this->sameInvoice($operation, $invoiceId)) {
                    return $this->apply($locked, $operation, $invoiceId, $paidAt);
                }
            }

            // A payment that comes after an attempt was closed as failed, but inside Stripe's pending-update window: Stripe applied the
            // change, so the change was paid after all. Not after the window, not for an attempt closed as expired, and not while
            // another change is pending (it is never cleared by this one): those payments are surfaced to an operator instead.
            if ($late) {
                // The window runs from the first request: a replay answered with the original result must not extend it.
                $since = $late->created_at;
                $reason = match (true) {
                    $late->status !== 'payment_failed' => 'attempt_expired',
                    $locked->pending_change_status !== null => 'another_change_pending',
                    ! $since || $paidAt > $since->copy()->addHours(self::STRIPE_PENDING_WINDOW_HOURS)->getTimestamp() => 'outside_pending_window',
                    $this->laterChangeSettled($locked, $late) => 'later_change_settled',
                    default => null,
                };
                if ($reason === null) {
                    return $this->apply($locked, $late, $invoiceId, $paidAt);
                }
                $this->noteUnappliedLatePayment($locked, $late, $invoiceId, $reason);
            }

            return false;
        }, attempts: 3);
    }

    /**
     * The payment of the pending immediate change failed. The subscription keeps the plan it has paid for (no grace: the
     * subscription itself is not unpaid), the pending state stops blocking the account, and the attempt stays recorded so a later
     * payment of the same invoice still applies. Returns true when the invoice is (now or already) recorded as that failure.
     */
    public function failUnpaidInvoice(Subscription $subscription, object $invoice): bool
    {
        [$invoiceId, $priceIds] = $this->identify($invoice);
        if ($invoiceId === null) {
            return false;
        }

        return DB::transaction(function () use ($subscription, $invoiceId, $priceIds): bool {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            // The redelivered failure of an earlier, closed attempt (which recorded this invoice) is that attempt's, whatever is open now.
            if ($this->closedOperationFor($locked, $priceIds, $invoiceId, self::CLOSED_UNPAID) !== null) {
                return true;
            }

            if (in_array($locked->pending_change_status, self::OPEN_PENDING, true) && $locked->pending_stripe_price_id
                && in_array($locked->pending_stripe_price_id, $priceIds, true)) {
                $operation = $this->operation($locked, (string) $locked->pending_stripe_price_id, self::SETTLEABLE);
                if (! $operation || ! $this->sameInvoice($operation, $invoiceId)) {
                    return false;
                }
                $snapshot = $operation->request_snapshot;
                $snapshot['provider_invoice_id'] ??= $invoiceId;
                $snapshot['provider_truth_resolution'] = ['result' => 'payment_failed', 'invoice' => $invoiceId, 'checked_at' => now()->toIso8601String()];
                $operation->forceFill([
                    'status' => 'payment_failed', 'error_category' => 'InvoicePaymentFailed', 'request_snapshot' => $snapshot, 'lease_expires_at' => null,
                ])->save();
                $this->clearPending($locked);
                AdminAudit::record(null, 'billing.subscription_change_payment_failed', 'subscription', $locked->uuid, [
                    'operation_uuid' => $operation->uuid,
                    'stripe_invoice' => $invoiceId,
                ]);

                return true;
            }

            return false;
        }, attempts: 3);
    }

    /**
     * Reconciliation found the pending immediate change unpaid and gone: Stripe's pending-update window has passed, Stripe shows no
     * pending update and the subscription still has the old price. Only that is evidence it did not happen; an unknown outcome
     * is never closed earlier or on weaker evidence.
     */
    public function expireIfGone(Subscription $subscription, object $remote): bool
    {
        if (! in_array($subscription->pending_change_status, self::OPEN_PENDING, true)) {
            return false;
        }
        $items = $remote->items->data ?? [];
        $price = $items[0]->price ?? null;
        $remotePriceId = is_string($price) ? $price : (is_object($price) ? ($price->id ?? null) : null);
        if (! is_string($remotePriceId) || ($remote->pending_update ?? null) !== null) {
            return false;
        }

        $latest = $remote->latest_invoice ?? null;
        $latestInvoice = is_object($latest) ? $this->identify($latest) : null;
        $latestInvoice = $latestInvoice !== null && $latestInvoice[0] !== null ? $latestInvoice : null;

        return DB::transaction(function () use ($subscription, $remotePriceId, $latestInvoice): bool {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->pending_change_status, self::OPEN_PENDING, true) || ! $locked->pending_stripe_price_id
                || hash_equals((string) $locked->pending_stripe_price_id, $remotePriceId)) {
                return false;
            }
            $operation = $this->operation($locked, (string) $locked->pending_stripe_price_id, self::SETTLEABLE);
            if (! $operation) {
                return false;
            }
            $since = $operation->submitted_at ?? $operation->created_at;
            if (! $since || $since->gt(now()->subHours(self::PENDING_WINDOW_HOURS))) {
                return false;
            }
            $snapshot = $operation->request_snapshot;
            $snapshot['provider_truth_resolution'] = ['result' => 'expired_not_applied', 'checked_at' => now()->toIso8601String()];
            if (! is_string($snapshot['provider_invoice_id'] ?? null) && $latestInvoice !== null
                && in_array((string) $locked->pending_stripe_price_id, $latestInvoice[1], true)) {
                $snapshot['provider_invoice_id'] = $latestInvoice[0];   // Stripe's latest invoice is this change's proration invoice
            }
            $operation->forceFill([
                'status' => 'expired', 'error_category' => 'PendingUpdateExpired', 'request_snapshot' => $snapshot, 'lease_expires_at' => null,
            ])->save();
            $this->clearPending($locked);
            AdminAudit::record(null, 'billing.subscription_change_expired', 'subscription', $locked->uuid, ['operation_uuid' => $operation->uuid]);

            return true;
        }, attempts: 3);
    }

    /** Whether an immediate change is pending for this price (used to keep entitlements on the paid plan). */
    public function hasImmediateOperation(Subscription $subscription): bool
    {
        return is_string($subscription->pending_stripe_price_id)
            && $this->operation($subscription, $subscription->pending_stripe_price_id, self::SETTLEABLE, lock: false) !== null;
    }

    /**
     * The provider time of the last immediate change that was paid and applied after the given instant: a subscription event
     * older than that still shows the plan before the payment and must not take the plan back.
     */
    public function confirmedChangeAfter(Subscription $subscription, \DateTimeInterface $eventTime): ?SubscriptionOperation
    {
        return SubscriptionOperation::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('operation', self::IMMEDIATE_OPERATIONS)
            ->where('status', 'confirmed')
            ->whereNotNull('confirmed_at')
            ->get()
            ->first(function (SubscriptionOperation $operation) use ($eventTime): bool {
                $at = data_get($operation->request_snapshot, 'confirmed_provider_at');

                return is_numeric($at) && (int) $at > $eventTime->getTimestamp();
            });
    }

    private function apply(Subscription $locked, SubscriptionOperation $operation, string $invoiceId, int $paidAt): bool
    {
        $snapshot = $operation->request_snapshot;
        $snapshot['confirmed_provider_at'] = $paidAt;
        $locked->forceFill([
            'plan' => PlanTier::from($snapshot['target_plan']),
            'interval' => BillingInterval::from($snapshot['target_interval']),
            'stripe_price_id' => $snapshot['target_stripe_price_id'],
            'pricing_version' => $snapshot['target_pricing_version'],
            'amount' => $snapshot['target_amount'],
            'currency' => $snapshot['target_currency'],
        ])->save();
        $this->clearPending($locked);
        $operation->forceFill(['status' => 'confirmed', 'confirmed_at' => now(), 'request_snapshot' => $snapshot, 'lease_expires_at' => null])->save();
        AdminAudit::record(null, 'billing.subscription_change_confirmed', 'subscription', $locked->uuid, [
            'operation' => $operation->operation,
            'stripe_invoice' => $invoiceId,
        ]);

        return true;
    }

    private function clearPending(Subscription $locked): void
    {
        $locked->forceFill([
            'pending_plan' => null,
            'pending_interval' => null,
            'pending_stripe_price_id' => null,
            'pending_pricing_version' => null,
            'pending_change_effective_at' => null,
            'pending_change_status' => null,
        ])->save();
    }

    /** @param  list<string>  $statuses */
    private function operation(Subscription $subscription, string $targetPriceId, array $statuses, bool $lock = true): ?SubscriptionOperation
    {
        return SubscriptionOperation::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('operation', self::IMMEDIATE_OPERATIONS)
            ->whereIn('status', $statuses)
            ->whereJsonContains('request_snapshot->target_stripe_price_id', $targetPriceId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->latest('id')
            ->first();
    }

    /**
     * The closed attempt that recorded exactly this Stripe invoice (any of several attempts for the same price).
     *
     * @param  list<string>  $priceIds
     * @param  list<string>  $statuses
     */
    private function closedOperationFor(Subscription $locked, array $priceIds, string $invoiceId, array $statuses): ?SubscriptionOperation
    {
        return SubscriptionOperation::query()
            ->where('subscription_id', $locked->id)
            ->whereIn('operation', self::IMMEDIATE_OPERATIONS)
            ->whereIn('status', $statuses)
            ->lockForUpdate()
            ->latest('id')
            ->get()
            ->first(fn (SubscriptionOperation $operation): bool => in_array(data_get($operation->request_snapshot, 'target_stripe_price_id'), $priceIds, true)
                && data_get($operation->request_snapshot, 'provider_invoice_id') === $invoiceId);
    }

    /** A later immediate or scheduled change was already settled: applying this older attempt now would put the plan behind Stripe. */
    private function laterChangeSettled(Subscription $locked, SubscriptionOperation $late): bool
    {
        return SubscriptionOperation::query()
            ->where('subscription_id', $locked->id)
            ->where('id', '>', $late->id)
            ->whereIn('operation', [...self::IMMEDIATE_OPERATIONS, 'downgrade'])
            ->where('status', 'confirmed')
            ->exists();
    }

    /** A real payment that cannot be applied as the upgrade is recorded once for an operator. */
    private function noteUnappliedLatePayment(Subscription $locked, SubscriptionOperation $operation, string $invoiceId, string $reason): void
    {
        if (data_get($operation->request_snapshot, 'late_payment_noted_invoice') === $invoiceId) {
            return;
        }
        $snapshot = $operation->request_snapshot;
        $snapshot['late_payment_noted_invoice'] = $invoiceId;
        $operation->forceFill(['request_snapshot' => $snapshot])->save();
        AdminAudit::record(null, 'billing.subscription_change_late_payment_unapplied', 'subscription', $locked->uuid, [
            'operation_uuid' => $operation->uuid,
            'stripe_invoice' => $invoiceId,
            'reason' => $reason,
        ]);
    }

    /** A recorded Stripe invoice id of the operation must be the one being settled. */
    private function sameInvoice(SubscriptionOperation $operation, string $invoiceId): bool
    {
        $expected = data_get($operation->request_snapshot, 'provider_invoice_id');

        return ! is_string($expected) || $expected === '' || hash_equals($expected, $invoiceId);
    }

    /** @return array{0: ?string, 1: list<string>} */
    private function identify(object $invoice): array
    {
        if (($invoice->billing_reason ?? null) !== 'subscription_update') {
            return [null, []];
        }
        $invoiceId = $invoice->id ?? null;
        $priceIds = $this->invoicePriceIds($invoice);
        if (! is_string($invoiceId) || $invoiceId === '' || $priceIds === []) {
            return [null, []];
        }

        return [$invoiceId, $priceIds];
    }

    private function providerPaidAt(object $invoice): int
    {
        $paidAt = $invoice->status_transitions->paid_at ?? null;

        return is_numeric($paidAt) ? (int) $paidAt : now()->timestamp;
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
