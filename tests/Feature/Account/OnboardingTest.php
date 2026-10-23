<?php

namespace Tests\Feature\Account;

use App\Billing\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Match an owner-published catalog where the purchasable prices are configured.
        foreach (config('pricing.prices') as $i => $price) {
            config(["pricing.prices.{$i}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}_{$price['currency']}"]);
        }
    }

    private function customer(SubscriptionStatus ...$statuses): Customer
    {
        $customer = Customer::factory()->withUser()->create(['stripe_customer_id' => 'cus_'.Str::random(14)]);

        foreach ($statuses as $status) {
            Subscription::factory()->for($customer)->status($status)->create(['stripe_customer_id' => $customer->stripe_customer_id]);
        }

        return $customer;
    }

    public function test_active_customer_can_add_a_website(): void
    {
        $customer = $this->customer(SubscriptionStatus::Active);

        $this->actingAs($customer->user)->get(route('account.websites.create'))->assertOk()->assertSee('Connect your website');

        $this->post(route('account.websites.store'), ['url' => 'Company.example/', 'type' => 'wordpress'])
            ->assertRedirect(route('account.websites.options'));
        $response = $this->post(route('account.websites.assign'), ['subscription' => $customer->subscriptions()->sole()->uuid]);
        $website = Website::sole();
        $response->assertRedirect(route('account.websites.show', $website));
        $this->assertSame('https://company.example', $website->url);
        $this->assertSame($customer->id, $website->customer_id);
        $this->assertSame($customer->subscriptions()->sole()->id, $website->subscription_id);
        $this->assertSame(WebsiteStatus::Pending, $website->status);
        $this->assertSame(ConnectorStatus::NotInstalled, $website->connector_status);
    }

    /** @return array<string, array{SubscriptionStatus|null}> */
    public static function inactiveStates(): array
    {
        return [
            'no subscription' => [null],
            'pending confirmation' => [SubscriptionStatus::Pending],
            'past due' => [SubscriptionStatus::PastDue],
            'unpaid' => [SubscriptionStatus::Unpaid],
            'incomplete' => [SubscriptionStatus::Incomplete],
            'canceled' => [SubscriptionStatus::Canceled],
        ];
    }

    #[DataProvider('inactiveStates')]
    public function test_customer_without_active_subscription_can_start_purchase_journey(?SubscriptionStatus $status): void
    {
        $customer = $status ? $this->customer($status) : $this->customer();

        $this->actingAs($customer->user);
        $this->get(route('account.websites.create'))->assertOk()->assertSee(route('account.websites.store'));
        $this->post(route('account.websites.store'), ['url' => 'https://company.example', 'type' => 'wordpress'])
            ->assertRedirect(route('account.websites.options'));
        $this->get(route('account.websites.options'))->assertOk()->assertSee(__('account.websites.buy_subscription'));

        $this->assertDatabaseCount('websites', 0);
    }

    public function test_each_plan_covers_one_website(): void
    {
        $customer = $this->customer(SubscriptionStatus::Active);
        $this->actingAs($customer->user);

        $wordpress = $customer->subscriptions()->sole();
        $this->post(route('account.websites.store'), ['url' => 'https://one.example', 'type' => 'wordpress'])->assertRedirect(route('account.websites.options'));
        $this->post(route('account.websites.assign'), ['subscription' => $wordpress->uuid])->assertRedirect();
        $this->post(route('account.websites.store'), ['url' => 'https://two.example', 'type' => 'woocommerce'])->assertRedirect(route('account.websites.options'));
        $woo = Subscription::factory()->for($customer)->create(['product_type' => 'woocommerce']);
        $this->post(route('account.websites.assign'), ['subscription' => $woo->uuid])->assertRedirect();
        $this->assertDatabaseCount('websites', 2);
    }

    public function test_product_type_mismatch_and_cross_customer_slot_are_rejected(): void
    {
        $customer = $this->customer(SubscriptionStatus::Active);
        $other = $this->customer(SubscriptionStatus::Active);
        $this->actingAs($customer->user);
        $this->post(route('account.websites.store'), ['url' => 'https://store.example', 'type' => 'woocommerce'])->assertRedirect(route('account.websites.options'));
        $wordpressSlot = $customer->subscriptions()->sole();
        $this->from(route('account.websites.options'))->post(route('account.websites.assign'), ['subscription' => $wordpressSlot->uuid])
            ->assertSessionHasErrors('subscription');
        $this->post(route('account.websites.assign'), ['subscription' => $other->subscriptions()->sole()->uuid])->assertNotFound();
        $this->assertDatabaseCount('websites', 0);
    }

    public function test_plan_selection_is_server_catalog_backed_and_retains_website_target(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user);
        $this->post(route('account.websites.store'), ['url' => 'https://shop.example', 'type' => 'woocommerce'])->assertRedirect(route('account.websites.options'));
        $this->get(route('account.websites.options'))
            ->assertOk()
            ->assertSee('https://shop.example')
            ->assertSee(__('pricing.plans.woocommerce.basic.name'))
            ->assertSee(__('account.websites.annual_savings', ['amount' => app(PricingCatalog::class)->offers(ProductType::WooCommerce, app(PricingCatalog::class)->market('hu'))[0]->annualSavings()->format(), 'months' => app(PricingCatalog::class)->offers(ProductType::WooCommerce, app(PricingCatalog::class)->market('hu'))[0]->freeMonthsOnAnnual()]));
        $this->post(route('account.websites.purchase'), ['plan' => 'secure', 'interval' => 'annual'])
            ->assertRedirect(route('checkout.show', ['product' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual']));
    }

    public function test_website_input_is_validated(): void
    {
        $customer = $this->customer(SubscriptionStatus::Active, SubscriptionStatus::Active);
        Website::factory()->for($customer)->create(['url' => 'https://company.example']);
        $this->actingAs($customer->user);

        $this->post(route('account.websites.store'), ['url' => 'not a url', 'type' => 'wordpress'])->assertSessionHasErrors('url');
        $this->post(route('account.websites.store'), ['url' => 'javascript:alert(1)', 'type' => 'wordpress'])->assertSessionHasErrors('url');
        $this->post(route('account.websites.store'), ['url' => 'https://store.example', 'type' => 'joomla'])->assertSessionHasErrors('type');
        $this->post(route('account.websites.store'), ['url' => 'https://Company.example/', 'type' => 'wordpress'])->assertSessionHasErrors(['url' => __('account.websites.duplicate')]);
    }

    public function test_unconfirmed_connector_state_is_never_shown_as_connected(): void
    {
        $customer = $this->customer(SubscriptionStatus::Active);
        $this->actingAs($customer->user);

        foreach ([ConnectorStatus::NotInstalled, ConnectorStatus::AwaitingConnection, ConnectorStatus::Error] as $connector) {
            // Even if some other status field claims otherwise.
            $website = Website::factory()->for($customer)->create(['connector_status' => $connector, 'status' => WebsiteStatus::Connected]);

            $this->assertFalse($website->isConnected());
            $this->assertNotSame('done', $website->onboardingStates()['connection']);
            $this->assertSame('not_started', $website->onboardingStates()['ready']);

            $this->get(route('account.websites.show', $website))
                ->assertOk()
                ->assertSee(__('connector.presence.never_connected'))
                ->assertDontSee('>'.__('connector.presence.connected').'<', false)
                ->assertSee(__('connector.account.pair_title'));
        }

        $this->get(route('account.dashboard'))->assertSee('0 connected');
    }

    public function test_steps_complete_only_with_connector_confirmation_and_initial_check(): void
    {
        $website = Website::factory()->create(['connector_status' => ConnectorStatus::Connected, 'status' => WebsiteStatus::Connected]);

        // Connected, but no health report or inventory yet.
        $this->assertSame(['connect' => 'done', 'connection' => 'done', 'initial_check' => 'current', 'ready' => 'not_started'], $website->onboardingStates());

        $website->update(['health_status' => 'healthy', 'health_findings' => [], 'health_checked_at' => now()]);
        $website->inventory()->create(['core' => ['version' => '7.1.2', 'update_available' => false], 'plugins' => [], 'themes' => [], 'php_version' => '8.4.0']);

        $this->assertSame(['connect' => 'done', 'connection' => 'done', 'initial_check' => 'done', 'ready' => 'done'], $website->refresh()->onboardingStates());

        $website->update(['health_status' => 'critical']);
        $this->assertSame('not_started', $website->refresh()->onboardingStates()['ready']);
    }
}
