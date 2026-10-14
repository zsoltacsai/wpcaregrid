<?php

namespace App\Checkout;

/** Validated identity details persisted on the CheckoutIntent before redirecting to Stripe. */
final readonly class CustomerDetails
{
    /** @param  array<string, string|null>|null  $billingAddress */
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
