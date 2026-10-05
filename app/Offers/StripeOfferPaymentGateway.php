<?php

namespace App\Offers;

use App\Checkout\CheckoutSession;
use App\Checkout\CheckoutUnavailable;
use App\Localization\Locales;
use App\Models\RecoveryOffer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Creates one-time Stripe Checkout Sessions (mode "payment") for a stored
 * recovery offer. The amount and currency are read from the offer row, never
 * from a request, and the line is built inline (price_data): no Stripe Price
 * exists for an offer, and none of the public catalogue prices can be used.
 * Same key rules as the subscription checkout.
 */
final class StripeOfferPaymentGateway implements OfferPaymentGateway
{
    public function __construct(
        private readonly ?string $secret,
        private readonly bool $allowLiveMode = false,
        private ?StripeClient $client = null,
    ) {}

    public function createSession(RecoveryOffer $offer, string $previousSessionId = ''): CheckoutSession
    {
        try {
            $session = $this->client()->checkout->sessions->create(
                $this->sessionParameters($offer),
                // Repeating the same step (a retry) must not create a second session.
                ['idempotency_key' => 'recovery-offer-'.$offer->uuid.'-'.sha1($previousSessionId)],
            );
        } catch (ApiErrorException $e) {
            Log::warning('Stripe checkout session for a recovery offer could not be created.', [
                'recovery_offer' => $offer->uuid,
                'stripe_error' => $e->getStripeCode() ?? $e::class,
                'stripe_request_id' => $e->getRequestId(),
            ]);

            throw CheckoutUnavailable::providerError();
        }

        return new CheckoutSession($session->id, $session->url, Carbon::createFromTimestamp($session->expires_at));
    }

    public function expireSession(string $sessionId): void
    {
        try {
            $this->client()->checkout->sessions->expire($sessionId);
        } catch (ApiErrorException $e) {
            // Already completed or expired; nothing to undo.
            Log::info('Stripe checkout session of a recovery offer was not expired.', ['stripe_session' => $sessionId, 'stripe_error' => $e->getStripeCode()]);
        }
    }

    /**
     * Public so tests can check them without calling Stripe.
     *
     * @return array<string, mixed>
     */
    public function sessionParameters(RecoveryOffer $offer): array
    {
        $metadata = ['recovery_offer' => $offer->uuid, 'kind' => 'recovery_offer'];

        // Stripe accepts sessions that last from 30 minutes to 24 hours; never past the offer's validity.
        $expires = Carbon::now()->addHours(23);
        if ($offer->valid_until->lt($expires)) {
            $expires = $offer->valid_until->copy();
        }
        $expires = $expires->max(Carbon::now()->addMinutes(31));

        return [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($offer->currency),
                    'unit_amount' => $offer->amount,
                    'product_data' => ['name' => mb_substr($offer->title, 0, 120)],
                ],
            ]],
            ...($offer->customer?->stripe_customer_id
                ? ['customer' => $offer->customer->stripe_customer_id]
                : ['customer_email' => $offer->customer->email]),
            'client_reference_id' => $offer->uuid,
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'locale' => Locales::isSupported(app()->getLocale()) ? app()->getLocale() : 'auto',
            'expires_at' => $expires->getTimestamp(),
            'success_url' => route('account.offers.show', $offer).'?payment=returned',
            'cancel_url' => route('account.offers.show', $offer),
        ];
    }

    private function client(): StripeClient
    {
        if (! $this->secret) {
            throw CheckoutUnavailable::notConfigured();
        }

        if (str_contains($this->secret, '_live_') && ! $this->allowLiveMode) {
            throw CheckoutUnavailable::liveKeyRefused();
        }

        return $this->client ??= new StripeClient($this->secret);
    }
}
