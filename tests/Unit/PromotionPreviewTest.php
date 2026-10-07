<?php

namespace Tests\Unit;

use App\Checkout\PromotionPreview;
use App\Models\Promotion;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Tests\TestCase;

class PromotionPreviewTest extends TestCase
{
    public function test_no_promotion_keeps_initial_and_future_at_catalog_price(): void
    {
        $preview = PromotionPreview::from($this->price(), null);

        $this->assertSame(89000, $preview->initialTotal);
        $this->assertSame(89000, $preview->futureTotal);
        $this->assertSame(0, $preview->initialDiscount);
        $this->assertSame(0, $preview->futureDiscount);
    }

    public function test_non_even_percent_preserves_minor_units(): void
    {
        $promotion = new Promotion(['discount_type' => 'percent', 'percent_off' => '12.50', 'duration' => 'forever']);
        $preview = PromotionPreview::from($this->price(), $promotion);

        $this->assertSame(11125, $preview->initialDiscount);
        $this->assertSame(77875, $preview->initialTotal);
        $this->assertSame('€778.75', $preview->toArray()['formatted']['future_total']);
    }

    private function price()
    {
        $catalog = PricingCatalog::fromConfig(config('pricing'));

        return $catalog->price(ProductType::WordPress, PlanTier::Business, BillingInterval::Annual, $catalog->marketForCurrency('EUR'));
    }
}
