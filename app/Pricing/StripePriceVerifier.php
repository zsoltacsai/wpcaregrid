<?php

namespace App\Pricing;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Compares one Stripe Price with what the catalogue says it charges: it must
 * exist, be active, recurring on the right interval, in the same currency
 * and amount, and (productOf) belong to the configured Stripe Product. Read-only: nothing is created or changed in Stripe. Used by
 * pricing:verify-stripe and before an owner publishes a price.
 */
class StripePriceVerifier
{
    public const OK = 'ok';

    public function configured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /** "ok", or what is wrong ("missing ID", "not found (…)", "amount 100"…). */
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
