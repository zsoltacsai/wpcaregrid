<?php

namespace Tests\Feature\Account;

use App\Billing\SubscriptionManagementGateway;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSubscriptionManagementGateway;
use Tests\TestCase;

/** A customer must never reach another customer's records. */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Customer $alice;

    private Customer $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = Customer::factory()->withUser()->create(['email' => 'alice@company.example', 'company_name' => 'Alice Ltd', 'tax_id' => 'HU11111111']);
        $this->bob = Customer::factory()->withUser()->create(['email' => 'bob@store.example', 'company_name' => 'Bob Store', 'tax_id' => 'HU22222222']);
    }

    public function test_customer_cannot_view_another_customers_website(): void
    {
        $bobsSite = Website::factory()->for($this->bob)->create(['url' => 'https://bob.example']);

        $this->actingAs($this->alice->user)->get(route('account.websites.show', $bobsSite))->assertForbidden();
        $this->actingAs($this->bob->user)->get(route('account.websites.show', $bobsSite))->assertOk();
    }

    public function test_customer_cannot_view_another_customers_subscription(): void
    {
        $bobsPlan = Subscription::factory()->for($this->bob)->create();

        $this->actingAs($this->alice->user)->get(route('account.subscriptions.show', $bobsPlan))->assertForbidden();
        $this->actingAs($this->bob->user)->get(route('account.subscriptions.show', $bobsPlan))->assertOk();
    }

    public function test_customer_cannot_cancel_another_customers_subscription(): void
    {
        $gateway = new FakeSubscriptionManagementGateway;
        $this->app->instance(SubscriptionManagementGateway::class, $gateway);
        $bobsPlan = Subscription::factory()->for($this->bob)->create(['billing_source' => 'stripe']);

        $this->actingAs($this->alice->user)->post(route('account.subscriptions.cancel', $bobsPlan))->assertForbidden();
        $this->assertSame([], $gateway->calls);
    }

    public function test_unassigned_subscription_is_not_visible_to_anyone(): void
    {
        $orphan = Subscription::factory()->create(['customer_id' => null]);

        $this->actingAs($this->alice->user)->get(route('account.subscriptions.show', $orphan))->assertForbidden();
    }

    public function test_lists_and_billing_only_show_own_records(): void
    {
        Website::factory()->for($this->bob)->create(['url' => 'https://bob.example']);
        Subscription::factory()->for($this->bob)->create(['product_type' => 'woocommerce', 'plan' => 'secure']);
        Subscription::factory()->for($this->alice)->create();

        $this->actingAs($this->alice->user);

        $this->get(route('account.websites.index'))->assertOk()->assertDontSee('bob.example');
        $this->get(route('account.subscription'))->assertOk()->assertDontSee('Secure — WooCommerce');
        $this->get(route('account.billing'))->assertOk()->assertSee('Alice Ltd')->assertDontSee('Bob Store')->assertDontSee('HU22222222');
        $this->get(route('account.dashboard'))->assertOk()->assertDontSee('bob@store.example');
    }

    public function test_customer_facing_urls_use_uuids(): void
    {
        $site = Website::factory()->for($this->alice)->create();

        $this->assertStringContainsString($site->uuid, route('account.websites.show', $site));
        $this->actingAs($this->alice->user)->get('/account/websites/'.$site->id)->assertNotFound();
    }

    public function test_profile_update_cannot_touch_protected_fields_or_other_customers(): void
    {
        Subscription::factory()->for($this->alice)->create();

        $this->actingAs($this->alice->user)->put(route('account.profile.update'), [
            'first_name' => 'Alicia',
            'last_name' => 'Smith',
            'customer_type' => 'company',
            'company_name' => 'Alice Holdings',
            'country_code' => 'AT',
            'tax_id' => 'ATU123',
            // Not editable here; must be ignored.
            'email' => 'hijack@company.example',
            'stripe_customer_id' => 'cus_evil',
            'user_id' => $this->bob->user_id,
            'customer_id' => $this->bob->id,
            'amount' => 1,
            'plan' => 'secure',
        ])->assertRedirect(route('account.profile'));

        $alice = $this->alice->refresh();
        $this->assertSame('Alicia', $alice->first_name);
        $this->assertSame('Alice Holdings', $alice->company_name);
        $this->assertSame('AT', $alice->country_code);
        $this->assertSame('alice@company.example', $alice->email);
        $this->assertNotSame('cus_evil', $alice->stripe_customer_id);
        $this->assertSame($this->alice->user_id, $alice->user_id);
        $this->assertSame('business', $alice->subscriptions()->sole()->plan->value);
        $this->assertSame('Bob Store', $this->bob->refresh()->company_name);
    }

    public function test_customer_profile_change_without_csrf_is_rejected_before_mutation(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->actingAs($this->alice->user)->put(route('account.profile.update'), [
            'first_name' => 'Changed without a token',
            'last_name' => 'Customer',
            'customer_type' => 'individual',
            'country_code' => 'HU',
        ])->assertStatus(419);

        $this->assertNotSame('Changed without a token', $this->alice->refresh()->first_name);
    }

    public function test_checkout_details_of_another_browser_are_not_exposed(): void
    {
        $intent = CheckoutIntent::create([
            'status' => 'completed', 'customer_id' => $this->bob->id, 'email' => 'bob@store.example', 'first_name' => 'Bob', 'last_name' => 'B', 'country' => 'HU',
            'product_type' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 899000,
            'tax_treatment' => 'final_price_no_vat_added', 'stripe_price_id' => 'price_x', 'stripe_checkout_session_id' => 'cs_test_bob',
        ]);

        $this->actingAs($this->alice->user)
            ->get(route('checkout.success', ['session_id' => 'cs_test_bob']))
            ->assertOk()
            ->assertDontSee('bob@store.example');
    }
}
