<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contact and billing details only. Email, subscriptions, prices and Stripe
 * references are not editable here; unknown fields are ignored.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.profile', ['customer' => $request->attributes->get('customer')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $customer = $request->attributes->get('customer');
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'customer_type' => ['required', Rule::in(['individual', 'company'])],
            'company_name' => ['nullable', 'required_if:customer_type,company', 'string', 'max:150'],
            'country_code' => ['required', 'string', Rule::in(array_keys(__('countries')))],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $customer->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'customer_type' => $validated['customer_type'],
            'company_name' => $validated['customer_type'] === 'company' ? $validated['company_name'] : null,
            'country_code' => $validated['country_code'],
            'tax_id' => $validated['tax_id'] ?? null,
            'billing_address' => array_filter([
                'line1' => $validated['address_line1'] ?? null,
                'line2' => $validated['address_line2'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'city' => $validated['city'] ?? null,
            ]) ?: null,
        ]);

        $request->user()->update(['name' => $customer->fullName()]);

        return redirect()->route('account.profile')->with('status', __('account.profile.saved'));
    }
}
