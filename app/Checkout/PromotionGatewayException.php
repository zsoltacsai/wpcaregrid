<?php

namespace App\Checkout;

use RuntimeException;

/** The payment provider could not create or change a coupon; the reason maps to an owner message. */
final class PromotionGatewayException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Promotion gateway: {$reason}");
    }

    public function ownerMessage(): string
    {
        return __("owner.coupons.gateway_errors.{$this->reason}");
    }
}
