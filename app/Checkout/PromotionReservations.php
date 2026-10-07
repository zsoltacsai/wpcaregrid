<?php

namespace App\Checkout;

use App\Models\CheckoutIntent;
use App\Models\Promotion;
use App\Models\PromotionReservation;
use App\Pricing\Price;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Serializes coupon claims on the promotion row so limits include open checkouts. */
final class PromotionReservations
{
    public function reserve(Promotion $promotion, CheckoutIntent $intent, Price $price, string $email, ?int $customerId): void
    {
        DB::transaction(function () use ($promotion, $intent, $price, $email, $customerId) {
            $locked = Promotion::query()->whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            if ((int) $intent->promotion_id !== (int) $locked->id) {
                throw new PromotionRejected('not_applicable');
            }

            $now = now();

            $existing = PromotionReservation::query()->where('checkout_intent_id', $intent->id)->lockForUpdate()->first();
            if ($existing?->status === 'reserved') {
                if ((int) $existing->promotion_id !== (int) $locked->id
                    || ! hash_equals($existing->email_hash, $this->emailHash($email))
                    || ($existing->customer_id && $customerId && (int) $existing->customer_id !== $customerId)) {
                    throw new PromotionRejected('already_used');
                }

                return;
            }
            if ($existing) {
                throw new PromotionRejected('used_up');
            }

            if ($reason = $locked->ineligibilityReason($price, $now)) {
                throw new PromotionRejected($reason);
            }

            $emailHash = $this->emailHash($email);
            // A local timestamp cannot prove a Stripe Checkout Session is
            // unpayable: Stripe may have created it just before the process
            // crashed, or its completion webhook may be delayed. Only a
            // signed terminal event or a definite pre-session failure may
            // release capacity. Count every still-reserved row, even after
            // its advisory `reserved_until` timestamp.
            //
            // Under MySQL REPEATABLE READ, this must remain a current/locking
            // read so the capacity decision sees commits made by the
            // transaction that held the promotion row lock immediately
            // before us.
            $activeReservations = PromotionReservation::query()
                ->where('promotion_id', $locked->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->get();

            if ($locked->max_redemptions !== null
                && $locked->times_redeemed + $activeReservations->count() >= $locked->max_redemptions) {
                throw new PromotionRejected('used_up');
            }

            if ($locked->max_redemptions_per_customer !== null) {
                $completed = CheckoutIntent::query()
                    ->where('promotion_id', $locked->id)
                    ->where('status', CheckoutStatus::Completed->value)
                    ->where(fn ($query) => $query->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
                        ->when($customerId, fn ($query) => $query->orWhere('customer_id', $customerId)))
                    ->lockForUpdate()
                    ->get(['id'])
                    ->count();
                $reservedByCustomer = $activeReservations
                    ->filter(fn (PromotionReservation $reservation) => hash_equals($reservation->email_hash, $emailHash)
                        || ($customerId && $reservation->customer_id === $customerId))
                    ->count();

                if ($completed + $reservedByCustomer >= $locked->max_redemptions_per_customer) {
                    throw new PromotionRejected('already_used');
                }
            }

            PromotionReservation::create([
                'promotion_id' => $locked->id,
                'checkout_intent_id' => $intent->id,
                'customer_id' => $customerId,
                'email_hash' => $emailHash,
                'status' => 'reserved',
                'reserved_until' => $now->addHours(24),
            ]);
        }, attempts: 3);
    }

    public function setExpiry(CheckoutIntent $intent, mixed $expiresAt): void
    {
        if (! $expiresAt instanceof Carbon) {
            return;
        }

        PromotionReservation::query()
            ->where('checkout_intent_id', $intent->id)
            ->where('status', 'reserved')
            ->update(['reserved_until' => $expiresAt, 'updated_at' => now()]);
    }

    /** Consume a reservation once. The legacy branch supports checkouts created before reservations existed. */
    public function consume(CheckoutIntent $intent): bool
    {
        if (! $intent->promotion_id || $intent->status !== CheckoutStatus::Completed) {
            return false;
        }

        return DB::transaction(function () use ($intent) {
            $promotion = Promotion::query()->whereKey($intent->promotion_id)->lockForUpdate()->first();
            if (! $promotion) {
                return false;
            }

            $reservation = PromotionReservation::query()->where('checkout_intent_id', $intent->id)->lockForUpdate()->first();
            if ($reservation && ($reservation->promotion_id !== $promotion->id || $reservation->status !== 'reserved')) {
                return false;
            }
            if ($reservation && ! hash_equals($reservation->email_hash, $this->emailHash($intent->email))) {
                return false;
            }
            if ($reservation?->customer_id && $intent->customer_id && $reservation->customer_id !== $intent->customer_id) {
                return false;
            }

            if ($reservation) {
                $reservation->update(['status' => 'consumed', 'consumed_at' => now(), 'reserved_until' => null]);
            } else {
                PromotionReservation::create([
                    'promotion_id' => $promotion->id,
                    'checkout_intent_id' => $intent->id,
                    'customer_id' => $intent->customer_id,
                    'email_hash' => $this->emailHash($intent->email),
                    'status' => 'consumed',
                    'consumed_at' => now(),
                ]);
            }

            $promotion->increment('times_redeemed');

            return true;
        });
    }

    public function release(CheckoutIntent $intent): bool
    {
        return DB::transaction(function () use ($intent) {
            $reservation = PromotionReservation::query()->where('checkout_intent_id', $intent->id)->first();
            if (! $reservation) {
                return false;
            }

            // Use the same lock order as reserve/consume so a signed expiry
            // cannot race a late successful payment and consume.
            $promotion = Promotion::query()->whereKey($reservation->promotion_id)->lockForUpdate()->first();
            if (! $promotion) {
                return false;
            }

            return PromotionReservation::query()
                ->whereKey($reservation->id)
                ->where('status', 'reserved')
                ->update(['status' => 'released', 'released_at' => now(), 'reserved_until' => null]) > 0;
        });
    }

    public function activeCount(Promotion $promotion): int
    {
        return PromotionReservation::query()
            ->where('promotion_id', $promotion->id)
            ->where('status', 'reserved')
            ->count();
    }

    private function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }
}
