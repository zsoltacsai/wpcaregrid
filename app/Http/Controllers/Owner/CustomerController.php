<?php

namespace App\Http\Controllers\Owner;

use App\Billing\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\WebsiteEvent;
use App\Owner\AdminAudit;
use App\Owner\CustomerDirectory;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use App\SiteHealth\SiteHealth;
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
        ]);
    }

    public function show(Customer $customer, SiteHealth $health): View
    {
        $customer->load(['subscriptions' => fn ($q) => $q->latest('id'), 'websites.activeConnector', 'websites.inventory', 'websites.subscription', 'websites.customer', 'user']);

        return view('owner.customers.show', [
            'customer' => $customer,
            'health' => $customer->websites->mapWithKeys(fn ($website) => [$website->id => $health->for($website)]),
            'events' => WebsiteEvent::with(['website', 'actorUser'])->whereIn('website_id', $customer->websites->pluck('id'))->latest('id')->limit(20)->get(),
            'audit' => AdminAuditEvent::with('actor')->where('subject_type', 'customer')->where('subject_key', $customer->uuid)->latest('id')->limit(10)->get(),
            'countries' => (array) __('countries'),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
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

        $customer->fill([
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

        $changed = array_values(array_intersect(array_keys($customer->getDirty()), self::EDITABLE));
        if ($changed === []) {
            return back()->with('status', __('owner.customers.unchanged'));
        }

        DB::transaction(function () use ($customer, $changed, $request) {
            $customer->save();
            $customer->user?->update(['name' => $customer->fullName()]);
            // Field names only: the values are in the customer record, not copied into the log.
            AdminAudit::record($request->user(), 'customer.updated', 'customer', $customer->uuid, ['fields' => $changed]);
        });

        return redirect()->route('owner.customers.show', $customer)->with('status', __('owner.customers.saved'));
    }
}
