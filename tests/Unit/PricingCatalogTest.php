<?php

namespace Tests\Unit;

use App\Pricing\BillingInterval;
use App\Pricing\Exceptions\InvalidPriceSelection;
use App\Pricing\Exceptions\MarketCurrencyMismatch;
use App\Pricing\Exceptions\PriceInactive;
use App\Pricing\Exceptions\PriceNotPurchasable;
use App\Pricing\PlanTier;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use App\Pricing\TaxTreatment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PricingCatalogTest extends TestCase
{
    private function catalog(?string $locale = null): PricingCatalog
    {
        return PricingCatalog::fromConfig(config('pricing'), $locale);
    }

    /** Give every configured price a (fake-format) Stripe ID, as a deployed environment would. */
    private function withStripeIds(): void
    {
        foreach (array_keys(config('pricing.prices')) as $i) {
            config(["pricing.prices.{$i}.stripe_price_id" => "price_test_{$i}"]);
        }
    }

    /** @return array<string, array{string, string, int, int}> Amounts in forint. */
    public static function hungarianPrices(): array
    {
        return [
            'WordPress Basic' => ['wordpress', 'basic', 8990, 89900],
            'WordPress Business' => ['wordpress', 'business', 18990, 189900],
            'WordPress Secure' => ['wordpress', 'secure', 28990, 289900],
            'WooCommerce Basic' => ['woocommerce', 'basic', 14900, 149000],
            'WooCommerce Business' => ['woocommerce', 'business', 24900, 249000],
            'WooCommerce Secure' => ['woocommerce', 'secure', 39900, 399000],
        ];
    }

    #[DataProvider('hungarianPrices')]
    public function test_hungarian_prices(string $product, string $tier, int $monthly, int $annual): void
    {
        $catalog = $this->catalog();
        $market = $catalog->market('hu');

        $monthlyPrice = $catalog->price(ProductType::from($product), PlanTier::from($tier), BillingInterval::Monthly, $market);
        $annualPrice = $catalog->price(ProductType::from($product), PlanTier::from($tier), BillingInterval::Annual, $market);

        // Stored in fillér (minor units), as Stripe expects HUF amounts.
        $this->assertSame($monthly * 100, $monthlyPrice->money->amount);
        $this->assertSame($annual * 100, $annualPrice->money->amount);
        $this->assertSame('HUF', $monthlyPrice->money->currency->code);
        $this->assertSame('HUF', $annualPrice->money->currency->code);
    }

    public function test_every_annual_price_is_ten_times_monthly_and_stored_explicitly(): void
    {
        $rows = collect(config('pricing.prices'));

        $this->assertCount(48, $rows);

        foreach ($rows->where('interval', 'annual') as $annual) {
            $monthly = $rows->first(fn ($row) => $row['interval'] === 'monthly'
                && $row['product_type'] === $annual['product_type']
                && $row['plan'] === $annual['plan']
                && $row['market'] === $annual['market']
                && $row['currency'] === $annual['currency']);

            $this->assertNotNull($monthly);
            $this->assertIsInt($annual['amount']);
            $this->assertSame($monthly['amount'] * 10, $annual['amount']);
        }

        foreach (ProductType::cases() as $type) {
            foreach ($this->catalog()->offers($type) as $offer) {
                $this->assertSame(2, $offer->freeMonthsOnAnnual());
            }
        }
    }

    public function test_wordpress_and_woocommerce_are_priced_separately(): void
    {
        $catalog = $this->catalog();
        $wp = collect($catalog->offers(ProductType::WordPress))->map(fn ($o) => $o->monthly->money->amount)->all();
        $woo = collect($catalog->offers(ProductType::WooCommerce))->map(fn ($o) => $o->monthly->money->amount)->all();

        $this->assertSame([899000, 1899000, 2899000], $wp);
        $this->assertSame([1490000, 2490000, 3990000], $woo);
    }

    /** @return array<string, array{string, string, string}> */
    public static function backupFrequencies(): array
    {
        return [
            'WordPress Basic' => ['wordpress', 'basic', 'monthly'],
            'WordPress Business' => ['wordpress', 'business', 'weekly'],
            'WordPress Secure' => ['wordpress', 'secure', 'daily'],
            'WooCommerce Basic' => ['woocommerce', 'basic', 'weekly'],
            'WooCommerce Business' => ['woocommerce', 'business', 'daily'],
            'WooCommerce Secure' => ['woocommerce', 'secure', 'daily'],
        ];
    }

    #[DataProvider('backupFrequencies')]
    public function test_backup_frequency(string $product, string $tier, string $frequency): void
    {
        $plan = $this->catalog()->plan(ProductType::from($product), PlanTier::from($tier));
        $backups = array_values(array_filter($plan->features, fn ($f) => $f->key === 'remote_backups'));

        $this->assertSame($frequency, $plan->backupFrequency);
        $this->assertCount(1, $backups);
        $this->assertSame($frequency, $backups[0]->frequency);
        $this->assertSame(ucfirst($frequency).' remote backups', $backups[0]->label);
    }

    public function test_every_plan_has_a_complete_resolved_feature_list(): void
    {
        foreach (ProductType::cases() as $type) {
            $plans = $this->catalog()->plans($type);

            foreach ($plans as $index => $plan) {
                $keys = array_map(fn ($f) => $f->key, $plan->features);

                $this->assertNotContains('everything_in', $keys);
                $this->assertContains('remote_backups', $keys);
                $this->assertContains('security_check', $keys);

                // A higher tier includes every feature of the tier below.
                if ($index > 0) {
                    $lower = array_map(fn ($f) => $f->key, $plans[$index - 1]->features);
                    $this->assertSame([], array_values(array_diff($lower, $keys)), "{$plan->id()} is missing features of the tier below.");
                }

                foreach ($plan->featureLabels() as $label) {
                    $this->assertStringNotContainsString('pricing.', $label, 'Untranslated feature label.');
                }
            }
        }
    }

    public function test_plan_descriptions(): void
    {
        foreach (ProductType::cases() as $type) {
            $this->assertSame(
                ['Essential maintenance for smaller WordPress sites.', 'Regular maintenance for business websites.', 'Extra security and faster response.'],
                array_map(fn ($plan) => $plan->description, $this->catalog()->plans($type)),
            );
        }
    }

    public function test_business_is_the_only_highlighted_plan(): void
    {
        foreach (ProductType::cases() as $type) {
            $highlighted = array_values(array_filter($this->catalog()->plans($type), fn ($p) => $p->highlighted));

            $this->assertCount(1, $highlighted);
            $this->assertSame(PlanTier::Business, $highlighted[0]->tier);
            $this->assertSame('Most popular', $highlighted[0]->badge);
        }
    }

    public function test_hungary_resolves_to_huf_with_final_price_tax_treatment(): void
    {
        $this->withStripeIds();
        $catalog = $this->catalog();

        $market = $catalog->marketForCountry('HU');
        $price = $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Business, BillingInterval::Monthly), 'hu');

        $this->assertSame('hu', $market->key);
        $this->assertSame('HUF', $market->currencyCode);
        $this->assertSame(TaxTreatment::FinalPriceNoVatAdded, $market->taxTreatment);
        $this->assertSame('HUF', $price->money->currency->code);
        $this->assertSame(1899000, $price->money->amount);
    }

    public function test_money_is_formatted_for_the_locale(): void
    {
        $price = $this->catalog('en')->price(ProductType::WordPress, PlanTier::Business, BillingInterval::Annual, $this->catalog()->market('hu'));
        $hu = $this->catalog('hu')->price(ProductType::WordPress, PlanTier::Business, BillingInterval::Annual, $this->catalog('hu')->market('hu'));

        $this->assertSame('189,900', $price->money->formattedAmount());
        $this->assertSame("189,900\u{00A0}Ft", $price->money->format());
        $this->assertSame("189\u{202F}900\u{00A0}Ft", $hu->money->format());
    }

    public function test_currency_formatter_uses_integer_minor_units_without_float_arithmetic(): void
    {
        $catalog = $this->catalog('en');
        $this->assertSame('€49', $catalog->currency('EUR')->format(4900));
        $this->assertSame('$1,590', $catalog->currency('USD')->format(159000));
        $this->assertSame('89,900 Ft', $catalog->currency('HUF')->format(8990000));
    }

    public function test_fixed_international_prices_are_present_in_each_currency(): void
    {
        $rows = collect(config('pricing.prices'));
        $this->assertCount(48, $rows);
        $this->assertSame(['HUF', 'EUR', 'USD', 'GBP'], $rows->pluck('currency')->unique()->values()->all());

        foreach (['EUR', 'USD', 'GBP'] as $currency) {
            foreach ([
                ['wordpress', 'basic', 49], ['wordpress', 'business', 89], ['wordpress', 'secure', 149],
                ['woocommerce', 'basic', 69], ['woocommerce', 'business', 99], ['woocommerce', 'secure', 159],
            ] as [$product, $plan, $monthlyMajor]) {
                $monthly = $rows->first(fn ($row) => $row['product_type'] === $product && $row['plan'] === $plan && $row['interval'] === 'monthly' && $row['currency'] === $currency);
                $annual = $rows->first(fn ($row) => $row['product_type'] === $product && $row['plan'] === $plan && $row['interval'] === 'annual' && $row['currency'] === $currency);
                $this->assertSame($monthlyMajor * 100, $monthly['amount']);
                $this->assertSame($monthlyMajor * 1000, $annual['amount']);
            }
        }
    }

    public function test_non_hungarian_country_can_resolve_each_international_currency(): void
    {
        $this->withStripeIds();
        $catalog = $this->catalog();
        $selection = new PriceSelection(ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly);

        foreach (['DE' => 'EUR', 'US' => 'USD', 'GB' => 'GBP', 'JP' => 'EUR'] as $country => $currency) {
            $price = $catalog->resolve($selection, $country, $currency);
            $this->assertSame($currency, $price->money->currency->code);
            $this->assertSame('INTERNATIONAL', $price->marketGroup);
        }
    }

    public function test_hungarian_and_international_currency_combinations_are_enforced(): void
    {
        $this->withStripeIds();
        $catalog = $this->catalog();
        $selection = new PriceSelection(ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly);

        $this->assertSame('HUF', $catalog->resolve($selection, 'HU', 'HUF')->money->currency->code);
        foreach (['EUR', 'USD', 'GBP'] as $currency) {
            try {
                $catalog->resolve($selection, 'HU', $currency);
                $this->fail('Hungary accepted '.$currency.'.');
            } catch (MarketCurrencyMismatch) {
                $this->addToAssertionCount(1);
            }

        }

        foreach (['DE', 'US', 'GB'] as $country) {
            foreach (['EUR', 'USD', 'GBP'] as $currency) {
                $price = $catalog->resolve($selection, $country, $currency);
                $this->assertSame($currency, $price->money->currency->code, "{$country} / {$currency}");
            }
            try {
                $catalog->resolve($selection, $country, 'HUF');
                $this->fail("{$country} billing accepted HUF.");
            } catch (MarketCurrencyMismatch) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, array{mixed, mixed, mixed}> */
    public static function invalidSelections(): array
    {
        return [
            'invalid product type' => ['joomla', 'basic', 'monthly'],
            'invalid plan' => ['wordpress', 'platinum', 'monthly'],
            'invalid interval' => ['wordpress', 'basic', 'weekly'],
            'missing values' => [null, null, null],
            'array injection' => [['wordpress'], 'basic', 'monthly'],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_selection_fails(mixed $product, mixed $plan, mixed $interval): void
    {
        $this->expectException(InvalidPriceSelection::class);

        PriceSelection::fromInput($product, $plan, $interval);
    }

    public function test_inactive_price_cannot_be_resolved(): void
    {
        $this->withStripeIds();
        config(['pricing.prices.0.active' => false]); // wordpress basic monthly hu

        $this->expectException(PriceInactive::class);
        $this->catalog()->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly), 'HU');
    }

    public function test_missing_stripe_price_id_cannot_be_resolved(): void
    {
        config(['pricing.prices.0.stripe_price_id' => null]);

        $this->expectException(PriceNotPurchasable::class);
        $this->catalog()->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly), 'HU');
    }

    public function test_resolved_price_carries_the_configured_stripe_id(): void
    {
        config(['pricing.prices.3.stripe_price_id' => 'price_configured']); // wordpress business annual hu

        $price = $this->catalog()->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Business, BillingInterval::Annual), 'HU');

        $this->assertSame('price_configured', $price->stripePriceId);
    }

    public function test_each_price_exposes_a_unique_authoritative_stripe_config_key(): void
    {
        $prices = $this->catalog()->allPrices();
        $keys = array_map(fn ($price) => $price->stripePriceConfigKey, $prices);

        $this->assertCount(48, $keys);
        $this->assertCount(48, array_unique($keys));
        $this->assertContains('STRIPE_PRICE_WORDPRESS_BASIC_MONTHLY_HUF', $keys);
        $this->assertContains('STRIPE_PRICE_WOOCOMMERCE_SECURE_ANNUAL_GBP', $keys);
    }

    public function test_price_currency_must_match_its_market(): void
    {
        config(['pricing.prices.0.currency' => 'EUR']);

        $this->expectException(InvalidArgumentException::class);
        $this->catalog();
    }
}
