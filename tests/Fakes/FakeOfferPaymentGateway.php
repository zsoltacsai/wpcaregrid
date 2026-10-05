<?php

namespace Tests\Fakes;

use App\Checkout\CheckoutSession;
use App\Checkout\CheckoutUnavailable;
use App\Models\RecoveryOffer;
use App\Offers\OfferPaymentGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Records the offers it was asked to charge instead of talking to Stripe. */
class FakeOfferPaymentGateway implements OfferPaymentGateway
{
    /** @var list<array{offer: string, amount: int, currency: string, previous: string}> What would have been charged. */
    public array $created = [];

    /** @var list<string> */
    public array $expired = [];

    public bool $fail = false;

    public function createSession(RecoveryOffer $offer, string $previousSessionId = ''): CheckoutSession
    {
        if ($this->fail) {
            throw CheckoutUnavailable::providerError();
        }

        // The amount and currency come from the stored offer, as in the real gateway.
        $this->created[] = ['offer' => $offer->uuid, 'amount' => $offer->amount, 'currency' => $offer->currency, 'previous' => $previousSessionId];
        $id = 'cs_test_offer_'.Str::random(16);

        return new CheckoutSession($id, "https://checkout.stripe.com/c/pay/{$id}", Carbon::now()->addHours(23));
    }

    public function expireSession(string $sessionId): void
    {
        $this->expired[] = $sessionId;
    }
}
