<?php

namespace App\Http\Controllers\Owner;

use App\Billing\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\TaxVerification;
use App\Models\VatValidation;
use App\Models\WebsiteEvent;
use App\Owner\AdminAudit;
use App\Owner\CustomerDirectory;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use App\SiteHealth\SiteHealth;
use App\Tax\BillingIdentityCompleteness;
use App\Tax\CustomerBillingIdentityService;
use App\Tax\Vat\VatIdentifierMasker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customers in the owner area. The owner may correct contact and billing
 * details; logins, passwords, payment methods, Stripe data and credentials
 * are not editable (or shown) here. Changes are audited by field name.
 */
class CustomerController extends Controller
{
    /** The only customer fields the owner can change. */
    public const EDITABLE = ['first_name', 'last_name', 'customer_type', 'company_name', 'country_code', 'tax_id', 'billing_address'];

    public function index(Request $request, CustomerDirectory $directory): View
    {
        $filters = CustomerDirectory::filters($request->query());

        return view('owner.customers.index', [
            'customers' => $directory->paginate($filters),
            'filters' => $filters,
            'statuses' => SubscriptionStatus::cases(),
            'types' => ProductType::cases(),
            'plans' => PlanTier::cases(),
            'countries' => (array) __('countries'),
            'openVatReviewCount' => TaxVerification::query()->whereNull('resolved_at')->whereIn('status', ['pending_verification', 'manual_review'])->count(),
        ]);
    }

    public function show(Customer $customer, SiteHealth $health, BillingIdentityCompleteness $billingCompleteness): View
    {
        $customer->load(['syntheticFixture', 'billingEmailOutbox' => fn ($q) => $q->latest('id')->limit(10), 'subscriptions' => fn ($q) => $q->with('billingInvoices')->latest('id'), 'websites.activeConnector', 'websites.inventory', 'websites.subscription', 'websites.customer', 'user']);
        $identity = $customer->latestBillingIdentity();
        $vatHistory = VatValidation::query()
            ->whereHas('billingIdentity', fn ($query) => $query->where('customer_id', $customer->id))
            ->latest('checked_at')->limit(20)->get();
        $reviewCases = TaxVerification::query()
            ->whereHas('billingIdentity', fn ($query) => $query->where('customer_id', $customer->id))
            ->latest('created_at')->limit(20)->get();
        $latestVatValidation = $identity?->vatValidations()->latest('checked_at')->first();
        $lastSuccessfulVatValidation = $identity?->lastSuccessfulVatValidation();

        return view('owner.customers.show', [
            'customer' => $customer,
            'health' => $customer->websites->mapWithKeys(fn ($website) => [$website->id => $health->for($website)]),
            'events' => WebsiteEvent::with(['website', 'actorUser'])->whereIn('website_id', $customer->websites->pluck('id'))->latest('id')->limit(20)->get(),
            'audit' => AdminAuditEvent::with('actor')->where('subject_type', 'customer')->where('subject_key', $customer->uuid)->latest('id')->limit(10)->get(),
            'countries' => (array) __('countries'),
            'billingIdentity' => $identity,
            'billingIdentityComplete' => $billingCompleteness->customerIsComplete($customer),
            'billingIdentityMissing' => $billingCompleteness->missingForCustomer($customer),
            'vatHistory' => $vatHistory,
            'reviewCases' => $reviewCases,
            'latestVatValidation' => $latestVatValidation,
            'lastSuccessfulVatValidation' => $lastSuccessfulVatValidation,
            'maskedVatIdentifier' => VatIdentifierMasker::mask($latestVatValidation?->country_code ?? $identity?->country_code, $latestVatValidation?->vat_identifier ?? $identity?->tax_identifier ?? $customer->taxIdentifier()),
        ]);
    }

    public function update(Request $request, Customer $customer, CustomerBillingIdentityService $identities): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'customer_type' => ['required', Rule::in(['individual', 'company'])],
            'company_name' => ['nullable', 'required_if:customer_type,company', 'string', 'max:150'],
            'country_code' => ['required', 'string', Rule::in(array_keys((array) __('countries')))],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $next = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'customer_type' => $validated['customer_type'],
            'company_name' => $validated['customer_type'] === 'company' ? $validated['company_name'] : null,
            'country_code' => strtoupper($validated['country_code']),
            'tax_identifier_encrypted' => filled($validated['tax_id'] ?? null) ? trim($validated['tax_id']) : null,
            'billing_address' => array_filter([
                'line1' => $validated['address_line1'] ?? null,
                'line2' => $validated['address_line2'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'city' => $validated['city'] ?? null,
            ]) ?: null,
        ];
        $changed = [];

        DB::transaction(function () use ($customer, $next, &$changed, $request, $identities) {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $oldTaxIdentifier = $locked->taxIdentifier();
            $nextTaxIdentifier = filled($next['tax_identifier_encrypted'] ?? null) ? $next['tax_identifier_encrypted'] : $oldTaxIdentifier;
            $taxChanged = $oldTaxIdentifier !== $nextTaxIdentifier;
            $locked->fill([
                ...$next,
                'tax_id' => $taxChanged ? null : $locked->tax_id,
                'tax_identifier_encrypted' => $taxChanged ? $nextTaxIdentifier : $locked->tax_identifier_encrypted,
            ]);
            $changed = array_values(array_intersect(array_keys($locked->getDirty()), self::EDITABLE));
            if ($taxChanged && ! in_array('tax_id', $changed, true)) {
                $changed[] = 'tax_id';
            }
            if ($changed === []) {
                return;
            }

            $locked->save();
            $locked->user?->update(['name' => $locked->fullName()]);
            $identities->snapshotProfile($locked, [
                'customer_type' => $locked->customer_type,
                'company_name' => $locked->company_name,
                'legal_name' => $locked->customer_type === 'company'
                    ? $locked->company_name
                    : trim($locked->first_name.' '.$locked->last_name),
                'country_code' => $locked->country_code,
                'tax_id' => $locked->taxIdentifier(),
                'billing_address' => $locked->billing_address,
            ]);
            // Field names only: the values are in the customer record, not copied into the log.
            AdminAudit::record($request->user(), 'customer.updated', 'customer', $locked->uuid, ['fields' => $changed]);
        });

        if ($changed === []) {
            return back()->with('status', __('owner.customers.unchanged'));
        }

        return redirect()->route('owner.customers.show', $customer)->with('status', __('owner.customers.saved'));
    }
}
