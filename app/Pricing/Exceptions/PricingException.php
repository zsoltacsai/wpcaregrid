<?php

namespace App\Pricing\Exceptions;

use RuntimeException;

/**
 * A selection that cannot be sold. Each subclass maps to a customer-facing
 * message in lang/{locale}/checkout.php under `errors`.
 */
abstract class PricingException extends RuntimeException
{
    abstract public function reason(): string;

    public function customerMessage(): string
    {
        return __('checkout.errors.'.$this->reason());
    }
}
