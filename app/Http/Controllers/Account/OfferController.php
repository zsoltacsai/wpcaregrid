<?php

namespace App\Http\Controllers\Account;

use App\Checkout\CheckoutUnavailable;
use App\Http\Controllers\Controller;
use App\Models\RecoveryOffer;
use App\Offers\OfferStatus;
use App\Offers\RecoveryOffers;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The customer's view of recovery offers addressed to them. The only things a
 * customer can do are accept, decline and pay: no field of the request can
 * change the price, currency, scope or website, and every offer is loaded by
 * UUID and authorised by RecoveryOfferPolicy (their own, never a draft).
 */
class OfferController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.offers.index', [
            'offers' => $request->attributes->get('customer')->recoveryOffers()->visibleToCustomer()->with('website')->latest('id')->get(),
        ]);
    }

    public function show(RecoveryOffer $offer): View
    {
        $this->authorize('view', $offer);

        return view('account.offers.show', ['offer' => $offer->load('website')]);
    }

    public function accept(RecoveryOffer $offer, RecoveryOffers $offers): RedirectResponse
    {
        $this->authorize('respond', $offer);

        return $this->toPayment($offer, fn () => $offers->accept($offer));
    }

    /** Continue to payment for an offer that was accepted earlier. */
    public function pay(RecoveryOffer $offer, RecoveryOffers $offers): RedirectResponse
    {
        $this->authorize('respond', $offer);

        return $this->toPayment($offer, fn () => $offers->startPayment($offer));
    }

    public function decline(RecoveryOffer $offer, RecoveryOffers $offers): RedirectResponse
    {
        $this->authorize('respond', $offer);
        $offers->decline($offer);

        return redirect()->route('account.offers.show', $offer)->with('status', __('offers.declined'));
    }

    private function toPayment(RecoveryOffer $offer, callable $start): RedirectResponse
    {
        try {
            $updated = $start();
        } catch (CheckoutUnavailable) {
            // Accepted, but Stripe could not start the payment: the customer can try again.
            return redirect()->route('account.offers.show', $offer)->with('offer_error', __('offers.errors.payment_unavailable'));
        }

        return $updated->status === OfferStatus::Accepted && $updated->hasLiveCheckout()
            ? redirect()->away($updated->stripe_checkout_url)
            : redirect()->route('account.offers.show', $offer);
    }
}
