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

    public function test_money_is_formatted_for_the_currency(): void
    {
        $price = $this->catalog()->productLine('wordpress')->plans[1]->price(BillingInterval::Annual);

        $this->assertSame("189\u{202F}900", $price->money->formattedAmount());
        $this->assertSame("189\u{202F}900\u{00A0}HUF", $price->money->format());
    }

    public function test_plan_ids_and_stripe_price_ids_are_exposed(): void
    {
        config(['pricing.product_lines.wordpress.plans.basic.prices.HUF.monthly.stripe_price_id' => 'price_123']);

        $plan = $this->catalog()->productLine('wordpress')->plans[0];

        $this->assertSame('wordpress-basic', $plan->id());
        $this->assertSame('price_123', $plan->price(BillingInterval::Monthly)->stripePriceId);
    }

    public function test_unknown_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PricingCatalog::fromConfig(config('pricing'), 'EUR');
    }
}
