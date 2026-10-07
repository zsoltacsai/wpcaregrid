<?php

namespace App\Checkout;

use App\Billing\DiscountAmounts;
use App\Models\Promotion;
use App\Pricing\Money;
use App\Pricing\Price;

/** An informative estimate; the signed Stripe invoice remains billing authority. */
final readonly class PromotionPreview
{
    public function __construct(
        public Price $price,
        public ?Promotion $promotion,
        public int $initialDiscount,
        public int $initialTotal,
        public ?int $futureDiscount,
        public ?int $futureTotal,
    ) {}

    public static function from(Price $price, ?Promotion $promotion): self
    {
        $base = $price->money->amount;
        $discount = 0;

        if ($promotion?->discount_type === 'percent') {
            $percent = Promotion::parsePercent($promotion->percent_off);
            if ($percent === null) {
                throw new PromotionRejected('not_applicable');
            }
            $discount = $base - DiscountAmounts::afterPercent($base, $percent);
        } elseif ($promotion?->discount_type === 'fixed') {
            if ($promotion->currency !== $price->money->currency->code || ! is_int($promotion->amount_off) || $promotion->amount_off < 0) {
                throw new PromotionRejected('not_applicable');
            }
            $discount = min($base, $promotion->amount_off);
        } elseif ($promotion !== null) {
            throw new PromotionRejected('not_applicable');
        }

        if ($promotion !== null && ! in_array($promotion->duration, ['once', 'forever', 'repeating'], true)) {
            throw new PromotionRejected('not_applicable');
        }

        // Legacy repeating coupons can expire before the next cycle. Without
        // Stripe's exact end date, a definite future renewal would mislead.
        $futureDiscount = $promotion?->duration === 'repeating' ? null : ($promotion?->duration === 'forever' ? $discount : 0);

        return new self($price, $promotion, $discount, $base - $discount, $futureDiscount, $futureDiscount === null ? null : $base - $futureDiscount);
    }

    /** No provider IDs, internal IDs, counts or customer information leave this boundary. */
    public function toArray(): array
    {
        $currency = $this->price->money->currency;
        $base = $this->price->money->amount;

        return [
            'base_amount' => $base,
            'initial_discount_amount' => $this->initialDiscount,
            'initial_total' => $this->initialTotal,
            'future_discount_amount' => $this->futureDiscount,
            'future_total' => $this->futureTotal,
            'discount_type' => $this->promotion?->discount_type,
            'discount_percent' => $this->promotion?->discount_type === 'percent' ? $this->promotion->percentLabel() : null,
            'discount_duration' => $this->promotion?->duration,
            'currency' => $currency->code,
            'interval' => $this->price->interval->value,
            'formatted' => [
                'base' => (new Money($base, $currency))->format(),
                'initial_discount' => (new Money($this->initialDiscount, $currency))->format(),
                'initial_total' => (new Money($this->initialTotal, $currency))->format(),
                'future_total' => $this->futureTotal === null ? null : (new Money($this->futureTotal, $currency))->format(),
            ],
        ];
    }
}
