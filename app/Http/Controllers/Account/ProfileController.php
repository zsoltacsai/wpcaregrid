<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Localization\Locales;
use App\Models\Customer;
use App\Models\PrivacyRequest;
use App\Pricing\BillingInterval;
use App\Pricing\Exceptions\InvalidPriceSelection;
use App\Pricing\PlanTier;
use App\Pricing\PriceSelection;
use App\Pricing\ProductType;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestStatus;
use App\Privacy\PrivacyRequestType;
use App\Tax\BillingIdentityCompleteness;
use App\Tax\CustomerBillingIdentityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Contact and billing details only. Email, subscriptions, prices and Stripe
 * references are not editable here; unknown fields are ignored.
 */
class ProfileController extends Controller
{
    public function edit(Request $request, BillingIdentityCompleteness $completeness): View
    {
        $customer = $request->attributes->get('customer');
        $identity = $customer->latestBillingIdentity();
        $history = $identity?->vatValidations()->latest('checked_at')->get() ?? collect();
        $lastSuccessful = $identity?->lastSuccessfulVatValidation();
        $supported = array_values(config('tax.vies.supported_country_codes', []));
        $supported = array_values(array_unique(array_merge($supported, array_keys(config('tax.vies.prefix_aliases', [])))));
        $countries = collect((array) __('countries'))->only($supported)->all();
        foreach (array_keys(config('tax.vies.prefix_aliases', [])) as $alias) {
            if (isset(__('countries')[$alias])) {
                $countries[$alias] = __('countries')[$alias];
            }
        }

        $continueCheckout = $request->boolean('continue_checkout');
        $checkoutSelection = null;
        if ($continueCheckout) {
            try {
                $checkoutSelection = PriceSelection::fromInput(
                    $request->query('product'),
                    $request->query('plan'),
                    $request->query('interval'),
                );
            } catch (InvalidPriceSelection) {
                $continueCheckout = false;
            }
        }
        $missingBillingFields = $completeness->missingForCustomer($customer);
        $rectificationRequest = null;
        if ($request->filled('privacy_request')) {
            $rectificationRequest = $customer->privacyRequests()
                ->where('uuid', $request->query('privacy_request'))
                ->where('request_type', PrivacyRequestType::Rectification->value)
                ->where('status', PrivacyRequestStatus::Verified->value)
                ->firstOrFail();
        }

        $locales = Locales::supported();

        return view('account.profile', compact('customer', 'identity', 'history', 'lastSuccessful', 'countries', 'continueCheckout', 'checkoutSelection', 'missingBillingFields', 'rectificationRequest', 'locales'));
    }

    public function update(Request $request, CustomerBillingIdentityService $identities, BillingIdentityCompleteness $completeness, PrivacyRequestService $privacyRequests): RedirectResponse
    {
        $customer = $request->attributes->get('customer');
        $this->authorize('update', $customer);

        $continueCheckout = $request->boolean('continue_checkout');
        foreach (['first_name', 'last_name', 'company_name', 'address_line1', 'address_line2', 'postal_code', 'city'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $request->merge([$field => trim(preg_replace('/\\s+/u', ' ', $value) ?? $value)]);
            }
        }
        if (is_string($request->input('country_code'))) {
            $request->merge(['country_code' => strtoupper($request->input('country_code'))]);
        }
        if (is_string($request->input('tax_id'))) {
            $request->merge(['tax_id' => trim($request->input('tax_id'))]);
        }
        $rules = [
            'privacy_request' => ['sometimes', 'nullable', 'uuid'],
            'locale' => ['sometimes', 'string', Rule::in(Locales::codes())],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'customer_type' => ['required', Rule::in(['individual', 'company'])],
            'company_name' => ['nullable', 'required_if:customer_type,company', 'string', 'max:150'],
            'country_code' => ['required', 'string', Rule::in(array_keys(__('countries')))],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'address_line1' => [$continueCheckout ? 'required' : 'nullable', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'postal_code' => [$continueCheckout ? 'required' : 'nullable', 'string', 'max:20'],
            'city' => [$continueCheckout ? 'required' : 'nullable', 'string', 'max:100'],
        ];
        if ($continueCheckout) {
            $rules += [
                'product' => ['required', Rule::enum(ProductType::class)],
                'plan' => ['required', Rule::enum(PlanTier::class)],
                'interval' => ['required', Rule::enum(BillingInterval::class)],
            ];
        }
        $validated = $request->validate($rules);

        $billingAddress = array_filter([
            'line1' => $validated['address_line1'] ?? null,
            'line2' => $validated['address_line2'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'city' => $validated['city'] ?? null,
        ]) ?: null;

        $nextProfile = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'customer_type' => $validated['customer_type'],
            'company_name' => $validated['customer_type'] === 'company' ? ($validated['company_name'] ?? null) : null,
            'country_code' => strtoupper($validated['country_code']),
            'tax_id' => $validated['tax_id'] ?? null,
            'billing_address' => $billingAddress,
            'locale' => $validated['locale'] ?? (Locales::isSupported($request->user()->locale) ? $request->user()->locale : Locales::default()),
        ];

        $rectification = null;
        if (filled($validated['privacy_request'] ?? null)) {
            $rectification = $customer->privacyRequests()->where('uuid', $validated['privacy_request'])->firstOrFail();
            abort_unless($rectification->request_type === PrivacyRequestType::Rectification && $rectification->status === PrivacyRequestStatus::Verified, 404);
        }

        $changedFields = DB::transaction(function () use ($customer, $request, $nextProfile, $identities, $privacyRequests, $rectification): array {
            $lockedCustomer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $lockedRectification = null;
            if ($rectification) {
                $lockedRectification = PrivacyRequest::query()->whereKey($rectification->id)->lockForUpdate()->firstOrFail();
                abort_unless($lockedRectification->customer_id === $lockedCustomer->id
                    && $lockedRectification->request_type === PrivacyRequestType::Rectification
                    && $lockedRectification->status === PrivacyRequestStatus::Verified, 404);
            }

            $changedFields = [];
            foreach ([
                'first_name' => $nextProfile['first_name'],
                'last_name' => $nextProfile['last_name'],
                'customer_type' => $nextProfile['customer_type'],
                'company_name' => $nextProfile['company_name'],
                'country_code' => $nextProfile['country_code'],
                'tax_id' => $nextProfile['tax_id'],
                'billing_address' => $nextProfile['billing_address'],
                'locale' => $nextProfile['locale'],
            ] as $field => $value) {
                $current = match ($field) {
                    'tax_id' => $lockedCustomer->taxIdentifier(),
                    'locale' => $request->user()->locale,
                    default => $lockedCustomer->{$field},
                };
                if ($field === 'country_code') {
                    $current = strtoupper((string) $current);
                }
                if ($current !== $value) {
                    $changedFields[] = $field;
                }
            }

            $identityChanged = array_intersect($changedFields, [
                'first_name', 'last_name', 'customer_type', 'company_name', 'country_code', 'tax_id', 'billing_address',
            ]) !== [] || $lockedCustomer->tax_id !== null;
            $lockedCustomer->update([
                ...$nextProfile,
                'tax_id' => null,
                'tax_identifier_encrypted' => $nextProfile['tax_id'],
            ]);

            if ($identityChanged) {
                $identities->snapshotProfile($lockedCustomer, [
                    'customer_type' => $lockedCustomer->customer_type,
                    'company_name' => $lockedCustomer->company_name,
                    'legal_name' => $lockedCustomer->customer_type === 'company'
                        ? $lockedCustomer->company_name
                        : trim($lockedCustomer->first_name.' '.$lockedCustomer->last_name),
                    'country_code' => $lockedCustomer->country_code,
                    'tax_id' => $lockedCustomer->taxIdentifier(),
                    'billing_address' => $lockedCustomer->billing_address,
                ]);
            }

            $request->user()->update(['name' => $lockedCustomer->fullName(), 'locale' => $nextProfile['locale']]);
            if ($lockedRectification && $changedFields !== []) {
                $privacyRequests->completeRectification($lockedRectification, $lockedCustomer, $request->user(), $changedFields);
            }

            return $changedFields;
        });

        Cookie::queue(Locales::cookie($nextProfile['locale']));

        if ($rectification && $changedFields === []) {
            return redirect()->route('account.profile', ['privacy_request' => $rectification->uuid])->withErrors(['privacy_request' => __('account.privacy.no_profile_changes')]);
        }

        if ($continueCheckout && $completeness->customerIsComplete($customer->fresh())) {
            return redirect()->route('checkout.show', [
                'product' => $validated['product'],
                'plan' => $validated['plan'],
                'interval' => $validated['interval'],
            ])->with('status', __('account.profile.saved'));
        }

        if ($rectification && $changedFields !== []) {
            return redirect()->route('account.profile')->with('status', __('account.profile.rectification_saved'));
        }

        return redirect()->route('account.profile')->with('status', __('account.profile.saved'));
    }
}
