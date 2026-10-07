<?php

namespace Tests\Feature;

use App\Checkout\CheckoutStatus;
use App\Checkout\PromotionRejected;
use App\Checkout\PromotionReservations;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Promotion;
use App\Models\PromotionReservation;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (config('pricing.prices') as $index => $price) {
            config(["pricing.prices.{$index}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}_{$price['currency']}"]);
        }
    }

    private function promotion(array $attributes = []): Promotion
    {
        return Promotion::create($attributes + [
            'code' => 'LIMITED',
            'stripe_promotion_code_id' => 'promo_test_limited',
            'discount_type' => 'percent',
            'percent_off' => '10.00',
            'max_redemptions' => 1,
            'max_redemptions_per_customer' => 1,
        ]);
    }

    private function intent(string $email = 'buyer@example.test', array $overrides = []): CheckoutIntent
    {
        return CheckoutIntent::create($overrides + [
            'status' => CheckoutStatus::CheckoutCreated,
            'promotion_id' => Promotion::where('code', $overrides['promotion_code'] ?? 'LIMITED')->value('id'),
            'email' => $email,
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'country' => 'HU',
            'product_type' => 'wordpress',
            'plan' => 'basic',
            'interval' => 'monthly',
            'market' => 'hu',
            'currency' => 'HUF',
            'amount' => 899000,
            'tax_treatment' => 'final_price_no_vat_added',
            'stripe_price_id' => 'price_wordpress_basic_monthly_HUF',
        ]);
    }

    private function price()
    {
        $catalog = app(PricingCatalog::class);

        return $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly), 'HU', 'HUF');
    }

    public function test_global_usage_is_reserved_atomically_and_a_second_claim_is_rejected(): void
    {
        $promotion = $this->promotion();
        $reservations = app(PromotionReservations::class);
        $first = $this->intent();
        $second = $this->intent('other@example.test');

        $reservations->reserve($promotion, $first, $this->price(), $first->email, null);

        try {
            $reservations->reserve($promotion, $second, $this->price(), $second->email, null);
            $this->fail('A second checkout must not reserve the only remaining use.');
        } catch (PromotionRejected $exception) {
            $this->assertSame('used_up', $exception->reason);
        }

        $this->assertDatabaseCount('promotion_reservations', 1);
        $this->assertSame(0, $promotion->refresh()->times_redeemed);
        $this->assertSame(0, $promotion->remainingRedemptions());
    }

    public function test_per_customer_limit_includes_an_open_reservation(): void
    {
        $promotion = $this->promotion(['max_redemptions' => null]);
        $reservations = app(PromotionReservations::class);
        $first = $this->intent('buyer@example.test');
        $second = $this->intent('BUYER@example.test');

        $reservations->reserve($promotion, $first, $this->price(), $first->email, null);

        try {
            $reservations->reserve($promotion, $second, $this->price(), $second->email, null);
            $this->fail('An email must not claim two single-use reservations with case variation.');
        } catch (PromotionRejected $exception) {
            $this->assertSame('already_used', $exception->reason);
        }
    }

    public function test_unlimited_promotion_still_allows_multiple_reservations(): void
    {
        $promotion = $this->promotion(['code' => 'UNLIMITED', 'stripe_promotion_code_id' => 'promo_unlimited', 'max_redemptions' => null, 'max_redemptions_per_customer' => null]);
        $reservations = app(PromotionReservations::class);
        $first = $this->intent('first@example.test', ['promotion_code' => 'UNLIMITED']);
        $second = $this->intent('second@example.test', ['promotion_code' => 'UNLIMITED']);

        $reservations->reserve($promotion, $first, $this->price(), $first->email, null);
        $reservations->reserve($promotion, $second, $this->price(), $second->email, null);

        $this->assertSame(2, $reservations->activeCount($promotion));
        $this->assertSame(0, $promotion->refresh()->times_redeemed);
    }

    public function test_expired_claim_is_released_and_slot_can_be_reclaimed(): void
    {
        $promotion = $this->promotion();
        $reservations = app(PromotionReservations::class);
        $expired = $this->intent();
        $next = $this->intent('next@example.test');
        $reservations->reserve($promotion, $expired, $this->price(), $expired->email, null);
        PromotionReservation::where('checkout_intent_id', $expired->id)->update(['reserved_until' => now()->subSecond()]);

        $reservations->reserve($promotion, $next, $this->price(), $next->email, null);

        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $expired->id, 'status' => 'released']);
        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $next->id, 'status' => 'reserved']);
    }

    public function test_release_and_consume_are_idempotent_and_consume_only_the_intents_claim(): void
    {
        $promotion = $this->promotion();
        $reservations = app(PromotionReservations::class);
        $intent = $this->intent();
        $reservations->reserve($promotion, $intent, $this->price(), $intent->email, null);
        $intent->update(['status' => CheckoutStatus::Completed]);

        $this->assertTrue($reservations->consume($intent));
        $this->assertFalse($reservations->consume($intent));
        $this->assertSame(1, $promotion->refresh()->times_redeemed);
        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $intent->id, 'status' => 'consumed']);

        $otherPromotion = $this->promotion(['code' => 'OTHER', 'stripe_promotion_code_id' => 'promo_other']);
        $this->assertSame(0, $otherPromotion->refresh()->times_redeemed);
    }

    public function test_failed_checkout_releases_without_consuming(): void
    {
        $promotion = $this->promotion();
        $reservations = app(PromotionReservations::class);
        $intent = $this->intent();
        $reservations->reserve($promotion, $intent, $this->price(), $intent->email, null);

        $this->assertTrue($reservations->release($intent));
        $this->assertFalse($reservations->release($intent));
        $this->assertSame(0, $promotion->refresh()->times_redeemed);
        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $intent->id, 'status' => 'released']);
    }

    public function test_reservation_cannot_be_consumed_after_checkout_identity_is_tampered(): void
    {
        $promotion = $this->promotion();
        $reservations = app(PromotionReservations::class);
        $intent = $this->intent();
        $reservations->reserve($promotion, $intent, $this->price(), $intent->email, null);
        $intent->update(['status' => CheckoutStatus::Completed, 'email' => 'different@example.test']);

        $this->assertFalse($reservations->consume($intent));
        $this->assertSame(0, $promotion->refresh()->times_redeemed);
        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $intent->id, 'status' => 'reserved']);
    }

    public function test_reservation_cannot_be_consumed_by_a_different_customer(): void
    {
        $promotion = $this->promotion();
        $reservations = app(PromotionReservations::class);
        $owner = Customer::factory()->create();
        $other = Customer::factory()->create();
        $intent = $this->intent($owner->email, ['customer_id' => $owner->id]);
        $reservations->reserve($promotion, $intent, $this->price(), $intent->email, $owner->id);
        $intent->update(['status' => CheckoutStatus::Completed, 'customer_id' => $other->id]);

        $this->assertFalse($reservations->consume($intent));
        $this->assertSame(0, $promotion->refresh()->times_redeemed);
        $this->assertDatabaseHas('promotion_reservations', [
            'checkout_intent_id' => $intent->id,
            'customer_id' => $owner->id,
            'status' => 'reserved',
        ]);
    }

    public function test_reservation_cannot_be_claimed_for_a_different_promotion_or_email(): void
    {
        $promotion = $this->promotion();
        $otherPromotion = $this->promotion(['code' => 'OTHER', 'stripe_promotion_code_id' => 'promo_other']);
        $reservations = app(PromotionReservations::class);
        $intent = $this->intent();

        try {
            $reservations->reserve($otherPromotion, $intent, $this->price(), $intent->email, null);
            $this->fail('An intent must not claim a promotion different from its own server-side promotion.');
        } catch (PromotionRejected $exception) {
            $this->assertSame('not_applicable', $exception->reason);
        }

        $reservations->reserve($promotion, $intent, $this->price(), $intent->email, null);

        try {
            $reservations->reserve($promotion, $intent, $this->price(), 'other@example.test', null);
            $this->fail('An existing reservation must remain bound to its normalized email identity.');
        } catch (PromotionRejected $exception) {
            $this->assertSame('already_used', $exception->reason);
        }
    }
}
