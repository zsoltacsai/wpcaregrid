<?php

namespace App\Billing;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionOperation;
use App\Models\User;
use App\Owner\AdminAudit;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class SubscriptionManagement
{
    public function __construct(
        private readonly SubscriptionManagementGateway $gateway,
        private readonly PricingCatalog $catalog,
        private readonly QueueBillingEmail $billingEmails,
    ) {}

    public function changePlan(
        Subscription $subscription,
        Customer $customer,
        ProductType $productType,
        PlanTier $tier,
        BillingInterval $interval,
        ?int $userId = null,
    ): SubscriptionOperation {
        if ($subscription->customer_id !== $customer->id || $subscription->billing_source !== 'stripe' || $subscription->isInternal()) {
            throw new LogicException('This subscription cannot be changed through Stripe.');
        }
        if ($productType !== $subscription->product_type) {
            throw new LogicException('Changing the subscription product family is not supported.');
        }
        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)
            || $subscription->cancel_at_period_end) {
            throw new LogicException('This subscription is not eligible for a plan change.');
        }

        $country = $customer->latestBillingIdentity()?->country_code ?? $customer->country_code;
        if (! is_string($country) || $country === '') {
            throw new LogicException('A verified billing country is required before changing a plan.');
        }
        $target = $this->catalog->resolve(new PriceSelection($productType, $tier, $interval), $country, $subscription->currency);
        if (! $target->stripePriceId) {
            throw new LogicException('The selected plan is not available for purchase.');
        }
        if ($target->stripePriceId === $subscription->stripe_price_id) {
            throw new LogicException('The selected plan is already active.');
        }

        $currentRank = array_search($subscription->plan, PlanTier::cases(), true);
        $targetRank = array_search($tier, PlanTier::cases(), true);
        $annualToMonthly = $subscription->interval === BillingInterval::Annual && $interval === BillingInterval::Monthly;
        $scheduled = $annualToMonthly || ($targetRank !== false && $currentRank !== false && $targetRank < $currentRank);
        $effectiveAt = $scheduled ? $subscription->current_period_end : now();
        if ($scheduled && ! $effectiveAt) {
            throw new LogicException('A known renewal date is required to schedule this change.');
        }

        $operation = $scheduled ? 'downgrade' : (($subscription->interval !== $interval) ? 'billing_period_switch' : 'upgrade');
        $baseKey = hash('sha256', implode('|', [
            $subscription->uuid,
            $operation,
            $target->stripePriceId,
            // An immediate change has no date: a clock value here made every retry (even a second later) a different request.
            $scheduled ? ($effectiveAt?->utc()->format('YmdHis') ?? 'scheduled') : 'immediate',
        ]));
        // A request that comes after the earlier identical attempt was closed (paid, payment failed, expired) is a new attempt with
        // its own operation and idempotency key; while the earlier one is open, the same request keeps the same key.
        $closedBefore = SubscriptionOperation::query()
            ->where('subscription_id', $subscription->id)->where('operation', $operation)
            ->whereJsonContains('request_snapshot->target_stripe_price_id', $target->stripePriceId)
            ->whereIn('status', ['confirmed', 'payment_failed', 'expired'])->count();
        $operationKey = $closedBefore === 0 ? $baseKey : hash('sha256', $baseKey.'|attempt|'.$closedBefore);
        try {
            $row = DB::transaction(function () use ($subscription, $customer, $operation, $operationKey, $target, $effectiveAt, $scheduled, $userId): SubscriptionOperation {
                $lockedSubscription = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                if ($lockedSubscription->pending_change_status !== null) {
                    $existing = SubscriptionOperation::query()->where('operation_key', $operationKey)->first();
                    if ($existing) {
                        return $existing;
                    }
                    throw new LogicException('A subscription change is already pending confirmation.');
                }

                $operationRow = SubscriptionOperation::create([
                    'subscription_id' => $subscription->id,
                    'customer_id' => $customer->id,
                    'requested_by' => $userId,
                    'operation' => $operation,
                    'operation_key' => $operationKey,
                    'status' => 'requested',
                    'request_snapshot' => [
                        'target_plan' => $target->tier->value,
                        'target_interval' => $target->interval->value,
                        'target_stripe_price_id' => $target->stripePriceId,
                        'target_pricing_version' => $target->version,
                        'target_amount' => $target->money->amount,
                        'target_currency' => $target->money->currency->code,
                        'effective_at' => $effectiveAt?->toIso8601String(),
                        'scheduled' => $scheduled,
                    ],
                    'lease_expires_at' => null,
                ]);

                $lockedSubscription->forceFill([
                    'pending_plan' => $target->tier->value,
                    'pending_interval' => $target->interval->value,
                    'pending_stripe_price_id' => $target->stripePriceId,
                    'pending_pricing_version' => $target->version,
                    'pending_change_effective_at' => $effectiveAt,
                    // A requested downgrade is not provider-confirmed until Stripe
                    // returns the schedule identity below.
                    'pending_change_status' => $scheduled ? 'awaiting_confirmation' : 'awaiting_payment',
                ])->save();

                return $operationRow;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $row = SubscriptionOperation::where('operation_key', $operationKey)->firstOrFail();
        }

        // An immediate change whose Stripe call had an unknown outcome is replayed with the same idempotency key: Stripe returns the
        // original result if it had processed it, otherwise it applies the change now. The replay is bounded; after that an
        // operator has to settle it (it is surfaced by the audit trail and the billing monitoring), never guessed.
        $replayable = ['requested', 'processing'];
        if (! $scheduled && $row->status === 'reconciliation_required') {
            $this->assertReplayAllowed($row, $subscription);
            $replayable[] = 'reconciliation_required';
        }
        if (! in_array($row->status, $replayable, true)) {
            if ($scheduled && in_array($row->status, ['awaiting_confirmation', 'confirmed'], true)
                && is_string(data_get($row->request_snapshot, 'provider_schedule_id'))) {
                $this->queueScheduledChangeEmail($row, $subscription, $customer, $productType, $tier, $interval, $effectiveAt);
            }

            return $row;
        }

        $claimed = DB::table('subscription_operations')->where('id', $row->id)
            ->whereIn('status', $replayable)
            ->where(function ($query) {
                $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now());
            })
            ->update(['status' => 'processing', 'lease_expires_at' => now()->addMinutes(2), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return $row->refresh();
        }
        if ($row->status === 'reconciliation_required') {
            $this->countReplay($row);
        }

        try {
            $response = $this->gateway->changePrice(
                $subscription,
                (string) $target->stripePriceId,
                ! $scheduled,
                (string) $row->uuid,
                'subscription-change-'.$row->uuid,
            );
            if ($scheduled && (! is_string($response['schedule_id'] ?? null) || $response['schedule_id'] === '')) {
                throw new \UnexpectedValueException('Stripe did not confirm the requested subscription schedule.');
            }
            DB::transaction(function () use ($row, $response): void {
                $operation = SubscriptionOperation::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                $snapshot = $operation->request_snapshot;
                if (is_string($response['invoice_id'] ?? null) && $response['invoice_id'] !== '') {
                    $snapshot['provider_invoice_id'] = $response['invoice_id'];
                }
                if (is_string($response['schedule_id'] ?? null) && $response['schedule_id'] !== '') {
                    $snapshot['provider_schedule_id'] = $response['schedule_id'];
                }

                // A webhook that settled the change while the request was in flight (paid, failed, expired) is final: the late
                // response never takes the operation back to "awaiting".
                $operation->forceFill([
                    'request_snapshot' => $snapshot,
                    'status' => in_array($operation->status, ['confirmed', 'payment_failed', 'expired'], true) ? $operation->status : 'awaiting_confirmation',
                    'provider_request_id' => $response['request_id'],
                    'submitted_at' => $operation->submitted_at ?? now(),
                    'lease_expires_at' => null,
                ])->save();
                // Stripe has now confirmed an earlier unknown request: the payment is awaited, as after a first successful request.
                if (! (data_get($snapshot, 'scheduled') === true) && $operation->status === 'awaiting_confirmation') {
                    Subscription::query()->whereKey($operation->subscription_id)
                        ->where('pending_change_status', 'reconciliation_required')
                        ->where('pending_stripe_price_id', data_get($snapshot, 'target_stripe_price_id'))
                        ->update(['pending_change_status' => 'awaiting_payment', 'updated_at' => now()]);
                }
                if (is_string($response['schedule_id'] ?? null) && $response['schedule_id'] !== '') {
                    Subscription::query()->whereKey($operation->subscription_id)
                        ->where('pending_change_status', 'awaiting_confirmation')
                        ->where('pending_stripe_price_id', data_get($operation->request_snapshot, 'target_stripe_price_id'))
                        ->update(['pending_change_status' => 'scheduled', 'updated_at' => now()]);
                }
            }, attempts: 3);
            AdminAudit::record($userId ? User::find($userId) : null, 'billing.subscription_change_requested', 'subscription', $subscription->uuid, [
                'operation' => $operation,
                'operation_uuid' => $row->uuid,
                'target_plan' => $target->tier->value,
                'target_interval' => $target->interval->value,
            ]);
        } catch (Throwable $exception) {
            $confirmedWhileRequestFailed = DB::transaction(function () use ($row, $subscription, $target, $effectiveAt, $exception): bool {
                $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                $lockedOperation = SubscriptionOperation::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                if ($lockedOperation->status === 'confirmed') {
                    return true;
                }

                $lockedOperation->forceFill([
                    'status' => 'reconciliation_required',
                    'error_category' => class_basename($exception),
                    'lease_expires_at' => null,
                ])->save();

                if ($locked->pending_stripe_price_id === $target->stripePriceId) {
                    $locked->forceFill([
                        'pending_change_effective_at' => $effectiveAt,
                        'pending_change_status' => 'reconciliation_required',
                    ])->save();
                }

                return false;
            }, attempts: 3);

            if (! $confirmedWhileRequestFailed) {
                AdminAudit::record($userId ? User::find($userId) : null, 'billing.subscription_change_failed', 'subscription', $subscription->uuid, [
                    'operation' => $operation,
                    'operation_uuid' => $row->uuid,
                    'error_category' => class_basename($exception),
                ]);
                throw $exception;
            }

            return $row->refresh();
        }

        if ($scheduled) {
            $this->queueScheduledChangeEmail($row, $subscription, $customer, $productType, $tier, $interval, $effectiveAt);
        }

        return $row->refresh();
    }

    /** Automatic replays of one unknown-outcome request, after the first attempt. */
    private const MAX_REPLAYS = 3;

    private function assertReplayAllowed(SubscriptionOperation $row, Subscription $subscription): void
    {
        if ((int) data_get($row->request_snapshot, 'replays', 0) < self::MAX_REPLAYS) {
            return;
        }
        if (data_get($row->request_snapshot, 'operator_required_at') === null) {
            DB::transaction(function () use ($row, $subscription): void {
                $locked = SubscriptionOperation::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                if (data_get($locked->request_snapshot, 'operator_required_at') !== null) {
                    return;
                }
                $snapshot = $locked->request_snapshot;
                $snapshot['operator_required_at'] = now()->toIso8601String();
                $locked->forceFill(['request_snapshot' => $snapshot])->save();
                AdminAudit::record(null, 'billing.subscription_change_operator_required', 'subscription', $subscription->uuid, [
                    'operation_uuid' => $locked->uuid,
                    'replays' => (int) data_get($snapshot, 'replays', 0),
                ]);
            }, attempts: 3);
        }

        throw new LogicException('This plan change could not be confirmed with Stripe automatically and needs an operator to settle it.');
    }

    private function countReplay(SubscriptionOperation $row): void
    {
        DB::transaction(function () use ($row): void {
            $locked = SubscriptionOperation::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $snapshot = $locked->request_snapshot;
            $snapshot['replays'] = (int) data_get($snapshot, 'replays', 0) + 1;
            $locked->forceFill(['request_snapshot' => $snapshot])->save();
        }, attempts: 3);
    }

    private function queueScheduledChangeEmail(
        SubscriptionOperation $operation,
        Subscription $subscription,
        Customer $customer,
        ProductType $productType,
        PlanTier $tier,
        BillingInterval $interval,
        ?Carbon $effectiveAt,
    ): void {
        $this->billingEmails->enqueue(
            'subscription:'.$subscription->uuid.':change-scheduled:'.$operation->uuid,
            'downgrade_scheduled',
            $customer,
            $subscription,
            payload: [
                'plan_name' => $this->catalog->plan($productType, $tier)->name,
                'interval' => $interval->value,
                'effective_at' => $effectiveAt?->toIso8601String(),
            ],
        );
    }

    public function cancelAtPeriodEnd(Subscription $subscription, Customer $customer, ?int $userId = null): SubscriptionOperation
    {
        return $this->changeCancellation($subscription, $customer, true, $userId);
    }

    public function reactivate(Subscription $subscription, Customer $customer, ?int $userId = null): SubscriptionOperation
    {
        return $this->changeCancellation($subscription, $customer, false, $userId);
    }

    private function changeCancellation(Subscription $subscription, Customer $customer, bool $cancel, ?int $userId): SubscriptionOperation
    {
        if ($subscription->customer_id !== $customer->id || $subscription->billing_source !== 'stripe' || $subscription->isInternal()) {
            throw new LogicException('This subscription cannot be managed through Stripe.');
        }

        if ($cancel && ($subscription->cancel_at_period_end || ! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue], true))) {
            throw new LogicException('The subscription is not eligible for cancellation.');
        }
        if (! $cancel && (! $subscription->cancel_at_period_end || ! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue], true))) {
            throw new LogicException('The subscription is not eligible for reactivation.');
        }

        $operation = $cancel ? 'cancel_at_period_end' : 'reactivate';
        $period = $subscription->current_period_end?->utc()->format('YmdHis') ?? 'period-unknown';
        $latest = SubscriptionOperation::query()->where('subscription_id', $subscription->id)->latest('id')->first();
        $sameIntent = $latest?->operation === $operation && data_get($latest->request_snapshot, 'period_end') === $period;
        $operationKey = $sameIntent
            ? $latest->operation_key
            : hash('sha256', implode('|', [$subscription->uuid, $operation, $period, $latest?->uuid ?? 'initial']));
        try {
            $row = DB::transaction(function () use ($subscription, $customer, $operation, $operationKey, $cancel, $period, $userId): SubscriptionOperation {
                $existing = SubscriptionOperation::query()->where('operation_key', $operationKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }

                return SubscriptionOperation::create([
                    'subscription_id' => $subscription->id,
                    'customer_id' => $customer->id,
                    'requested_by' => $userId,
                    'operation' => $operation,
                    'operation_key' => $operationKey,
                    'status' => 'requested',
                    'request_snapshot' => ['cancel_at_period_end' => $cancel, 'period_end' => $period],
                    'lease_expires_at' => null,
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $row = SubscriptionOperation::where('operation_key', $operationKey)->firstOrFail();
        }

        // A request whose Stripe call failed (reconciliation_required) is replayed with the same idempotency key: Stripe returns the
        // original result if it had processed it, otherwise it applies the change now. Returning the row without calling Stripe
        // would leave the customer unable to cancel (or reactivate) and the controller would still report the request as made.
        $replayable = ['requested', 'processing', 'reconciliation_required'];
        if (! in_array($row->status, $replayable, true)) {
            return $row;
        }

        $claimed = DB::table('subscription_operations')->where('id', $row->id)
            ->whereIn('status', $replayable)
            ->where(function ($query) {
                $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now());
            })
            ->update(['status' => 'processing', 'lease_expires_at' => now()->addMinutes(2), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return $row->refresh();
        }

        try {
            $response = $this->gateway->setCancelAtPeriodEnd($subscription, $cancel, 'subscription-operation-'.$row->uuid);
            $row->forceFill([
                'status' => 'awaiting_confirmation',
                'provider_request_id' => $response['request_id'],
                'submitted_at' => now(),
                'lease_expires_at' => null,
            ])->save();
            AdminAudit::record($userId ? User::find($userId) : null, $cancel ? 'billing.cancellation_scheduled' : 'billing.reactivation_requested', 'subscription', $subscription->uuid, [
                'operation_uuid' => $row->uuid,
                'effective_at' => $subscription->current_period_end?->toIso8601String(),
            ]);
        } catch (Throwable $exception) {
            // Refreshed first: the claim above changed the row with a query, so this instance still holds the old lease (null)
            // and would not write the release; the lease would block the retry until it expires.
            $row->refresh()->forceFill([
                'status' => 'reconciliation_required',
                'error_category' => class_basename($exception),
                'lease_expires_at' => null,
            ])->save();
            throw $exception;
        }

        return $row->refresh();
    }
}
