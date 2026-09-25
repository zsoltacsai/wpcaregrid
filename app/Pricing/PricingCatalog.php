<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * Read model over config/pricing.php for a single currency.
 *
 * Presentation code asks the catalogue for plans and prices instead of
 * reading config directly, so the source can later move to Stripe or a
 * database without changing the views.
 */
final class PricingCatalog
{
    /**
     * @param  array<string, ProductLine>  $productLines
     */
    public function __construct(
        public readonly Currency $currency,
        private readonly array $productLines,
        public readonly string $defaultProductLine,
        public readonly BillingInterval $defaultInterval,
    ) {}

    /** @param  array<string, mixed>  $config */
    public static function fromConfig(array $config, ?string $currencyCode = null): self
    {
        $currencyCode ??= $config['default_currency'];

        if (! isset($config['currencies'][$currencyCode])) {
            throw new InvalidArgumentException("Currency [{$currencyCode}] is not configured for pricing.");
        }

        $currency = Currency::fromConfig($currencyCode, $config['currencies'][$currencyCode]);

        $productLines = [];

        foreach ($config['product_lines'] as $lineKey => $line) {
            $plans = [];

            foreach ($line['plans'] as $planKey => $plan) {
                $prices = [];

                foreach (BillingInterval::cases() as $interval) {
                    $price = $plan['prices'][$currencyCode][$interval->value]
                        ?? throw new InvalidArgumentException("Plan [{$lineKey}-{$planKey}] has no {$interval->value} price in {$currencyCode}.");

                    $prices[$interval->value] = new Price(
                        money: new Money((int) $price['amount'], $currency),
                        interval: $interval,
                        stripePriceId: $price['stripe_price_id'] ?? null,
                    );
                }

                $plans[] = new Plan(
                    productLine: $lineKey,
                    key: $planKey,
                    name: $plan['name'],
                    description: $plan['description'] ?? '',
                    features: $plan['features'] ?? [],
                    prices: $prices,
                    highlighted: $plan['highlighted'] ?? false,
                    badge: $plan['badge'] ?? null,
                );
            }

            $productLines[$lineKey] = new ProductLine(
                key: $lineKey,
                label: $line['label'],
                summary: $line['summary'] ?? '',
                plans: $plans,
            );
        }

        return new self(
            currency: $currency,
            productLines: $productLines,
            defaultProductLine: $config['default_product_line'] ?? array_key_first($productLines),
            defaultInterval: BillingInterval::from($config['default_interval'] ?? 'monthly'),
        );
    }

    /** @return array<string, ProductLine> */
    public function productLines(): array
    {
        return $this->productLines;
    }

    public function productLine(string $key): ProductLine
    {
        return $this->productLines[$key]
            ?? throw new InvalidArgumentException("Unknown product line [{$key}].");
    }

    /** @return list<Plan> */
    public function plans(): array
    {
        return array_merge(...array_values(array_map(fn (ProductLine $line) => $line->plans, $this->productLines)));
    }

    /**
     * The annual discount every plan is guaranteed to get, in months.
     * Used for the "2 months free" message so it never overstates a plan.
     */
    public function annualFreeMonths(): int
    {
        $months = array_map(fn (Plan $plan) => $plan->freeMonthsOnAnnual(), $this->plans());

        return $months === [] ? 0 : min($months);
    }
}
