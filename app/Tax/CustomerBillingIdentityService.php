<?php

namespace App\Tax;

use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\CustomerBillingIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerBillingIdentityService
{
    /**
     * Create an immutable profile snapshot after a verified profile edit.
     * Callers decide whether billing identity fields actually changed.
     *
     * @param  array<string, mixed>  $identity
     */
    public function snapshotProfile(Customer $customer, array $identity): CustomerBillingIdentity
    {
        return DB::transaction(function () use ($customer, $identity) {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            return $this->createNext($locked, [
                ...$this->onlyIdentityFields($identity),
                'source' => 'profile',
                'verification_status' => ($identity['customer_type'] ?? 'individual') === 'company'
                    ? VerificationStatus::PendingVerification
                    : VerificationStatus::NotRequired,
            ]);
        }, attempts: 3);
    }

    /**
     * Snapshot the exact billing identity accepted for a confirmed Checkout.
     * Repeated webhook delivery returns the already-linked immutable snapshot.
     */
    public function snapshotFromCheckoutIntent(Customer $customer, CheckoutIntent $intent): CustomerBillingIdentity
    {
        return DB::transaction(function () use ($customer, $intent) {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $lockedIntent = CheckoutIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if (($lockedIntent->customer_id !== null && $lockedIntent->customer_id !== $customer->id)
                || Customer::normalizeEmail($lockedIntent->email) !== Customer::normalizeEmail($customer->email)) {
                throw ValidationException::withMessages(['billing_identity' => 'Checkout intent and customer do not match.']);
            }

            if ($lockedIntent->billing_identity_id) {
                $existing = CustomerBillingIdentity::query()->whereKey($lockedIntent->billing_identity_id)->firstOrFail();
                if ($existing->customer_id !== $customer->id) {
                    throw ValidationException::withMessages(['billing_identity' => 'Checkout intent is linked to another customer identity.']);
                }

                return $existing;
            }

            $snapshot = $this->createNext($customer, [
                ...$this->onlyIdentityFields([
                    'customer_type' => $lockedIntent->customer_type,
                    'company_name' => $lockedIntent->company_name,
                    'legal_name' => $lockedIntent->customer_type === 'company'
                        ? $lockedIntent->company_name
                        : trim($lockedIntent->first_name.' '.$lockedIntent->last_name),
                    'country_code' => $lockedIntent->country,
                    'tax_id' => $lockedIntent->taxIdentifier(),
                    'billing_address' => $lockedIntent->billing_address,
                ]),
                'source' => 'checkout_webhook',
                'verification_status' => $lockedIntent->customer_type === 'company'
                    ? VerificationStatus::PendingVerification
                    : VerificationStatus::NotRequired,
            ]);

            $lockedIntent->billingIdentity()->associate($snapshot)->save();

            return $snapshot;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $attributes */
    private function createNext(Customer $customer, array $attributes): CustomerBillingIdentity
    {
        $version = ((int) CustomerBillingIdentity::query()->where('customer_id', $customer->id)->max('version')) + 1;
        $taxIdentifier = $attributes['tax_identifier'] ?? null;

        $identity = new CustomerBillingIdentity;
        $identity->forceFill([
            ...$attributes,
            'customer_id' => $customer->id,
            'version' => $version,
            'tax_identifier_hash' => is_string($taxIdentifier) && trim($taxIdentifier) !== ''
                ? $this->fingerprint($taxIdentifier)
                : null,
        ]);
        $identity->save();

        return $identity;
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    private function onlyIdentityFields(array $identity): array
    {
        $type = ($identity['customer_type'] ?? 'individual') === 'company' ? 'company' : 'individual';
        $company = $type === 'company' ? trim((string) ($identity['company_name'] ?? '')) : null;
        $address = $identity['billing_address'] ?? [];
        $address = is_array($address) ? $address : [];

        return [
            'customer_type' => $type,
            'legal_name' => $type === 'company' ? ($company ?: trim((string) ($identity['legal_name'] ?? ''))) : trim((string) ($identity['legal_name'] ?? '')),
            'company_name' => $company ?: null,
            'country_code' => strtoupper((string) ($identity['country_code'] ?? '')),
            'address_line1' => $address['line1'] ?? null,
            'address_line2' => $address['line2'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'city' => $address['city'] ?? null,
            'region' => $address['region'] ?? null,
            'tax_identifier' => filled($identity['tax_id'] ?? null) ? trim((string) $identity['tax_id']) : null,
            'declared_business_status' => $type === 'company',
        ];
    }

    private function fingerprint(string $identifier): string
    {
        $key = (string) (config('tax.identifiers.fingerprint_key') ?: config('app.key'));
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }

        return hash_hmac('sha256', mb_strtoupper(preg_replace('/\s+/u', '', trim($identifier)) ?? trim($identifier)), $key);
    }
}
