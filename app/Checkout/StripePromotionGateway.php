<?php

namespace App\Checkout;

use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Creates and switches Stripe coupons and promotion codes for the owner
 * area. The same key rules as checkout apply: no key, no coupons; a live key
 * is refused unless live mode was enabled deliberately. Error details are
 * logged without secrets and never shown.
 */
final class StripePromotionGateway implements PromotionGateway
{
    public function __construct(
        private readonly ?string $secret,
        private readonly bool $allowLiveMode = false,
        private ?StripeClient $client = null,
    ) {}

    public function create(string $code, string $percentOff, string $duration, ?int $months, string $name): array
    {
        $client = $this->client();

        try {
            // A code is unique among active promotion codes in Stripe; stop before creating anything.
            if ($client->promotionCodes->all(['code' => $code, 'active' => true, 'limit' => 1])->data !== []) {
                throw new PromotionGatewayException('code_in_stripe');
            }

            $coupon = $client->coupons->create([
                'percent_off' => (float) $percentOff,
                'duration' => $duration,
                ...($duration === 'repeating' ? ['duration_in_months' => $months] : []),
                'name' => mb_substr($name, 0, 40),
                'metadata' => ['managed_by' => 'wp-caregrid-owner'],
            ]);

            try {
                $promotionCode = $client->promotionCodes->create([
                    'promotion' => ['type' => 'coupon', 'coupon' => $coupon->id],
                    'code' => $code,
                    'active' => true,
                    'metadata' => ['managed_by' => 'wp-caregrid-owner'],
                ]);
            } catch (ApiErrorException $e) {
                // Do not leave a coupon behind that no code points to.
                $this->quietly(fn () => $client->coupons->delete($coupon->id));

                throw $e;
            }
        } catch (ApiErrorException $e) {
            $this->log('create', $e);

            throw new PromotionGatewayException('provider');
        }

        return ['coupon' => $coupon->id, 'promotion_code' => $promotionCode->id];
    }

    public function setActive(string $promotionCodeId, bool $active): void
    {
        try {
            $this->client()->promotionCodes->update($promotionCodeId, ['active' => $active]);
        } catch (ApiErrorException $e) {
            $this->log('set_active', $e);

            throw new PromotionGatewayException('provider');
        }
    }

    public function remove(?string $couponId, ?string $promotionCodeId): void
    {
        $client = $this->client();

        if ($promotionCodeId) {
            $this->quietly(fn () => $client->promotionCodes->update($promotionCodeId, ['active' => false]));
        }

        if ($couponId) {
            $this->quietly(fn () => $client->coupons->delete($couponId));
        }
    }

    private function quietly(callable $call): void
    {
        try {
            $call();
        } catch (ApiErrorException $e) {
            $this->log('cleanup', $e);
        }
    }

    private function log(string $operation, ApiErrorException $e): void
    {
        // Stripe error messages never contain the secret key.
        Log::warning('Stripe coupon call failed.', ['operation' => $operation, 'stripe_error' => $e->getStripeCode() ?? $e::class, 'stripe_request_id' => $e->getRequestId()]);
    }

    private function client(): StripeClient
    {
        if (! $this->secret) {
            throw new PromotionGatewayException('not_configured');
        }

        if (str_contains($this->secret, '_live_') && ! $this->allowLiveMode) {
            throw new PromotionGatewayException('live_key_refused');
        }

        return $this->client ??= new StripeClient($this->secret);
    }
}
