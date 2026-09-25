<?php

namespace Tests\Unit;

use App\Pricing\BillingInterval;
use App\Pricing\PricingCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PricingCatalogTest extends TestCase
{
    private function catalog(): PricingCatalog
    {
        return PricingCatalog::fromConfig(config('pricing'));
    }

    /** @return array<string, array{string, string, int, int}> */
    public static function agreedPrices(): array
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

    #[DataProvider('agreedPrices')]
    public function test_plans_use_the_agreed_prices(string $line, string $key, int $monthly, int $annual): void
    {
        $plan = collect($this->catalog()->productLine($line)->plans)->firstWhere('key', $key);

        $this->assertSame($monthly, $plan->price(BillingInterval::Monthly)->money->amount);
        $this->assertSame($annual, $plan->price(BillingInterval::Annual)->money->amount);
        $this->assertSame('HUF', $plan->price(BillingInterval::Monthly)->money->currency->code);
        $this->assertSame(2, $plan->freeMonthsOnAnnual());
    }

    public function test_business_is_the_only_highlighted_plan(): void
    {
        foreach ($this->catalog()->productLines() as $line) {
            $highlighted = array_values(array_filter($line->plans, fn ($plan) => $plan->highlighted));

            $this->assertCount(1, $highlighted);
            $this->assertSame('business', $highlighted[0]->key);
            $this->assertSame('Most popular', $highlighted[0]->badge);
        }
    }

    public function test_annual_savings_and_free_months(): void
    {
        $plan = $this->catalog()->productLine('wordpress')->plans[0];

        $this->assertSame(17980, $plan->annualSavings()->amount);
        $this->assertSame(2, $this->catalog()->annualFreeMonths());
    }

    public function test_money_is_formatted_for_the_locale(): void
    {
        $english = $this->catalog()->productLine('wordpress')->plans[1]->price(BillingInterval::Annual);
        $hungarian = PricingCatalog::fromConfig(config('pricing'), locale: 'hu')
            ->productLine('wordpress')->plans[1]->price(BillingInterval::Annual);

        $this->assertSame('189,900', $english->money->formattedAmount());
        $this->assertSame("189,900\u{00A0}Ft", $english->money->format());
        $this->assertSame("189\u{202F}900\u{00A0}Ft", $hungarian->money->format());
    }

    public function test_plan_ids_and_stripe_price_ids_are_exposed(): void
    {
        config(['pricing.product_lines.wordpress.plans.basic.prices.0.monthly.stripe_price_id' => 'price_123']);

        $plan = $this->catalog()->productLine('wordpress')->plans[0];

        $this->assertSame('wordpress-basic', $plan->id());
        $this->assertSame('Basic', $plan->name);
        $this->assertSame('price_123', $plan->price(BillingInterval::Monthly)->stripePriceId);
    }

    public function test_locale_specific_price_set_wins_over_a_general_one(): void
    {
        config(['pricing.product_lines.wordpress.plans.basic.prices.1' => [
            'currency' => 'HUF',
            'locale' => 'hu',
            'monthly' => ['amount' => 1, 'stripe_price_id' => null],
            'annual' => ['amount' => 10, 'stripe_price_id' => null],
        ]]);

        $hu = PricingCatalog::fromConfig(config('pricing'), locale: 'hu')->productLine('wordpress')->plans[0];
        $en = PricingCatalog::fromConfig(config('pricing'), locale: 'en')->productLine('wordpress')->plans[0];

        $this->assertSame(1, $hu->price(BillingInterval::Monthly)->money->amount);
        $this->assertSame(8990, $en->price(BillingInterval::Monthly)->money->amount);
    }

    public function test_currencies_without_prices_are_not_invented(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no EUR prices');

        PricingCatalog::fromConfig(config('pricing'), 'EUR');
    }

    public function test_unknown_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PricingCatalog::fromConfig(config('pricing'), 'CHF');
    }

    /** @return array<string, array{string, string, string}> */
    public static function backupFrequencies(): array
    {
        return [
            'WordPress Basic' => ['wordpress', 'basic', 'Monthly remote backups'],
            'WordPress Business' => ['wordpress', 'business', 'Weekly remote backups'],
            'WordPress Secure' => ['wordpress', 'secure', 'Daily remote backups'],
            'WooCommerce Basic' => ['woocommerce', 'basic', 'Weekly remote backups'],
            'WooCommerce Business' => ['woocommerce', 'business', 'Daily remote backups'],
            'WooCommerce Secure' => ['woocommerce', 'secure', 'Daily remote backups'],
        ];
    }

    #[DataProvider('backupFrequencies')]
    public function test_backup_frequency_feature_matches_the_config(string $line, string $key, string $feature): void
    {
        $plan = collect($this->catalog()->productLine($line)->plans)->firstWhere('key', $key);
        $backupLines = array_values(array_filter($plan->features, fn ($f) => str_ends_with($f, 'remote backups')));

        $this->assertSame([$feature], $backupLines);
        $this->assertSame(config("pricing.product_lines.{$line}.plans.{$key}.backup_frequency"), $plan->backupFrequency);
        $this->assertNotContains(':backups', $plan->features);
    }

    public function test_plan_descriptions(): void
    {
        foreach ($this->catalog()->productLines() as $line) {
            $this->assertSame(
                ['Essential maintenance for smaller WordPress sites.', 'Regular maintenance for business websites.', 'Extra security and faster response.'],
                array_map(fn ($plan) => $plan->description, $line->plans),
            );
        }
    }
}
