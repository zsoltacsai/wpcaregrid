<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\TaxVerification;
use App\Owner\AdminAudit;
use App\Tax\TaxVerificationWorkflow;
use App\Tax\Vat\VatNumberNormalization;
use App\Tax\Vat\VatNumberValidator;
use App\Tax\Vat\VatValidationStatus;
use App\Tax\VerificationStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class VatVerificationController extends Controller
{
    public function index(): View
    {
        $reviews = TaxVerification::query()
            ->with(['billingIdentity.customer'])
            ->whereNull('resolved_at')
            ->whereIn('status', [VerificationStatus::PendingVerification->value, VerificationStatus::ManualReview->value])
            ->latest('created_at')
            ->paginate(25);

        return view('owner.vat-verifications.index', compact('reviews'));
    }

    public function recheck(
        Request $request,
        Customer $customer,
        VatNumberNormalization $normalization,
        VatNumberValidator $validator,
        TaxVerificationWorkflow $reviews,
    ): RedirectResponse {
        $identity = $customer->latestBillingIdentity();
        abort_unless($identity && $identity->customer_id === $customer->id, 404);

        $latest = $identity->vatValidations()->latest('checked_at')->first();
        $identifier = $latest?->vat_identifier ?: $identity->tax_identifier;
        if (! filled($identifier)) {
            return back()->withErrors(['vat' => __('owner.customers.vat.no_identifier')]);
        }

        $country = $latest?->country_code;
        if (! $country) {
            $prefix = strtoupper(substr(trim((string) $identifier), 0, 2));
            $country = in_array($prefix, config('tax.vies.supported_country_codes', []), true)
                || array_key_exists($prefix, config('tax.vies.prefix_aliases', []))
                ? $prefix
                : $identity->country_code;
        }

        try {
            // Normalize first to avoid making a provider call using an inferred or unsupported country.
            $normalization->normalize($country, $identifier);
        } catch (\InvalidArgumentException) {
            return back()->withErrors(['vat' => __('owner.customers.vat.no_identifier')]);
        }

        $result = $validator->validate($country, $identifier, $identity, forceRefresh: true);
        if (in_array($result->status, [VatValidationStatus::Unavailable, VatValidationStatus::PendingVerification, VatValidationStatus::ManualReview], true)) {
            $reviews->open($identity, VerificationStatus::PendingVerification, 'vies_unavailable_or_limited');
        }

        AdminAudit::record($request->user('owner'), 'customer.vat_rechecked', 'customer', $customer->uuid, [
            'identity_version' => $identity->version,
            'status' => $result->status->value,
            'evidence_id' => $result->evidenceId,
        ]);

        return back()->with('vat_recheck_status', $result->status->value);
    }

    public function resolve(Request $request, TaxVerification $verification, TaxVerificationWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'rejected', 'not_required'])],
            'reason_code' => ['required', Rule::in(['documents_reviewed', 'documents_incomplete', 'not_applicable'])],
        ]);
        $verification->load('billingIdentity.customer');
        $identity = $verification->billingIdentity;
        abort_unless($identity && $identity->customer, 404);

        $status = VerificationStatus::from($validated['decision']);
        $workflow->resolve($verification, $status, $request->user('owner')->id, $validated['reason_code']);
        AdminAudit::record($request->user('owner'), 'customer.vat_review_resolved', 'customer', $identity->customer->uuid, [
            'identity_version' => $identity->version,
            'review_id' => $verification->id,
            'decision' => $status->value,
            'reason_code' => $validated['reason_code'],
        ]);

        return redirect()->route('owner.vat-verifications.index')->with('status', __('owner.customers.vat.review_saved'));
    }
}
