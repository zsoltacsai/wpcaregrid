<?php

namespace Tests\Feature;

use App\Billing\SubscriptionStatus;
use App\Checkout\CheckoutGateway;
use App\Checkout\CheckoutStatus;
use App\Checkout\PromotionReservations;
use App\Mail\AccountActivation;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Promotion;
use App\Models\PromotionReservation;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Pricing\ProductType;
use App\Tax\BillingIdentityCompleteness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SendsStripeWebhooks;
use Tests\Fakes\FakeCheckoutGateway;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase, SendsStripeWebhooks;

    private FakeCheckoutGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // As in a configured environment: every HU price has a Stripe Price ID.
        foreach (config('pricing.prices') as $i => $price) {
            config(["pricing.prices.{$i}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}_{$price['currency']}"]);
        }

        $this->gateway = new FakeCheckoutGateway;
        $this->app->instance(CheckoutGateway::class, $this->gateway);
        $this->useWebhookSecret();

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
                'customer_type' => 'individual',
                'country' => 'HU',
                'address_line1' => 'Teszt utca 1.',
                'postal_code' => '1011',
                'city' => 'Budapest',
                'currency' => 'HUF',
            ]);
    }

    /** @param array<string, mixed> $overrides */
    private function preview(array $overrides = [])
    {
        return $this->postJson(route('checkout.promotion-preview'), $overrides + [
            'product' => 'wordpress', 'plan' => 'business', 'interval' => 'annual',
            'country' => 'DE', 'currency' => 'EUR', 'email' => 'preview@company.example',
            'promotion_code' => 'PREVIEW',
        ]);
    }

    public function test_promotion_preview_shows_once_and_forever_economics_without_creating_billing_state(): void
    {
        foreach ([
            ['code' => 'PREVIEW', 'percent' => 10, 'duration' => 'once', 'initial_discount' => 8900, 'initial' => 80100, 'future' => 89000],
            ['code' => 'FOREVER25', 'percent' => 25, 'duration' => 'forever', 'initial_discount' => 22250, 'initial' => 66750, 'future' => 66750],
            ['code' => 'FREEONCE', 'percent' => 100, 'duration' => 'once', 'initial_discount' => 89000, 'initial' => 0, 'future' => 89000],
        ] as $case) {
            $promotion = Promotion::create([
                'code' => $case['code'], 'stripe_promotion_code_id' => 'promo_'.strtolower($case['code']),
                'discount_type' => 'percent', 'percent_off' => $case['percent'], 'duration' => $case['duration'],
            ]);

            $this->preview(['promotion_code' => $case['code']])->assertOk()
                ->assertJsonPath('base_amount', 89000)
                ->assertJsonPath('initial_discount_amount', $case['initial_discount'])
                ->assertJsonPath('initial_total', $case['initial'])
                ->assertJsonPath('future_total', $case['future'])
                ->assertJsonPath('discount_duration', $case['duration'])
                ->assertJsonPath('formatted.initial_total', $case['initial'] === 0 ? '€0' : ($case['initial'] === 80100 ? '€801' : '€667.50'))
                ->assertJsonMissingPath('stripe_price_id')
                ->assertJsonMissingPath('stripe_coupon_id');
            $this->assertSame(0, $promotion->refresh()->times_redeemed);
        }

        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertDatabaseCount('promotion_reservations', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_promotion_preview_rejects_tampering_and_unavailable_codes_without_side_effects(): void
    {
        Promotion::create(['code' => 'PREVIEW', 'stripe_promotion_code_id' => 'promo_preview', 'discount_type' => 'percent', 'percent_off' => 10]);
        Promotion::create(['code' => 'EXPIRED', 'stripe_promotion_code_id' => 'promo_expired', 'discount_type' => 'percent', 'percent_off' => 10, 'expires_at' => now()->subDay()]);
        Promotion::create(['code' => 'EXHAUSTED', 'stripe_promotion_code_id' => 'promo_exhausted', 'discount_type' => 'percent', 'percent_off' => 10, 'max_redemptions' => 1, 'times_redeemed' => 1]);

        foreach (['NO_SUCH_CODE', 'EXPIRED', 'EXHAUSTED'] as $code) {
            $this->preview(['promotion_code' => $code])->assertUnprocessable()
                ->assertJsonPath('message', __('checkout.preview.unavailable'));
        }
        foreach (['amount' => 0, 'stripe_price_id' => 'price_fake', 'stripe_coupon_id' => 'coupon_fake', 'market' => 'HU', 'promotion_id' => 1] as $key => $value) {
            $this->preview([$key => $value])->assertUnprocessable();
        }
        $this->preview(['country' => 'HU', 'currency' => 'EUR'])->assertUnprocessable();
        $this->preview(['country' => 'DE', 'currency' => 'HUF'])->assertUnprocessable();
        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertDatabaseCount('promotion_reservations', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_promotion_preview_obeys_existing_customer_currency_lock(): void
    {
        Promotion::create(['code' => 'PREVIEW', 'stripe_promotion_code_id' => 'promo_preview', 'discount_type' => 'percent', 'percent_off' => 100, 'duration' => 'once']);
        $customer = Customer::factory()->withUser()->create(['country_code' => 'DE', 'stripe_customer_id' => 'cus_preview_eur']);
        Subscription::factory()->for($customer)->create(['stripe_customer_id' => 'cus_preview_eur', 'currency' => 'EUR']);

        $this->actingAs($customer->user)->withSession(['display_currency' => 'GBP'])
            ->preview(['currency' => 'GBP', 'email' => 'forged@example.test'])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('checkout.errors.customer_currency_mismatch', ['currency' => 'EUR']));
        $this->preview(['currency' => 'EUR', 'email' => 'forged@example.test'])
            ->assertOk()->assertJsonPath('initial_total', 0)->assertJsonPath('future_total', 89000);
        $this->assertDatabaseCount('checkout_intents', 0);
    }

    public function test_limited_promotion_preview_does_not_block_reuse_of_its_existing_checkout(): void
    {
        Promotion::create([
            'code' => 'PREVIEW', 'stripe_promotion_code_id' => 'promo_preview',
            'discount_type' => 'percent', 'percent_off' => 10, 'duration' => 'once',
            'max_redemptions' => 1, 'max_redemptions_per_customer' => 1,
        ]);

        $first = $this->submit(['promotion_code' => 'PREVIEW', 'country' => 'DE', 'currency' => 'EUR', 'interval' => 'annual']);
        $second = $this->submit(['promotion_code' => 'PREVIEW', 'country' => 'DE', 'currency' => 'EUR', 'interval' => 'annual']);
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'), $second->getContent());
        $this->assertDatabaseCount('checkout_intents', 1);
        $this->assertDatabaseCount('promotion_reservations', 1);
        $this->assertCount(1, $this->gateway->created);

        $this->preview(['promotion_code' => 'PREVIEW', 'email' => 'customer@company.example'])
            ->assertUnprocessable()->assertJsonPath('message', __('checkout.preview.unavailable'));
    }

    public function test_checkout_page_contains_read_only_promotion_preview_and_distinct_payment_labels(): void
    {
        $this->get(route('checkout.show', ['product' => 'wordpress', 'plan' => 'business', 'interval' => 'annual']))
            ->assertOk()
            ->assertSee('promotion-preview', false)
            ->assertSee(__('checkout.preview.today'))
            ->assertSee('promotionPreview?.formatted.future_total', false)
            ->assertSee(__('checkout.preview.regular'));
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

    /** @return array<string, array{string, string, string, bool}> */
    public static function checkoutMarketCopy(): array
    {
        return [
            'Hungary HUF' => ['HU', 'HUF', '24,900', true],
            'United States USD' => ['US', 'USD', '$99', false],
            'United Kingdom GBP' => ['GB', 'GBP', '£99', false],
            'international EUR' => ['DE', 'EUR', '€99', false],
        ];
    }

    #[DataProvider('checkoutMarketCopy')]
    public function test_checkout_tax_copy_matches_the_selected_market_and_currency(string $country, string $currency, string $amount, bool $expectsHungarianTaxCopy): void
    {
        $response = $this->withSession([
            'display_currency' => $currency,
            '_old_input' => ['country' => $country, 'currency' => $currency],
        ])
            ->get(route('checkout.show', ['product' => 'woocommerce', 'plan' => 'business', 'interval' => 'monthly']))
            ->assertOk()
            ->assertSee('WooCommerce Business')
            ->assertSee($amount)
            ->assertSee("x-show=\"country === 'HU' && currency === 'HUF'\"", false)
            ->assertSee(__('checkout.fields.currency_hint'))
            ->assertDontSee('Hungarian billing is charged in HUF');

        if ($expectsHungarianTaxCopy) {
            $response->assertSee(__('checkout.tax_note'));
        } else {
            $response->assertDontSee('Prices in Hungarian forint');
        }
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
        $this->assertSame('HU', $intent->market);
        $this->assertSame('HUF', $intent->currency);
        $this->assertSame(1899000, $intent->amount);
        $this->assertSame('final_price_no_vat_added', $intent->tax_treatment);
        $this->assertSame('price_wordpress_business_monthly_HUF', $intent->stripe_price_id);
        $this->assertNotNull($intent->uuid);
        $this->assertSame('individual', $intent->customer_type);
        $this->assertSame('Teszt utca 1.', $intent->billing_address['line1']);
        $this->assertNull($intent->billing_address['line2']);
        $this->assertSame('1011', $intent->billing_address['postal_code']);
        $this->assertSame('Budapest', $intent->billing_address['city']);
    }

    public function test_guest_checkout_requires_complete_billing_identity_before_any_checkout_side_effect(): void
    {
        $this->submit(['address_line1' => '   '])->assertSessionHasErrors('address_line1');

        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_company_billing_identity_is_persisted_before_stripe_and_tax_id_remains_optional(): void
    {
        $this->submit([
            'customer_type' => 'company',
            'company_name' => 'Synthetic Test Kft',
            'tax_id' => '',
        ])->assertStatus(303);

        $intent = CheckoutIntent::sole();
        $this->assertSame('company', $intent->customer_type);
        $this->assertSame('Synthetic Test Kft', $intent->company_name);
        $this->assertNull($intent->taxIdentifier());
        $this->assertSame(1, count($this->gateway->created));
    }

    public function test_logged_in_incomplete_profile_is_sent_to_profile_and_cannot_start_checkout(): void
    {
        $customer = Customer::factory()->withUser()->create(['billing_address' => null]);
        $this->actingAs($customer->user)
            ->get(route('checkout.show', ['product' => 'wordpress', 'plan' => 'basic', 'interval' => 'annual']))
            ->assertRedirect(route('account.profile', ['continue_checkout' => 1, 'product' => 'wordpress', 'plan' => 'basic', 'interval' => 'annual']))
            ->assertSessionHas('checkout_identity_required');

        $this->actingAs($customer->user)->post(route('checkout.store'), [
            'product' => 'wordpress', 'plan' => 'basic', 'interval' => 'annual', 'country' => 'HU', 'currency' => 'HUF',
        ])->assertRedirect(route('account.profile', ['continue_checkout' => 1, 'product' => 'wordpress', 'plan' => 'basic', 'interval' => 'annual']));

        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_additional_site_selection_survives_billing_profile_completion_gate(): void
    {
        $customer = Customer::factory()->withUser()->create(['billing_address' => null]);
        $this->actingAs($customer->user);
        $this->post(route('account.websites.store'), ['url' => 'profile.example', 'type' => 'woocommerce'])
            ->assertRedirect(route('account.websites.options'));

        $this->get(route('checkout.show', ['product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual']))
            ->assertRedirect(route('account.profile', ['continue_checkout' => 1, 'product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual']))
            ->assertSessionHas('account.website_purchase_draft', fn (array $draft) => $draft['url'] === 'https://profile.example' && $draft['type'] === 'woocommerce');

        $this->post(route('checkout.store'), [
            'product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual', 'country' => 'HU', 'currency' => 'HUF',
        ])->assertRedirect(route('account.profile', ['continue_checkout' => 1, 'product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual']))
            ->assertSessionHas('account.website_purchase_draft');

        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->created);

        $this->put(route('account.profile.update'), [
            'continue_checkout' => 1,
            'product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual',
            'first_name' => $customer->first_name, 'last_name' => $customer->last_name,
            'customer_type' => 'individual', 'country_code' => 'HU',
            'address_line1' => 'Test utca 1.', 'postal_code' => '1011', 'city' => 'Budapest',
        ])->assertRedirect(route('checkout.show', ['product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual']));
        $this->assertSame([], app(BillingIdentityCompleteness::class)->missingForCustomer($customer->fresh()));
        $response = $this->actingAs($customer->user->fresh())
            ->get(route('checkout.show', ['product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual']));
        $this->assertSame(200, $response->status(), (string) $response->headers->get('Location'));
        $response->assertSee('https://profile.example');
    }

    public function test_product_type_cannot_be_changed_between_website_selection_and_checkout(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user);
        $this->post(route('account.websites.store'), ['url' => 'https://typed.example', 'type' => 'woocommerce']);

        $this->get(route('checkout.show', ['product' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly']))
            ->assertRedirect(route('account.websites.options'))
            ->assertSessionHasErrors('website');
        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_submitted_price_id_and_amount_are_ignored(): void
    {
        $this->submit([
            'stripe_price_id' => 'price_attacker',
            'stripe_coupon_id' => 'coupon_attacker',
            'stripe_promotion_code_id' => 'promo_attacker',
            'promotion_id' => 999999,
            'discounts' => [['promotion_code' => 'promo_attacker']],
            'discount_percent' => 100,
            'discount_amount' => 1,
            'price' => 'price_attacker',
            'amount' => 1,
            'currency' => 'HUF',
            'market' => 'eu',
        ]);

        $intent = CheckoutIntent::sole();

        $this->assertSame('price_wordpress_business_monthly_HUF', $intent->stripe_price_id);
        $this->assertSame('price_wordpress_business_monthly_HUF', $this->gateway->created[0]['intent']->stripe_price_id);
        $this->assertSame(1899000, $intent->amount);
        $this->assertSame('HUF', $intent->currency);
        $this->assertSame('HU', $intent->market);
        $this->assertNull($intent->promotion_id);
        $this->assertNull($this->gateway->created[0]['promotion']);
    }

    public function test_interval_changes_the_resolved_price(): void
    {
        $this->submit(['product' => 'woocommerce', 'plan' => 'basic', 'interval' => 'annual']);

        $intent = CheckoutIntent::sole();
        $this->assertSame('price_woocommerce_basic_annual_HUF', $intent->stripe_price_id);
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
            'unsupported currency' => ['currency', 'JPY'],
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

    public function test_international_customer_gets_server_resolved_eur_price(): void
    {
        $this->submit(['country' => 'DE', 'currency' => 'EUR'])->assertStatus(303);

        $intent = CheckoutIntent::sole();
        $this->assertSame('INTERNATIONAL', $intent->market);
        $this->assertSame('EUR', $intent->currency);
        $this->assertSame(8900, $intent->amount);
        $this->assertSame('price_wordpress_business_monthly_EUR', $intent->stripe_price_id);
    }

    public function test_market_currency_rules_are_enforced_server_side(): void
    {
        $this->submit(['country' => 'HU', 'currency' => 'GBP'])
            ->assertSessionHasErrors(['checkout' => __('checkout.errors.market_currency_mismatch')]);
        $this->submit(['country' => 'US', 'currency' => 'HUF'])
            ->assertSessionHasErrors(['checkout' => __('checkout.errors.market_currency_mismatch')]);

        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_existing_eur_customer_cannot_submit_gbp_even_with_a_valid_coupon(): void
    {
        $customer = Customer::factory()->withUser()->create(['country_code' => 'DE', 'stripe_customer_id' => 'cus_existing_eur']);
        Subscription::factory()->for($customer)->create([
            'stripe_customer_id' => $customer->stripe_customer_id,
            'currency' => 'EUR', 'market' => 'INTERNATIONAL', 'amount' => 89000,
        ]);
        $promotion = Promotion::create([
            'code' => 'LOCKTEST', 'stripe_promotion_code_id' => 'promo_lock',
            'discount_type' => 'percent', 'percent_off' => 100, 'duration' => 'once',
            'max_redemptions' => 1,
        ]);

        $this->actingAs($customer->user)->submit([
            'email' => 'attacker@example.test', 'country' => 'DE', 'currency' => 'GBP', 'promotion_code' => 'LOCKTEST',
            'stripe_price_id' => 'price_attacker', 'market' => 'gb',
        ])->assertSessionHasErrors(['checkout' => __('checkout.errors.customer_currency_mismatch', ['currency' => 'EUR'])]);

        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertDatabaseCount('promotion_reservations', 0);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertSame(0, $promotion->refresh()->times_redeemed);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_stale_gbp_preference_displays_locked_eur_and_same_currency_checkout_still_works(): void
    {
        $customer = Customer::factory()->withUser()->create(['country_code' => 'DE', 'stripe_customer_id' => 'cus_existing_eur']);
        Subscription::factory()->for($customer)->create(['stripe_customer_id' => $customer->stripe_customer_id, 'currency' => 'EUR']);

        $this->actingAs($customer->user)->withSession(['display_currency' => 'GBP', '_old_input' => ['currency' => 'GBP', 'country' => 'DE']])
            ->get(route('checkout.show', ['product' => 'wordpress', 'plan' => 'business', 'interval' => 'annual']))
            ->assertOk()
            ->assertSee(__('checkout.fields.currency_locked', ['currency' => 'EUR']))
            ->assertSee('lockedCurrency:', false)
            ->assertSee('value="EUR" selected', false);

        $this->submit(['country' => 'DE', 'currency' => 'EUR', 'email' => 'wrong@example.test'])->assertStatus(303);
        $this->assertSame($customer->email, CheckoutIntent::sole()->email);
        $this->assertSame('EUR', CheckoutIntent::sole()->currency);
        $this->assertCount(1, $this->gateway->created);
    }

    public function test_historical_cancelled_subscription_does_not_lock_a_customer(): void
    {
        $customer = Customer::factory()->withUser()->create(['country_code' => 'DE', 'stripe_customer_id' => 'cus_cancelled']);
        Subscription::factory()->for($customer)->status(SubscriptionStatus::Canceled)->create([
            'stripe_customer_id' => $customer->stripe_customer_id, 'currency' => 'EUR',
        ]);

        $this->actingAs($customer->user)->submit(['country' => 'DE', 'currency' => 'GBP'])->assertStatus(303);
        $this->assertSame('GBP', CheckoutIntent::sole()->currency);
    }

    public function test_multiple_current_subscriptions_agree_or_fail_closed(): void
    {
        $customer = Customer::factory()->withUser()->create(['country_code' => 'DE', 'stripe_customer_id' => 'cus_multiple']);
        foreach ([SubscriptionStatus::Active, SubscriptionStatus::PastDue] as $status) {
            Subscription::factory()->for($customer)->status($status)->create([
                'stripe_customer_id' => $customer->stripe_customer_id, 'currency' => 'EUR',
            ]);
        }

        $this->actingAs($customer->user)->submit(['country' => 'DE', 'currency' => 'GBP'])
            ->assertSessionHasErrors(['checkout' => __('checkout.errors.customer_currency_mismatch', ['currency' => 'EUR'])]);
        $this->assertDatabaseCount('checkout_intents', 0);

        $customer->subscriptions()->orderByDesc('id')->firstOrFail()->update(['currency' => 'GBP']);
        $this->submit(['country' => 'DE', 'currency' => 'EUR'])
            ->assertSessionHasErrors(['checkout' => __('checkout.errors.customer_currency_ambiguous')]);
        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_inconsistent_stripe_customer_mapping_fails_closed(): void
    {
        $customer = Customer::factory()->withUser()->create(['country_code' => 'DE', 'stripe_customer_id' => 'cus_right']);
        Subscription::factory()->for($customer)->create(['stripe_customer_id' => 'cus_wrong', 'currency' => 'EUR']);

        $this->actingAs($customer->user)->submit(['country' => 'DE', 'currency' => 'EUR'])
            ->assertSessionHasErrors(['checkout' => __('checkout.errors.customer_currency_ambiguous')]);
        $this->assertDatabaseCount('checkout_intents', 0);
    }

    public function test_huf_lock_rejects_international_selection_and_guests_keep_their_choice(): void
    {
        $customer = Customer::factory()->withUser()->create(['country_code' => 'HU', 'stripe_customer_id' => 'cus_huf']);
        Subscription::factory()->for($customer)->create(['stripe_customer_id' => $customer->stripe_customer_id, 'currency' => 'HUF']);

        $this->actingAs($customer->user)->submit(['country' => 'DE', 'currency' => 'EUR'])
            ->assertSessionHasErrors(['checkout' => __('checkout.errors.customer_currency_mismatch', ['currency' => 'HUF'])]);
        $this->assertDatabaseCount('checkout_intents', 0);

        auth()->logout();
        $this->submit(['country' => 'DE', 'currency' => 'EUR'])->assertStatus(303);
        $this->submit(['country' => 'GB', 'currency' => 'GBP'])->assertStatus(303);
        $this->assertSame(['EUR', 'GBP'], CheckoutIntent::orderBy('id')->pluck('currency')->all());
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

    public function test_fixed_discount_currency_must_match_the_server_resolved_checkout_currency(): void
    {
        Promotion::create([
            'code' => 'FIXHUF', 'stripe_promotion_code_id' => 'promo_huf', 'discount_type' => 'fixed',
            'amount_off' => 100000, 'currency' => 'HUF',
        ]);
        Promotion::create([
            'code' => 'FIXEUR', 'stripe_promotion_code_id' => 'promo_eur', 'discount_type' => 'fixed',
            'amount_off' => 1000, 'currency' => 'EUR',
        ]);

        $this->submit(['promotion_code' => 'FIXHUF', 'country' => 'HU', 'currency' => 'HUF'])->assertStatus(303);
        $this->submit(['promotion_code' => 'FIXHUF', 'country' => 'DE', 'currency' => 'EUR'])
            ->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.not_applicable')]);
        $this->submit(['promotion_code' => 'FIXEUR', 'country' => 'DE', 'currency' => 'EUR'])->assertStatus(303);
        $this->submit(['promotion_code' => 'FIXEUR', 'country' => 'US', 'currency' => 'USD'])
            ->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.not_applicable')]);

        $intents = CheckoutIntent::orderBy('id')->get();
        $this->assertCount(2, $intents);
        $this->assertSame(['HUF', 'EUR'], $intents->pluck('currency')->all());
        $this->assertSame(['FIXHUF', 'FIXEUR'], $intents->pluck('promotion_code')->all());
    }

    public function test_percentage_promotion_stays_currency_agnostic_and_annual_uses_configured_annual_price(): void
    {
        Promotion::create([
            'code' => 'TENPERCENT', 'stripe_promotion_code_id' => 'promo_percent', 'discount_type' => 'percent',
            'percent_off' => 10,
        ]);

        foreach ([['HU', 'HUF'], ['DE', 'EUR'], ['US', 'USD'], ['GB', 'GBP']] as [$country, $currency]) {
            $this->submit(['promotion_code' => 'TENPERCENT', 'country' => $country, 'currency' => $currency])->assertStatus(303);
        }

        $this->submit([
            'promotion_code' => 'TENPERCENT', 'country' => 'DE', 'currency' => 'EUR',
            'product' => 'wordpress', 'plan' => 'basic', 'interval' => 'annual',
        ])->assertStatus(303);

        $annual = CheckoutIntent::where('interval', 'annual')->sole();
        $this->assertSame(49000, $annual->amount);
        $this->assertSame('price_wordpress_basic_annual_EUR', $annual->stripe_price_id);
        $this->assertSame('TENPERCENT', $annual->promotion_code);
        $this->assertSame(5, CheckoutIntent::count());
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

    public function test_definite_checkout_provider_failure_releases_coupon_reservation(): void
    {
        Promotion::create([
            'code' => 'FAILSAFE', 'stripe_promotion_code_id' => 'promo_fail',
            'discount_type' => 'percent', 'percent_off' => 10, 'max_redemptions' => 1,
        ]);
        $this->gateway->fail = true;

        $this->submit(['promotion_code' => 'FAILSAFE'])->assertSessionHasErrors(['checkout' => __('checkout.errors.unavailable')]);

        $intent = CheckoutIntent::sole();
        $this->assertSame(CheckoutStatus::Failed, $intent->status);
        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $intent->id, 'status' => 'released']);
    }

    public function test_ambiguous_checkout_creation_failure_holds_reservation_for_signed_stripe_resolution(): void
    {
        Promotion::create([
            'code' => 'UNKNOWNOUTCOME', 'stripe_promotion_code_id' => 'promo_unknown',
            'discount_type' => 'percent', 'percent_off' => 10, 'max_redemptions' => 1,
        ]);
        $this->gateway->fail = true;
        $this->gateway->outcomeUnknown = true;

        $this->submit(['promotion_code' => 'UNKNOWNOUTCOME'])->assertSessionHasErrors(['checkout' => __('checkout.errors.unavailable')]);

        $intent = CheckoutIntent::sole();
        $this->assertSame(CheckoutStatus::Pending, $intent->status);
        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $intent->id, 'status' => 'reserved']);
        $this->assertSame(0, Promotion::sole()->times_redeemed);

        // Stripe can deliver its signed expiry event even if the create
        // response was lost before the local Session ID could be saved.
        $this->stripeEvent('checkout.session.expired', [
            'id' => 'cs_test_response_lost',
            'client_reference_id' => $intent->uuid,
        ])->assertStatus(204);

        $this->assertSame(CheckoutStatus::Expired, $intent->refresh()->status);
        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $intent->id, 'status' => 'released']);
        $this->assertSame(0, Promotion::sole()->times_redeemed);
    }

    public function test_paid_webhook_can_complete_an_intent_when_stripe_create_response_was_lost(): void
    {
        Promotion::create([
            'code' => 'LOSTRESPONSE', 'stripe_promotion_code_id' => 'promo_lost_response',
            'discount_type' => 'percent', 'percent_off' => 10, 'max_redemptions' => 1,
        ]);
        $this->gateway->fail = true;
        $this->gateway->outcomeUnknown = true;

        $this->submit(['promotion_code' => 'LOSTRESPONSE'])->assertSessionHasErrors(['checkout' => __('checkout.errors.unavailable')]);

        $intent = CheckoutIntent::sole();
        $sessionId = 'cs_test_lost_response';
        $this->stripeEvent('checkout.session.completed', [
            'id' => $sessionId,
            'client_reference_id' => $intent->uuid,
            'payment_status' => 'paid',
            'customer' => 'cus_test_lost_response',
            'subscription' => 'sub_test_lost_response',
        ])->assertStatus(204);

        $this->assertSame(CheckoutStatus::Completed, $intent->refresh()->status);
        $this->assertSame($sessionId, $intent->stripe_checkout_session_id);
        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $intent->id, 'status' => 'consumed']);
        $this->assertSame(1, Promotion::sole()->refresh()->times_redeemed);
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

    public function test_authenticated_customer_buys_second_site_and_signed_webhook_binds_it_once(): void
    {
        Mail::fake();
        $customer = Customer::factory()->withUser()->create(['country_code' => 'HU', 'stripe_customer_id' => 'cus_existing_account']);
        $firstSubscription = Subscription::factory()->for($customer)->create([
            'stripe_customer_id' => $customer->stripe_customer_id,
            'stripe_subscription_id' => 'sub_first_site',
            'product_type' => 'wordpress',
        ]);
        $firstWebsite = Website::factory()->for($customer)->create([
            'url' => 'https://first.example', 'type' => 'wordpress', 'subscription_id' => $firstSubscription->id,
        ]);
        $usersBefore = User::count();
        $this->actingAs($customer->user);

        $this->post(route('account.websites.store'), ['url' => 'Store.example/', 'type' => 'woocommerce'])
            ->assertRedirect(route('account.websites.options'));
        $this->get(route('account.websites.options'))->assertOk()->assertSee('https://store.example')->assertSee(__('account.websites.buy_subscription'));
        $this->post(route('account.websites.purchase'), ['plan' => 'secure', 'interval' => 'annual'])
            ->assertRedirect(route('checkout.show', ['product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual']));
        $this->get(route('checkout.show', ['product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual']))
            ->assertOk()->assertSee('https://store.example')->assertSee(__('pricing.intervals.annual'));

        $this->post(route('checkout.store'), [
            'product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual', 'country' => 'HU', 'currency' => 'HUF',
        ])->assertStatus(303);
        $intent = CheckoutIntent::sole();
        $this->assertSame($customer->id, $intent->customer_id);
        $this->assertSame($customer->email, $intent->email);
        $this->assertSame('https://store.example', $intent->intended_website_url);
        $this->assertSame(ProductType::WooCommerce, $intent->intended_website_type);
        $this->assertSame('price_woocommerce_secure_annual_HUF', $intent->stripe_price_id);
        $this->assertSame($customer->stripe_customer_id, $intent->customer->stripe_customer_id);
        $this->assertSame($usersBefore, User::count());
        $this->assertSame(1, count($this->gateway->created));

        // Stripe may deliver the subscription event before the Checkout event.
        $this->stripeEvent('customer.subscription.created', $this->stripeSubscription('sub_second_site', 'active', [
            'customer' => $customer->stripe_customer_id,
            'metadata' => ['checkout_intent' => $intent->uuid],
            'items' => ['data' => [[
                'id' => 'si_second', 'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addYear()->timestamp,
                'price' => ['id' => 'price_woocommerce_secure_annual_HUF', 'unit_amount' => 149000000, 'currency' => 'huf'],
            ]]],
        ]), eventId: 'evt_multisite_subscription')->assertStatus(204);

        $this->stripeEvent('checkout.session.completed', [
            'id' => $intent->stripe_checkout_session_id,
            'client_reference_id' => $intent->uuid,
            'payment_status' => 'paid',
            'customer' => $customer->stripe_customer_id,
            'subscription' => 'sub_second_site',
            'metadata' => ['checkout_intent' => $intent->uuid],
        ], eventId: 'evt_multisite_checkout')->assertStatus(204);

        $secondSubscription = Subscription::where('stripe_subscription_id', 'sub_second_site')->sole();
        $secondWebsite = Website::where('url', 'https://store.example')->sole();
        $this->assertSame($customer->id, $secondSubscription->customer_id);
        $this->assertSame($customer->id, $secondWebsite->customer_id);
        $this->assertSame($secondSubscription->id, $secondWebsite->subscription_id);
        $this->assertSame(ProductType::WooCommerce, $secondWebsite->type);
        $this->assertSame($firstSubscription->id, $firstWebsite->refresh()->subscription_id);
        $this->assertSame(2, $customer->fresh()->subscriptions()->count());
        $this->assertSame(2, $customer->fresh()->websites()->count());
        Mail::assertNotQueued(AccountActivation::class);

        $this->stripeEvent('customer.subscription.created', $this->stripeSubscription('sub_second_site', 'active', [
            'customer' => $customer->stripe_customer_id,
            'metadata' => ['checkout_intent' => $intent->uuid],
            'items' => ['data' => [[
                'id' => 'si_second', 'price' => ['id' => 'price_woocommerce_secure_annual_HUF', 'unit_amount' => 149000000, 'currency' => 'huf'],
            ]]],
        ]), eventId: 'evt_multisite_subscription')->assertStatus(204);
        $this->assertSame(2, Website::count());

        $this->get(route('checkout.success', ['session_id' => $intent->stripe_checkout_session_id]))
            ->assertOk()->assertSee(__('account.websites.connect_website'))->assertSee(route('account.websites.show', $secondWebsite));
        $this->get(route('account.subscription'))->assertOk()->assertSee(__('account.websites.add_another'));
    }

    public function test_failed_additional_site_checkout_never_creates_a_paid_website(): void
    {
        $customer = Customer::factory()->withUser()->create(['stripe_customer_id' => 'cus_failed_addsite']);
        $this->actingAs($customer->user);
        $this->post(route('account.websites.store'), ['url' => 'https://failed.example', 'type' => 'wordpress']);
        $this->post(route('account.websites.purchase'), ['plan' => 'basic', 'interval' => 'monthly']);
        $this->post(route('checkout.store'), [
            'product' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly', 'country' => 'HU', 'currency' => 'HUF',
        ])->assertStatus(303);
        $intent = CheckoutIntent::sole();

        $this->stripeEvent('checkout.session.async_payment_failed', [
            'id' => $intent->stripe_checkout_session_id,
            'client_reference_id' => $intent->uuid,
        ], eventId: 'evt_multisite_failed')->assertStatus(204);

        $this->assertSame(CheckoutStatus::Failed, $intent->refresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('websites', 0);
    }

    public function test_paid_site_collision_keeps_the_subscription_recoverable_without_misbinding(): void
    {
        $customer = Customer::factory()->withUser()->create(['stripe_customer_id' => 'cus_site_collision']);
        $first = Subscription::factory()->for($customer)->create([
            'stripe_customer_id' => $customer->stripe_customer_id,
            'stripe_subscription_id' => 'sub_collision_first',
            'product_type' => 'wordpress',
        ]);
        $this->actingAs($customer->user);
        $this->post(route('account.websites.store'), ['url' => 'https://race.example', 'type' => 'wordpress']);
        $this->post(route('account.websites.purchase'), ['plan' => 'basic', 'interval' => 'monthly']);
        $this->post(route('checkout.store'), [
            'product' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly', 'country' => 'HU', 'currency' => 'HUF',
        ])->assertStatus(303);
        $intent = CheckoutIntent::sole();

        // Simulate another authorized flow claiming the URL before Stripe's webhook is processed.
        Website::factory()->for($customer)->create([
            'url' => 'https://race.example', 'type' => 'wordpress', 'subscription_id' => $first->id,
        ]);
        $this->stripeEvent('customer.subscription.created', $this->stripeSubscription('sub_collision_second', 'active', [
            'customer' => $customer->stripe_customer_id,
            'metadata' => ['checkout_intent' => $intent->uuid],
            'items' => ['data' => [[
                'id' => 'si_collision_second',
                'price' => ['id' => 'price_wordpress_basic_monthly_HUF', 'unit_amount' => 990000, 'currency' => 'huf'],
            ]]],
        ]), eventId: 'evt_site_collision')->assertStatus(204);

        $paid = Subscription::where('stripe_subscription_id', 'sub_collision_second')->sole();
        $this->assertNull($paid->website);
        $this->assertSame(2, $customer->fresh()->subscriptions()->count());
        $this->get(route('account.subscription'))->assertOk()->assertSee($paid->planName());

        $this->post(route('account.websites.store'), ['url' => 'https://recovered.example', 'type' => 'wordpress']);
        $this->get(route('account.websites.options'))->assertOk()->assertSee($paid->uuid);
        $this->assertSame(1, $customer->fresh()->websites()->count());
        $this->post(route('account.websites.assign'), ['subscription' => $paid->uuid])->assertRedirect();
        $this->assertSame($paid->id, Website::where('url', 'https://recovered.example')->sole()->subscription_id);
        $this->assertSame(2, $customer->fresh()->websites()->count());
    }

    public function test_additional_site_checkout_double_submit_reuses_one_stripe_session(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user);
        $this->post(route('account.websites.store'), ['url' => 'https://repeat.example', 'type' => 'woocommerce']);
        $this->post(route('account.websites.purchase'), ['plan' => 'business', 'interval' => 'monthly']);
        $payload = ['product' => 'woocommerce', 'plan' => 'business', 'interval' => 'monthly', 'country' => 'HU', 'currency' => 'HUF'];

        $this->post(route('checkout.store'), $payload)->assertStatus(303);
        $this->post(route('checkout.store'), $payload)->assertStatus(303);

        $this->assertDatabaseCount('checkout_intents', 1);
        $this->assertCount(1, $this->gateway->created);
        $this->assertSame('https://repeat.example', CheckoutIntent::sole()->intended_website_url);
    }

    public function test_a_different_plan_cannot_open_a_second_checkout_for_the_same_pending_website(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user);
        $this->post(route('account.websites.store'), ['url' => 'https://one-purchase.example', 'type' => 'wordpress']);
        $this->post(route('account.websites.purchase'), ['plan' => 'basic', 'interval' => 'monthly']);
        $this->post(route('checkout.store'), [
            'product' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly', 'country' => 'HU', 'currency' => 'HUF',
        ])->assertStatus(303);

        $this->post(route('account.websites.purchase'), ['plan' => 'secure', 'interval' => 'annual']);
        $this->post(route('checkout.store'), [
            'product' => 'wordpress', 'plan' => 'secure', 'interval' => 'annual', 'country' => 'HU', 'currency' => 'HUF',
        ])->assertRedirect(route('account.subscription'))->assertSessionHas('status', __('account.websites.checkout_pending'));

        $this->assertDatabaseCount('checkout_intents', 1);
        $this->assertCount(1, $this->gateway->created);
        $this->assertSame('basic', CheckoutIntent::sole()->plan->value);
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

    public function test_cancel_holds_a_coupon_reservation_until_signed_stripe_expiry(): void
    {
        Promotion::create([
            'code' => 'CANCELSAFE', 'stripe_promotion_code_id' => 'promo_cancel',
            'discount_type' => 'percent', 'percent_off' => 10, 'max_redemptions' => 1,
        ]);
        $this->submit(['promotion_code' => 'CANCELSAFE']);
        $intent = CheckoutIntent::sole();
        $this->gateway->failExpire = true;
        $this->get(route('checkout.cancel', $intent))->assertOk();

        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $intent->id, 'status' => 'reserved']);
        PromotionReservation::where('checkout_intent_id', $intent->id)->update(['reserved_until' => now()->subSecond()]);
        $this->submit(['email' => 'blocked@company.example', 'promotion_code' => 'CANCELSAFE'])
            ->assertSessionHasErrors('promotion_code');
        $this->assertSame(CheckoutStatus::Cancelled, $intent->refresh()->status);

        $this->stripeEvent('checkout.session.expired', [
            'id' => $intent->stripe_checkout_session_id,
            'client_reference_id' => $intent->uuid,
        ], eventId: 'evt_cancelsafe_expired')->assertStatus(204);
        $this->stripeEvent('checkout.session.expired', [
            'id' => $intent->stripe_checkout_session_id,
            'client_reference_id' => $intent->uuid,
        ], eventId: 'evt_cancelsafe_expired')->assertStatus(204);

        $this->assertSame(CheckoutStatus::Expired, $intent->refresh()->status);
        $this->assertDatabaseHas('promotion_reservations', ['checkout_intent_id' => $intent->id, 'status' => 'released']);
        $this->assertSame(0, Promotion::sole()->times_redeemed);

        $this->submit(['email' => 'replacement@company.example', 'promotion_code' => 'CANCELSAFE'])
            ->assertRedirect();
        $this->assertDatabaseCount('promotion_reservations', 2);
        $this->assertSame(1, app(PromotionReservations::class)->activeCount(Promotion::sole()));
    }

    public function test_another_browser_cannot_cancel_an_intent(): void
    {
        $this->submit();
        $intent = CheckoutIntent::sole();

        $this->switchBrowser();
        $this->get(route('checkout.cancel', $intent))->assertNotFound();

        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->refresh()->status);
        $this->assertSame([], $this->gateway->expired);
    }

    public function test_cancel_does_not_overwrite_a_completed_intent(): void
    {
        $this->submit();
        $intent = CheckoutIntent::sole();
        $intent->update(['status' => CheckoutStatus::Completed]);

        $this->get(route('checkout.cancel', $intent))->assertOk();

        $this->assertSame(CheckoutStatus::Completed, $intent->refresh()->status);
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
        $html = $this->get('/en/')->getContent();

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
