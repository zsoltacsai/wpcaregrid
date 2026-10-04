<?php

namespace App\Console\Commands;

use App\Pricing\BillingInterval;
use App\Pricing\PricingCatalog;
use App\Pricing\StripePriceVerifier;
use Illuminate\Console\Command;

/**
 * Checks every configured Stripe Price ID against Stripe: it must exist, be
 * active, recurring on the right interval, and charge exactly the amount and
 * currency in the catalogue (config/pricing.php plus prices published in the
 * owner area), and belong to the Stripe Product configured for its product
 * type. Run after changing prices in either place.
 */
class VerifyStripePrices extends Command
{
    protected $signature = 'pricing:verify-stripe';

    protected $description = 'Compare configured Stripe Price IDs with the pricing catalogue';

    public function handle(PricingCatalog $catalog, StripePriceVerifier $verifier): int
    {
        if (! $verifier->configured()) {
            $this->error('STRIPE_SECRET is not set.');

            return self::FAILURE;
        }

        $rows = [];
        $problems = 0;

        foreach ($catalog->plans() as $plan) {
            foreach (BillingInterval::cases() as $interval) {
                foreach (array_keys(config('pricing.markets')) as $marketKey) {
                    $price = $catalog->price($plan->productType, $plan->tier, $interval, $catalog->market($marketKey));

                    if (! $price) {
                        continue;
                    }

                    $issue = $verifier->verify($price->stripePriceId, $price->money->amount, $price->money->currency->code, $interval, $plan->productType);
                    $problems += $issue === StripePriceVerifier::OK ? 0 : 1;
                    $rows[] = ["{$plan->productType->value}.{$plan->tier->value}.{$interval->value}.{$marketKey}", $price->stripePriceId ?: '—', $issue];
                }
            }
        }

        $this->table(['Price', 'Stripe Price ID', 'Result'], $rows);

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
