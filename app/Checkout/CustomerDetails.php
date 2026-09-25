<?php

namespace App\Checkout;

/**
 * Who is buying. Only the first four fields are collected today; the rest
 * are placeholders for company billing in a later milestone.
 */
final readonly class CustomerDetails
{
    /** @param  array<string, string>|null  $billingAddress */
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public string $country,
        public string $customerType = 'individual',
        public ?string $companyName = null,
        public ?string $taxId = null,
        public ?array $billingAddress = null,
    ) {}
}
