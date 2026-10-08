<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Tax\CustomerBillingIdentityService;
use App\Tax\TaxVerificationWorkflow;
use App\Tax\Vat\VatNumberValidator;
use App\Tax\Vat\VatValidationStatus;
use App\Tax\VerificationStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class VatVerificationController extends Controller
{
    public function store(
        Request $request,
        CustomerBillingIdentityService $identities,
        VatNumberValidator $validator,
        TaxVerificationWorkflow $reviews,
    ): RedirectResponse {
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');
        $this->authorize('update', $customer);

        $supported = array_values(config('tax.vies.supported_country_codes', []));
        $aliases = array_keys(config('tax.vies.prefix_aliases', []));
        $validated = $request->validate([
            'vat_country' => ['required', 'string', 'size:2', Rule::in(array_merge($supported, $aliases))],
            'vat_number' => ['required', 'string', 'max:50'],
        ]);

        if ($customer->customer_type !== 'company') {
            return back()->withErrors(['vat_number' => __('account.profile.vies.company_only')])->withInput();
        }

        $identifier = trim($validated['vat_number']);
        $identity = DB::transaction(function () use ($customer, $identifier, $identities) {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $current = $locked->taxIdentifier();

            if ($current !== $identifier) {
                $locked->forceFill(['tax_id' => null, 'tax_identifier_encrypted' => $identifier])->save();
            }

            $identity = $locked->latestBillingIdentity();
            if ($current !== $identifier || ! $identity) {
                $identity = $identities->snapshotProfile($locked, [
                    'customer_type' => $locked->customer_type,
                    'company_name' => $locked->company_name,
                    'legal_name' => $locked->company_name ?: trim($locked->first_name.' '.$locked->last_name),
                    'country_code' => $locked->country_code,
                    'tax_id' => $identifier,
                    'billing_address' => $locked->billing_address,
                ]);
            }

            if ($identity->customer_id !== $locked->id) {
                abort(404);
            }

            return $identity;
        }, attempts: 3);

        $result = $validator->validate($validated['vat_country'], $identifier, $identity);
        if (in_array($result->status, [VatValidationStatus::Unavailable, VatValidationStatus::PendingVerification, VatValidationStatus::ManualReview], true)) {
            $reviews->open($identity, VerificationStatus::PendingVerification, 'vies_unavailable_or_limited');
        }

        return redirect()->route('account.profile')->with('vat_status', $result->status->value);
    }
}
