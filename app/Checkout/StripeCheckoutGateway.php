<?php

namespace App\Checkout;

use App\Models\CheckoutIntent;
use App\Models\Promotion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Creates Stripe Checkout Sessions in subscription mode from a stored
 * CheckoutIntent. The Stripe Price ID always comes from the intent, which
 * the server filled from the pricing catalogue.
 */
final class StripeCheckoutGateway implements CheckoutGateway
{
    public function __construct(
        private readonly ?string $secret,
        private readonly bool $allowLiveMode = false,
        private ?StripeClient $client = null,
    ) {}

    public function createSession(CheckoutIntent $intent, ?Promotion $promotion): CheckoutSession
    {
        try {
            $session = $this->client()->checkout->sessions->create(
                $this->sessionParameters($intent, $promotion),
                ['idempotency_key' => "checkout-intent-{$intent->uuid}"],
            );
        } catch (ApiErrorException $e) {
            // Stripe error messages never contain the secret key or card data.
            Log::warning('Stripe checkout session could not be created.', [
                'checkout_intent' => $intent->uuid,
                'stripe_error' => $e->getStripeCode() ?? $e::class,
                'stripe_request_id' => $e->getRequestId(),
            ]);

            throw CheckoutUnavailable::providerError();
        }

        return new CheckoutSession(
            id: $session->id,
            url: $session->url,
            expiresAt: Carbon::createFromTimestamp($session->expires_at),
        );
    }

    public function expireSession(string $sessionId): void
    {
        try {
            $this->client()->checkout->sessions->expire($sessionId);
        } catch (ApiErrorException $e) {
            // Already completed or expired; nothing to undo.
            Log::info('Stripe checkout session was not expired.', ['stripe_session' => $sessionId, 'stripe_error' => $e->getStripeCode()]);
        }
    }

    /**
     * Parameters for the Checkout Session. Public so tests can check them
     * without calling Stripe.
     *
     * @return array<string, mixed>
     */
    public function sessionParameters(CheckoutIntent $intent, ?Promotion $promotion): array
    {
        $metadata = [
            'checkout_intent' => $intent->uuid,
            'product_type' => $intent->product_type->value,
            'plan' => $intent->plan->value,
            'interval' => $intent->interval->value,
            'market' => $intent->market,
        ];

        $parameters = [
            'mode' => 'subscription',
            'line_items' => [['price' => $intent->stripe_price_id, 'quantity' => 1]],
            'customer_email' => $intent->email,
            'client_reference_id' => $intent->uuid,
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
            'locale' => 'auto',
            // Stripe replaces {CHECKOUT_SESSION_ID}; it must not be URL-encoded.
            'success_url' => route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('checkout.cancel', $intent),
        ];

        // Codes are only accepted through our form, after our own eligibility
        // check. Stripe's own code field stays off so it cannot be bypassed.
        if ($promotion) {
            $parameters['discounts'] = [['promotion_code' => $promotion->stripe_promotion_code_id]];
        }

        return $parameters;
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
