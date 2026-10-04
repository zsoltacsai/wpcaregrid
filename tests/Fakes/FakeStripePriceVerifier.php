<?php

namespace Tests\Fakes;

use App\Pricing\BillingInterval;
use App\Pricing\StripePriceVerifier;
use Stripe\StripeClient;

/**
 * Stripe as seen by StripePriceVerifier, without any network: the result of
 * the Price check and the Product each Price belongs to. verify() and
 * expectedProduct() are the real ones, so the product rule is exercised.
 */
class FakeStripePriceVerifier extends StripePriceVerifier
{
    /** check() result for every Price ID not listed in $results. */
    public string $result = self::OK;

    /** @var array<string, string> check() result per Price ID */
    public array $results = [];

    /**
     * The Product Stripe reports per Price ID. Unlisted IDs named like
     * "price_<product type>_…" belong to "prod_<product type>".
     *
     * @var array<string, string>
     */
    public array $products = [];

    /** @var list<array{0: ?string, 1: int, 2: string, 3: string}> */
    public array $checked = [];

    public function check(?string $stripePriceId, int $amount, string $currency, BillingInterval $interval): string
    {
        $this->checked[] = [$stripePriceId, $amount, $currency, $interval->value];

        return $this->results[$stripePriceId] ?? $this->result;
    }

    public function productOf(string $stripePriceId): ?string
    {
        if (array_key_exists($stripePriceId, $this->products)) {
            return $this->products[$stripePriceId];
        }

        return preg_match('/^price_(wordpress|woocommerce)_/', $stripePriceId, $m) ? "prod_{$m[1]}" : null;
    }

    protected function client(): StripeClient
    {
        throw new \LogicException('Tests never call Stripe.');
    }
}
