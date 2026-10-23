<?php

namespace App\Http\Controllers\Account;

use App\Backups\BackupPolicy;
use App\Checkout\ExistingCustomerCurrencyLock;
use App\Connector\ConnectorError;
use App\Connector\DisconnectWebsite;
use App\Connector\JobQueue;
use App\Connector\JobType;
use App\Connector\PairingCodes;
use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Pricing\BillingInterval;
use App\Pricing\Exceptions\AmbiguousCustomerCurrency;
use App\Pricing\PlanTier;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use App\Rules\PublicWebsiteUrl;
use App\Websites\AssignWebsiteToSubscription;
use App\Websites\NormalizeWebsiteUrl;
use App\Websites\WebsitePurchaseTarget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class WebsiteController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->attributes->get('customer');

        return view('account.websites.index', [
            'websites' => $customer->websites()->latest('id')->get(),
            'hasAccess' => $customer->hasActiveSubscription(),
            'canAddWebsite' => true,
        ]);
    }

    public function create(Request $request, PricingCatalog $pricing): View
    {
        $request->session()->forget('account.website_purchase_draft');

        return view('account.websites.create', ['defaultType' => $pricing->defaultProductType]);
    }

    public function store(Request $request, NormalizeWebsiteUrl $normalize): RedirectResponse
    {
        $customer = $request->attributes->get('customer');
        $request->merge(['url' => $normalize((string) $request->input('url'))]);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:255', 'url:http,https', new PublicWebsiteUrl, Rule::unique('websites')->where('customer_id', $customer->id)],
            'type' => ['required', Rule::enum(ProductType::class)],
        ], ['url.unique' => __('account.websites.duplicate')]);

        $request->session()->put('account.website_purchase_draft', [
            'url' => $validated['url'], 'type' => $validated['type'], 'created_at' => now()->timestamp,
        ]);

        return redirect()->route('account.websites.options');
    }

    public function options(Request $request, PricingCatalog $pricing, ExistingCustomerCurrencyLock $currencyLock): View|RedirectResponse
    {
        $target = WebsitePurchaseTarget::fromDraft($request->session()->get('account.website_purchase_draft'));
        if (! $target) {
            $request->session()->forget('account.website_purchase_draft');

            return redirect()->route('account.websites.create')->withErrors(['website' => __('account.websites.draft_expired')]);
        }

        $customer = $request->attributes->get('customer');
        $subscriptions = $customer->activeSubscriptions()->where('product_type', $target->type->value)
            ->whereDoesntHave('website')->oldest('id')->get();
        try {
            $currency = $currencyLock->forCustomer($customer)
                ?? (strtoupper((string) $customer->country_code) === 'HU' ? 'HUF' : $pricing->defaultInternationalCurrency());
        } catch (AmbiguousCustomerCurrency $exception) {
            return redirect()->route('account.subscription')->withErrors(['checkout' => $exception->customerMessage()]);
        }
        $market = is_string($currency) ? $pricing->marketForCurrency($currency) : null;
        $countries = __('countries');
        $billingCountry = is_array($countries)
            ? ($countries[strtoupper((string) $customer->country_code)] ?? strtoupper((string) $customer->country_code))
            : strtoupper((string) $customer->country_code);
        $offers = $market ? array_values(array_filter($pricing->offers($target->type, $market), fn ($offer) => $offer->monthly->active && $offer->annual->active
            && $offer->monthly->hasStripePrice() && $offer->annual->hasStripePrice()
        )) : [];

        return view('account.websites.options', compact('target', 'subscriptions', 'offers', 'currency', 'billingCountry'));
    }

    public function assign(Request $request, AssignWebsiteToSubscription $assign): RedirectResponse
    {
        $customer = $request->attributes->get('customer');
        $target = WebsitePurchaseTarget::fromDraft($request->session()->get('account.website_purchase_draft'));
        if (! $target) {
            $request->session()->forget('account.website_purchase_draft');

            return redirect()->route('account.websites.create')->withErrors(['website' => __('account.websites.draft_expired')]);
        }

        $validated = $request->validate(['subscription' => ['required', 'uuid']]);
        try {
            $website = $assign($customer, $validated['subscription'], $target);
        } catch (UniqueConstraintViolationException) {
            Log::notice('Customer website assignment hit a uniqueness conflict.', ['customer_uuid' => $customer->uuid]);

            return back()->withErrors(['url' => __('account.websites.duplicate')]);
        }

        $request->session()->forget('account.website_purchase_draft');

        return redirect()->route('account.websites.show', $website)->with('status', __('account.onboarding.created'));
    }

    public function purchase(Request $request): RedirectResponse
    {
        $target = WebsitePurchaseTarget::fromDraft($request->session()->get('account.website_purchase_draft'));
        if (! $target) {
            $request->session()->forget('account.website_purchase_draft');

            return redirect()->route('account.websites.create')->withErrors(['website' => __('account.websites.draft_expired')]);
        }

        $selection = $request->validate([
            'plan' => ['required', Rule::enum(PlanTier::class)],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
        ]);

        return redirect()->route('checkout.show', [
            'product' => $target->type->value, 'plan' => $selection['plan'], 'interval' => $selection['interval'],
        ]);
    }

    public function show(Website $website): View
    {
        $this->authorize('view', $website);

        $website->load(['activeConnector', 'inventory']);

        return view('account.websites.show', [
            'website' => $website,
            'connector' => $website->activeConnector,
            'inventory' => $website->inventory,
            'capabilitySnapshot' => $website->capabilitySnapshot,
            'jobs' => $website->jobs()->with('backup')->latest('id')->limit(10)->get(),
            'canPair' => request()->user()->can('pair', $website),
            'latestBackup' => $website->backups()->latest('id')->first(),
            'latestVerified' => $website->backups()->usableRecoveryPoint()->latest('verified_at')->first(),
            'backupPolicy' => app(BackupPolicy::class)->for($website),
            'canBackup' => request()->user()->can('backup', $website),
            'canAdminAccess' => request()->user()->can('adminAccess', $website) && $website->isConnected(),
            'pairingCode' => $this->flashedPairingCode(),
        ]);
    }

    public function requestCompatibilityCheck(Request $request, Website $website, JobQueue $jobs): RedirectResponse
    {
        $this->authorize('view', $website);

        if ($website->jobs()->where('type', JobType::GetInventory)->whereIn('status', ['queued', 'delivered'])->exists()) {
            return redirect()->route('account.websites.show', $website)->with('status', __('compatibility.check_pending'));
        }

        try {
            $jobs->queue($website, JobType::GetInventory, requestedBy: 'customer:'.$request->user()->id);
        } catch (ConnectorError) {
            return redirect()->route('account.websites.show', $website)->with('compatibility_error', __('compatibility.check_unavailable'));
        }

        return redirect()->route('account.websites.show', $website)->with('status', __('compatibility.check_requested'));
    }

    /**
     * Issue a one-time pairing code. It is shown once, via the session
     * (never in a URL), and only its hash is stored.
     */
    public function pairingCode(Request $request, Website $website, PairingCodes $codes): RedirectResponse
    {
        $this->authorize('pair', $website);

        $code = $codes->issue($website, $request->user());

        return redirect()->route('account.websites.show', $website)
            ->with('pairing_code', ['code' => $code, 'expires_at' => now()->addSeconds(config('connector.pairing_code_ttl'))->toIso8601String()]);
    }

    /** Revoke the website's connector. It cannot reconnect without a new pairing code. */
    public function disconnect(Request $request, Website $website, DisconnectWebsite $disconnect): RedirectResponse
    {
        $this->authorize('disconnect', $website);

        $disconnect($website, 'disconnected_by_customer', 'customer:'.$request->user()->id);

        return redirect()->route('account.websites.show', $website)->with('status', __('connector.account.disconnected'));
    }

    /**
     * The code generated on the previous request (flashed, shown once).
     * Sessions are JSON-serialised, so the expiry travels as a string.
     *
     * @return array{code: string, expires_at: Carbon}|null
     */
    private function flashedPairingCode(): ?array
    {
        $flashed = session('pairing_code');

        if (! is_array($flashed) || ! is_string($flashed['code'] ?? null) || ! is_string($flashed['expires_at'] ?? null)) {
            return null;
        }

        return ['code' => $flashed['code'], 'expires_at' => Carbon::parse($flashed['expires_at'])];
    }
}
