<?php

namespace Tests\Feature;

use App\Checkout\CheckoutGateway;
use App\Checkout\CheckoutStatus;
use App\Models\CheckoutIntent;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeCheckoutGateway;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private FakeCheckoutGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // As in a configured environment: every HU price has a Stripe Price ID.
        foreach (config('pricing.prices') as $i => $price) {
            config(["pricing.prices.{$i}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}"]);
        }

        $this->gateway = new FakeCheckoutGateway;
        $this->app->instance(CheckoutGateway::class, $this->gateway);

        // Keep one browser session across requests, as a real browser would.
        $this->withCookie(config('session.cookie'), $this->browserSession = str_repeat('b', 40));
    }

    private string $browserSession;

    /** Continue as a different browser. */
    private function switchBrowser(): void
    {
        $this->withCookie(config('session.cookie'), str_repeat('c', 40));
    }

    /** @param  array<string, mixed>  $overrides */
    private function submit(array $overrides = [])
    {
        return $this->from(route('checkout.show', ['product' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly']))
            ->post(route('checkout.store'), $overrides + [
                'product' => 'wordpress',
                'plan' => 'business',
                'interval' => 'monthly',
                'email' => 'customer@company.example',
                'first_name' => 'Anna',
                'last_name' => 'Kovács',
                'country' => 'HU',
            ]);
    }

    public function test_checkout_page_shows_the_selected_plan_and_server_prices(): void
    {
        $this->get(route('checkout.show', ['product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual']))
            ->assertOk()
            ->assertSee('Complete your order')
            ->assertSee('WooCommerce Secure')
            ->assertSee('399,000')
            ->assertSee('39,900')
            ->assertSee('Daily remote backups')
            ->assertSee('value="annual"', false)
            ->assertDontSee('price_woocommerce', false);
    }

    public function test_checkout_page_rejects_an_invalid_selection(): void
    {
        $this->get(route('checkout.show', ['product' => 'joomla', 'plan' => 'basic']))
            ->assertRedirect(route('pricing'));
        $this->get(route('checkout.show', ['product' => 'wordpress', 'plan' => 'gold']))
            ->assertRedirect(route('pricing'));
        $this->get(route('checkout.show', ['product' => 'wordpress', 'plan' => 'basic', 'interval' => 'weekly']))
            ->assertRedirect(route('pricing'));
    }

    public function test_intent_is_recorded_before_redirecting_to_stripe(): void
    {
        $response = $this->submit();

        $intent = CheckoutIntent::sole();
        $call = $this->gateway->created[0];

        // The intent existed, pending, when Stripe was called.
        $this->assertTrue($call['persisted']);
        $this->assertSame(CheckoutStatus::Pending, $call['status_at_call']);

        $response->assertStatus(303)->assertRedirect($intent->stripe_checkout_url);
        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->status);
        $this->assertStringStartsWith('cs_test_', $intent->stripe_checkout_session_id);
        $this->assertSame('customer@company.example', $intent->email);
        $this->assertSame('wordpress', $intent->product_type->value);
        $this->assertSame('business', $intent->plan->value);
        $this->assertSame('monthly', $intent->interval->value);
        $this->assertSame('hu', $intent->market);
        $this->assertSame('HUF', $intent->currency);
        $this->assertSame(1899000, $intent->amount);
        $this->assertSame('final_price_no_vat_added', $intent->tax_treatment);
        $this->assertSame('price_wordpress_business_monthly', $intent->stripe_price_id);
        $this->assertNotNull($intent->uuid);
    }

    public function test_submitted_price_id_and_amount_are_ignored(): void
    {
        $this->submit([
            'stripe_price_id' => 'price_attacker',
            'price' => 'price_attacker',
            'amount' => 1,
            'currency' => 'EUR',
            'market' => 'eu',
        ]);

        $intent = CheckoutIntent::sole();

        $this->assertSame('price_wordpress_business_monthly', $intent->stripe_price_id);
        $this->assertSame('price_wordpress_business_monthly', $this->gateway->created[0]['intent']->stripe_price_id);
        $this->assertSame(1899000, $intent->amount);
        $this->assertSame('HUF', $intent->currency);
        $this->assertSame('hu', $intent->market);
    }

    public function test_interval_changes_the_resolved_price(): void
    {
        $this->submit(['product' => 'woocommerce', 'plan' => 'basic', 'interval' => 'annual']);

        $intent = CheckoutIntent::sole();
        $this->assertSame('price_woocommerce_basic_annual', $intent->stripe_price_id);
        $this->assertSame(14900000, $intent->amount);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidFields(): array
    {
        return [
            'invalid product type' => ['product', 'joomla'],
            'invalid plan' => ['plan', 'platinum'],
            'invalid interval' => ['interval', 'weekly'],
            'invalid email' => ['email', 'not-an-email'],
            'unknown country' => ['country', 'XX'],
            'malformed promotion code' => ['promotion_code', 'DROP TABLE;'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_input_fails_validation(string $field, string $value): void
    {
        $this->submit([$field => $value])->assertSessionHasErrors($field);

        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_international_customer_gets_a_clear_message(): void
    {
        $this->submit(['country' => 'DE'])
            ->assertSessionHasErrors(['country' => __('checkout.errors.market_not_available')]);

        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_inactive_price_cannot_be_checked_out(): void
    {
        $index = collect(config('pricing.prices'))->search(fn ($p) => $p['product_type'] === 'wordpress' && $p['plan'] === 'business' && $p['interval'] === 'monthly');
        config(["pricing.prices.{$index}.active" => false]);

        $this->submit()->assertSessionHasErrors(['checkout' => __('checkout.errors.price_inactive')]);
        $this->assertDatabaseCount('checkout_intents', 0);
    }

    public function test_missing_stripe_price_id_fails_cleanly(): void
    {
        $index = collect(config('pricing.prices'))->search(fn ($p) => $p['product_type'] === 'wordpress' && $p['plan'] === 'business' && $p['interval'] === 'monthly');
        config(["pricing.prices.{$index}.stripe_price_id" => null]);

        $this->submit()->assertSessionHasErrors(['checkout' => __('checkout.errors.price_not_purchasable')]);
        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_valid_promotion_code_is_passed_to_stripe(): void
    {
        $promotion = Promotion::create(['code' => 'WELCOME10', 'stripe_promotion_code_id' => 'promo_123', 'discount_type' => 'percent', 'percent_off' => 10]);

        $this->submit(['promotion_code' => 'welcome10'])->assertStatus(303);

        $intent = CheckoutIntent::sole();
        $this->assertSame($promotion->id, $intent->promotion_id);
        $this->assertSame('WELCOME10', $intent->promotion_code);
        $this->assertTrue($this->gateway->created[0]['promotion']->is($promotion));
    }

    public function test_promotion_restrictions_are_enforced_on_the_server(): void
    {
        Promotion::create(['code' => 'ANNUAL20', 'stripe_promotion_code_id' => 'promo_a', 'discount_type' => 'percent', 'percent_off' => 20, 'intervals' => ['annual']]);
        Promotion::create(['code' => 'WOO', 'stripe_promotion_code_id' => 'promo_w', 'discount_type' => 'percent', 'percent_off' => 5, 'product_types' => ['woocommerce']]);
        Promotion::create(['code' => 'OLD', 'stripe_promotion_code_id' => 'promo_o', 'discount_type' => 'percent', 'percent_off' => 5, 'expires_at' => now()->subDay()]);
        Promotion::create(['code' => 'FULL', 'stripe_promotion_code_id' => 'promo_f', 'discount_type' => 'percent', 'percent_off' => 5, 'max_redemptions' => 1, 'times_redeemed' => 1]);

        $this->submit(['promotion_code' => 'ANNUAL20'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.not_applicable')]);
        $this->submit(['promotion_code' => 'WOO'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.not_applicable')]);
        $this->submit(['promotion_code' => 'OLD'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.expired')]);
        $this->submit(['promotion_code' => 'FULL'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.used_up')]);
        $this->submit(['promotion_code' => 'NOPE'])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.unknown')]);

        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->created);

        $this->submit(['promotion_code' => 'ANNUAL20', 'interval' => 'annual'])->assertStatus(303);
        $this->assertDatabaseCount('checkout_intents', 1);
    }

    public function test_repeated_submission_reuses_the_open_stripe_session(): void
    {
        $first = $this->submit();
        $second = $this->submit();

        $this->assertCount(1, $this->gateway->created);
        $this->assertDatabaseCount('checkout_intents', 1);
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));

        // A different selection is a new checkout.
        $this->submit(['interval' => 'annual']);
        $this->assertCount(2, $this->gateway->created);
    }

    public function test_stripe_failure_is_recorded_and_shown(): void
    {
        $this->gateway->fail = true;

        $this->submit()->assertSessionHasErrors(['checkout' => __('checkout.errors.unavailable')]);

        $this->assertSame(CheckoutStatus::Failed, CheckoutIntent::sole()->status);
    }

    public function test_success_url_does_not_mark_the_order_as_paid(): void
    {
        $this->submit();
        $intent = CheckoutIntent::sole();

        $this->get(route('checkout.success', ['session_id' => $intent->stripe_checkout_session_id]))
            ->assertOk()
            ->assertSee('Your payment is being confirmed.')
            ->assertDontSee('Payment received')
            ->assertSee(strtoupper(substr($intent->uuid, 0, 8)));

        // Nothing is provisioned by the redirect.
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('subscriptions', 0);

        $intent->refresh();
        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->status);
        $this->assertNull($intent->completed_at);

        // Once the verified webhook has completed it, the page says so.
        $intent->update(['status' => CheckoutStatus::Completed]);
        $this->get(route('checkout.success', ['session_id' => $intent->stripe_checkout_session_id]))
            ->assertSee('Payment received')
            ->assertDontSee('Your payment is being confirmed.');
    }

    public function test_success_page_hides_order_details_from_other_sessions(): void
    {
        $this->submit();
        $intent = CheckoutIntent::sole();

        $this->switchBrowser();

        $this->get(route('checkout.success', ['session_id' => $intent->stripe_checkout_session_id]))
            ->assertOk()
            ->assertDontSee($intent->email)
            ->assertDontSee(strtoupper(substr($intent->uuid, 0, 8)));
    }

    public function test_cancel_marks_the_intent_cancelled_and_expires_the_stripe_session(): void
    {
        $this->submit();
        $intent = CheckoutIntent::sole();

        $this->get(route('checkout.cancel', $intent))
            ->assertOk()
            ->assertSee('Checkout cancelled')
            ->assertSee(e(route('checkout.show', ['product' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly'])), false);

        $this->assertSame(CheckoutStatus::Cancelled, $intent->refresh()->status);
        $this->assertSame([$intent->stripe_checkout_session_id], $this->gateway->expired);
    }

    public function test_another_browser_cannot_cancel_an_intent(): void
    {
        $this->submit();
        $intent = CheckoutIntent::sole();

        $this->switchBrowser();
        $this->get(route('checkout.cancel', $intent))->assertOk();

        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->refresh()->status);
        $this->assertSame([], $this->gateway->expired);
    }

    public function test_checkout_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->submit(['email' => "c{$i}@company.example"]);
        }

        $this->submit(['email' => 'c11@company.example'])->assertStatus(429);
    }

    public function test_homepage_plan_buttons_link_to_checkout_with_a_logical_selection_only(): void
    {
        $html = $this->get('/')->getContent();

        preg_match_all('#href="(http://[^"]+/checkout\?[^"]+)"#', $html, $matches);
        $this->assertCount(6, $matches[1], 'One default-interval link per plan card.');

        foreach ($matches[1] as $url) {
            parse_str(parse_url(html_entity_decode($url), PHP_URL_QUERY), $query);
            $this->assertSame(['product', 'plan', 'interval'], array_keys($query));
        }

        $this->assertStringNotContainsString('price_wordpress', $html);
        $this->assertStringNotContainsString('stripe', strtolower(strip_tags($html)));
    }
}
