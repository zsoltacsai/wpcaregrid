<?php

namespace Tests\Feature;

use App\Billing\ReconcileStripeSubscriptions;
use App\Billing\StripeSubscriptionReader;
use App\Billing\SubscriptionStatus;
use App\Events\Platform\PlatformRecoverySignal;
use App\Models\BillingEmailOutbox;
use App\Models\BillingInvoice;
use App\Models\Subscription;
use App\Models\SubscriptionOperation;
use App\Pricing\PlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StripeSubscriptionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_snapshot_repairs_missed_status_and_records_reconciliation(): void
    {
        $subscription = Subscription::factory()->create([
            'billing_source' => 'stripe',
            'status' => SubscriptionStatus::PastDue,
            'grace_started_at' => now()->subDays(2),
            'grace_ends_at' => now()->addDays(5),
            'stripe_synced_at' => now()->subHour(),
        ]);
        $this->useRemoteSnapshot($subscription, 'active');

        $result = app(ReconcileStripeSubscriptions::class)->run();

        $this->assertSame(['checked' => 1, 'reconciled' => 0, 'changed' => 1, 'failed' => 0, 'skipped' => 0], $result);
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->grace_started_at);
        $this->assertSame('healthy', $subscription->fresh()->reconciliation_status);
        $this->assertNotNull($subscription->fresh()->last_reconciled_at);
        $this->assertNull($subscription->fresh()->reconciliation_error_category);
    }

    public function test_reconciliation_applies_a_missed_paid_invoice_through_the_invoice_lifecycle(): void
    {
        $subscription = Subscription::factory()->create([
            'billing_source' => 'stripe',
            'status' => SubscriptionStatus::PastDue,
            'last_payment_status' => 'failed',
            'grace_started_at' => now()->subDays(2),
            'grace_ends_at' => now()->addDays(5),
            'stripe_synced_at' => now()->subHour(),
        ]);
        $invoice = $this->invoice($subscription, 'paid');
        $this->useRemoteSnapshot($subscription, 'active', $invoice);

        $result = app(ReconcileStripeSubscriptions::class)->run();

        $this->assertSame(1, $result['checked']);
        $this->assertSame('paid', $subscription->fresh()->last_payment_status);
        $this->assertNull($subscription->fresh()->grace_started_at);
        $this->assertSame('paid', BillingInvoice::where('stripe_invoice_id', $invoice->id)->value('invoice_status'));
    }

    public function test_reconciliation_confirms_a_paid_pending_upgrade_from_the_invoice_price(): void
    {
        $subscription = Subscription::factory()->create([
            'billing_source' => 'stripe',
            'pending_plan' => PlanTier::Secure->value,
            'pending_interval' => 'monthly',
            'pending_stripe_price_id' => 'price_secure_target',
            'pending_pricing_version' => 1,
            'pending_change_status' => 'awaiting_payment',
            'stripe_synced_at' => now()->subHour(),
        ]);
        $operation = SubscriptionOperation::create([
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'operation' => 'upgrade',
            'operation_key' => 'reconcile-paid-upgrade-'.$subscription->id,
            'status' => 'awaiting_confirmation',
            'request_snapshot' => [
                'target_plan' => PlanTier::Secure->value,
                'target_interval' => 'monthly',
                'target_stripe_price_id' => 'price_secure_target',
                'target_pricing_version' => 1,
                'target_amount' => 2899000,
                'target_currency' => 'HUF',
            ],
        ]);
        $invoice = $this->invoice($subscription, 'paid', 'subscription_update', 'price_secure_target');
        $this->useRemoteSnapshot($subscription, 'active', $invoice);

        app(ReconcileStripeSubscriptions::class)->run();

        $this->assertSame(PlanTier::Secure, $subscription->fresh()->plan);
        $this->assertNull($subscription->fresh()->pending_change_status);
        $this->assertSame('confirmed', $operation->fresh()->status);
        $this->assertSame('paid', BillingInvoice::where('stripe_invoice_id', $invoice->id)->value('invoice_status'));
    }

    public function test_reconciliation_applies_a_missed_payment_failure_once_without_extending_grace(): void
    {
        $subscription = Subscription::factory()->create([
            'billing_source' => 'stripe',
            'status' => SubscriptionStatus::Active,
            'last_payment_status' => 'paid',
            'stripe_synced_at' => now()->subHour(),
        ]);
        $invoice = $this->invoice($subscription, 'open');
        $this->useRemoteSnapshot($subscription, 'past_due', $invoice);

        app(ReconcileStripeSubscriptions::class)->run();
        $graceStartedAt = $subscription->fresh()->grace_started_at;
        $this->travel(1)->minutes();
        app(ReconcileStripeSubscriptions::class)->run();

        $this->assertSame('failed', $subscription->fresh()->last_payment_status);
        $this->assertSame($graceStartedAt?->timestamp, $subscription->fresh()->grace_started_at?->timestamp);
        $this->assertSame(1, BillingInvoice::where('stripe_invoice_id', $invoice->id)->count());
        $this->assertSame(1, BillingEmailOutbox::where('event_key', 'invoice:'.$invoice->id.':payment-failed')->count());
    }

    public function test_reconciliation_refuses_a_latest_invoice_for_a_different_subscription(): void
    {
        $subscription = Subscription::factory()->create([
            'billing_source' => 'stripe',
            'stripe_synced_at' => now()->subHour(),
        ]);
        $invoice = $this->invoice($subscription, 'paid');
        $invoice->parent->subscription_details->subscription = 'sub_unrelated';
        $this->useRemoteSnapshot($subscription, 'active', $invoice);

        $result = app(ReconcileStripeSubscriptions::class)->run();

        $this->assertSame(1, $result['failed']);
        $this->assertSame('failed', $subscription->fresh()->reconciliation_status);
        $this->assertDatabaseMissing('billing_invoices', ['stripe_invoice_id' => $invoice->id]);
    }

    public function test_provider_identity_mismatch_fails_closed_without_mutating_subscription(): void
    {
        Event::fake();
        $subscription = Subscription::factory()->create([
            'billing_source' => 'stripe',
            'status' => SubscriptionStatus::PastDue,
            'stripe_synced_at' => now()->subHour(),
        ]);
        $this->app->instance(StripeSubscriptionReader::class, new class($subscription) implements StripeSubscriptionReader
        {
            public function __construct(private Subscription $subscription) {}

            public function retrieve(string $stripeSubscriptionId): object
            {
                return (object) [
                    'id' => $stripeSubscriptionId,
                    'customer' => 'cus_some_other_customer',
                    'status' => 'active',
                ];
            }
        });

        $result = app(ReconcileStripeSubscriptions::class)->run();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->fresh()->status);
        $this->assertSame('failed', $subscription->fresh()->reconciliation_status);
        $this->assertSame('UnexpectedValueException', $subscription->fresh()->reconciliation_error_category);
        Event::assertDispatched(PlatformRecoverySignal::class, fn ($event) => $event->type === 'billing.reconciliation_failed');
    }

    public function test_bounded_reconciliation_advances_past_the_first_batch(): void
    {
        $subscriptions = collect(range(1, 3))->map(fn () => Subscription::factory()->create([
            'billing_source' => 'stripe',
            'stripe_synced_at' => now()->subHour(),
            'last_reconciled_at' => null,
        ]));
        $reader = new class implements StripeSubscriptionReader
        {
            public array $retrieved = [];

            public function retrieve(string $stripeSubscriptionId): object
            {
                $this->retrieved[] = $stripeSubscriptionId;
                $subscription = Subscription::query()->where('stripe_subscription_id', $stripeSubscriptionId)->firstOrFail();

                return (object) [
                    'id' => $stripeSubscriptionId,
                    'customer' => $subscription->stripe_customer_id,
                    'status' => 'active',
                    'cancel_at_period_end' => false,
                    'metadata' => (object) [],
                    'items' => (object) ['data' => [(object) [
                        'price' => (object) ['id' => $subscription->stripe_price_id, 'unit_amount' => 1899000, 'currency' => 'huf'],
                        'current_period_start' => now()->timestamp,
                        'current_period_end' => now()->addMonth()->timestamp,
                    ]]],
                ];
            }
        };
        $this->app->instance(StripeSubscriptionReader::class, $reader);

        $firstBatch = app(ReconcileStripeSubscriptions::class)->run(limit: 2);
        $this->travel(1)->minutes();
        $secondBatch = app(ReconcileStripeSubscriptions::class)->run(limit: 2);

        $this->assertSame(2, $firstBatch['checked']);
        $this->assertSame(2, $secondBatch['checked']);
        $this->assertCount(3, array_unique($reader->retrieved));
        $this->assertTrue($subscriptions->every(fn (Subscription $subscription) => $subscription->fresh()->last_reconciled_at !== null));
    }

    public function test_reconciliation_does_not_overwrite_a_webhook_update_that_arrived_during_fetch(): void
    {
        $subscription = Subscription::factory()->create([
            'billing_source' => 'stripe',
            'status' => SubscriptionStatus::PastDue,
            'stripe_synced_at' => now()->subHour(),
        ]);
        $this->app->instance(StripeSubscriptionReader::class, new class($subscription) implements StripeSubscriptionReader
        {
            public function __construct(private Subscription $subscription) {}

            public function retrieve(string $stripeSubscriptionId): object
            {
                $this->subscription->forceFill(['stripe_synced_at' => now(), 'status' => SubscriptionStatus::Canceled])->save();

                return (object) [
                    'id' => $stripeSubscriptionId,
                    'customer' => $this->subscription->stripe_customer_id,
                    'status' => 'active',
                    'items' => (object) ['data' => [(object) [
                        'price' => (object) ['id' => $this->subscription->stripe_price_id, 'unit_amount' => 1899000, 'currency' => 'huf'],
                        'current_period_start' => now()->timestamp,
                        'current_period_end' => now()->addMonth()->timestamp,
                    ]]],
                    'metadata' => (object) [],
                ];
            }
        });

        $result = app(ReconcileStripeSubscriptions::class)->run();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertSame('skipped_concurrent_update', $subscription->fresh()->reconciliation_status);
    }

    private function useRemoteSnapshot(Subscription $subscription, string $status, ?object $latestInvoice = null): void
    {
        $this->app->instance(StripeSubscriptionReader::class, new class($subscription, $status, $latestInvoice) implements StripeSubscriptionReader
        {
            public function __construct(private Subscription $subscription, private string $status, private ?object $latestInvoice) {}

            public function retrieve(string $stripeSubscriptionId): object
            {
                return (object) [
                    'id' => $stripeSubscriptionId,
                    'customer' => $this->subscription->stripe_customer_id,
                    'status' => $this->status,
                    'cancel_at_period_end' => false,
                    'metadata' => (object) [],
                    'latest_invoice' => $this->latestInvoice,
                    'items' => (object) ['data' => [(object) [
                        'price' => (object) ['id' => $this->subscription->stripe_price_id, 'unit_amount' => 1899000, 'currency' => 'huf'],
                        'current_period_start' => now()->timestamp,
                        'current_period_end' => now()->addMonth()->timestamp,
                    ]]],
                ];
            }
        });
    }

    private function invoice(Subscription $subscription, string $status, string $billingReason = 'subscription_cycle', ?string $priceId = null): object
    {
        return (object) [
            'id' => 'in_reconcile_'.$status,
            'object' => 'invoice',
            'status' => $status,
            'billing_reason' => $billingReason,
            'created' => now()->subHour()->timestamp,
            'customer' => $subscription->stripe_customer_id,
            'subscription' => $subscription->stripe_subscription_id,
            'parent' => (object) ['subscription_details' => (object) ['subscription' => $subscription->stripe_subscription_id]],
            'status_transitions' => (object) ['paid_at' => $status === 'paid' ? now()->subMinutes(5)->timestamp : null],
            'currency' => 'huf',
            'total' => 1899000,
            'amount_paid' => $status === 'paid' ? 1899000 : 0,
            'amount_due' => $status === 'paid' ? 0 : 1899000,
            'attempted' => $status === 'open',
            'attempt_count' => $status === 'open' ? 1 : 0,
            'lines' => (object) ['data' => $priceId ? [(object) ['pricing' => (object) ['price_details' => (object) ['price' => $priceId]]]] : []],
        ];
    }
}
