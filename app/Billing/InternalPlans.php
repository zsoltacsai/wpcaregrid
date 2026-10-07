<?php

namespace App\Billing;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Owner\AdminAudit;
use App\Pricing\ProductType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns, changes and ends internal plans (PROBONO) for a customer. Only
 * called from the owner area. An internal plan is an ordinary Subscription
 * row without Stripe, a public tier or a price (docs/PROBONO.md); the rest of
 * the system reads it through Subscription::grantsAccess() and the explicit
 * capabilities of InternalPlan.
 */
final class InternalPlans
{
    public function assign(Customer $customer, InternalPlan $plan, ProductType $product, ?Carbon $startsAt, ?Carbon $endsAt, ?string $note, User $actor): Subscription
    {
        $this->validateWindow($startsAt, $endsAt);

        if (! $customer->active) {
            throw ValidationException::withMessages(['customer' => __('owner.probono.errors.customer_inactive')]);
        }

        return DB::transaction(function () use ($customer, $plan, $product, $startsAt, $endsAt, $note, $actor) {
            // One spare plan at a time: each plan covers one website, and this also stops a double submit.
            $spare = Subscription::where('customer_id', $customer->id)
                ->where('internal_plan', $plan->value)
                ->whereIn('status', [SubscriptionStatus::Pending->value, SubscriptionStatus::Active->value])
                ->whereDoesntHave('website')
                ->lockForUpdate()
                ->exists();

            if ($spare) {
                throw ValidationException::withMessages(['customer' => __('owner.probono.errors.spare_exists', ['plan' => $plan->label()])]);
            }

            $started = $startsAt === null || $startsAt->lte(now());

            $subscription = Subscription::create([
                'billing_source' => 'internal_probono',
                'customer_id' => $customer->id,
                'internal_plan' => $plan,
                'product_type' => $product,
                'status' => $started ? SubscriptionStatus::Active : SubscriptionStatus::Pending,
                'activated_at' => $started ? now() : null,
                'internal_starts_at' => $startsAt,
                'internal_ends_at' => $endsAt,
                'internal_note' => $note,
                'assigned_by_user_id' => $actor->id,
            ]);

            AdminAudit::record($actor, 'probono.assigned', 'subscription', $subscription->uuid, [
                'customer' => $customer->uuid,
                'plan' => $plan->value,
                'product_type' => $product->value,
                'starts_at' => $startsAt?->toIso8601String(),
                'ends_at' => $endsAt?->toIso8601String(),
            ]);

            return $subscription;
        });
    }

    /** Change the dates and the internal note of an open internal plan. */
    public function update(Subscription $subscription, ?Carbon $startsAt, ?Carbon $endsAt, ?string $note, User $actor): Subscription
    {
        $this->ensureInternalAndOpen($subscription);
        $this->validateWindow($startsAt, $endsAt);

        return DB::transaction(function () use ($subscription, $startsAt, $endsAt, $note, $actor) {
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $this->ensureInternalAndOpen($subscription);

            $subscription->fill(['internal_starts_at' => $startsAt, 'internal_ends_at' => $endsAt, 'internal_note' => $note]);
            $changed = array_values(array_intersect(array_keys($subscription->getDirty()), ['internal_starts_at', 'internal_ends_at', 'internal_note']));

            if ($changed !== []) {
                // A start date moved into the future puts an active plan back to pending, and vice versa.
                $started = $startsAt === null || $startsAt->lte(now());
                $subscription->status = $started ? SubscriptionStatus::Active : SubscriptionStatus::Pending;
                if ($started && ! $subscription->activated_at) {
                    $subscription->activated_at = now();
                }
                $subscription->save();

                AdminAudit::record($actor, 'probono.changed', 'subscription', $subscription->uuid, ['fields' => $changed]);
            }

            return $subscription;
        });
    }

    /** End the plan now. The customer keeps their website record; service operations stop. */
    public function end(Subscription $subscription, User $actor): Subscription
    {
        $this->ensureInternalAndOpen($subscription);

        return DB::transaction(function () use ($subscription, $actor) {
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $this->ensureInternalAndOpen($subscription);

            $subscription->forceFill([
                'status' => SubscriptionStatus::Canceled,
                'canceled_at' => now(),
                'ended_at' => now(),
                'internal_ends_at' => $subscription->internal_ends_at?->lt(now()) ? $subscription->internal_ends_at : now(),
            ])->save();

            AdminAudit::record($actor, 'probono.ended', 'subscription', $subscription->uuid, ['customer' => $subscription->customer?->uuid]);

            return $subscription;
        });
    }

    /**
     * Start plans whose start date has come and end plans whose end date has
     * passed. Safe to repeat; run hourly (routes/console.php).
     *
     * @return array{started: int, ended: int}
     */
    public function sync(): array
    {
        $started = $ended = 0;

        Subscription::whereNotNull('internal_plan')
            ->where('status', SubscriptionStatus::Pending->value)
            ->where('internal_starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('internal_ends_at')->orWhere('internal_ends_at', '>', now()))
            ->each(function (Subscription $subscription) use (&$started) {
                $subscription->forceFill(['status' => SubscriptionStatus::Active, 'activated_at' => $subscription->activated_at ?? now()])->save();
                AdminAudit::record(null, 'probono.started', 'subscription', $subscription->uuid);
                $started++;
            });

        Subscription::whereNotNull('internal_plan')
            ->whereIn('status', [SubscriptionStatus::Pending->value, SubscriptionStatus::Active->value])
            ->whereNotNull('internal_ends_at')
            ->where('internal_ends_at', '<=', now())
            ->each(function (Subscription $subscription) use (&$ended) {
                $subscription->forceFill(['status' => SubscriptionStatus::Canceled, 'ended_at' => $subscription->internal_ends_at, 'canceled_at' => $subscription->internal_ends_at])->save();
                AdminAudit::record(null, 'probono.expired', 'subscription', $subscription->uuid);
                $ended++;
            });

        return ['started' => $started, 'ended' => $ended];
    }

    private function ensureInternalAndOpen(Subscription $subscription): void
    {
        if (! $subscription->isInternal() || ! in_array($subscription->status, [SubscriptionStatus::Pending, SubscriptionStatus::Active], true)) {
            throw ValidationException::withMessages(['subscription' => __('owner.probono.errors.not_open')]);
        }
    }

    private function validateWindow(?Carbon $startsAt, ?Carbon $endsAt): void
    {
        if ($endsAt !== null && $endsAt->lte(now())) {
            throw ValidationException::withMessages(['ends_at' => __('owner.probono.errors.end_in_past')]);
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt->lte($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => __('owner.probono.errors.end_before_start')]);
        }
    }
}
