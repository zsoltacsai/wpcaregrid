<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * Read model over config/pricing.php for one currency and locale.
 *
 * Presentation code asks the catalogue for plans and prices instead of
 * reading config directly, so the source can later move to Stripe or a
 * database without changing the views. Plan text comes from
 * lang/{locale}/pricing.php.
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

    /**
     * @param  array<string, mixed>  $config  config('pricing')
     * @param  string|null  $currencyCode  Defaults to the currency mapped to the locale.
     */
    public static function fromConfig(array $config, ?string $currencyCode = null, ?string $locale = null): self
    {
        $locale ??= app()->getLocale();
        $currencyCode ??= $config['locale_currencies'][$locale] ?? $config['default_currency'];

        if (! isset($config['currencies'][$currencyCode])) {
            throw new InvalidArgumentException("Currency [{$currencyCode}] is not configured for pricing.");
        }

        $currency = Currency::fromConfig($currencyCode, $config['currencies'][$currencyCode], $locale);

        $productLines = [];

        foreach ($config['product_lines'] as $lineKey => $line) {
            $plans = [];

            foreach ($line['plans'] as $planKey => $plan) {
                $id = "{$lineKey}-{$planKey}";
                $priceSet = self::priceSet($plan['prices'] ?? [], $currencyCode, $locale)
                    ?? throw new InvalidArgumentException("Plan [{$id}] has no {$currencyCode} prices.");

                $prices = [];

                foreach (BillingInterval::cases() as $interval) {
                    $price = $priceSet[$interval->value]
                        ?? throw new InvalidArgumentException("Plan [{$id}] has no {$interval->value} price in {$currencyCode}.");

                    $prices[$interval->value] = new Price(
                        money: new Money((int) $price['amount'], $currency),
                        interval: $interval,
                        stripePriceId: $price['stripe_price_id'] ?? null,
                        locale: $priceSet['locale'] ?? null,
                    );
                }

                $text = "pricing.product_lines.{$lineKey}.plans.{$planKey}";
                $backupFrequency = $plan['backup_frequency'] ?? null;
                $features = array_map(
                    fn (string $feature) => $feature === ':backups' && $backupFrequency
                        ? __("pricing.backups.{$backupFrequency}")
                        : $feature,
                    (array) __("{$text}.features"),
                );

                $plans[] = new Plan(
                    productLine: $lineKey,
                    key: $planKey,
                    name: __("{$text}.name"),
                    description: __("{$text}.description"),
                    features: $features,
                    prices: $prices,
                    highlighted: $plan['highlighted'] ?? false,
                    badge: isset($plan['badge']) ? __("pricing.badges.{$plan['badge']}") : null,
                    backupFrequency: $backupFrequency,
                );
            }

            $productLines[$lineKey] = new ProductLine(
                key: $lineKey,
                label: __("pricing.product_lines.{$lineKey}.label"),
                summary: __("pricing.product_lines.{$lineKey}.summary"),
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

    /**
     * The price set for a currency, preferring one limited to the locale
     * over a general one.
     *
     * @param  list<array<string, mixed>>  $sets
     * @return array<string, mixed>|null
     */
    private static function priceSet(array $sets, string $currency, string $locale): ?array
    {
        $matching = array_filter($sets, fn (array $set) => $set['currency'] === $currency
            && in_array($set['locale'] ?? null, [null, $locale], true));

        usort($matching, fn (array $a, array $b) => isset($b['locale']) <=> isset($a['locale']));

        return $matching[0] ?? null;
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
