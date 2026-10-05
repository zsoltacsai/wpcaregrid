<?php

namespace App\Checkout;

/**
 * The payment provider as the coupon management needs it. Stripe is the only
 * implementation; the interface exists so tests never call Stripe.
 *
 * A Stripe coupon cannot be changed after it is created, so only the
 * percentage, the duration and the code (all fixed) go to Stripe. Everything
 * else (validity window, usage limits, restrictions) is checked by us before
 * a Checkout Session is created (Promotion::ineligibilityReason).
 */
interface PromotionGateway
{
    /**
     * Create the coupon and its customer-facing promotion code.
     *
     * @param  string  $percentOff  "10", "12.5" or "100"
     * @param  string  $duration  once | repeating | forever
     * @return array{coupon: string, promotion_code: string}
     *
     * @throws PromotionGatewayException
     */
    public function create(string $code, string $percentOff, string $duration, ?int $months, string $name): array;

    /** @throws PromotionGatewayException */
    public function setActive(string $promotionCodeId, bool $active): void;

    /** Deactivate the promotion code and delete the coupon (an unused coupon only). */
    public function remove(?string $couponId, ?string $promotionCodeId): void;
}
