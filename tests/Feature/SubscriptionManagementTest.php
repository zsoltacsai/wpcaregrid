<?php

namespace Tests\Feature;

use App\Billing\SubscriptionManagement;
use App\Billing\SubscriptionManagementGateway;
use App\Models\Customer;
use App\Models\Subscription;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsStripeWebhooks;
use Tests\Fakes\FakeSubscriptionManagementGateway;
use Tests\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;
    use SendsStripeWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useWebhookSecret();
    }

    public function test_cancellation_is_provider_confirmed_idempotent_and_does_not_mutate_mirror(): void
    {
        $gateway = new FakeSubscriptionManagementGateway;
        $this->app->instance(SubscriptionManagementGateway::class, $gateway);
        $subscription = Subscription::factory()->create(['billing_source' => 'stripe']);
        $customer = $subscription->customer;

        $first = app(SubscriptionManagement::class)->cancelAtPeriodEnd($subscription, $customer);
        $second = app(SubscriptionManagement::class)->cancelAtPeriodEnd($subscription, $customer);

        $this->assertSame('awaiting_confirmation', $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $gateway->calls);
        $this->assertFalse($subscription->fresh()->cancel_at_period_end, 'Stripe webhook remains the authority for local state.');
        $this->assertSame('req_test_123', $first->provider_request_id);
        $this->assertDatabaseHas('admin_audit_events', [
            'event' => 'billing.cancellation_scheduled',
            'subject_key' => $subscription->uuid,
        ]);
    }

    public function test_reactivation_uses_a_separate_operation_and_foreign_customer_is_refused(): void
    {
        $gateway = new FakeSubscriptionManagementGateway;
        $this->app->instance(SubscriptionManagementGateway::class, $gateway);
        $subscription = Subscription::factory()->create(['billing_source' => 'stripe', 'cancel_at_period_end' => true]);
        $operation = app(SubscriptionManagement::class)->reactivate($subscription, $subscription->customer);

        $this->assertSame('reactivate', $operation->operation);
        $this->assertFalse($operation->request_snapshot['cancel_at_period_end']);

        $this->expectException(\LogicException::class);
        app(SubscriptionManagement::class)->reactivate($subscription, Customer::factory()->create());
    }

    public function test_signed_stripe_update_confirms_the_pending_cancel_operation(): void
    {
        $gateway = new FakeSubscriptionManagementGateway;
        $this->app->instance(SubscriptionManagementGateway::class, $gateway);
        $subscription = Subscription::factory()->create(['billing_source' => 'stripe']);
        $operation = app(SubscriptionManagement::class)->cancelAtPeriodEnd($subscription, $subscription->customer);

        $this->stripeEvent('customer.subscription.updated', $this->stripeSubscription($subscription->stripe_subscription_id, 'active', [
            'customer' => $subscription->stripe_customer_id,
            'cancel_at_period_end' => true,
        ]))->assertStatus(204);

        $this->assertSame('confirmed', $operation->refresh()->status);
        $this->assertNotNull($operation->confirmed_at);
        $this->assertTrue($subscription->refresh()->cancel_at_period_end);
    }

    public function test_cancel_reactivate_cancel_in_one_period_gets_a_new_idempotency_operation(): void
    {
        $gateway = new FakeSubscriptionManagementGateway;
        $this->app->instance(SubscriptionManagementGateway::class, $gateway);
        $subscription = Subscription::factory()->create(['billing_source' => 'stripe']);
        $customer = $subscription->customer;
        $firstCancel = app(SubscriptionManagement::class)->cancelAtPeriodEnd($subscription, $customer);

        $subscription->forceFill(['cancel_at_period_end' => true])->save();
        app(SubscriptionManagement::class)->reactivate($subscription, $customer);

        $subscription->forceFill(['cancel_at_period_end' => false])->save();
        $secondCancel = app(SubscriptionManagement::class)->cancelAtPeriodEnd($subscription, $customer);

        $this->assertNotSame($firstCancel->operation_key, $secondCancel->operation_key);
        $this->assertCount(3, $gateway->calls);
    }

    public function test_upgrade_stays_on_current_entitlement_until_matching_stripe_invoice_is_paid(): void
    {
        $this->configureStripePriceIds();
        $catalog = app(PricingCatalog::class);
        $customer = Customer::factory()->create(['country_code' => 'HU']);
        $current = $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Business, BillingInterval::Monthly), 'HU', 'HUF');
        $subscription = Subscription::factory()->for($customer)->create([
            'billing_source' => 'stripe', 'product_type' => ProductType::WordPress, 'plan' => PlanTier::Business,
            'interval' => BillingInterval::Monthly, 'stripe_price_id' => $current->stripePriceId,
        ]);
        $target = $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Secure, BillingInterval::Monthly), 'HU', 'HUF');
        $gateway = new FakeSubscriptionManagementGateway;
        $this->app->instance(SubscriptionManagementGateway::class, $gateway);

        $operation = app(SubscriptionManagement::class)->changePlan($subscription, $customer, ProductType::WordPress, PlanTier::Secure, BillingInterval::Monthly);

        $this->assertSame('upgrade', $operation->operation);
        $this->assertSame('awaiting_confirmation', $operation->status);
        $this->assertTrue($gateway->priceChanges[0]['immediate']);
        $this->assertSame(PlanTier::Business, $subscription->fresh()->plan);
        $this->assertSame('awaiting_payment', $subscription->fresh()->pending_change_status);

        $this->stripeEvent('invoice.paid', [
            'id' => 'in_test_change',
            'object' => 'invoice',
            'customer' => $subscription->stripe_customer_id,
            'billing_reason' => 'subscription_update',
            'status' => 'paid',
            'currency' => 'huf',
            'amount_paid' => 190000,
            'amount_due' => 0,
            'created' => now()->timestamp,
            'status_transitions' => ['paid_at' => now()->timestamp],
            'parent' => ['subscription_details' => ['subscription' => $subscription->stripe_subscription_id]],
            'lines' => ['data' => [[
                'pricing' => ['price_details' => ['price' => $target->stripePriceId]],
            ]]],
        ], 'evt_paid_plan_change')->assertStatus(204);
        $this->assertSame(PlanTier::Secure, $subscription->fresh()->plan);
        $this->assertNull($subscription->fresh()->pending_change_status);
        $this->assertSame('confirmed', $operation->fresh()->status);
    }

    public function test_provider_failure_is_recorded_as_reconciliation_required_and_audited_without_changing_plan(): void
    {
        $this->configureStripePriceIds();
        $catalog = app(PricingCatalog::class);
        $customer = Customer::factory()->create(['country_code' => 'HU']);
        $current = $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Business, BillingInterval::Monthly), 'HU', 'HUF');
        $subscription = Subscription::factory()->for($customer)->create([
            'billing_source' => 'stripe',
            'product_type' => ProductType::WordPress,
            'plan' => PlanTier::Business,
            'interval' => BillingInterval::Monthly,
            'stripe_price_id' => $current->stripePriceId,
        ]);
        $gateway = new FakeSubscriptionManagementGateway;
        $gateway->failure = new \RuntimeException('Provider unavailable');
        $this->app->instance(SubscriptionManagementGateway::class, $gateway);

        try {
            app(SubscriptionManagement::class)->changePlan($subscription, $customer, ProductType::WordPress, PlanTier::Secure, BillingInterval::Monthly);
            $this->fail('A provider failure must be propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Provider unavailable', $exception->getMessage());
        }

        $this->assertSame(PlanTier::Business, $subscription->fresh()->plan);
        $this->assertSame('reconciliation_required', $subscription->fresh()->pending_change_status);
        $this->assertDatabaseHas('admin_audit_events', [
            'event' => 'billing.subscription_change_failed',
            'subject_key' => $subscription->uuid,
        ]);

        try {
            app(SubscriptionManagement::class)->changePlan($subscription->fresh(), $customer, ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly);
            $this->fail('A pending reconciliation must block a second plan change.');
        } catch (\LogicException $exception) {
            $this->assertSame('A subscription change is already pending confirmation.', $exception->getMessage());
        }
    }

    public function test_webhook_confirmation_received_during_provider_request_is_not_regressed_after_response(): void
    {
        $this->configureStripePriceIds();
        $catalog = app(PricingCatalog::class);
        $customer = Customer::factory()->create(['country_code' => 'HU']);
        $current = $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Business, BillingInterval::Monthly), 'HU', 'HUF');
        $subscription = Subscription::factory()->for($customer)->create([
            'billing_source' => 'stripe',
            'product_type' => ProductType::WordPress,
            'plan' => PlanTier::Business,
            'interval' => BillingInterval::Monthly,
            'stripe_price_id' => $current->stripePriceId,
        ]);
        $target = $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Secure, BillingInterval::Monthly), 'HU', 'HUF');
        $gateway = new FakeSubscriptionManagementGateway;
        $gateway->duringPriceChange = function () use ($subscription, $target): void {
            $this->stripeEvent('invoice.paid', [
                'id' => 'in_test_change_race',
                'object' => 'invoice',
                'customer' => $subscription->stripe_customer_id,
                'billing_reason' => 'subscription_update',
                'status' => 'paid',
                'currency' => 'huf',
                'amount_paid' => 190000,
                'amount_due' => 0,
                'created' => now()->timestamp,
                'status_transitions' => ['paid_at' => now()->timestamp],
                'parent' => ['subscription_details' => ['subscription' => $subscription->stripe_subscription_id]],
                'lines' => ['data' => [[
                    'pricing' => ['price_details' => ['price' => $target->stripePriceId]],
                ]]],
            ], 'evt_paid_plan_change_during_request')->assertStatus(204);
        };
        $this->app->instance(SubscriptionManagementGateway::class, $gateway);

        $operation = app(SubscriptionManagement::class)->changePlan($subscription, $customer, ProductType::WordPress, PlanTier::Secure, BillingInterval::Monthly);

        $this->assertSame('confirmed', $operation->fresh()->status);
        $this->assertSame(PlanTier::Secure, $subscription->fresh()->plan);
        $this->assertNull($subscription->fresh()->pending_change_status);
    }

    public function test_downgrade_is_scheduled_and_preserves_current_paid_entitlement(): void
    {
        $this->configureStripePriceIds();
        $catalog = app(PricingCatalog::class);
        $customer = Customer::factory()->create(['country_code' => 'HU']);
        $current = $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Secure, BillingInterval::Annual), 'HU', 'HUF');
        $subscription = Subscription::factory()->for($customer)->create([
            'billing_source' => 'stripe', 'product_type' => ProductType::WordPress, 'plan' => PlanTier::Secure,
            'interval' => BillingInterval::Annual, 'stripe_price_id' => $current->stripePriceId,
            'current_period_end' => now()->addMonths(8),
        ]);
        $gateway = new FakeSubscriptionManagementGateway;
        $this->app->instance(SubscriptionManagementGateway::class, $gateway);

        $operation = app(SubscriptionManagement::class)->changePlan($subscription, $customer, ProductType::WordPress, PlanTier::Business, BillingInterval::Annual);

        $this->assertSame('downgrade', $operation->operation);
        $this->assertSame('scheduled', $subscription->fresh()->pending_change_status);
        $this->assertSame($subscription->current_period_end->timestamp, $subscription->fresh()->pending_change_effective_at->timestamp);
        $this->assertSame(PlanTier::Secure, $subscription->fresh()->plan);
        $this->assertFalse($gateway->priceChanges[0]['immediate']);
    }

    private function configureStripePriceIds(): void
    {
        foreach (config('pricing.prices') as $index => $price) {
            config(["pricing.prices.{$index}.stripe_price_id" => "price_test_m11_{$index}"]);
        }
        app()->forgetInstance(PricingCatalog::class);
    }
}
