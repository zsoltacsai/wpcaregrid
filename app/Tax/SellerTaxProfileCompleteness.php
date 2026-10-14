<?php

namespace App\Tax;

use App\Models\SellerTaxProfile;

/** Deterministic completeness check for the factual seller identity used on invoices. */
final class SellerTaxProfileCompleteness
{
    /** @return list<string> */
    public function missingFields(SellerTaxProfile $profile): array
    {
        $address = is_array($profile->seller_address) ? $profile->seller_address : [];
        $required = [
            'legal_name' => $profile->legal_name,
            'establishment_country' => $profile->establishment_country,
            'seller_address.address_line1' => $address['address_line1'] ?? null,
            'seller_address.postal_code' => $address['postal_code'] ?? null,
            'seller_address.city' => $address['city'] ?? null,
            'seller_address.country_code' => $address['country_code'] ?? null,
        ];

        return array_keys(array_filter($required, fn (mixed $value): bool => ! is_string($value) || trim($value) === ''));
    }

    public function isComplete(SellerTaxProfile $profile): bool
    {
        return $this->missingFields($profile) === [];
    }
}
