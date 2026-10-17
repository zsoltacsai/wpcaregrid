<?php

namespace App\Pricing;

use App\Pricing\Exceptions\InvalidPriceSelection;
use App\Pricing\Exceptions\MarketCurrencyMismatch;
use App\Pricing\Exceptions\MarketNotAvailable;
use App\Pricing\Exceptions\PriceInactive;
use App\Pricing\Exceptions\PriceNotPurchasable;
use ArrayAccess;
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

    /**
     * @param  array<string, mixed>  $config  config('pricing'), with published owner prices applied (PricingOverrides)
     * @param  array<string, ArrayAccess<string, mixed>|array<string, mixed>>  $marketing  Owner-written plan wording,
     *                                                                                     keyed "{product}.{tier}" (ContentRepository::planMarketing())
     */
    public static function fromConfig(array $config, ?string $locale = null, array $marketing = []): self
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
                group: $market['group'] ?? ($key === 'hu' ? 'HU' : 'INTERNATIONAL'),
            );
        }

        $plans = [];
        foreach ($config['plans'] as $productKey => $tiers) {
            $productType = ProductType::from($productKey);

            foreach ($tiers as $tierKey => $plan) {
                $tier = PlanTier::from($tierKey);
                $text = "pricing.plans.{$productKey}.{$tierKey}";
                $owner = $marketing["{$productKey}.{$tierKey}"] ?? null;
                $features = array_map(fn (string $key) => self::feature($key, $plan), $plan['features']);
                $backupRecoveryPoints = $plan['backup_recovery_points'] ?? null;
                if (! is_array($backupRecoveryPoints)
                    || array_diff(['daily', 'weekly', 'monthly'], array_keys($backupRecoveryPoints)) !== []
                    || array_filter(['daily', 'weekly', 'monthly'], fn (string $class) => ! is_int($backupRecoveryPoints[$class]) || $backupRecoveryPoints[$class] < 0) !== []) {
                    throw new InvalidArgumentException("Plan [{$productKey}.{$tierKey}] has invalid backup recovery-point entitlements.");
                }
                if (! is_bool($plan['manual_backup_allowed'] ?? null) || ! is_bool($plan['restore_eligible'] ?? null)) {
                    throw new InvalidArgumentException("Plan [{$productKey}.{$tierKey}] has incomplete backup feature entitlements.");
                }

                if ($owner !== null) {
                    // Wording only: what a plan includes technically (backups, checks) stays in config.
                    $custom = array_values(array_filter(array_map(fn ($item) => trim((string) ($item['label'] ?? '')), (array) $owner['features'])));
                    $features = $custom === [] ? $features : array_map(fn (string $label, int $i) => new Feature("custom.{$i}", $label), $custom, array_keys($custom));
                }

                $plans["{$productKey}.{$tierKey}"] = new Plan(
                    productType: $productType,
                    tier: $tier,
                    name: $owner !== null ? (string) $owner['name'] : __("{$text}.name"),
                    description: $owner !== null ? (string) $owner['description'] : __("{$text}.description"),
                    features: $features,
                    backupFrequency: $plan['backup_frequency'],
                    backupRecoveryPoints: [
                        'daily' => $backupRecoveryPoints['daily'],
                        'weekly' => $backupRecoveryPoints['weekly'],
                        'monthly' => $backupRecoveryPoints['monthly'],
                    ],
                    manualBackupAllowed: $plan['manual_backup_allowed'],
                    restoreEligible: $plan['restore_eligible'],
                    highlighted: $plan['highlighted'] ?? false,
                    badge: $owner !== null
                        ? (filled($owner['badge']) ? (string) $owner['badge'] : null)
                        : (isset($plan['badge']) ? __("pricing.badges.{$plan['badge']}") : null),
                    cta: $owner !== null && filled($owner['cta_label']) ? (string) $owner['cta_label'] : null,
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
                marketGroup: $market->group,
                stripePriceConfigKey: (string) ($price['stripe_price_config_key'] ?? ''),
                money: new Money((int) $price['amount'], $currencies[$price['currency']]),
                stripePriceId: $price['stripe_price_id'] ?? null,
                active: (bool) ($price['active'] ?? true),
                retiredStripePriceIds: array_values(array_filter((array) ($price['retired_stripe_price_ids'] ?? []), 'is_string')),
                version: $price['pricing_version'] ?? 'config-'.substr(hash('sha256', implode('|', [
                    $price['product_type'], $price['plan'], $price['interval'], $price['market'],
                    $price['currency'], (string) $price['amount'], (string) ($price['stripe_price_id'] ?? ''),
                ])), 0, 32),
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
        return new Feature(
            key: $key,
            label: self::featureLabel($key, $plan),
            frequency: self::featureFrequency($key, $plan),
        );
    }

    /**
     * A feature's label in a locale (the current one by default).
     *
     * @param  array<string, mixed>  $plan
     */
    public static function featureLabel(string $key, array $plan, ?string $locale = null): string
    {
        $frequency = self::featureFrequency($key, $plan);

        return __($frequency ? "pricing.features.{$key}.{$frequency}" : "pricing.features.{$key}", [], $locale);
    }

    /** @param  array<string, mixed>  $plan */
    private static function featureFrequency(string $key, array $plan): ?string
    {
        return match ($key) {
            'remote_backups' => $plan['backup_frequency'] ?? null,
            'security_check' => $plan['security_check_frequency'] ?? null,
            default => null,
        };
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

    /**
     * Currencies a customer can really buy in: those of markets that have
     * prices in the catalogue (config/pricing.php, owner-published prices).
     * A market without prices contributes nothing, so no currency is offered
     * before its real prices exist.
     *
     * @return list<string> Currency codes, in market order.
     */
    public function sellableCurrencies(): array
    {
        $enabled = (array) config('owner_settings.pricing.enabled_currencies', config('pricing.enabled_currencies', array_keys($this->currencies)));
        $codes = [];
        foreach (array_values(array_unique($enabled)) as $currencyCode) {
            foreach ($this->markets as $market) {
                if ($market->currencyCode === $currencyCode && $this->hasPrices($market)) {
                    $codes[] = $currencyCode;
                    break;
                }
            }
        }

        return $codes;
    }

    /** Recovery offers are one-off manual prices and remain HUF-only in this milestone. */
    public function recoveryOfferCurrencies(): array
    {
        return in_array('HUF', $this->sellableCurrencies(), true) ? ['HUF'] : [];
    }

    /** The configured international default when enabled, otherwise an enabled international choice. */
    public function defaultInternationalCurrency(): ?string
    {
        $configured = (string) config('owner_settings.pricing.default_international_currency', config('pricing.default_international_currency', 'EUR'));
        if (in_array($configured, $this->sellableCurrencies(), true) && $configured !== 'HUF') {
            return $configured;
        }

        foreach (['EUR', 'USD', 'GBP'] as $currency) {
            if (in_array($currency, $this->sellableCurrencies(), true)) {
                return $currency;
            }
        }

        return null;
    }

    /** The first market with prices that sells in this currency. */
    public function marketForCurrency(string $currencyCode): ?Market
    {
        $configured = config("pricing.currency_markets.{$currencyCode}");
        if (is_string($configured) && isset($this->markets[$configured]) && $this->hasPrices($this->markets[$configured])) {
            return $this->markets[$configured];
        }

        foreach ($this->markets as $market) {
            if ($market->currencyCode === $currencyCode && $this->hasPrices($market)) {
                return $market;
            }
        }

        return null;
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

    /**
     * The price with this Stripe Price ID, if any: the current one, else a
     * price that used it before (an existing subscription keeps its plan
     * after the owner changes the price for new checkouts).
     */
    public function priceByStripeId(string $stripePriceId): ?Price
    {
        foreach ($this->prices as $price) {
            if ($price->hasStripePrice() && $price->stripePriceId === $stripePriceId) {
                return $price;
            }
        }

        foreach ($this->prices as $price) {
            if (in_array($stripePriceId, $price->retiredStripePriceIds, true)) {
                return $price;
            }
        }

        return null;
    }

    /** @return list<Price> Every price, active or not, in every market. */
    public function allPrices(): array
    {
        return $this->prices;
    }

    /** @return array<string, Market> */
    public function markets(): array
    {
        return $this->markets;
    }

    /**
     * Resolve what a customer may be charged for a selection, based on their
     * billing country. The only way checkout obtains a price.
     *
     * @throws MarketNotAvailable when the country's market has no price for this plan
     * @throws PriceInactive when the price exists but is not being sold
     * @throws PriceNotPurchasable when no Stripe Price ID is configured
     */
    public function resolve(PriceSelection $selection, string $country, ?string $currency = null): Price
    {
        $country = strtoupper(trim($country));
        $hungarian = $country === 'HU';
        $currency ??= $hungarian ? 'HUF' : $this->defaultInternationalCurrency();

        if ($hungarian && $currency !== 'HUF') {
            throw new MarketCurrencyMismatch;
        }
        if (! $hungarian && ($currency === 'HUF' || ! in_array($currency, $this->sellableCurrencies(), true))) {
            throw new MarketCurrencyMismatch;
        }

        $market = $hungarian ? $this->market('hu') : ($this->marketForCurrency($currency) ?? throw new MarketCurrencyMismatch);
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
