<?php

namespace App\Http\Controllers;

use App\Checkout\CheckoutGateway;
use App\Checkout\CheckoutStatus;
use App\Checkout\CheckoutUnavailable;
use App\Checkout\PromotionRejected;
use App\Checkout\StartCheckout;
use App\Http\Requests\StartCheckoutRequest;
use App\Models\CheckoutIntent;
use App\Pricing\Exceptions\InvalidPriceSelection;
use App\Pricing\Exceptions\MarketNotAvailable;
use App\Pricing\Exceptions\PricingException;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    /** Session key holding the UUIDs of intents started in this browser session. */
    private const SESSION_INTENTS = 'checkout.intents';

    public function show(Request $request, PricingCatalog $pricing): View|RedirectResponse
    {
        try {
            $selection = PriceSelection::fromInput(
                $request->query('product'),
                $request->query('plan'),
                $request->query('interval', $pricing->defaultInterval->value),
            );
        } catch (InvalidPriceSelection $e) {
            return redirect()->route('pricing')->with('checkout_error', $e->customerMessage());
        }

        $market = $pricing->market($pricing->displayMarket);
        $offer = collect($pricing->offers($selection->productType, $market))
            ->first(fn ($offer) => $offer->plan->tier === $selection->tier);

        if (! $offer) {
            return redirect()->route('pricing')->with('checkout_error', __('checkout.errors.invalid_selection'));
        }

        return view('pages.checkout.show', [
            'selection' => $selection,
            'offer' => $offer,
            'market' => $market,
            'freeMonths' => $offer->freeMonthsOnAnnual(),
            'countries' => __('countries'),
            // Countries whose market has prices; the page warns about the rest
            // before submitting. The server checks again.
            'purchasableCountries' => collect(array_keys(__('countries')))
                ->filter(fn (string $country) => $pricing->hasPrices($pricing->marketForCountry($country)))
                ->values(),
        ]);
    }

    public function store(StartCheckoutRequest $request, StartCheckout $startCheckout): RedirectResponse
    {
        try {
            $intent = $startCheckout->handle(
                $request->selection(),
                $request->customer(),
                $request->validated('promotion_code'),
                $request->session()->getId(),
            );
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
        // while it is open. Expiring the Stripe session stops a later payment.
        if ($this->startedInThisSession($request, $intent) && $intent->canMoveTo(CheckoutStatus::Cancelled)) {
            $intent->update(['status' => CheckoutStatus::Cancelled, 'cancelled_at' => now()]);

            if ($intent->stripe_checkout_session_id) {
                rescue(fn () => $gateway->expireSession($intent->stripe_checkout_session_id), report: false);
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
