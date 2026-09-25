<?php

namespace Tests\Unit;

use App\Pricing\BillingInterval;
use App\Pricing\Exceptions\InvalidPriceSelection;
use App\Pricing\Exceptions\MarketNotAvailable;
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

        $this->assertCount(12, $rows);

        foreach ($rows->where('interval', 'annual') as $annual) {
            $monthly = $rows->first(fn ($row) => $row['interval'] === 'monthly'
                && $row['product_type'] === $annual['product_type']
                && $row['plan'] === $annual['plan']
                && $row['market'] === $annual['market']);

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

    /** @return array<string, array{string}> */
    public static function internationalCountries(): array
    {
        return ['Germany (EUR market)' => ['DE'], 'United Kingdom (GBP market)' => ['GB'], 'United States (fallback)' => ['US'], 'Unlisted country' => ['JP']];
    }

    #[DataProvider('internationalCountries')]
    public function test_international_markets_have_no_invented_prices(string $country): void
    {
        $this->withStripeIds();
        $catalog = $this->catalog();

        $this->assertNotSame('hu', $catalog->marketForCountry($country)->key);
        $this->assertFalse($catalog->hasPrices($catalog->marketForCountry($country)));

        $this->expectException(MarketNotAvailable::class);
        $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly), $country);
    }

    public function test_only_hungarian_prices_exist(): void
    {
        $this->assertSame(['HUF'], collect(config('pricing.prices'))->pluck('currency')->unique()->values()->all());
        $this->assertSame(['hu'], collect(config('pricing.prices'))->pluck('market')->unique()->values()->all());
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

    public function test_price_currency_must_match_its_market(): void
    {
        config(['pricing.prices.0.currency' => 'EUR']);

        $this->expectException(InvalidArgumentException::class);
        $this->catalog();
    }
}
