<?php

namespace App\Pricing;

final readonly class ProductLine
{
    /** @param  list<Plan>  $plans */
    public function __construct(
        public string $key,
        public string $label,
        public string $summary,
        public array $plans,
    ) {}
}
