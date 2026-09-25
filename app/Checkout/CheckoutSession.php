<?php

namespace App\Checkout;

use Illuminate\Support\Carbon;

final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public string $url,
        public Carbon $expiresAt,
    ) {}
}
