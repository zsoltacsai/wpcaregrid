<?php

namespace App\Http\Controllers;

use App\Checkout\BillingIdentityIncomplete;
use App\Checkout\CheckoutGateway;
use App\Checkout\CheckoutOfferResolver;
use App\Checkout\CheckoutStatus;
use App\Checkout\CheckoutUnavailable;
use App\Checkout\ExistingCustomerCurrencyLock;
use App\Checkout\PromotionPreview;
use App\Checkout\PromotionRejected;
use App\Checkout\StartCheckout;
use App\Http\Requests\PromotionPreviewRequest;
use App\Http\Requests\StartCheckoutRequest;
use App\Localization\MarketingRoutes;
use App\Models\CheckoutIntent;
use App\Pricing\BillingInterval;
use App\Pricing\DisplayCurrency;
use App\Pricing\Exceptions\AmbiguousCustomerCurrency;
use App\Pricing\Exceptions\InvalidPriceSelection;
use App\Pricing\Exceptions\MarketNotAvailable;
use App\Pricing\Exceptions\PricingException;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use App\Tax\BillingIdentityCompleteness;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckoutController extends Controller
{
    /** Session key holding the UUIDs of intents started in this browser session. */
    private const SESSION_INTENTS = 'checkout.intents';

    public function show(Request $request, PricingCatalog $pricing, ExistingCustomerCurrencyLock $currencyLock, BillingIdentityCompleteness $billingIdentity): View|RedirectResponse
    {
        try {
            $selection = PriceSelection::fromInput(
                $request->query('product'),
                $request->query('plan'),
                $request->query('interval', $pricing->defaultInterval->value),
            );
        } catch (InvalidPriceSelection $e) {
            return redirect(app(MarketingRoutes::class)->pricing())->with('checkout_error', $e->customerMessage());
        }

        $display = app(DisplayCurrency::class);
        $account = $request->user()?->customer;
        if ($account && ! $billingIdentity->customerIsComplete($account)) {
            return redirect()->route('account.profile', [
                'continue_checkout' => 1,
                'product' => $selection->productType->value,
                'plan' => $selection->tier->value,
                'interval' => $selection->interval->value,
            ])->with('checkout_identity_required', true);
        }
        try {
            $lockedCurrency = $account ? $currencyLock->forCustomer($account) : null;
        } catch (AmbiguousCustomerCurrency $e) {
            return redirect(app(MarketingRoutes::class)->pricing())->with('checkout_error', $e->customerMessage());
        }
        $billingCountry = old('country', $account?->country_code ?? 'HU');
        $currency = $display->selected();
        if ($lockedCurrency !== null) {
            $currency = $lockedCurrency;
        } elseif (strtoupper((string) $billingCountry) === 'HU') {
            $currency = 'HUF';
        } elseif ($currency === 'HUF') {
            $currency = $pricing->defaultInternationalCurrency();
        }
        if (! is_string($currency) || ! in_array($currency, $display->available(), true) || ! ($market = $pricing->marketForCurrency($currency))) {
            return redirect(app(MarketingRoutes::class)->pricing())->with('checkout_error', __('checkout.errors.invalid_selection'));
        }

        // Presentation only: country and currency are revalidated by StartCheckout before Stripe is called.
        $offer = collect($pricing->offers($selection->productType, $market))
            ->first(fn ($offer) => $offer->plan->tier === $selection->tier);

        if (! $offer) {
            return redirect(app(MarketingRoutes::class)->pricing())->with('checkout_error', __('checkout.errors.invalid_selection'));
        }

        $priceMatrix = [];
        foreach ($display->available() as $code) {
            $currencyMarket = $pricing->marketForCurrency($code);
            $currencyOffer = $currencyMarket ? collect($pricing->offers($selection->productType, $currencyMarket))
                ->first(fn ($candidate) => $candidate->plan->tier === $selection->tier) : null;
            if (! $currencyOffer) {
                continue;
            }
            foreach ([BillingInterval::Monthly, BillingInterval::Annual] as $billingInterval) {
                $price = $currencyOffer->price($billingInterval);
                $priceMatrix[$code][$billingInterval->value] = [
                    'amount' => $price->money->formattedAmount(),
                    'symbol' => $price->money->currency->symbol,
                    'formatted' => $price->money->format(),
                ];
            }
        }

        return view('pages.checkout.show', [
            'selection' => $selection,
            'offer' => $offer,
            'market' => $market,
            'currency' => $currency,
            'lockedCurrency' => $lockedCurrency,
            'currencies' => $display->available(),
            'defaultInternationalCurrency' => $pricing->defaultInternationalCurrency(),
            'priceMatrix' => $priceMatrix,
            'freeMonths' => $offer->freeMonthsOnAnnual(),
            'countries' => __('countries'),
            'account' => $account,
            // Countries whose market has prices; the page warns about the rest
            // before submitting. The server checks again.
            'purchasableCountries' => collect(array_keys(__('countries')))
                ->filter(fn (string $country) => strtoupper($country) === 'HU'
                    ? in_array('HUF', $display->available(), true)
                    : $pricing->defaultInternationalCurrency() !== null)
                ->values(),
        ]);
    }

    public function store(StartCheckoutRequest $request, StartCheckout $startCheckout): RedirectResponse
    {
        try {
            $intent = $startCheckout->handle(
                $request->selection(),
                $request->customer($request->user()?->customer),
                $request->validated('promotion_code'),
                $request->session()->getId(),
                $request->user()?->customer,
                $request->validated('currency'),
            );
        } catch (BillingIdentityIncomplete $e) {
            if ($customer = $request->user()?->customer) {
                return redirect()->route('account.profile', [
                    'continue_checkout' => 1,
                    'product' => $request->validated('product'),
                    'plan' => $request->validated('plan'),
                    'interval' => $request->validated('interval'),
                ])->with('checkout_identity_required', true);
            }

            return back()->withInput()->withErrors(['billing_identity' => __('checkout.identity.incomplete')]);
        } catch (MarketNotAvailable $e) {
            return back()->withInput()->withErrors(['country' => $e->customerMessage()]);
        } catch (PricingException $e) {
            return back()->withInput()->withErrors(['checkout' => $e->customerMessage()]);
        } catch (PromotionRejected $e) {
            return back()->withInput()->withErrors(['promotion_code' => $e->customerMessage()]);
        } catch (CheckoutUnavailable) {
            return back()->withInput()->withErrors(['checkout' => __('checkout.errors.unavailable')]);
        }

        $request->session()->push(self::SESSION_INTENTS, $intent->uuid);

        return redirect()->away($intent->stripe_checkout_url, 303);
    }

    /** Read-only economics for the current selection. Checkout resolves again before reserving. */
    public function preview(PromotionPreviewRequest $request, CheckoutOfferResolver $offers): JsonResponse
    {
        try {
            $offer = $offers->resolve(
                $request->selection(),
                $request->validated('country'),
                $request->user()?->customer?->email ?? $request->validated('email'),
                $request->validated('currency'),
                $request->validated('promotion_code'),
                $request->user()?->customer,
                preview: true,
            );

            return response()->json(PromotionPreview::from($offer['price'], $offer['promotion'])->toArray());
        } catch (PricingException $e) {
            return response()->json(['message' => $e->customerMessage()], 422);
        } catch (PromotionRejected) {
            return response()->json(['message' => __('checkout.preview.unavailable')], 422);
        }
    }

    /**
     * Stripe sends the customer here after checkout. This is not proof of
     * payment and changes nothing: the intent is completed by the webhook.
     */
    public function success(Request $request): View
    {
        $sessionId = $request->query('session_id');
        $intent = null;

        if (is_string($sessionId) && preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $sessionId)) {
            $intent = CheckoutIntent::where('stripe_checkout_session_id', $sessionId)->first();

            // Only show order details to the browser that started the checkout.
            if ($intent && ! $this->startedInThisSession($request, $intent)) {
                $intent = null;
            }
        }

        return view('pages.checkout.success', ['intent' => $intent]);
    }

    public function cancel(Request $request, CheckoutIntent $intent, CheckoutGateway $gateway): View
    {
        // Only the browser that started the checkout can cancel it, and only
        // while it is open. The cancel URL is only a request to expire Stripe;
        // capacity remains held until Stripe confirms expiry by webhook.
        if ($this->startedInThisSession($request, $intent) && $intent->canMoveTo(CheckoutStatus::Cancelled)) {
            $sessionId = DB::transaction(function () use ($intent) {
                $locked = CheckoutIntent::query()->whereKey($intent->id)->lockForUpdate()->first();
                if (! $locked || ! $locked->canMoveTo(CheckoutStatus::Cancelled)) {
                    return null;
                }

                $locked->update(['status' => CheckoutStatus::Cancelled, 'cancelled_at' => now()]);

                return $locked->stripe_checkout_session_id;
            });

            if ($sessionId) {
                rescue(fn () => $gateway->expireSession($sessionId), report: false);
            }
        }

        return view('pages.checkout.cancel', [
            'retryUrl' => route('checkout.show', [
                'product' => $intent->product_type->value,
                'plan' => $intent->plan->value,
                'interval' => $intent->interval->value,
            ]),
        ]);
    }

    private function startedInThisSession(Request $request, CheckoutIntent $intent): bool
    {
        return in_array($intent->uuid, $request->session()->get(self::SESSION_INTENTS, []), true)
            && hash_equals((string) $intent->browser_session_hash, CheckoutIntent::hashBrowserSession($request->session()->getId()));
    }
}
