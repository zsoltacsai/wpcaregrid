<?php

namespace App\Checkout;

use RuntimeException;

/** A promotion code that cannot be used; the reason maps to a customer message. */
final class PromotionRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Promotion code rejected: {$reason}");
    }

    public function customerMessage(): string
    {
        return __("checkout.promotion_errors.{$this->reason}");
    }
}
