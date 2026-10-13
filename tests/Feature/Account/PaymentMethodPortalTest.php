<?php

namespace Tests\Feature\Account;

use App\Billing\PaymentMethodPortalGateway;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_is_redirected_to_portal_for_only_its_canonical_stripe_customer(): void
    {
        $customer = Customer::factory()->withUser()->create(['stripe_customer_id' => 'cus_owner_account']);
        $gateway = new class implements PaymentMethodPortalGateway
        {
            public ?string $customerId = null;

            public ?string $returnUrl = null;

            public function createSession(string $stripeCustomerId, string $returnUrl): string
            {
                $this->customerId = $stripeCustomerId;
                $this->returnUrl = $returnUrl;

                return 'https://billing.stripe.com/p/session_test';
            }
        };
        $this->app->instance(PaymentMethodPortalGateway::class, $gateway);

        $this->actingAs($customer->user)
            ->post(route('account.billing.payment-method'))
            ->assertRedirect('https://billing.stripe.com/p/session_test');

        $this->assertSame('cus_owner_account', $gateway->customerId);
        $this->assertSame(route('account.subscription'), $gateway->returnUrl);
    }

    public function test_unlinked_customer_cannot_open_a_portal_session(): void
    {
        $customer = Customer::factory()->withUser()->create(['stripe_customer_id' => null]);
        $gateway = new class implements PaymentMethodPortalGateway
        {
            public function createSession(string $stripeCustomerId, string $returnUrl): string
            {
                throw new \RuntimeException('Must not be called.');
            }
        };
        $this->app->instance(PaymentMethodPortalGateway::class, $gateway);

        $this->actingAs($customer->user)
            ->from(route('account.subscription'))
            ->post(route('account.billing.payment-method'))
            ->assertRedirect(route('account.subscription'))
            ->assertSessionHasErrors('subscription');
    }

    public function test_customer_subscription_page_offers_payment_method_management_without_changing_plan_controls(): void
    {
        $customer = Customer::factory()->withUser()->create(['stripe_customer_id' => 'cus_own_customer']);
        Subscription::factory()->for($customer)->create([
            'billing_source' => 'stripe',
            'stripe_customer_id' => 'cus_own_customer',
        ]);

        $this->actingAs($customer->user)
            ->get(route('account.subscription'))
            ->assertOk()
            ->assertSee(__('account.subscription.manage_payment_method'))
            ->assertSee(route('account.billing.payment-method'))
            ->assertSee(__('account.subscription.change_plan'));
    }
}
