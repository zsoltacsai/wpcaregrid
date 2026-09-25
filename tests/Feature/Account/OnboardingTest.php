<?php

namespace Tests\Feature\Account;

use App\Billing\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function customer(SubscriptionStatus ...$statuses): Customer
    {
        $customer = Customer::factory()->withUser()->create();

        foreach ($statuses as $status) {
            Subscription::factory()->for($customer)->status($status)->create();
        }

        return $customer;
    }

    public function test_active_customer_can_add_a_website(): void
    {
        $customer = $this->customer(SubscriptionStatus::Active);

        $this->actingAs($customer->user)->get(route('account.websites.create'))->assertOk()->assertSee('Connect your website');

        $response = $this->post(route('account.websites.store'), ['url' => 'Company.example/', 'type' => 'wordpress']);

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
    public function test_customer_without_active_subscription_cannot_onboard(?SubscriptionStatus $status): void
    {
        $customer = $status ? $this->customer($status) : $this->customer();

        $this->actingAs($customer->user);
        $this->get(route('account.websites.create'))->assertForbidden();
        $this->post(route('account.websites.store'), ['url' => 'https://company.example', 'type' => 'wordpress'])->assertForbidden();
        $this->get(route('account.dashboard'))->assertOk()->assertDontSee(route('account.websites.create'));

        $this->assertDatabaseCount('websites', 0);
    }

    public function test_each_plan_covers_one_website(): void
    {
        $customer = $this->customer(SubscriptionStatus::Active);
        $this->actingAs($customer->user);

        $this->post(route('account.websites.store'), ['url' => 'https://one.example', 'type' => 'wordpress'])->assertRedirect();
        $this->post(route('account.websites.store'), ['url' => 'https://two.example', 'type' => 'wordpress'])->assertForbidden();

        Subscription::factory()->for($customer)->create();
        $this->post(route('account.websites.store'), ['url' => 'https://two.example', 'type' => 'woocommerce'])->assertRedirect();
        $this->assertDatabaseCount('websites', 2);
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
                ->assertSee(__('account.onboarding.not_connected'))
                ->assertSee(__('account.onboarding.connection_placeholder'));
        }

        $this->get(route('account.dashboard'))->assertSee('0 connected');
    }

    public function test_confirmed_connection_completes_the_connection_step(): void
    {
        $website = Website::factory()->create(['connector_status' => ConnectorStatus::Connected, 'status' => WebsiteStatus::Connected]);

        $this->assertSame(['connect' => 'done', 'connection' => 'done', 'initial_check' => 'done', 'ready' => 'done'], $website->onboardingStates());
    }
}
