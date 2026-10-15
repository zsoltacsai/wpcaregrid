<?php

namespace App\Http\Requests;

use App\Checkout\CustomerDetails;
use App\Legal\LegalDocumentCatalog;
use App\Models\Customer;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\PriceSelection;
use App\Pricing\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The checkout form. Only a logical selection and customer details are
 * accepted; any amount or Stripe Price ID in the request is ignored.
 */
class StartCheckoutRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'product' => ['required', Rule::enum(ProductType::class)],
            'plan' => ['required', Rule::enum(PlanTier::class)],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
            'currency' => ['required', 'string', Rule::in(array_keys((array) config('pricing.currencies')))],
            'promotion_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];

        $terms = app(LegalDocumentCatalog::class)->current('terms_of_service', app()->getLocale());
        if ($terms?->acceptance_mode === 'acceptance_required_before_checkout') {
            $rules += [
                'terms_document_uuid' => ['required', 'uuid', Rule::in([$terms->uuid])],
                'terms_content_sha256' => ['required', 'string', Rule::in([(string) $terms->content_sha256])],
                'terms_accepted' => ['required', 'accepted'],
            ];
        }

        if ($this->user()?->customer instanceof Customer) {
            return $rules + ['country' => ['required', 'string', Rule::in(array_keys(__('countries')))]];
        }

        return $rules + [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'customer_type' => ['required', Rule::in(['individual', 'company'])],
            'company_name' => ['nullable', 'required_if:customer_type,company', 'string', 'max:150'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'country' => ['required', 'string', Rule::in(array_keys(__('countries')))],
            'address_line1' => ['required', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'email' => __('checkout.fields.email'),
            'first_name' => __('checkout.fields.first_name'),
            'last_name' => __('checkout.fields.last_name'),
            'customer_type' => __('checkout.fields.customer_type'),
            'company_name' => __('checkout.fields.company_name'),
            'address_line1' => __('checkout.fields.address_line1'),
            'postal_code' => __('checkout.fields.postal_code'),
            'city' => __('checkout.fields.city'),
            'country' => __('checkout.fields.country'),
            'promotion_code' => __('checkout.fields.promotion_code'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? trim($this->email) : $this->email,
            'country' => is_string($this->country) ? strtoupper($this->country) : $this->country,
            'promotion_code' => is_string($this->promotion_code) ? trim($this->promotion_code) : $this->promotion_code,
            'first_name' => is_string($this->first_name) ? trim(preg_replace('/\s+/u', ' ', $this->first_name) ?? $this->first_name) : $this->first_name,
            'last_name' => is_string($this->last_name) ? trim(preg_replace('/\s+/u', ' ', $this->last_name) ?? $this->last_name) : $this->last_name,
            'company_name' => is_string($this->company_name) ? trim(preg_replace('/\s+/u', ' ', $this->company_name) ?? $this->company_name) : $this->company_name,
            'tax_id' => is_string($this->tax_id) ? trim($this->tax_id) : $this->tax_id,
            'address_line1' => is_string($this->address_line1) ? trim(preg_replace('/\s+/u', ' ', $this->address_line1) ?? $this->address_line1) : $this->address_line1,
            'address_line2' => is_string($this->address_line2) ? trim(preg_replace('/\s+/u', ' ', $this->address_line2) ?? $this->address_line2) : $this->address_line2,
            'postal_code' => is_string($this->postal_code) ? trim(preg_replace('/\s+/u', ' ', $this->postal_code) ?? $this->postal_code) : $this->postal_code,
            'city' => is_string($this->city) ? trim(preg_replace('/\s+/u', ' ', $this->city) ?? $this->city) : $this->city,
        ]);
    }

    public function selection(): PriceSelection
    {
        return PriceSelection::fromInput($this->validated('product'), $this->validated('plan'), $this->validated('interval'));
    }

    public function customer(?Customer $account = null): CustomerDetails
    {
        if ($account) {
            return new CustomerDetails(
                email: $account->email,
                firstName: $account->first_name,
                lastName: $account->last_name,
                country: $this->validated('country'),
                customerType: $account->customer_type ?? 'individual',
                companyName: $account->company_name,
                taxId: $account->taxIdentifier(),
                billingAddress: $account->billing_address,
            );
        }

        return new CustomerDetails(
            email: $this->validated('email'),
            firstName: trim($this->validated('first_name')),
            lastName: trim($this->validated('last_name')),
            country: $this->validated('country'),
            customerType: $this->validated('customer_type'),
            companyName: $this->validated('company_name'),
            taxId: $this->validated('tax_id'),
            billingAddress: [
                'line1' => $this->validated('address_line1'),
                'line2' => $this->validated('address_line2'),
                'postal_code' => $this->validated('postal_code'),
                'city' => $this->validated('city'),
            ],
        );
    }
}
