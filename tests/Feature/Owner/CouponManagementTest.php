<?php

namespace Tests\Feature\Owner;

use App\Checkout\CheckoutGateway;
use App\Checkout\PromotionGateway;
use App\Models\AdminAuditEvent;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Promotion;
use App\Models\User;
use App\Operator\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\EnforcesCsrf;
use Tests\Fakes\FakeCheckoutGateway;
use Tests\Fakes\FakePromotionGateway;
use Tests\TestCase;

/** Coupons managed in the owner area (docs/COUPONS.md) and used by the one checkout engine. */
class CouponManagementTest extends TestCase
{
    use ActsAsOwner, EnforcesCsrf, RefreshDatabase;

    private FakePromotionGateway $stripe;

    private FakeCheckoutGateway $checkout;

    protected function setUp(): void
    {
        parent::setUp();

        // Many requests per test: the limits themselves are tested in test_creating_coupons_is_rate_limited.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->stripe = new FakePromotionGateway;
        $this->app->instance(PromotionGateway::class, $this->stripe);
        $this->checkout = new FakeCheckoutGateway;
        $this->app->instance(CheckoutGateway::class, $this->checkout);

        foreach (config('pricing.prices') as $i => $price) {
            config(["pricing.prices.{$i}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}_{$price['currency']}"]);
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function create(array $overrides = [])
    {
        return $this->post(route('owner.coupons.store'), $overrides + ['code' => 'PILOT10', 'percent_off' => '10', 'duration' => 'once']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function buy(array $overrides = [])
    {
        return $this->withCookie(config('session.cookie'), str_repeat('b', 40))->post(route('checkout.store'), $overrides + [
            'product' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly',
            'email' => 'customer@company.example', 'first_name' => 'Anna', 'last_name' => 'Kovács', 'country' => 'HU', 'currency' => 'HUF',
            'customer_type' => 'individual', 'address_line1' => 'Teszt utca 1.', 'postal_code' => '1011', 'city' => 'Budapest',
        ]);
    }

    public function test_the_owner_creates_a_coupon_in_stripe_and_locally_and_it_is_audited(): void
    {
        $owner = $this->asOwner();

        $this->create(['code' => 'pilot10', 'percent_off' => '12,5', 'duration' => 'once', 'max_redemptions' => 5, 'max_redemptions_per_customer' => 1, 'product_types' => ['wordpress'], 'plans' => ['business', 'secure'], 'intervals' => ['monthly'], 'market_group' => 'INTERNATIONAL', 'note' => 'Pilot customers, internal.'])
            ->assertRedirect(route('owner.coupons.show', 'PILOT10'));

        $coupon = Promotion::sole();
        $this->assertSame('PILOT10', $coupon->code);
        $this->assertSame('12.50', $coupon->percent_off);
        $this->assertSame('percent', $coupon->discount_type);
        $this->assertSame('once', $coupon->duration);
        $this->assertNull($coupon->duration_months);
        $this->assertSame(5, $coupon->max_redemptions);
        $this->assertSame(1, $coupon->max_redemptions_per_customer);
        $this->assertSame(['wordpress'], $coupon->product_types);
        $this->assertSame(['business', 'secure'], $coupon->plans);
        $this->assertSame(['monthly'], $coupon->intervals);
        $this->assertSame(['INTERNATIONAL'], $coupon->markets);
        $this->assertTrue($coupon->active);
        $this->assertSame('promo_test_1', $coupon->stripe_promotion_code_id);
        $this->assertSame($owner->id, $coupon->created_by_user_id);
        $this->assertSame([['code' => 'PILOT10', 'percent_off' => '12.50', 'duration' => 'once', 'months' => null]], $this->stripe->created);

        $event = AdminAuditEvent::where('event', 'coupon.created')->sole();
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertSame('PILOT10', $event->subject_key);
        $this->assertStringNotContainsString('sk_', json_encode($event->data));
    }

    public function test_the_percentage_must_be_above_zero_and_at_most_one_hundred(): void
    {
        $this->asOwner();

        foreach (['0', '0.00', '-5', '100.01', '101', '1000', '10.123', 'abc', '', '10%', '1e2', ' '] as $bad) {
            $this->create(['code' => 'BAD'.md5($bad), 'percent_off' => $bad])->assertSessionHasErrors('percent_off');
        }
        $this->assertDatabaseCount('promotions', 0);
        $this->assertSame([], $this->stripe->created, 'Nothing is sent to Stripe for an invalid percentage.');

        foreach (['0.01' => '0.01', '1' => '1.00', '10' => '10.00', '33.33' => '33.33', '99.99' => '99.99', '100' => '100.00', '100.00' => '100.00'] as $input => $stored) {
            $this->create(['code' => 'OK'.str_replace('.', 'P', $input), 'percent_off' => (string) $input])->assertSessionDoesntHaveErrors();
            $this->assertSame($stored, Promotion::where('code', 'OK'.str_replace('.', 'P', $input))->sole()->percent_off);
        }
    }

    public function test_one_hundred_percent_is_an_ordinary_configurable_coupon(): void
    {
        $this->asOwner();

        $this->create(['code' => 'FREEUPDATE', 'percent_off' => '100', 'duration' => 'once'])->assertSessionDoesntHaveErrors();

        $coupon = Promotion::sole();
        $this->assertTrue($coupon->isFullDiscount());
        $this->assertFalse($coupon->makesEveryInvoiceFree(), 'Once: the subscription is charged afterwards, so a payment method is still needed.');
        $this->assertSame('100', $coupon->percentLabel());
        $this->assertSame('100.00', $this->stripe->created[0]['percent_off']);
    }

    public function test_code_format_duplicates_and_inputs_are_validated(): void
    {
        $this->asOwner();

        foreach (['', 'AB', str_repeat('A', 33), 'WITH SPACE', 'DROP;TABLE', 'ÉÁ-1234'] as $bad) {
            $this->create(['code' => $bad])->assertSessionHasErrors('code');
        }
        $this->create()->assertSessionDoesntHaveErrors();
        $this->create(['code' => 'pilot10'])->assertSessionHasErrors('code');

        $this->create(['code' => 'REP', 'duration' => 'repeating'])->assertSessionHasErrors('duration');
        $this->create(['code' => 'REP', 'duration' => 'repeating', 'duration_months' => 3])->assertSessionHasErrors('duration');
        $this->create(['code' => 'DUR', 'duration' => 'sometimes'])->assertSessionHasErrors('duration');
        $this->create(['code' => 'LIM', 'max_redemptions' => 0])->assertSessionHasErrors('max_redemptions');
        $this->create(['code' => 'PLN', 'plans' => ['platinum']])->assertSessionHasErrors('plans.0');
        $this->create(['code' => 'EXP', 'starts_at' => '2030-02-01T00:00', 'expires_at' => '2030-01-01T00:00'])->assertSessionHasErrors('expires_at');

        $this->assertSame(1, Promotion::count(), 'Only the valid coupon exists.');
    }

    public function test_the_browser_cannot_supply_stripe_ids_or_amounts(): void
    {
        $this->asOwner();

        $this->create(['stripe_promotion_code_id' => 'promo_evil', 'stripe_coupon_id' => 'coupon_evil', 'amount_off' => 99999, 'discount_type' => 'fixed', 'times_redeemed' => 7, 'active' => false])
            ->assertSessionDoesntHaveErrors();

        $coupon = Promotion::sole();
        $this->assertSame('promo_test_1', $coupon->stripe_promotion_code_id);
        $this->assertSame('coupon_test_1', $coupon->stripe_coupon_id);
        $this->assertNull($coupon->amount_off);
        $this->assertSame('percent', $coupon->discount_type);
        $this->assertSame(0, $coupon->times_redeemed);
        $this->assertTrue($coupon->active);
    }

    public function test_a_stripe_failure_leaves_no_local_coupon(): void
    {
        $this->asOwner();
        $this->stripe->failWith = 'provider';

        $this->create()->assertRedirect()->assertSessionHas('owner_error');

        $this->assertDatabaseCount('promotions', 0);
        $this->assertDatabaseCount('admin_audit_events', 0);
    }

    public function test_rules_are_editable_but_code_percentage_and_duration_are_fixed(): void
    {
        $this->asOwner();
        $this->create(['code' => 'PILOT10', 'percent_off' => '10', 'duration' => 'once', 'max_redemptions' => 10]);
        $coupon = Promotion::sole();

        $this->put(route('owner.coupons.update', $coupon), [
            'expires_at' => '2030-12-31T23:59', 'max_redemptions' => 20, 'plans' => ['secure'], 'note' => 'Extended.',
            // These cannot be changed through the form:
            'percent_off' => '99', 'code' => 'HACKED', 'duration' => 'forever', 'times_redeemed' => 0, 'stripe_promotion_code_id' => 'promo_evil',
        ])->assertRedirect(route('owner.coupons.show', $coupon))->assertSessionHas('status', 'Coupon rules saved.');

        $coupon->refresh();
        $this->assertSame('PILOT10', $coupon->code);
        $this->assertSame('10.00', $coupon->percent_off);
        $this->assertSame('once', $coupon->duration);
        $this->assertSame('promo_test_1', $coupon->stripe_promotion_code_id);
        $this->assertSame(20, $coupon->max_redemptions);
        $this->assertSame(['secure'], $coupon->plans);
        $this->assertSame('2030-12-31 23:59:00', $coupon->expires_at->format('Y-m-d H:i:s'));

        $event = AdminAuditEvent::where('event', 'coupon.updated')->sole();
        $this->assertEqualsCanonicalizing(['expires_at', 'max_redemptions', 'plans', 'note'], $event->data['fields'], 'Field names only, not values.');

        $this->put(route('owner.coupons.update', $coupon), ['expires_at' => '2030-12-31T23:59', 'max_redemptions' => 20, 'plans' => ['secure'], 'note' => 'Extended.'])
            ->assertSessionHas('status', 'Nothing changed.');
        $this->assertSame(1, AdminAuditEvent::where('event', 'coupon.updated')->count());
    }

    public function test_the_usage_limit_cannot_go_below_the_uses_so_far(): void
    {
        $this->asOwner();
        $this->create();
        $coupon = Promotion::sole();
        $coupon->update(['times_redeemed' => 4, 'max_redemptions' => 10]);

        $this->put(route('owner.coupons.update', $coupon), ['max_redemptions' => 3])->assertSessionHasErrors('max_redemptions');
        $this->assertSame(10, $coupon->refresh()->max_redemptions);
        $this->put(route('owner.coupons.update', $coupon), ['max_redemptions' => 4])->assertSessionDoesntHaveErrors();
        $this->assertSame(0, $coupon->refresh()->remainingRedemptions());
    }

    public function test_market_restrictions_are_explicit_and_server_enforced(): void
    {
        $this->asOwner();
        $this->create(['code' => 'HUONLY', 'market_group' => 'HU'])->assertSessionDoesntHaveErrors();
        $coupon = Promotion::where('code', 'HUONLY')->sole();
        $this->assertSame(['HU'], $coupon->markets);

        $this->buy(['promotion_code' => 'HUONLY', 'country' => 'AT', 'currency' => 'EUR'])
            ->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.not_applicable')]);
        $this->buy(['promotion_code' => 'HUONLY', 'country' => 'HU', 'currency' => 'HUF'])
            ->assertStatus(303);

        $this->put(route('owner.coupons.update', $coupon), ['market_group' => 'INTERNATIONAL'])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(['INTERNATIONAL'], $coupon->refresh()->markets);
        $this->assertSame(1, AdminAuditEvent::where('event', 'coupon.updated')->count());
    }

    public function test_repeating_duration_is_not_available_for_new_coupons(): void
    {
        $this->asOwner();

        $this->create(['code' => 'REPEAT', 'duration' => 'repeating', 'duration_months' => 3])->assertSessionHasErrors('duration');

        $this->assertDatabaseCount('promotions', 0);
        $this->assertSame([], $this->stripe->created);
    }

    public function test_deactivating_and_activating_switches_stripe_and_the_checkout_follows(): void
    {
        $owner = $this->asOwner();
        $this->create();
        $coupon = Promotion::sole();

        $this->post(route('owner.coupons.deactivate', $coupon))->assertSessionHas('status');
        $this->assertFalse($coupon->refresh()->active);
        $this->assertSame([['id' => 'promo_test_1', 'active' => false]], $this->stripe->switched);
        $this->assertSame($owner->id, AdminAuditEvent::where('event', 'coupon.deactivated')->sole()->actor_user_id);

        $this->buy(['promotion_code' => 'PILOT10'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.inactive')]);
        $this->assertSame([], $this->checkout->created);

        $this->post(route('owner.coupons.activate', $coupon))->assertSessionHas('status');
        $this->assertTrue($coupon->refresh()->active);
        $this->assertTrue($this->stripe->switched[1]['active']);
        $this->assertSame(1, AdminAuditEvent::where('event', 'coupon.activated')->count());

        $this->buy(['promotion_code' => 'pilot10'])->assertStatus(303);
        $this->assertTrue($this->checkout->created[0]['promotion']->is($coupon));
    }

    public function test_when_stripe_cannot_switch_the_code_nothing_changes_locally(): void
    {
        $this->asOwner();
        $this->create();
        $coupon = Promotion::sole();
        $this->stripe->failWith = 'provider';

        $this->post(route('owner.coupons.deactivate', $coupon))->assertSessionHas('owner_error');

        $this->assertTrue($coupon->refresh()->active);
        $this->assertSame(0, AdminAuditEvent::where('event', 'coupon.deactivated')->count());
    }

    public function test_an_unused_coupon_can_be_deleted_but_one_with_history_cannot(): void
    {
        $this->asOwner();
        $this->create(['code' => 'UNUSED']);
        $this->create(['code' => 'USED']);
        $used = Promotion::where('code', 'USED')->sole();
        CheckoutIntent::create(['status' => 'completed', 'email' => 'a@b.example', 'first_name' => 'A', 'last_name' => 'B', 'country' => 'HU', 'product_type' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 899000, 'tax_treatment' => 'x', 'stripe_price_id' => 'price_x', 'promotion_id' => $used->id, 'promotion_code' => 'USED']);

        $this->delete(route('owner.coupons.destroy', 'USED'))->assertSessionHasErrors('coupon');
        $this->assertDatabaseHas('promotions', ['code' => 'USED']);

        $this->delete(route('owner.coupons.destroy', 'UNUSED'))->assertRedirect(route('owner.coupons.index'));
        $this->assertDatabaseMissing('promotions', ['code' => 'UNUSED']);
        $this->assertSame([['coupon' => 'coupon_test_1', 'promotion_code' => 'promo_test_1']], $this->stripe->removed);
        $this->assertSame(1, AdminAuditEvent::where('event', 'coupon.deleted')->count());
    }

    public function test_the_list_and_detail_show_usage_and_remaining_uses_and_never_leak_to_the_public(): void
    {
        $this->asOwner();
        $this->create(['code' => 'LISTED', 'max_redemptions' => 5, 'note' => 'Secret internal remark']);
        Promotion::sole()->update(['times_redeemed' => 2]);

        $this->get(route('owner.coupons.index'))->assertOk()->assertSee('LISTED')->assertSee('2')->assertSee('10%');
        $this->get(route('owner.coupons.show', 'listed'))->assertOk()->assertSee('Secret internal remark')->assertSee('Redemptions left')->assertSee('>3 ', false);
        $this->get(route('owner.coupons.show', 'NOPE'))->assertNotFound();

        // The same note never reaches a public page or the checkout.
        auth('owner')->logout();
        foreach ([route('home'), route('pricing'), route('checkout.show', ['product' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly'])] as $url) {
            $this->get($url)->assertDontSee('Secret internal remark')->assertDontSee('LISTED');
        }
    }

    public function test_the_one_checkout_engine_enforces_usage_limits_expiry_restrictions_and_per_customer_limits(): void
    {
        $this->asOwner();
        $this->create(['code' => 'ONCE', 'max_redemptions_per_customer' => 1]);
        $this->create(['code' => 'ANNUAL', 'intervals' => ['annual']]);
        $this->create(['code' => 'WOO', 'product_types' => ['woocommerce']]);
        $this->create(['code' => 'SECUREONLY', 'plans' => ['secure']]);
        $this->create(['code' => 'LATER', 'starts_at' => now()->addDay()->format('Y-m-d\TH:i')]);
        $this->create(['code' => 'CAPPED', 'max_redemptions' => 1]);
        Promotion::where('code', 'CAPPED')->update(['times_redeemed' => 1]);

        $this->buy(['promotion_code' => 'ANNUAL'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.not_applicable')]);
        $this->buy(['promotion_code' => 'WOO'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.not_applicable')]);
        $this->buy(['promotion_code' => 'SECUREONLY'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.not_applicable')]);
        $this->buy(['promotion_code' => 'LATER'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.not_started')]);
        $this->buy(['promotion_code' => 'CAPPED'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.used_up')]);
        $this->buy(['promotion_code' => 'MISSING'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.unknown')]);
        $this->assertSame([], $this->checkout->created);

        $this->buy(['promotion_code' => 'ANNUAL', 'interval' => 'annual'])->assertStatus(303);
        $this->buy(['promotion_code' => 'SECUREONLY', 'plan' => 'secure'])->assertStatus(303);

        // Per customer: a completed checkout by the same email uses it up, another email does not.
        $once = Promotion::where('code', 'ONCE')->sole();
        CheckoutIntent::create(['status' => 'completed', 'email' => 'customer@company.example', 'first_name' => 'A', 'last_name' => 'B', 'country' => 'HU', 'product_type' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 899000, 'tax_treatment' => 'x', 'stripe_price_id' => 'price_x', 'promotion_id' => $once->id, 'promotion_code' => 'ONCE']);
        $this->buy(['promotion_code' => 'ONCE'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.already_used')]);
        $this->buy(['promotion_code' => 'ONCE', 'email' => 'someone.else@company.example'])->assertStatus(303);
    }

    public function test_a_coupon_sent_to_stripe_carries_no_internal_note_and_the_browser_still_cannot_choose_the_amount(): void
    {
        $this->asOwner();
        $this->create(['code' => 'NOTED', 'note' => 'internal only']);

        $this->buy(['promotion_code' => 'NOTED', 'amount' => 1, 'stripe_price_id' => 'price_evil', 'discount' => 100])->assertStatus(303);

        $intent = CheckoutIntent::sole();
        $this->assertSame(1899000, $intent->amount);
        $this->assertSame('HUF', $intent->currency);
        $this->assertSame('price_wordpress_business_monthly_HUF', $intent->stripe_price_id);
    }

    // --- Authorization -----------------------------------------------------

    public function test_only_owners_can_manage_coupons(): void
    {
        $this->create(); // as a guest
        $this->assertDatabaseCount('promotions', 0);

        $operator = User::factory()->operator(Permission::cases())->create();
        $customer = Customer::factory()->withUser()->create();
        foreach ([['operator', $operator], ['web', $customer->user]] as [$guard, $user]) {
            $this->actingAs($user, $guard);
            $this->get(route('owner.coupons.index'))->assertRedirect(route('owner.login'));
            $this->post(route('owner.coupons.store'), ['code' => 'NOPE', 'percent_off' => '10', 'duration' => 'once'])->assertRedirect(route('owner.login'));
        }
        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_coupon_forms_are_csrf_protected(): void
    {
        $this->asOwner();
        $this->create();
        $coupon = Promotion::sole();

        $this->enforceCsrf();
        $this->post(route('owner.coupons.deactivate', $coupon))->assertStatus(419);
        $this->post(route('owner.coupons.store'), ['code' => 'NOCSRF', 'percent_off' => '10', 'duration' => 'once'])->assertStatus(419);
        $this->put(route('owner.coupons.update', $coupon), ['max_redemptions' => 3])->assertStatus(419);
        $this->delete(route('owner.coupons.destroy', $coupon))->assertStatus(419);

        $this->assertTrue($coupon->refresh()->active);
        $this->assertNull($coupon->max_redemptions);
        $this->assertDatabaseCount('promotions', 1);
    }

    public function test_creating_coupons_is_rate_limited(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        $this->asOwner();
        $codes = [];
        for ($i = 0; $i < 12; $i++) {
            $codes[] = $this->post(route('owner.coupons.store'), ['code' => 'RL'.$i, 'percent_off' => '5', 'duration' => 'once'])->getStatusCode();
        }
        $this->assertContains(429, $codes, 'Creating coupons reaches Stripe and is limited.');
    }

    public function test_every_coupon_route_names_the_coupons_ability(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'owner.coupons.'));
        $this->assertCount(8, $routes);
        foreach ($routes as $route) {
            $this->assertContains('can:owner.coupons.manage', $route->gatherMiddleware(), $route->getName());
        }
    }
}
