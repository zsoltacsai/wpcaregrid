<?php

namespace Tests\Feature\Owner;

use App\Checkout\CheckoutGateway;
use App\Models\AdminAuditEvent;
use App\Models\CheckoutIntent;
use App\Models\PriceDefinition;
use App\Pricing\BillingInterval;
use App\Pricing\Exceptions\PriceInactive;
use App\Pricing\PlanTier;
use App\Pricing\Price;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use App\Pricing\StripePriceVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsOwner;
use Tests\Fakes\FakeCheckoutGateway;
use Tests\TestCase;

/** Technical price editing: drafts, validation, publishing, Stripe checks and checkout safety. */
class PricingAdminTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    private const KEY = 'wordpress.business.monthly.hu';

    protected function setUp(): void
    {
        parent::setUp();

        // As in a configured environment: every HU price has a Stripe Price ID.
        foreach (config('pricing.prices') as $i => $price) {
            config(["pricing.prices.{$i}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}"]);
        }
        $this->app->instance(CheckoutGateway::class, new FakeCheckoutGateway);
    }

    private function catalog(): PricingCatalog
    {
        $this->app->forgetInstance(PricingCatalog::class);

        return app(PricingCatalog::class);
    }

    private function businessMonthly(): Price
    {
        $catalog = $this->catalog();

        return $catalog->price(ProductType::WordPress, PlanTier::Business, BillingInterval::Monthly, $catalog->market('hu'));
    }

    private function draft(array $input, string $key = self::KEY)
    {
        return $this->put(route('owner.pricing.prices.update', $key), $input + ['currency' => 'HUF', 'amount' => '19990', 'stripe_price_id' => 'price_business_2027', 'active' => '1']);
    }

    private function publish(string $key = self::KEY)
    {
        return $this->post(route('owner.pricing.prices.publish', $key), ['confirm' => '1']);
    }

    public function test_the_pricing_pages_show_the_catalogue_and_its_source(): void
    {
        $this->asOwner();

        $this->get(route('owner.pricing.index'))->assertOk()
            ->assertSee("18,990\u{00A0}Ft", false)->assertSee('price_wordpress_business_monthly')->assertSee('config/pricing.php')
            ->assertSee('WordPress Business');
        $this->get(route('owner.pricing.index', ['market' => 'eu']))->assertOk()->assertSee('No price');
        $this->get(route('owner.pricing.prices.edit', self::KEY))->assertOk()->assertSee('value="18990"', false)->assertSee('price_wordpress_business_monthly');
    }

    public function test_a_draft_changes_nothing_until_it_is_published(): void
    {
        $owner = $this->asOwner();
        $this->draft([])->assertRedirect(route('owner.pricing.prices.edit', self::KEY));

        $this->assertSame(1899000, $this->businessMonthly()->money->amount);
        $this->get('/')->assertSee('>18,990<', false)->assertDontSee('>19,990<', false);
        $this->assertSame(['currency' => 'HUF', 'amount' => 1999000, 'stripe_price_id' => 'price_business_2027', 'active' => true], PriceDefinition::sole()->draft);
        $this->assertNull(PriceDefinition::sole()->published_at);

        // Publishing needs the explicit confirmation.
        $this->post(route('owner.pricing.prices.publish', self::KEY))->assertSessionHasErrors('confirm');
        $this->assertSame(1899000, $this->businessMonthly()->money->amount);

        $this->publish()->assertSessionHasNoErrors()->assertRedirect(route('owner.pricing.prices.edit', self::KEY));
        $price = $this->businessMonthly();
        $this->assertSame([1999000, 'price_business_2027', true], [$price->money->amount, $price->stripePriceId, $price->active]);
        $this->get('/')->assertSee('>19,990<', false)->assertDontSee('>18,990<', false);

        $events = AdminAuditEvent::where('subject_type', 'price')->orderBy('id')->get();
        $this->assertSame(['price.created', 'price.published'], $events->pluck('event')->all());
        $this->assertSame([self::KEY, 1999000, 'HUF', true, false, true], [
            $events[1]->subject_key, $events[1]->data['amount'], $events[1]->data['currency'], $events[1]->data['active'], $events[1]->data['stripe_verified'], $events[1]->data['stripe_price_changed'],
        ]);
        $this->assertSame($owner->id, $events[1]->actor_user_id);
    }

    public function test_checkout_charges_the_published_price_and_ignores_submitted_amounts(): void
    {
        $this->asOwner();
        $this->draft([]);
        $this->publish();

        $this->post(route('checkout.store'), [
            'product' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly',
            'email' => 'buyer@company.example', 'first_name' => 'Anna', 'last_name' => 'Kovács', 'country' => 'HU',
            // Tampering attempts: never trusted.
            'amount' => 1, 'currency' => 'USD', 'stripe_price_id' => 'price_cheap', 'price' => 1,
        ])->assertRedirect();

        $intent = CheckoutIntent::sole();
        $this->assertSame([1999000, 'HUF', 'price_business_2027'], [$intent->amount, $intent->currency, $intent->stripe_price_id]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidDrafts(): array
    {
        return [
            'other currency' => [['currency' => 'EUR'], 'currency'],
            'free-text currency' => [['currency' => 'FORINT'], 'currency'],
            'decimals for HUF' => [['amount' => '19990.50'], 'amount'],
            'comma decimal' => [['amount' => '19990,5'], 'amount'],
            'negative' => [['amount' => '-19990'], 'amount'],
            'zero' => [['amount' => '0'], 'amount'],
            'exponent' => [['amount' => '1e5'], 'amount'],
            'over the Stripe limit' => [['amount' => '999999999'], 'amount'],
            'not a Stripe price id' => [['stripe_price_id' => 'prod_ABC123'], 'stripe_price_id'],
            'script in price id' => [['stripe_price_id' => 'price_<script>'], 'stripe_price_id'],
            'active without price id' => [['stripe_price_id' => ''], 'stripe_price_id'],
            'price id of another price' => [['stripe_price_id' => 'price_wordpress_basic_monthly'], 'stripe_price_id'],
        ];
    }

    #[DataProvider('invalidDrafts')]
    public function test_invalid_technical_values_are_refused(array $input, string $field): void
    {
        $this->asOwner();

        $this->draft($input)->assertSessionHasErrors($field);

        $this->assertSame(0, PriceDefinition::count());
        $this->assertSame(1899000, $this->businessMonthly()->money->amount);
    }

    public function test_a_price_can_be_taken_off_sale_for_new_checkouts(): void
    {
        $this->asOwner();
        $this->draft(['amount' => '18990', 'stripe_price_id' => 'price_wordpress_business_monthly', 'active' => '0']);
        $this->publish()->assertSessionHasNoErrors();

        $this->expectException(PriceInactive::class);
        $this->catalog()->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Business, BillingInterval::Monthly), 'HU');
    }

    public function test_existing_subscriptions_keep_mapping_to_their_plan_after_a_price_change(): void
    {
        $this->asOwner();
        $this->draft([]);
        $this->publish();
        $this->draft(['amount' => '20990', 'stripe_price_id' => 'price_business_2028']);
        $this->publish();

        $catalog = $this->catalog();
        foreach (['price_wordpress_business_monthly', 'price_business_2027', 'price_business_2028'] as $stripeId) {
            $price = $catalog->priceByStripeId($stripeId);
            $this->assertSame(['wordpress', 'business', 'monthly', 'hu'], [$price->productType->value, $price->tier->value, $price->interval->value, $price->market], $stripeId);
        }
        $this->assertSame('price_business_2028', $this->businessMonthly()->stripePriceId);
        $this->assertSame(['price_wordpress_business_monthly', 'price_business_2027'], PriceDefinition::sole()->retired_stripe_price_ids);

        // A retired Price ID cannot be given to another price.
        $this->draft(['stripe_price_id' => 'price_business_2027'], 'wordpress.secure.monthly.hu')->assertSessionHasErrors('stripe_price_id');
    }

    public function test_a_market_without_prices_can_get_one_in_its_own_currency(): void
    {
        $this->asOwner();
        $key = 'wordpress.business.monthly.eu';

        $this->draft(['currency' => 'HUF', 'amount' => '49'], $key)->assertSessionHasErrors('currency');
        $this->draft(['currency' => 'EUR', 'amount' => '49', 'stripe_price_id' => 'price_eu_business'], $key)->assertSessionHasNoErrors();
        $this->publish($key)->assertSessionHasNoErrors();

        $price = $this->catalog()->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Business, BillingInterval::Monthly), 'AT');
        $this->assertSame([4900, 'EUR', 'eu'], [$price->money->amount, $price->money->currency->code, $price->market]);
    }

    public function test_publishing_checks_the_price_in_stripe_when_stripe_is_configured(): void
    {
        config(['services.stripe.secret' => 'sk_test_unit']);
        $verifier = new class extends StripePriceVerifier
        {
            public string $result = 'amount 1899000';

            public array $checked = [];

            public function check(?string $stripePriceId, int $amount, string $currency, BillingInterval $interval): string
            {
                $this->checked[] = [$stripePriceId, $amount, $currency, $interval->value];

                return $this->result;
            }
        };
        $this->app->instance(StripePriceVerifier::class, $verifier);
        $this->asOwner();
        $this->draft([]);

        $this->publish()->assertSessionHasErrors('stripe_price_id');
        $this->assertSame(1899000, $this->businessMonthly()->money->amount, 'A mismatch in Stripe blocks publishing.');
        $this->assertSame([['price_business_2027', 1999000, 'HUF', 'monthly']], $verifier->checked);

        $verifier->result = StripePriceVerifier::OK;
        $this->publish()->assertSessionHasNoErrors();
        $this->assertSame(1999000, $this->businessMonthly()->money->amount);
        $this->assertTrue(AdminAuditEvent::where('event', 'price.published')->sole()->data['stripe_verified']);
    }

    public function test_the_stripe_check_runs_only_on_request_and_keeps_only_the_outcome(): void
    {
        $this->asOwner();
        $this->post(route('owner.pricing.verify'))->assertSessionHas('owner_error', fn ($e) => $e['code'] === 'STRIPE_NOT_CONFIGURED');

        config(['services.stripe.secret' => 'sk_test_unit_SECRET']);
        $this->app->instance(StripePriceVerifier::class, new class extends StripePriceVerifier
        {
            public function check(?string $stripePriceId, int $amount, string $currency, BillingInterval $interval): string
            {
                return $stripePriceId === 'price_wordpress_basic_monthly' ? 'inactive in Stripe' : self::OK;
            }
        });

        $this->post(route('owner.pricing.verify'))->assertSessionHas('status');
        $this->get(route('owner.pricing.index'))->assertSee('wordpress.basic.monthly.hu: inactive in Stripe')->assertDontSee('sk_test_unit_SECRET');
        $this->assertSame(['ok' => false, 'problems' => 1], AdminAuditEvent::where('event', 'stripe.verified')->sole()->data);
    }

    public function test_discarding_a_draft_keeps_the_published_price(): void
    {
        $this->asOwner();
        $this->draft([]);
        $this->publish();
        $this->draft(['amount' => '25990']);

        $this->post(route('owner.pricing.prices.discard', self::KEY))->assertRedirect();

        $this->assertNull(PriceDefinition::sole()->draft);
        $this->assertSame(1999000, $this->businessMonthly()->money->amount);
    }

    public function test_published_prices_that_no_longer_fit_config_are_ignored_not_half_applied(): void
    {
        PriceDefinition::create(['product_type' => 'wordpress', 'tier' => 'business', 'interval' => 'monthly', 'market' => 'hu'])
            ->forceFill(['currency' => 'EUR', 'amount' => 1, 'stripe_price_id' => 'price_x', 'active' => true, 'published_at' => now()])->save();

        $this->assertSame([1899000, 'HUF'], [$this->businessMonthly()->money->amount, $this->businessMonthly()->money->currency->code]);
        $this->get('/')->assertOk();
    }

    public function test_plan_features_and_backup_frequency_are_not_changed_by_wording(): void
    {
        $this->asOwner();
        $this->put(route('owner.content.update', ['pricing.wordpress.basic', 'en']), ['action' => 'publish', 'fields' => [
            'name' => 'Starter', 'description' => 'Small sites.', 'badge' => '', 'cta_label' => '', 'features' => [['label' => 'Monthly backups'], ['label' => 'Updates']],
        ]])->assertSessionHasNoErrors();

        $plan = $this->catalog()->plan(ProductType::WordPress, PlanTier::Basic);
        $this->assertSame(['Starter', ['Monthly backups', 'Updates'], 'monthly'], [$plan->name, $plan->featureLabels(), $plan->backupFrequency]);
    }
}
