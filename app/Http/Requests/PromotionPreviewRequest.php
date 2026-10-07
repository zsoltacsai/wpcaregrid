<?php

namespace App\Http\Requests;

use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\PriceSelection;
use App\Pricing\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Only semantic checkout choices may cross the public preview boundary. */
final class PromotionPreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'product' => ['required', Rule::enum(ProductType::class)],
            'plan' => ['required', Rule::enum(PlanTier::class)],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
            'country' => ['required', 'string', Rule::in(array_keys(__('countries')))],
            'currency' => ['required', 'string', Rule::in(array_keys((array) config('pricing.currencies')))],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'promotion_code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country' => is_string($this->country) ? strtoupper(trim($this->country)) : $this->country,
            'email' => is_string($this->email) ? trim($this->email) : $this->email,
            'promotion_code' => is_string($this->promotion_code) ? trim($this->promotion_code) : $this->promotion_code,
        ]);
    }

    /** Reject attempts to turn preview into an arbitrary Price, coupon or amount lookup. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = ['product', 'plan', 'interval', 'country', 'currency', 'email', 'promotion_code', '_token'];
            if (array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('promotion_code', __('checkout.preview.invalid_input'));
            }
        }];
    }

    public function selection(): PriceSelection
    {
        return PriceSelection::fromInput($this->validated('product'), $this->validated('plan'), $this->validated('interval'));
    }
}
