<?php

namespace App\Pricing;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Compares one Stripe Price with what the catalogue says it charges: it must
 * exist, be active, recurring on the right interval, in the same currency
 * and amount, and belong to the Stripe Product configured for its product
 * type (STRIPE_PRODUCT_WORDPRESS / STRIPE_PRODUCT_WOOCOMMERCE). The expected
 * product always comes from configuration, never from a request.
 *
 * Read-only: nothing is created or changed in Stripe. verify() is the one
 * check used by pricing:verify-stripe, the owner's "Check prices in
 * Stripe" and publishing a price in the owner area.
 */
class StripePriceVerifier
{
    public const OK = 'ok';

    public function configured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * The full check of a catalogue price: check(), then the product.
     * "ok", or what is wrong ("amount 100", "product prod_…"…).
     */
    public function verify(?string $stripePriceId, int $amount, string $currency, BillingInterval $interval, ProductType $productType): string
    {
        $result = $this->check($stripePriceId, $amount, $currency, $interval);
        if ($result !== self::OK) {
            return $result;
        }

        $expected = $this->expectedProduct($productType);
        if ($expected === null) {
            return 'product not configured (STRIPE_PRODUCT_'.strtoupper($productType->value).')';
        }

        $actual = $this->productOf((string) $stripePriceId);

        return $actual === $expected ? self::OK : 'product '.($actual ?? 'unreadable');
    }

    /** The configured Stripe Product for a product type, or null when not set. */
    public function expectedProduct(ProductType $productType): ?string
    {
        $product = config("pricing.stripe_products.{$productType->value}");

        return is_string($product) && $product !== '' ? $product : null;
    }

    /** "ok", or what is wrong with the Price itself ("missing ID", "not found (…)", "amount 100"…). */
    public function check(?string $stripePriceId, int $amount, string $currency, BillingInterval $interval): string
    {
        if (blank($stripePriceId)) {
            return 'missing ID';
        }

        try {
            $remote = $this->client()->prices->retrieve($stripePriceId);
        } catch (ApiErrorException $e) {
            return 'not found ('.($e->getStripeCode() ?? 'error').')';
        }

        $expectedInterval = $interval === BillingInterval::Annual ? 'year' : 'month';

        return match (true) {
            ! $remote->active => 'inactive in Stripe',
            ! (bool) config('services.stripe.allow_live_mode') && (bool) ($remote->livemode ?? false) => 'live mode price',
            strtoupper((string) $remote->currency) !== $currency => "currency {$remote->currency}",
            $remote->unit_amount !== $amount => "amount {$remote->unit_amount}",
            ($remote->recurring->interval ?? null) !== $expectedInterval || ($remote->recurring->interval_count ?? 1) !== 1 => 'wrong interval',
            default => self::OK,
        };
    }

    /** The Stripe Product a Price belongs to, or null when the Price cannot be read. */
    public function productOf(string $stripePriceId): ?string
    {
        try {
            $product = $this->client()->prices->retrieve($stripePriceId)->product;
        } catch (ApiErrorException) {
            return null;
        }

        return is_string($product) ? $product : ($product->id ?? null);
    }

    protected function client(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }
}
