<?php

namespace App\Billing;

use App\Models\Promotion;
use App\Models\Subscription;

/** Extracts only billing values present in Stripe's signed subscription event. */
final class SubscriptionBillingSnapshot
{
    /** @return array<string, mixed> */
    public function fromStripe(object $stripe, ?Subscription $existing, mixed $price): array
    {
        $hasConfirmedSnapshot = $existing?->billing_snapshot_confirmed_at !== null;
        $base = $this->integer($price->unit_amount ?? null) ?? ($hasConfirmedSnapshot ? $existing?->base_price_amount : null);
        $currency = isset($price->currency) ? strtoupper((string) $price->currency) : ($hasConfirmedSnapshot ? $existing?->base_price_currency : null);
        $data = $this->arrayOf($stripe);
        $hasDiscountState = array_key_exists('discounts', $data) || array_key_exists('discount', $data);
        $discounts = $data['discounts'] ?? (isset($data['discount']) ? [$data['discount']] : null);
        if (is_array($discounts) && isset($discounts['data']) && is_array($discounts['data'])) {
            $discounts = $discounts['data'];
        }

        if (! $hasDiscountState) {
            return [
                'base_price_amount' => $base,
                'base_price_currency' => $currency,
                'current_recurring_amount' => $existing?->current_recurring_amount ?? ($existing?->discount_type ? null : $base),
                'current_recurring_currency' => $currency,
            ];
        }

        if (is_array($discounts) && count($discounts) > 1) {
            // The application creates one promotion per checkout. If an
            // external Stripe action stacks more, retain the fact of a
            // discount but never calculate or claim a partial total.
            return [
                'base_price_amount' => $base,
                'base_price_currency' => $currency,
                'discount_type' => 'unknown',
                'discount_percent' => null,
                'discount_amount' => null,
                'discount_currency' => null,
                'discount_duration' => null,
                'discount_duration_months' => null,
                'stripe_coupon_id' => null,
                'stripe_promotion_code_id' => null,
                'stripe_promotion_code' => null,
                'current_recurring_amount' => null,
                'current_recurring_currency' => $currency,
            ];
        }

        $discount = is_array($discounts) ? reset($discounts) : null;
        if (is_string($discount)) {
            // Stripe may omit expansion. Keep the recurring amount unknown
            // rather than infer a discount from the local checkout intent.
            $discount = ['id' => $discount];
        }
        if (! $discount) {
            return [
                'base_price_amount' => $base,
                'base_price_currency' => $currency,
                'discount_type' => null,
                'discount_percent' => null,
                'discount_amount' => null,
                'discount_currency' => null,
                'discount_duration' => null,
                'discount_duration_months' => null,
                'stripe_coupon_id' => null,
                'stripe_promotion_code_id' => null,
                'stripe_promotion_code' => null,
                'current_recurring_amount' => $base,
                'current_recurring_currency' => $currency,
            ];
        }

        $discount = $this->arrayOf((object) $discount);
        $source = $this->arrayOf((object) ($discount['source'] ?? []));
        $coupon = $this->arrayOf((object) ($discount['coupon'] ?? $source['coupon'] ?? []));
        $promotionId = $this->idOf($discount['promotion_code'] ?? $source['promotion_code'] ?? null);
        $couponId = $this->idOf($coupon['id'] ?? $discount['coupon'] ?? $source['coupon'] ?? null);
        $promotion = Promotion::query()
            ->when($promotionId, fn ($query) => $query->where('stripe_promotion_code_id', $promotionId))
            ->when(! $promotionId && $couponId, fn ($query) => $query->where('stripe_coupon_id', $couponId))
            ->first();
        $promotionCode = $this->value($discount['promotion_code'] ?? $source['promotion_code'] ?? null, 'code')
            ?? $promotion?->code;

        $percent = $this->decimal($coupon['percent_off'] ?? null) ?? $this->decimal($promotion?->percent_off);
        $amountOff = $this->integer($coupon['amount_off'] ?? null) ?? $promotion?->amount_off;
        $discountCurrency = isset($coupon['currency']) ? strtoupper((string) $coupon['currency']) : ($promotion?->currency ? strtoupper($promotion->currency) : null);
        $duration = $coupon['duration'] ?? $promotion?->duration;
        $durationMonths = $this->integer($coupon['duration_in_months'] ?? null) ?? $promotion?->duration_months;
        $type = $percent !== null ? 'percent' : ($amountOff !== null ? 'fixed' : null);
        $end = $this->integer($discount['end'] ?? null);
        $active = $end === null || $end > now()->timestamp;
        $recurring = null;

        if ($base !== null && $type === 'percent') {
            // A Stripe once coupon discounts only the first invoice; it is not recurring.
            $recurring = $duration === 'once' || ! $active ? $base : $this->percentAfterDiscount($base, $percent);
        } elseif ($base !== null && $type === 'fixed') {
            if ($discountCurrency === $currency) {
                $recurring = $duration === 'once' || ! $active ? $base : max(0, $base - $amountOff);
            }
        } elseif ($base !== null && $type === null) {
            $recurring = null;
        }

        return [
            'base_price_amount' => $base,
            'base_price_currency' => $currency,
            'discount_type' => $type,
            'discount_percent' => $percent,
            'discount_amount' => $amountOff,
            'discount_currency' => $discountCurrency,
            'discount_duration' => is_string($duration) ? $duration : null,
            'discount_duration_months' => $durationMonths,
            'stripe_coupon_id' => $couponId ?? ($promotion?->stripe_coupon_id),
            'stripe_promotion_code_id' => $promotionId ?? ($promotion?->stripe_promotion_code_id),
            'stripe_promotion_code' => is_string($promotionCode) ? $promotionCode : null,
            'current_recurring_amount' => $recurring,
            'current_recurring_currency' => $currency,
        ];
    }

    private function percentAfterDiscount(int $amount, string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '0');
        $hundredths = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        $factor = 10000 - $hundredths;

        // Split before multiplying so even the largest signed integer amount
        // cannot overflow PHP's integer range during percentage calculation.
        $wholeAmount = intdiv($amount, 10000);
        $remainder = $amount % 10000;

        return ($wholeAmount * $factor) + intdiv(($remainder * $factor) + 5000, 10000);
    }

    private function decimal(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $text = (string) $value;
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $matches)) {
            return null;
        }

        $hundredths = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($hundredths > 10000) {
            return null;
        }

        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $normalized = ltrim($value, '0') ?: '0';
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int) $normalized;
    }

    private function idOf(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        $array = is_object($value) ? $this->arrayOf($value) : (is_array($value) ? $value : []);

        return is_string($array['id'] ?? null) ? $array['id'] : null;
    }

    private function value(mixed $value, string $key): mixed
    {
        if (is_object($value)) {
            $value = $this->arrayOf($value);
        }

        return is_array($value) ? ($value[$key] ?? null) : null;
    }

    /** @return array<string, mixed> */
    private function arrayOf(object $value): array
    {
        if (method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return get_object_vars($value);
    }
}
