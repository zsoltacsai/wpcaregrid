<?php

namespace App\Http\Requests;

use App\Checkout\CustomerDetails;
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
        return [
            'product' => ['required', Rule::enum(ProductType::class)],
            'plan' => ['required', Rule::enum(PlanTier::class)],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', Rule::in(array_keys(__('countries')))],
            'promotion_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'email' => __('checkout.fields.email'),
            'first_name' => __('checkout.fields.first_name'),
            'last_name' => __('checkout.fields.last_name'),
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
        ]);
    }

    public function selection(): PriceSelection
    {
        return PriceSelection::fromInput($this->validated('product'), $this->validated('plan'), $this->validated('interval'));
    }

    public function customer(): CustomerDetails
    {
        return new CustomerDetails(
            email: $this->validated('email'),
            firstName: trim($this->validated('first_name')),
            lastName: trim($this->validated('last_name')),
            country: $this->validated('country'),
        );
    }
}
