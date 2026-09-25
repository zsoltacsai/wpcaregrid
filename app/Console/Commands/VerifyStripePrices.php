<?php

namespace App\Console\Commands;

use App\Pricing\BillingInterval;
use App\Pricing\Price;
use App\Pricing\PricingCatalog;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Checks every configured Stripe Price ID against Stripe: it must exist, be
 * active, recurring on the right interval, and charge exactly the amount and
 * currency in config/pricing.php. Run after changing prices in either place.
 */
class VerifyStripePrices extends Command
{
    protected $signature = 'pricing:verify-stripe';

    protected $description = 'Compare configured Stripe Price IDs with the pricing catalogue';

    public function handle(PricingCatalog $catalog): int
    {
        $secret = config('services.stripe.secret');

        if (! $secret) {
            $this->error('STRIPE_SECRET is not set.');

            return self::FAILURE;
        }

        $stripe = new StripeClient($secret);
        $rows = [];
        $problems = 0;

        foreach ($catalog->plans() as $plan) {
            foreach (BillingInterval::cases() as $interval) {
                foreach (array_keys(config('pricing.markets')) as $marketKey) {
                    $price = $catalog->price($plan->productType, $plan->tier, $interval, $catalog->market($marketKey));

                    if (! $price) {
                        continue;
                    }

                    $issue = $this->check($stripe, $price, $interval);
                    $problems += $issue === 'ok' ? 0 : 1;
                    $rows[] = ["{$plan->productType->value}.{$plan->tier->value}.{$interval->value}.{$marketKey}", $price->stripePriceId ?: '—', $issue];
                }
            }
        }

        $this->table(['Price', 'Stripe Price ID', 'Result'], $rows);

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(StripeClient $stripe, Price $price, BillingInterval $interval): string
    {
        if (! $price->hasStripePrice()) {
            return 'missing ID';
        }

        try {
            $remote = $stripe->prices->retrieve($price->stripePriceId);
        } catch (ApiErrorException $e) {
            return 'not found ('.($e->getStripeCode() ?? 'error').')';
        }

        $expectedInterval = $interval === BillingInterval::Annual ? 'year' : 'month';

        return match (true) {
            ! $remote->active => 'inactive in Stripe',
            strtoupper($remote->currency) !== $price->money->currency->code => "currency {$remote->currency}",
            $remote->unit_amount !== $price->money->amount => "amount {$remote->unit_amount}",
            ($remote->recurring->interval ?? null) !== $expectedInterval || ($remote->recurring->interval_count ?? 1) !== 1 => 'wrong interval',
            default => 'ok',
        };
    }
}
