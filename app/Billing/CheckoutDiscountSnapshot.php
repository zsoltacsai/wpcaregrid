<?php

namespace App\Billing;

use App\Models\Promotion;

/** Resolve a signed Checkout Session's applied promotion against server-held Stripe identifiers. */
final class CheckoutDiscountSnapshot
{
    /**
     * @return array<string, mixed>|null Null means Stripe confirmed no discount.
     */
    public function fromSession(object $session, ?Promotion $promotion): ?array
    {
        $data = $this->arrayOf($session);
        if (! array_key_exists('discounts', $data)) {
            return null;
        }

        $discounts = $data['discounts'];
        if (is_array($discounts) && isset($discounts['data']) && is_array($discounts['data'])) {
            $discounts = $discounts['data'];
        }
        if (! is_array($discounts) || $discounts === []) {
            return null;
        }

        $discount = count($discounts) === 1 ? $this->arrayOfValue(reset($discounts)) : [];
        $promotionCodeId = $this->idOf($discount['promotion_code'] ?? null);
        $couponId = $this->idOf($discount['coupon'] ?? null);
        $promotionMatches = $promotion !== null
            && (($promotionCodeId !== null && $promotionCodeId === $promotion->stripe_promotion_code_id)
                || ($couponId !== null && $couponId === $promotion->stripe_coupon_id));

        if ($promotionCodeId !== null && $promotionCodeId !== $promotion?->stripe_promotion_code_id) {
            $promotionMatches = false;
        }
        if ($couponId !== null && $couponId !== $promotion?->stripe_coupon_id) {
            $promotionMatches = false;
        }

        $amountDiscount = $this->integer($data['total_details']['amount_discount'] ?? null);
        $subtotal = $this->integer($data['amount_subtotal'] ?? null);
        $sessionCurrency = isset($data['currency']) && is_string($data['currency']) ? strtoupper($data['currency']) : null;
        if (count($discounts) !== 1 || ! $promotionMatches || $amountDiscount === null || $amountDiscount <= 0
            || ($subtotal !== null && $amountDiscount > $subtotal)
            || ($promotion?->discount_type === 'fixed' && $sessionCurrency !== strtoupper((string) $promotion->currency))) {
            return $this->unknown($promotionCodeId, $couponId, $amountDiscount);
        }

        $type = $promotion->discount_type;
        $duration = $promotion->duration;
        $percent = $type === 'percent' ? Promotion::parsePercent((string) $promotion->percent_off) : null;
        $amount = $type === 'fixed' ? $this->integer($promotion->amount_off) : null;
        $currency = is_string($promotion->currency) ? strtoupper($promotion->currency) : null;

        if (($type === 'percent' && $percent === null)
            || ($type === 'fixed' && ($amount === null || $currency === null))
            || ! in_array($duration, ['once', 'forever', 'repeating'], true)) {
            return $this->unknown($promotionCodeId, $couponId, $amountDiscount);
        }

        return [
            'initial_discount_type' => $type,
            'initial_discount_percent' => $percent,
            'initial_discount_amount' => $amount,
            'initial_discount_currency' => $currency,
            'initial_discount_duration' => $duration,
            'initial_discount_duration_months' => $duration === 'repeating' ? $promotion->duration_months : null,
            'initial_invoice_discount_total' => $amountDiscount,
            'initial_stripe_coupon_id' => $couponId ?? $promotion->stripe_coupon_id,
            'initial_stripe_promotion_code_id' => $promotionCodeId ?? $promotion->stripe_promotion_code_id,
            'initial_stripe_promotion_code' => $promotion->code,
            'initial_discount_snapshot_confirmed_at' => isset($data['created']) && is_numeric($data['created'])
                ? now()->setTimestamp((int) $data['created'])
                : now(),
        ];
    }

    /** @return array<string, mixed> */
    private function unknown(?string $promotionCodeId, ?string $couponId, ?int $amountDiscount): array
    {
        return [
            'initial_discount_type' => 'unknown',
            'initial_discount_percent' => null,
            'initial_discount_amount' => null,
            'initial_discount_currency' => null,
            'initial_discount_duration' => null,
            'initial_discount_duration_months' => null,
            'initial_invoice_discount_total' => $amountDiscount,
            'initial_stripe_coupon_id' => $couponId,
            'initial_stripe_promotion_code_id' => $promotionCodeId,
            'initial_stripe_promotion_code' => null,
            'initial_discount_snapshot_confirmed_at' => now(),
        ];
    }

    private function idOf(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        $value = $this->arrayOfValue($value);

        return is_string($value['id'] ?? null) ? $value['id'] : null;
    }

    /** @return array<string, mixed> */
    private function arrayOfValue(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? $value : [];
    }

    /** @return array<string, mixed> */
    private function arrayOf(object $value): array
    {
        return method_exists($value, 'toArray') ? $value->toArray() : get_object_vars($value);
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
}
