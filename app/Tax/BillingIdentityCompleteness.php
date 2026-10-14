<?php

namespace App\Tax;

use App\Models\CheckoutIntent;
use App\Models\Customer;

/** The shared, derived completeness contract for a billing identity before payment. */
final class BillingIdentityCompleteness
{
    /** @return list<string> Field keys suitable for localized profile guidance. */
    public function missingForCustomer(Customer $customer): array
    {
        $address = is_array($customer->billing_address) ? $customer->billing_address : [];

        return $this->missing([
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'customer_type' => $customer->customer_type,
            'company_name' => $customer->company_name,
            'country' => $customer->country_code,
            'address_line1' => $address['line1'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'city' => $address['city'] ?? null,
        ]);
    }

    /** @return list<string> */
    public function missingForIntent(CheckoutIntent $intent): array
    {
        $address = is_array($intent->billing_address) ? $intent->billing_address : [];

        return $this->missing([
            'first_name' => $intent->first_name,
            'last_name' => $intent->last_name,
            'customer_type' => $intent->customer_type,
            'company_name' => $intent->company_name,
            'country' => $intent->country,
            'address_line1' => $address['line1'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'city' => $address['city'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $identity
     * @return list<string>
     */
    public function missingForCheckoutDetails(array $identity): array
    {
        return $this->missing($identity);
    }

    public function customerIsComplete(Customer $customer): bool
    {
        return $this->missingForCustomer($customer) === [];
    }

    /** @param array<string, mixed> $identity
     * @return list<string>
     */
    private function missing(array $identity): array
    {
        $missing = [];
        $type = $this->text($identity['customer_type'] ?? null);

        if (! in_array($type, ['individual', 'company'], true)) {
            $missing[] = 'customer_type';
        }
        if ($this->text($identity['first_name'] ?? null) === '') {
            $missing[] = 'first_name';
        }
        if ($this->text($identity['last_name'] ?? null) === '') {
            $missing[] = 'last_name';
        }
        if ($type === 'company' && $this->text($identity['company_name'] ?? null) === '') {
            $missing[] = 'company_name';
        }
        $country = strtoupper($this->text($identity['country'] ?? $identity['country_code'] ?? null));
        if ($country === '' || ! array_key_exists($country, (array) __('countries'))) {
            $missing[] = 'country';
        }
        foreach (['address_line1', 'postal_code', 'city'] as $field) {
            if ($this->text($identity[$field] ?? null) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? $value) : '';
    }
}
