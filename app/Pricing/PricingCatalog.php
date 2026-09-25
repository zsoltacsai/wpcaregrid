<?php

namespace App\Pricing;

use App\Pricing\Exceptions\InvalidPriceSelection;
use App\Pricing\Exceptions\MarketNotAvailable;
use App\Pricing\Exceptions\PriceInactive;
use App\Pricing\Exceptions\PriceNotPurchasable;
use InvalidArgumentException;

/**
 * Read model over config/pricing.php.
 *
 * The public pricing table and the checkout both go through this class, so
 * they always agree. Plan wording comes from lang/{locale}/pricing.php.
 */
final class PricingCatalog
{
    /**
     * @param  array<string, Plan>  $plans  Keyed by "{product}.{tier}".
     * @param  array<string, Market>  $markets  In matching order.
     * @param  array<string, Currency>  $currencies
     * @param  list<Price>  $prices
     */
    public function __construct(
        private readonly array $plans,
        private readonly array $markets,
        private readonly array $currencies,
        private readonly array $prices,
        public readonly string $displayMarket,
        public readonly ProductType $defaultProductType,
        public readonly BillingInterval $defaultInterval,
    ) {}

    /** @param  array<string, mixed>  $config  config('pricing') */
    public static function fromConfig(array $config, ?string $locale = null): self
    {
        $locale ??= app()->getLocale();

        $currencies = [];
        foreach ($config['currencies'] as $code => $currency) {
            $currencies[$code] = Currency::fromConfig($code, $currency, $locale);
        }

        $markets = [];
        foreach ($config['markets'] as $key => $market) {
            if (! isset($currencies[$market['currency']])) {
                throw new InvalidArgumentException("Market [{$key}] uses unknown currency [{$market['currency']}].");
            }

            $markets[$key] = new Market(
                key: $key,
                countries: array_map('strtoupper', $market['countries']),
                currencyCode: $market['currency'],
                taxTreatment: TaxTreatment::from($market['tax_treatment']),
            );
        }

        $plans = [];
        foreach ($config['plans'] as $productKey => $tiers) {
            $productType = ProductType::from($productKey);

            foreach ($tiers as $tierKey => $plan) {
                $tier = PlanTier::from($tierKey);
                $text = "pricing.plans.{$productKey}.{$tierKey}";

                $plans["{$productKey}.{$tierKey}"] = new Plan(
                    productType: $productType,
                    tier: $tier,
                    name: __("{$text}.name"),
                    description: __("{$text}.description"),
                    features: array_map(fn (string $key) => self::feature($key, $plan), $plan['features']),
                    backupFrequency: $plan['backup_frequency'],
                    highlighted: $plan['highlighted'] ?? false,
                    badge: isset($plan['badge']) ? __("pricing.badges.{$plan['badge']}") : null,
                );
            }
        }

        $prices = [];
        foreach ($config['prices'] as $index => $price) {
            $market = $markets[$price['market']] ?? throw new InvalidArgumentException("Price #{$index} uses unknown market [{$price['market']}].");

            if ($price['currency'] !== $market->currencyCode) {
                throw new InvalidArgumentException("Price #{$index} is in {$price['currency']} but market [{$market->key}] uses {$market->currencyCode}.");
            }

            if (! isset($plans["{$price['product_type']}.{$price['plan']}"])) {
                throw new InvalidArgumentException("Price #{$index} refers to unknown plan [{$price['product_type']}.{$price['plan']}].");
            }

            $prices[] = new Price(
                productType: ProductType::from($price['product_type']),
                tier: PlanTier::from($price['plan']),
                interval: BillingInterval::from($price['interval']),
                market: $market->key,
                money: new Money((int) $price['amount'], $currencies[$price['currency']]),
                stripePriceId: $price['stripe_price_id'] ?? null,
                active: (bool) ($price['active'] ?? true),
            );
        }

        return new self(
            plans: $plans,
            markets: $markets,
            currencies: $currencies,
            prices: $prices,
            displayMarket: $config['display_market'],
            defaultProductType: ProductType::from($config['default_product_type']),
            defaultInterval: BillingInterval::from($config['default_interval']),
        );
    }

    /**
     * Resolve a feature key to its label, filling in the plan's frequency
     * for features that have one.
     *
     * @param  array<string, mixed>  $plan
     */
    private static function feature(string $key, array $plan): Feature
    {
        $frequency = match ($key) {
            'remote_backups' => $plan['backup_frequency'],
            'security_check' => $plan['security_check_frequency'],
            default => null,
        };

        return new Feature(
            key: $key,
            label: __($frequency ? "pricing.features.{$key}.{$frequency}" : "pricing.features.{$key}"),
            frequency: $frequency,
        );
    }

    /** @return list<ProductType> */
    public function productTypes(): array
    {
        return ProductType::cases();
    }

    /** @return list<Plan> Plans of a product type, lowest tier first; all plans when null. */
    public function plans(?ProductType $productType = null): array
    {
        return array_values(array_filter(
            $this->plans,
            fn (Plan $plan) => $productType === null || $plan->productType === $productType,
        ));
    }

    public function plan(ProductType $productType, PlanTier $tier): Plan
    {
        return $this->plans["{$productType->value}.{$tier->value}"]
            ?? throw new InvalidPriceSelection;
    }

    public function market(string $key): Market
    {
        return $this->markets[$key] ?? throw new InvalidArgumentException("Unknown market [{$key}].");
    }

    /** The first market whose country rule covers the billing country. */
    public function marketForCountry(string $country): Market
    {
        foreach ($this->markets as $market) {
            if ($market->covers($country)) {
                return $market;
            }
        }

        throw new MarketNotAvailable;
    }

    public function currency(string $code): Currency
    {
        return $this->currencies[$code] ?? throw new InvalidArgumentException("Unknown currency [{$code}].");
    }

    public function hasPrices(Market $market): bool
    {
        foreach ($this->prices as $price) {
            if ($price->market === $market->key) {
                return true;
            }
        }

        return false;
    }

    public function price(ProductType $productType, PlanTier $tier, BillingInterval $interval, Market $market): ?Price
    {
        foreach ($this->prices as $price) {
            if ($price->productType === $productType
                && $price->tier === $tier
                && $price->interval === $interval
                && $price->market === $market->key) {
                return $price;
            }
        }

        return null;
    }

    /** The configured price with this Stripe Price ID, if any. */
    public function priceByStripeId(string $stripePriceId): ?Price
    {
        foreach ($this->prices as $price) {
            if ($price->hasStripePrice() && $price->stripePriceId === $stripePriceId) {
                return $price;
            }
        }

        return null;
    }

    /**
     * Resolve what a customer may be charged for a selection, based on their
     * billing country. The only way checkout obtains a price.
     *
     * @throws MarketNotAvailable when the country's market has no price for this plan
     * @throws PriceInactive when the price exists but is not being sold
     * @throws PriceNotPurchasable when no Stripe Price ID is configured
     */
    public function resolve(PriceSelection $selection, string $country): Price
    {
        $market = $this->marketForCountry($country);
        $price = $this->price($selection->productType, $selection->tier, $selection->interval, $market);

        if (! $price) {
            throw new MarketNotAvailable;
        }

        if (! $price->active) {
            throw new PriceInactive;
        }

        if (! $price->hasStripePrice()) {
            throw new PriceNotPurchasable;
        }

        return $price;
    }

    /**
     * Pricing cards for one product type in a market (the display market by
     * default). Plans without both prices in that market are left out.
     *
     * @return list<PlanOffer>
     */
    public function offers(ProductType $productType, ?Market $market = null): array
    {
        $market ??= $this->market($this->displayMarket);
        $offers = [];

        foreach ($this->plans($productType) as $plan) {
            $monthly = $this->price($productType, $plan->tier, BillingInterval::Monthly, $market);
            $annual = $this->price($productType, $plan->tier, BillingInterval::Annual, $market);

            if ($monthly && $annual) {
                $previous = $plan->tier->previous();

                $offers[] = new PlanOffer(
                    plan: $plan,
                    monthly: $monthly,
                    annual: $annual,
                    previousPlan: $previous ? $this->plan($productType, $previous) : null,
                );
            }
        }

        return $offers;
    }

    /**
     * The annual discount every displayed plan gets, in months. Used for the
     * "2 months free" message so it never overstates any plan.
     */
    public function annualFreeMonths(?Market $market = null): int
    {
        $months = [];

        foreach ($this->productTypes() as $productType) {
            foreach ($this->offers($productType, $market) as $offer) {
                $months[] = $offer->freeMonthsOnAnnual();
            }
        }

        return $months === [] ? 0 : min($months);
    }
}
