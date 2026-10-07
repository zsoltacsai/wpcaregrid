<?php

namespace Tests\Fakes;

use App\Checkout\CheckoutGateway;
use App\Checkout\CheckoutSession;
use App\Checkout\CheckoutStatus;
use App\Checkout\CheckoutUnavailable;
use App\Models\CheckoutIntent;
use App\Models\Promotion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Records calls instead of talking to Stripe. */
class FakeCheckoutGateway implements CheckoutGateway
{
    /** @var list<array{intent: CheckoutIntent, promotion: ?Promotion, status_at_call: CheckoutStatus, persisted: bool}> */
    public array $created = [];

    /** @var list<string> */
    public array $expired = [];

    public bool $fail = false;

    public bool $outcomeUnknown = false;

    public bool $failExpire = false;

    public function createSession(CheckoutIntent $intent, ?Promotion $promotion): CheckoutSession
    {
        $this->created[] = [
            'intent' => $intent,
            'promotion' => $promotion,
            'status_at_call' => $intent->status,
            'persisted' => CheckoutIntent::whereKey($intent->getKey())->exists(),
        ];

        if ($this->fail) {
            throw CheckoutUnavailable::providerError($this->outcomeUnknown);
        }

        $id = 'cs_test_'.Str::random(24);

        return new CheckoutSession($id, "https://checkout.stripe.com/c/pay/{$id}", Carbon::now()->addDay());
    }

    public function expireSession(string $sessionId): void
    {
        $this->expired[] = $sessionId;

        if ($this->failExpire) {
            throw new \RuntimeException('Fake expire request failed.');
        }
    }
}
