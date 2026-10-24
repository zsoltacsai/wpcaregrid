<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\RecoveryOffer;
use App\Offers\OfferStatus;
use App\Offers\RecoveryOffers;
use App\Pricing\PriceEditor;
use App\Pricing\PricingCatalog;
use App\Support\DisplayDateTime;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recovery offers in the owner area (docs/RECOVERY_OFFERS.md). The customer is
 * chosen from the list of customers; the website must belong to that customer
 * (checked in RecoveryOffers). The amount is what the owner types, converted
 * on the server; only HU billing customers and HUF are currently eligible.
 */
class RecoveryOfferController extends Controller
{
    public function index(Request $request): View
    {
        $status = OfferStatus::tryFrom((string) $request->query('status'));

        return view('owner.offers.index', [
            'offers' => RecoveryOffer::with(['customer', 'website'])
                ->when($status, fn ($q) => $q->where('status', $status->value))
                ->latest('id')->paginate(25)->withQueryString(),
            'status' => $status,
            'statuses' => OfferStatus::cases(),
        ]);
    }

    public function create(Request $request, PricingCatalog $catalog): View
    {
        $customer = $request->filled('customer')
            ? Customer::where('uuid', (string) $request->query('customer'))->where('country_code', 'HU')->first()
            : null;

        return view('owner.offers.create', [
            'customers' => $customer ? collect([$customer]) : Customer::where('active', true)->where('country_code', 'HU')->orderBy('last_name')->orderBy('first_name')->limit(500)->get(['id', 'uuid', 'first_name', 'last_name', 'company_name', 'email']),
            'customer' => $customer,
            'websites' => $customer?->websites()->orderBy('url')->get(['id', 'uuid', 'url']) ?? collect(),
            'currencies' => $catalog->recoveryOfferCurrencies(),
            'timezone' => app(DisplayDateTime::class)->timezone()->getName(),
        ]);
    }

    public function store(Request $request, RecoveryOffers $offers): RedirectResponse
    {
        $data = $this->validated($request, withCustomer: true, displayDateTime: app(DisplayDateTime::class));
        $customer = Customer::where('uuid', $data['customer'])->where('active', true)->firstOrFail();

        $offer = $offers->create($customer, $data, $request->user());

        return redirect()->route('owner.offers.show', $offer)->with('status', __('owner.offers.created'));
    }

    public function show(RecoveryOffer $offer, PricingCatalog $catalog, PriceEditor $prices): View
    {
        $offer->load(['customer', 'website', 'createdBy']);

        return view('owner.offers.show', [
            'offer' => $offer,
            'websites' => $offer->customer->websites()->orderBy('url')->get(['id', 'uuid', 'url']),
            'currencies' => $catalog->recoveryOfferCurrencies(),
            'amount' => $prices->major($offer->amount, $offer->currency),
            'timezone' => app(DisplayDateTime::class)->timezone()->getName(),
            'audit' => AdminAuditEvent::with('actor')->where('subject_type', 'offer')->where('subject_key', $offer->uuid)->latest('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, RecoveryOffer $offer, RecoveryOffers $offers): RedirectResponse
    {
        $data = $this->validated($request, withCustomer: false, displayDateTime: app(DisplayDateTime::class));

        $offers->update($offer, $data, $request->user());

        return redirect()->route('owner.offers.show', $offer)->with('status', __('owner.offers.saved'));
    }

    public function send(Request $request, RecoveryOffer $offer, RecoveryOffers $offers): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('owner.offers.confirm_send')]);
        $offers->send($offer, $request->user());

        return redirect()->route('owner.offers.show', $offer)->with('status', __('owner.offers.sent'));
    }

    public function cancel(Request $request, RecoveryOffer $offer, RecoveryOffers $offers): RedirectResponse
    {
        $offers->cancel($offer, $request->user());

        return redirect()->route('owner.offers.show', $offer)->with('status', __('owner.offers.cancelled'));
    }

    public function complete(Request $request, RecoveryOffer $offer, RecoveryOffers $offers): RedirectResponse
    {
        $offers->complete($offer, $request->user());

        return redirect()->route('owner.offers.show', $offer)->with('status', __('owner.offers.completed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $withCustomer, DisplayDateTime $displayDateTime): array
    {
        $data = $request->validate([
            ...($withCustomer ? ['customer' => ['required', 'uuid']] : []),
            'website' => [$withCustomer ? 'required' : 'nullable', 'uuid'],
            'title' => ['required', 'string', 'max:150'],
            'scope' => ['required', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'string', 'max:20'],
            'currency' => ['required', 'string', Rule::in(array_keys((array) config('pricing.currencies')))],
            'valid_until' => ['required', 'date'],
        ]);

        $data['valid_until'] = $displayDateTime->parseLocal($data['valid_until']);

        return $data;
    }
}
