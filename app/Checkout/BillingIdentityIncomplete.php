<?php

namespace App\Checkout;

use RuntimeException;

final class BillingIdentityIncomplete extends RuntimeException
{
    /** @param list<string> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Billing identity is incomplete.');
    }
}
