<?php

namespace Tests\Feature\Account;

use App\Billing\SubscriptionStatus;
use App\Checkout\CheckoutGateway;
use App\Checkout\StripeCheckoutGateway;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeCheckoutGateway;
use Tests\TestCase;

class CustomerAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_real_subscription_data(): void
    {
        $customer = Customer::factory()->withUser()->create(['first_name' => 'Anna']);
        $subscription = Subscription::factory()->for($customer)->create(['current_period_end' => '2026-10-25 10:00:00']);

        $this->actingAs($customer->user)->get(route('account.dashboard'))
            ->assertOk()
            ->assertSee('Welcome back, Anna')
            ->assertSee('Business — WordPress')
            ->assertSee('Active')
            ->assertSee('25 Oct 2026')
            ->assertSee('0 connected')
            ->assertSee('Connect your first website')
            ->assertSee(route('account.websites.create'))
            ->assertDontSee('Demo data');
    }

    public function test_dashboard_explains_a_pending_subscription_without_activating_it(): void
    {
        $customer = Customer::factory()->withUser()->create();
        Subscription::factory()->for($customer)->status(SubscriptionStatus::Pending)->create(['current_period_end' => null]);

        $this->actingAs($customer->user)->get(route('account.dashboard'))
            ->assertOk()
            ->assertSee(__('account.dashboard.confirming'))
            ->assertDontSee('Connect your first website');
    }

    public function test_dashboard_warns_about_payment_problems(): void
    {
        $customer = Customer::factory()->withUser()->create();
        Subscription::factory()->for($customer)->status(SubscriptionStatus::PastDue)->create();

        $this->actingAs($customer->user)->get(route('account.dashboard'))
            ->assertSee(__('account.dashboard.payment_problem'))
            ->assertSee('Past due');
    }

    public function test_subscription_page_shows_all_billing_details(): void
    {
        $customer = Customer::factory()->withUser()->create();
        Subscription::factory()->for($customer)->create([
            'product_type' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual', 'amount' => 39900000,
            'current_period_start' => '2026-09-25', 'current_period_end' => '2027-09-25', 'cancel_at_period_end' => true,
        ]);

        $this->actingAs($customer->user)->get(route('account.subscription'))
            ->assertOk()
            ->assertSee('WooCommerce')
            ->assertSee('Secure')
            ->assertSee('Annual')
            ->assertSee('HUF')
            ->assertSee('399,000')
            ->assertSee('25 Sep 2026 – 25 Sep 2027')
            ->assertSee('Ends on 25 Sep 2027')
            ->assertSee('Manage subscription');
    }

    public function test_billing_and_profile_pages(): void
    {
        $customer = Customer::factory()->withUser()->create(['company_name' => 'Acme Kft.', 'customer_type' => 'company', 'tax_id' => 'HU12345678', 'country_code' => 'HU']);

        $this->actingAs($customer->user);
        $this->get(route('account.billing'))->assertOk()->assertSee('Acme Kft.')->assertSee('HU12345678')->assertSee('Hungary');
        $this->get(route('account.profile'))->assertOk()->assertSee('value="Acme Kft."', false);

        $this->put(route('account.profile.update'), ['first_name' => '', 'last_name' => 'X', 'customer_type' => 'company', 'company_name' => '', 'country_code' => 'ZZ'])
            ->assertSessionHasErrors(['first_name', 'company_name', 'country_code']);
    }

    public function test_logged_in_checkout_is_linked_to_the_account(): void
    {
        $customer = Customer::factory()->withUser()->create(['email' => 'anna@company.example', 'stripe_customer_id' => 'cus_known']);
        foreach (config('pricing.prices') as $i => $price) {
            config(["pricing.prices.{$i}.stripe_price_id" => "price_{$i}"]);
        }
        $gateway = new FakeCheckoutGateway;
        $this->app->instance(CheckoutGateway::class, $gateway);

        $this->actingAs($customer->user)->post(route('checkout.store'), [
            'product' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly',
            // A different email in the form is ignored for a logged-in customer.
            'email' => 'someone-else@company.example', 'first_name' => 'X', 'last_name' => 'Y', 'country' => 'HU', 'currency' => 'HUF',
        ])->assertStatus(303);

        $intent = CheckoutIntent::sole();
        $this->assertSame($customer->id, $intent->customer_id);
        $this->assertSame('anna@company.example', $intent->email);

        $parameters = (new StripeCheckoutGateway('sk_test_x'))->sessionParameters($intent, null);
        $this->assertSame('cus_known', $parameters['customer']);
        $this->assertArrayNotHasKey('customer_email', $parameters);
    }
}
