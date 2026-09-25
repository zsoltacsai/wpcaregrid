<?php

namespace Tests\Unit;

use App\Models\Promotion;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    private function price(ProductType $type = ProductType::WordPress, PlanTier $tier = PlanTier::Business, BillingInterval $interval = BillingInterval::Monthly)
    {
        $catalog = PricingCatalog::fromConfig(config('pricing'));

        return $catalog->price($type, $tier, $interval, $catalog->market('hu'));
    }

    private function promotion(array $attributes = []): Promotion
    {
        return new Promotion($attributes + [
            'code' => 'welcome10',
            'stripe_promotion_code_id' => 'promo_test',
            'active' => true,
            'discount_type' => 'percent',
            'percent_off' => 10,
        ]);
    }

    public function test_code_is_normalised(): void
    {
        $this->assertSame('WELCOME10', $this->promotion(['code' => ' welcome10 '])->code);
    }

    public function test_unrestricted_promotion_is_eligible(): void
    {
        $this->assertNull($this->promotion()->ineligibilityReason($this->price()));
    }

    public function test_inactive_or_unlinked_promotion_is_rejected(): void
    {
        $this->assertSame('inactive', $this->promotion(['active' => false])->ineligibilityReason($this->price()));
        $this->assertSame('inactive', $this->promotion(['stripe_promotion_code_id' => null])->ineligibilityReason($this->price()));
    }

    public function test_dates_are_enforced(): void
    {
        $now = Carbon::parse('2026-09-25 12:00');

        $this->assertSame('not_started', $this->promotion(['starts_at' => '2026-10-01'])->ineligibilityReason($this->price(), $now));
        $this->assertSame('expired', $this->promotion(['expires_at' => '2026-09-25 11:59'])->ineligibilityReason($this->price(), $now));
        $this->assertNull($this->promotion(['starts_at' => '2026-09-01', 'expires_at' => '2026-10-01'])->ineligibilityReason($this->price(), $now));
    }

    public function test_usage_limit_is_enforced(): void
    {
        $this->assertSame('used_up', $this->promotion(['max_redemptions' => 5, 'times_redeemed' => 5])->ineligibilityReason($this->price()));
        $this->assertNull($this->promotion(['max_redemptions' => 5, 'times_redeemed' => 4])->ineligibilityReason($this->price()));
    }

    public function test_product_type_restriction(): void
    {
        $promotion = $this->promotion(['product_types' => ['woocommerce']]);

        $this->assertSame('not_applicable', $promotion->ineligibilityReason($this->price(ProductType::WordPress)));
        $this->assertNull($promotion->ineligibilityReason($this->price(ProductType::WooCommerce)));
    }

    public function test_plan_restriction_by_tier_or_full_plan(): void
    {
        $byTier = $this->promotion(['plans' => ['secure']]);
        $byPlan = $this->promotion(['plans' => ['woocommerce.business']]);

        $this->assertSame('not_applicable', $byTier->ineligibilityReason($this->price(tier: PlanTier::Basic)));
        $this->assertNull($byTier->ineligibilityReason($this->price(tier: PlanTier::Secure)));
        $this->assertSame('not_applicable', $byPlan->ineligibilityReason($this->price(ProductType::WordPress, PlanTier::Business)));
        $this->assertNull($byPlan->ineligibilityReason($this->price(ProductType::WooCommerce, PlanTier::Business)));
    }

    public function test_interval_restriction(): void
    {
        $annualOnly = $this->promotion(['intervals' => ['annual']]);

        $this->assertSame('not_applicable', $annualOnly->ineligibilityReason($this->price(interval: BillingInterval::Monthly)));
        $this->assertNull($annualOnly->ineligibilityReason($this->price(interval: BillingInterval::Annual)));
    }

    public function test_market_restriction(): void
    {
        $this->assertSame('not_applicable', $this->promotion(['markets' => ['eu']])->ineligibilityReason($this->price()));
        $this->assertNull($this->promotion(['markets' => ['hu']])->ineligibilityReason($this->price()));
    }

    public function test_fixed_discount_must_match_the_currency(): void
    {
        $eur = $this->promotion(['discount_type' => 'fixed', 'percent_off' => null, 'amount_off' => 500, 'currency' => 'EUR']);
        $huf = $this->promotion(['discount_type' => 'fixed', 'percent_off' => null, 'amount_off' => 100000, 'currency' => 'HUF']);

        $this->assertSame('not_applicable', $eur->ineligibilityReason($this->price()));
        $this->assertNull($huf->ineligibilityReason($this->price()));
    }
}
