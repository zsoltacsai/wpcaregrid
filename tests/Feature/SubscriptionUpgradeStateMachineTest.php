<?php

namespace Tests\Feature;

use App\Billing\ReconcileStripeSubscriptions;
use App\Billing\StripeSubscriptionReader;
use App\Billing\SubscriptionManagement;
use App\Billing\SubscriptionManagementGateway;
use App\Models\AdminAuditEvent;
use App\Models\BillingEmailOutbox;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionOperation;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SendsStripeWebhooks;
use Tests\Fakes\FakeSubscriptionManagementGateway;
use Tests\TestCase;

/**
 * The state machine of an immediate (paid) plan change. Invariant: a paid plan change never grants the higher plan until
 * Stripe has authentically shown the payment, a paid change is never lost, and an unknown outcome is retried or surfaced,
 * never guessed. See docs/STRIPE_SUBSCRIPTION_STATE_MACHINE.md.
 */
class SubscriptionUpgradeStateMachineTest extends TestCase
{
    use RefreshDatabase;
    use SendsStripeWebhooks;

    protected Customer $customer;

    protected Subscription $subscription;

    protected FakeSubscriptionManagementGateway $gateway;

    protected string $currentPrice;

    protected string $targetPrice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useWebhookSecret();
        Mail::fake();   // the sync queue runs the webhook inbox and the outbox; no mail leaves, the outbox rows are asserted
        foreach (config('pricing.prices') as $index => $price) {
            config(["pricing.prices.{$index}.stripe_price_id" => "price_test_sm_{$index}"]);
        }
        app()->forgetInstance(PricingCatalog::class);
        $catalog = app(PricingCatalog::class);
        $this->customer = Customer::factory()->withUser()->create(['country_code' => 'HU']);
        $this->currentPrice = (string) $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Business, BillingInterval::Monthly), 'HU', 'HUF')->stripePriceId;
        $this->targetPrice = (string) $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Secure, BillingInterval::Monthly), 'HU', 'HUF')->stripePriceId;
        $this->subscription = Subscription::factory()->for($this->customer)->create([
            'billing_source' => 'stripe', 'product_type' => ProductType::WordPress, 'plan' => PlanTier::Business,
            'interval' => BillingInterval::Monthly, 'stripe_price_id' => $this->currentPrice,
            'stripe_synced_at' => now()->subHour(), 'stripe_synced_event_id' => 'evt_before',
        ]);
        $this->gateway = new FakeSubscriptionManagementGateway;
        $this->app->instance(SubscriptionManagementGateway::class, $this->gateway);
    }

    private function management(): SubscriptionManagement
    {
        return app(SubscriptionManagement::class);
    }

    protected function requestUpgrade(): SubscriptionOperation
    {
        return $this->management()->changePlan($this->subscription->fresh(), $this->customer, ProductType::WordPress, PlanTier::Secure, BillingInterval::Monthly);
    }

    /** The upgrade request when Stripe could not be reached: reconciliation_required. */
    private function requestUpgradeWithLostResponse(): SubscriptionOperation
    {
        $this->gateway->failure = new \RuntimeException('Stripe timeout');
        try {
            $this->requestUpgrade();
            $this->fail('A provider failure must be propagated.');
        } catch (\RuntimeException) {
        }
        $this->gateway->failure = null;

        return SubscriptionOperation::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function invoice(string $id, string $status, ?string $priceId = null, array $extra = [], ?int $paidAt = null): array
    {
        return $extra + [
            'id' => $id, 'object' => 'invoice', 'customer' => $this->subscription->stripe_customer_id,
            'billing_reason' => 'subscription_update', 'status' => $status, 'currency' => 'huf',
            'amount_paid' => $status === 'paid' ? 190000 : 0, 'amount_due' => $status === 'paid' ? 0 : 190000,
            'attempted' => $status !== 'draft', 'attempt_count' => 1,
            'created' => now()->timestamp, 'status_transitions' => $status === 'paid' ? ['paid_at' => $paidAt ?? now()->timestamp] : [],
            'parent' => ['subscription_details' => ['subscription' => $this->subscription->stripe_subscription_id]],
            'lines' => ['data' => [['pricing' => ['price_details' => ['price' => $priceId ?? $this->targetPrice]]]]],
        ];
    }

    protected function paid(string $invoiceId = 'in_test_change', string $eventId = 'evt_paid', ?int $created = null): void
    {
        $this->stripeEvent('invoice.paid', $this->invoice($invoiceId, 'paid', null, [], $created), $eventId, $created)->assertStatus(204);
    }

    private function paymentFailed(string $invoiceId = 'in_test_change', string $eventId = 'evt_failed', ?int $created = null): void
    {
        $this->stripeEvent('invoice.payment_failed', $this->invoice($invoiceId, 'open'), $eventId, $created)->assertStatus(204);
    }

    private function subscriptionEvent(string $priceId, string $eventId, ?int $created = null, array $overrides = []): void
    {
        $this->stripeEvent('customer.subscription.updated', $this->stripeSubscription($this->subscription->stripe_subscription_id, 'active', [
            'customer' => $this->subscription->stripe_customer_id,
            'items' => ['data' => [['price' => ['id' => $priceId, 'unit_amount' => 2899000]]]],
        ] + $overrides), $eventId, $created)->assertStatus(204);
    }

    /** The subscription as the Stripe API returns it to the reconciliation job. */
    private function remoteSubscription(string $priceId, array $overrides = [], ?array $latestInvoice = null): object
    {
        $remote = json_decode(json_encode($this->stripeSubscription($this->subscription->stripe_subscription_id, 'active', [
            'customer' => $this->subscription->stripe_customer_id,
            'items' => ['data' => [['price' => ['id' => $priceId, 'unit_amount' => 1899000]]]],
        ] + $overrides)));
        $remote->metadata = (object) [];
        $remote->schedule = null;
        if ($latestInvoice !== null) {
            $remote->latest_invoice = json_decode(json_encode($latestInvoice));
        }

        return $remote;
    }

    private function useRemote(object $remote): object
    {
        $reader = new class($remote) implements StripeSubscriptionReader
        {
            public function __construct(public object $remote) {}

            public function retrieve(string $stripeSubscriptionId): object
            {
                return $this->remote;
            }
        };
        $this->app->instance(StripeSubscriptionReader::class, $reader);

        return $reader;
    }

    private function plan(): PlanTier
    {
        return $this->subscription->fresh()->plan;
    }

    // ---- Finding A: a failed or expired upgrade payment ----

    public function test_a_failed_upgrade_payment_never_grants_the_plan_starts_no_grace_and_unblocks_a_new_request(): void
    {
        $operation = $this->requestUpgrade();
        $this->assertSame('awaiting_payment', $this->subscription->fresh()->pending_change_status);

        $this->paymentFailed();

        $subscription = $this->subscription->fresh();
        $this->assertSame(PlanTier::Business, $subscription->plan, 'the higher plan is never granted');
        $this->assertSame($this->currentPrice, $subscription->stripe_price_id);
        $this->assertNull($subscription->pending_change_status, 'the pending change no longer blocks the account');
        $this->assertNull($subscription->pending_stripe_price_id);
        $this->assertNull($subscription->grace_started_at, 'a failed upgrade payment is not a failed subscription payment: no grace');
        $this->assertNull($subscription->grace_ends_at);
        $this->assertNotSame('failed', $subscription->last_payment_status);
        $this->assertSame('payment_failed', $operation->fresh()->status);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'billing.subscription_change_payment_failed', 'subject_key' => $subscription->uuid]);

        // the customer is told the change did not go through, and the generic payment-failed notice (grace/retry wording) is not sent
        $this->assertSame(1, BillingEmailOutbox::query()->where('notification', 'upgrade_payment_failed')->count());
        $this->assertSame(0, BillingEmailOutbox::query()->where('notification', 'payment_failed')->count());

        // and the customer can ask again
        $second = $this->requestUpgrade();
        $this->assertNotSame($operation->id, $second->id);
        $this->assertSame('awaiting_payment', $this->subscription->fresh()->pending_change_status);
    }

    public function test_the_same_failure_delivered_twice_changes_state_and_notifies_once(): void
    {
        $operation = $this->requestUpgrade();

        $this->paymentFailed('in_test_change', 'evt_failed_1');
        $this->paymentFailed('in_test_change', 'evt_failed_1');   // replay of the same event
        $this->paymentFailed('in_test_change', 'evt_failed_2');   // a second delivery with a new event id

        $this->assertSame('payment_failed', $operation->fresh()->status);
        $this->assertSame(1, BillingEmailOutbox::query()->where('notification', 'upgrade_payment_failed')->count());
        $this->assertSame(1, AdminAuditEvent::query()->where('event', 'billing.subscription_change_payment_failed')->count());
        $this->assertNull($this->subscription->fresh()->grace_started_at);
    }

    public function test_a_failure_of_an_unrelated_invoice_neither_clears_the_pending_change_nor_is_treated_as_an_upgrade_failure(): void
    {
        $this->requestUpgrade();

        // a renewal invoice failing is an ordinary subscription payment failure: grace starts, the pending change stays
        $this->stripeEvent('invoice.payment_failed', $this->invoice('in_renewal', 'open', $this->currentPrice, ['billing_reason' => 'subscription_cycle']), 'evt_renewal_failed')->assertStatus(204);
        $this->assertSame('awaiting_payment', $this->subscription->fresh()->pending_change_status);
        $this->assertNotNull($this->subscription->fresh()->grace_started_at);

        // an upgrade-reason invoice for another price does not match the pending target either
        $other = $this->invoice('in_other', 'open', $this->currentPrice);
        $this->stripeEvent('invoice.payment_failed', $other, 'evt_other_failed')->assertStatus(204);
        $this->assertSame('awaiting_payment', $this->subscription->fresh()->pending_change_status);
    }

    public function test_a_late_payment_after_a_failed_attempt_still_applies_the_paid_upgrade_once(): void
    {
        $operation = $this->requestUpgrade();
        $this->paymentFailed('in_test_change', 'evt_failed', now()->subMinutes(5)->timestamp);
        $this->assertSame(PlanTier::Business, $this->plan());

        // the customer authenticates / retries on the invoice and it is paid: a paid change is never lost
        $this->paid('in_test_change', 'evt_paid_late');
        $this->paid('in_test_change', 'evt_paid_late_again');   // redelivery under another event id

        $subscription = $this->subscription->fresh();
        $this->assertSame(PlanTier::Secure, $subscription->plan);
        $this->assertSame($this->targetPrice, $subscription->stripe_price_id);
        $this->assertNull($subscription->pending_change_status);
        $this->assertSame('confirmed', $operation->fresh()->status);
        $this->assertSame(1, BillingEmailOutbox::query()->where('notification', 'upgrade_completed')->count());
    }

    public function test_a_payment_failure_that_arrives_after_the_payment_is_ignored_and_never_downgrades_or_blocks(): void
    {
        $operation = $this->requestUpgrade();
        $this->paid('in_test_change', 'evt_paid', now()->timestamp);

        $this->paymentFailed('in_test_change', 'evt_failed_old', now()->subMinutes(10)->timestamp);   // out of order

        $subscription = $this->subscription->fresh();
        $this->assertSame(PlanTier::Secure, $subscription->plan);
        $this->assertNull($subscription->pending_change_status);
        $this->assertSame('confirmed', $operation->fresh()->status);
        $this->assertNull($subscription->grace_started_at);
        $this->assertSame(0, BillingEmailOutbox::query()->where('notification', 'upgrade_payment_failed')->count());
    }

    public function test_an_expired_unpaid_upgrade_is_closed_by_reconciliation_only_after_stripes_window_and_only_when_stripe_shows_nothing_pending(): void
    {
        $operation = $this->requestUpgrade();
        $reader = $this->useRemote($this->remoteSubscription($this->currentPrice));

        // inside Stripe's pending-update window: nothing is decided
        app(ReconcileStripeSubscriptions::class)->run(10, $this->subscription->id);
        $this->assertSame('awaiting_payment', $this->subscription->fresh()->pending_change_status);

        // after the window, but Stripe still reports a pending update: still nothing is decided
        $this->travel(26)->hours();
        $reader->remote = $this->remoteSubscription($this->currentPrice, ['pending_update' => ['expires_at' => now()->addHour()->timestamp]]);
        app(ReconcileStripeSubscriptions::class)->run(10, $this->subscription->id);
        $this->assertSame('awaiting_payment', $this->subscription->fresh()->pending_change_status);

        // after the window, nothing pending, still the old price: it did not happen
        $reader->remote = $this->remoteSubscription($this->currentPrice);
        app(ReconcileStripeSubscriptions::class)->run(10, $this->subscription->id);

        $subscription = $this->subscription->fresh();
        $this->assertNull($subscription->pending_change_status);
        $this->assertSame(PlanTier::Business, $subscription->plan);
        $this->assertSame('expired', $operation->fresh()->status);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'billing.subscription_change_expired', 'subject_key' => $subscription->uuid]);
        $this->assertNull($subscription->grace_started_at);
    }

    public function test_a_payment_after_the_attempt_expired_never_grants_the_plan_by_itself(): void
    {
        $operation = $this->requestUpgrade();
        $this->useRemote($this->remoteSubscription($this->currentPrice));
        $this->travel(26)->hours();
        app(ReconcileStripeSubscriptions::class)->run(10, $this->subscription->id);
        $this->assertSame('expired', $operation->fresh()->status);
        $this->assertSame(PlanTier::Business, $this->plan());

        // paying the invoice after Stripe discarded the pending update does not change the Stripe subscription: no plan here either
        $this->paid('in_test_change', 'evt_paid_after_expiry', now()->timestamp);
        $this->assertSame(PlanTier::Business, $this->plan());
        $this->assertSame('expired', $operation->fresh()->status);
        $this->assertNull($this->subscription->fresh()->pending_change_status);

        // only Stripe showing the new price (the update really was applied) moves the plan, through the subscription object
        $this->subscriptionEvent($this->targetPrice, 'evt_applied_by_stripe', now()->addMinute()->timestamp);
        $this->assertSame(PlanTier::Secure, $this->plan());
    }

    public function test_a_payment_of_a_failed_attempt_after_the_pending_window_is_not_treated_as_the_upgrade(): void
    {
        $operation = $this->requestUpgrade();
        $this->paymentFailed('in_test_change', 'evt_failed', now()->timestamp);

        $this->paid('in_test_change', 'evt_paid_much_later', now()->addHours(30)->timestamp);
        $this->paid('in_test_change', 'evt_paid_much_later_again', now()->addHours(30)->timestamp);

        $this->assertSame(PlanTier::Business, $this->plan());
        $this->assertSame('payment_failed', $operation->fresh()->status);
        // a real payment that cannot be applied is surfaced to an operator, once
        $this->assertSame(1, AdminAuditEvent::query()->where('event', 'billing.subscription_change_late_payment_unapplied')->count());
    }

    public function test_a_failure_redelivered_after_the_attempt_expired_starts_no_grace(): void
    {
        $this->requestUpgrade();
        $this->useRemote($this->remoteSubscription($this->currentPrice));
        $this->travel(26)->hours();
        app(ReconcileStripeSubscriptions::class)->run(10, $this->subscription->id);

        $this->paymentFailed('in_test_change', 'evt_failed_after_expiry', now()->timestamp);

        $subscription = $this->subscription->fresh();
        $this->assertNull($subscription->grace_started_at);
        $this->assertNotSame('failed', $subscription->last_payment_status);
    }

    public function test_a_failure_redelivered_for_an_earlier_attempt_does_not_start_grace_while_a_new_attempt_is_open(): void
    {
        $this->gateway->invoiceId = 'in_one';
        $this->requestUpgrade();
        $this->paymentFailed('in_one', 'evt_failed_one', now()->timestamp);
        $this->gateway->invoiceId = 'in_two';
        $second = $this->requestUpgrade();
        $this->assertSame('awaiting_payment', $this->subscription->fresh()->pending_change_status);

        $this->paymentFailed('in_one', 'evt_failed_one_again', now()->addSecond()->timestamp);   // a redelivery of the first failure

        $subscription = $this->subscription->fresh();
        $this->assertNull($subscription->grace_started_at);
        $this->assertSame('awaiting_payment', $subscription->pending_change_status, 'the open second attempt is untouched');
        $this->assertSame('awaiting_confirmation', $second->fresh()->status);
    }

    public function test_a_late_payment_of_the_first_of_two_failed_attempts_is_not_lost(): void
    {
        $this->gateway->invoiceId = 'in_one';
        $first = $this->requestUpgrade();
        $this->paymentFailed('in_one', 'evt_failed_one', now()->timestamp);
        $this->gateway->invoiceId = 'in_two';
        $second = $this->requestUpgrade();
        $this->paymentFailed('in_two', 'evt_failed_two', now()->addSecond()->timestamp);
        $this->assertSame(['payment_failed', 'payment_failed'], [$first->fresh()->status, $second->fresh()->status]);

        $this->paid('in_one', 'evt_paid_one', now()->addMinutes(10)->timestamp);

        $this->assertSame(PlanTier::Secure, $this->plan());
        $this->assertSame('confirmed', $first->fresh()->status);
        $this->assertSame('payment_failed', $second->fresh()->status);
    }

    public function test_a_late_payment_while_another_change_is_pending_is_not_applied_over_it_and_is_surfaced(): void
    {
        $this->gateway->invoiceId = 'in_one';
        $first = $this->requestUpgrade();
        $this->paymentFailed('in_one', 'evt_failed_one', now()->timestamp);
        $this->gateway->invoiceId = 'in_two';
        $second = $this->requestUpgrade();

        $this->paid('in_one', 'evt_paid_one', now()->addMinutes(10)->timestamp);

        $subscription = $this->subscription->fresh();
        $this->assertSame(PlanTier::Business, $subscription->plan, 'the pending second attempt is not wiped by the first one');
        $this->assertSame('awaiting_payment', $subscription->pending_change_status);
        $this->assertSame('payment_failed', $first->fresh()->status);
        $this->assertSame('awaiting_confirmation', $second->fresh()->status);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'billing.subscription_change_late_payment_unapplied', 'subject_key' => $subscription->uuid]);
    }

    public function test_a_redelivered_failure_of_a_closed_attempt_does_not_close_an_open_attempt_that_has_no_invoice_id_yet(): void
    {
        $this->gateway->invoiceId = 'in_one';
        $this->requestUpgrade();
        $this->paymentFailed('in_one', 'evt_failed_one', now()->timestamp);
        $second = $this->requestUpgradeWithLostResponse();   // the second attempt: outcome unknown, no invoice id recorded
        $this->assertSame('reconciliation_required', $second->status);

        $this->paymentFailed('in_one', 'evt_failed_one_again', now()->addSecond()->timestamp);   // the first attempt's failure, redelivered

        $this->assertSame('reconciliation_required', $second->fresh()->status);
        $this->assertSame('reconciliation_required', $this->subscription->fresh()->pending_change_status);
        $this->assertNull($this->subscription->fresh()->grace_started_at);

        // and the first attempt's late payment does not settle the second attempt either
        $this->paid('in_one', 'evt_paid_one', now()->addMinutes(2)->timestamp);
        $this->assertSame('reconciliation_required', $second->fresh()->status);
        $this->assertSame(PlanTier::Business, $this->plan());
    }

    public function test_a_late_payment_is_not_applied_behind_a_later_change_that_was_already_settled(): void
    {
        $catalog = app(PricingCatalog::class);
        $businessAnnual = (string) $catalog->resolve(new PriceSelection(ProductType::WordPress, PlanTier::Business, BillingInterval::Annual), 'HU', 'HUF')->stripePriceId;
        $this->gateway->invoiceId = 'in_one';
        $this->requestUpgrade();   // Secure monthly
        $this->paymentFailed('in_one', 'evt_failed_one', now()->timestamp);
        $this->gateway->invoiceId = 'in_two';
        $this->management()->changePlan($this->subscription->fresh(), $this->customer, ProductType::WordPress, PlanTier::Business, BillingInterval::Annual);   // a switch to annual
        $this->stripeEvent('invoice.paid', $this->invoice('in_two', 'paid', $businessAnnual), 'evt_paid_two')->assertStatus(204);
        $this->assertSame(BillingInterval::Annual, $this->subscription->fresh()->interval);

        $this->paid('in_one', 'evt_paid_one_late', now()->addMinutes(10)->timestamp);   // the superseded Secure invoice

        $subscription = $this->subscription->fresh();
        $this->assertSame([PlanTier::Business, BillingInterval::Annual, $businessAnnual], [$subscription->plan, $subscription->interval, $subscription->stripe_price_id]);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'billing.subscription_change_late_payment_unapplied', 'subject_key' => $subscription->uuid]);
    }

    public function test_the_late_payment_window_runs_from_the_first_request_not_from_a_replay(): void
    {
        $first = $this->requestUpgradeWithLostResponse();
        $this->travel(22)->hours();
        $this->requestUpgrade();   // the replay is answered; submitted_at is now 22 hours after the first request
        $this->paymentFailed('in_test_change', 'evt_failed', now()->timestamp);
        $this->assertSame('payment_failed', $first->fresh()->status);

        $this->travel(2)->hours();
        $this->paid('in_test_change', 'evt_paid_24h_after_first_request', now()->timestamp);

        $this->assertSame(PlanTier::Business, $this->plan(), 'Stripe discarded the pending update 23 hours after the first request');
    }

    public function test_an_expired_attempt_that_lost_its_response_records_stripes_invoice_so_its_failure_is_recognised(): void
    {
        $operation = $this->requestUpgradeWithLostResponse();
        $this->useRemote($this->remoteSubscription($this->currentPrice, [], $this->invoice('in_draft_lost', 'draft')));
        $this->travel(26)->hours();
        app(ReconcileStripeSubscriptions::class)->run(10, $this->subscription->id);
        $this->assertSame('expired', $operation->fresh()->status);
        $this->assertSame('in_draft_lost', data_get($operation->fresh()->request_snapshot, 'provider_invoice_id'));

        $this->paymentFailed('in_draft_lost', 'evt_failed_expired_lost', now()->timestamp);

        $this->assertNull($this->subscription->fresh()->grace_started_at);
    }

    public function test_a_failure_webhook_that_settles_the_change_while_the_request_fails_is_final_and_a_later_payment_still_applies(): void
    {
        $this->gateway->duringPriceChange = function (): void {
            $this->paymentFailed('in_test_change', 'evt_failed_during_failing_request', now()->timestamp);

            throw new \RuntimeException('Stripe timeout');
        };

        $operation = $this->requestUpgrade();   // the settled outcome is returned, nothing is thrown or reopened

        $this->assertSame('payment_failed', $operation->fresh()->status);
        $this->assertNull($this->subscription->fresh()->pending_change_status);

        $this->paid('in_test_change', 'evt_paid_after', now()->addMinutes(5)->timestamp);
        $this->assertSame(PlanTier::Secure, $this->plan());
        $this->assertSame('confirmed', $operation->fresh()->status);
    }

    // ---- Finding B: an unknown outcome is retried, bounded, and visible ----

    public function test_a_retry_of_an_unknown_outcome_upgrade_calls_stripe_again_with_the_same_key_and_returns_to_awaiting_payment(): void
    {
        $failed = $this->requestUpgradeWithLostResponse();
        $this->assertSame('reconciliation_required', $failed->status);
        $this->assertSame('reconciliation_required', $this->subscription->fresh()->pending_change_status);
        $this->assertCount(0, $this->gateway->priceChanges);

        $retried = $this->requestUpgrade();

        $this->assertSame($failed->id, $retried->id, 'the same operation, not a second one');
        $this->assertSame('awaiting_confirmation', $retried->status);
        $this->assertCount(1, $this->gateway->priceChanges);
        $this->assertSame('subscription-change-'.$failed->uuid, $this->gateway->priceChanges[0]['key']);
        $this->assertSame('awaiting_payment', $this->subscription->fresh()->pending_change_status, 'Stripe has now confirmed the request: the payment is awaited');
        $this->assertSame(PlanTier::Business, $this->plan());

        // a further identical request is idempotent
        $this->assertSame($failed->id, $this->requestUpgrade()->id);
        $this->assertCount(1, $this->gateway->priceChanges);
    }

    public function test_the_replay_is_bounded_and_then_waits_for_an_operator_instead_of_retrying_forever(): void
    {
        $failed = $this->requestUpgradeWithLostResponse();

        $this->gateway->failure = new \RuntimeException('Stripe still down');
        $attempts = 0;
        for ($i = 0; $i < 12; $i++) {
            try {
                $this->requestUpgrade();
            } catch (\RuntimeException $exception) {
                if ($exception->getMessage() === 'Stripe still down') {
                    $attempts++;

                    continue;
                }
                $this->assertStringContainsString('operator', strtolower($exception->getMessage()));
                break;
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('operator', strtolower($exception->getMessage()));
                break;
            }
        }

        $this->assertLessThan(6, $attempts, 'the number of automatic replays is bounded');
        $this->assertSame('reconciliation_required', $failed->fresh()->status);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'billing.subscription_change_operator_required', 'subject_key' => $this->subscription->uuid]);

        $calls = count($this->gateway->priceChanges);
        $this->gateway->failure = null;
        try {
            $this->requestUpgrade();
        } catch (\LogicException) {
        }
        $this->assertCount($calls, $this->gateway->priceChanges, 'no further Stripe call once an operator is required');
    }

    public function test_a_different_change_is_refused_while_an_unknown_outcome_is_unresolved(): void
    {
        $this->requestUpgradeWithLostResponse();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A subscription change is already pending confirmation.');
        $this->management()->changePlan($this->subscription->fresh(), $this->customer, ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly);
    }

    // ---- Finding C: no higher plan before the payment is authentically shown ----

    public function test_a_subscription_event_with_the_new_price_does_not_grant_it_while_the_payment_is_awaited(): void
    {
        $operation = $this->requestUpgrade();
        $this->assertSame('awaiting_payment', $this->subscription->fresh()->pending_change_status);

        $this->subscriptionEvent($this->targetPrice, 'evt_new_price', now()->addSecond()->timestamp);

        $subscription = $this->subscription->fresh();
        $this->assertSame(PlanTier::Business, $subscription->plan, 'the plan is not granted before the paid invoice');
        $this->assertSame($this->currentPrice, $subscription->stripe_price_id);
        $this->assertSame('awaiting_payment', $subscription->pending_change_status);

        $this->paid('in_test_change', 'evt_paid', now()->addSeconds(10)->timestamp);   // the paid invoice then applies it, exactly once
        $this->assertSame(PlanTier::Secure, $this->plan());
        $this->assertNull($this->subscription->fresh()->pending_change_status);
        $this->assertSame('confirmed', $operation->fresh()->status);
    }

    public function test_a_subscription_event_with_the_new_price_does_not_grant_it_while_the_outcome_is_unknown(): void
    {
        $operation = $this->requestUpgradeWithLostResponse();
        $this->assertSame('reconciliation_required', $this->subscription->fresh()->pending_change_status);

        $this->subscriptionEvent($this->targetPrice, 'evt_new_price', now()->addSecond()->timestamp);

        $subscription = $this->subscription->fresh();
        $this->assertSame(PlanTier::Business, $subscription->plan, 'an unknown outcome is not guessed to be a paid one');
        $this->assertSame($this->currentPrice, $subscription->stripe_price_id);
        $this->assertSame('reconciliation_required', $subscription->pending_change_status);

        $this->paid('in_lost_response', 'evt_paid', now()->addSeconds(10)->timestamp);   // the authenticated paid invoice settles it
        $this->assertSame(PlanTier::Secure, $this->plan());
        $this->assertNull($this->subscription->fresh()->pending_change_status);
        $this->assertSame('confirmed', $operation->fresh()->status);
    }

    public function test_a_lost_response_upgrade_whose_payment_succeeded_is_applied_by_reconciliation_of_the_paid_invoice(): void
    {
        $this->requestUpgradeWithLostResponse();
        $this->useRemote($this->remoteSubscription($this->targetPrice, [], $this->invoice('in_lost', 'paid')));

        app(ReconcileStripeSubscriptions::class)->run(10, $this->subscription->id);

        $subscription = $this->subscription->fresh();
        $this->assertSame(PlanTier::Secure, $subscription->plan);
        $this->assertNull($subscription->pending_change_status);
        $this->assertSame('confirmed', SubscriptionOperation::query()->sole()->status);
    }

    // ---- Finding D: races, replays and ordering ----

    public function test_a_payment_failure_that_arrives_while_the_request_is_being_replayed_is_not_overwritten_by_the_response(): void
    {
        $failed = $this->requestUpgradeWithLostResponse();
        $this->gateway->duringPriceChange = function (): void {
            $this->paymentFailed('in_test_change', 'evt_failed_during_replay');
        };

        $this->requestUpgrade();

        $this->assertSame('payment_failed', $failed->fresh()->status, 'the later response must not regress the operation');
        $subscription = $this->subscription->fresh();
        $this->assertNull($subscription->pending_change_status);
        $this->assertSame(PlanTier::Business, $subscription->plan);
        $this->assertNull($subscription->grace_started_at);
    }

    public function test_a_payment_that_arrives_while_the_request_is_being_replayed_is_applied_and_not_regressed(): void
    {
        $failed = $this->requestUpgradeWithLostResponse();
        $this->gateway->duringPriceChange = function (): void {
            $this->paid('in_test_change', 'evt_paid_during_replay');
        };

        $this->requestUpgrade();

        $this->assertSame('confirmed', $failed->fresh()->status);
        $this->assertSame(PlanTier::Secure, $this->plan());
        $this->assertNull($this->subscription->fresh()->pending_change_status);
    }

    public function test_two_requests_for_the_same_upgrade_make_one_stripe_call_and_a_different_one_is_refused(): void
    {
        $first = $this->requestUpgrade();
        $again = $this->requestUpgrade();

        $this->assertSame($first->id, $again->id);
        $this->assertCount(1, $this->gateway->priceChanges);
        $this->assertSame(1, SubscriptionOperation::query()->count());

        $this->expectException(\LogicException::class);
        $this->management()->changePlan($this->subscription->fresh(), $this->customer, ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly);
    }

    public function test_cancelling_while_an_upgrade_is_pending_survives_the_upgrade_failing(): void
    {
        $upgrade = $this->requestUpgrade();
        $cancel = $this->management()->cancelAtPeriodEnd($this->subscription->fresh(), $this->customer);
        $this->assertSame('awaiting_confirmation', $cancel->status);

        // Stripe confirms the cancellation (old price, still pending upgrade) ...
        $this->subscriptionEvent($this->currentPrice, 'evt_cancel_confirmed', now()->addSecond()->timestamp, ['cancel_at_period_end' => true]);
        $subscription = $this->subscription->fresh();
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertSame('awaiting_payment', $subscription->pending_change_status);
        $this->assertSame('confirmed', $cancel->fresh()->status);

        // ... then the upgrade payment fails: the cancellation stands, the plan is unchanged, nothing is reactivated or blocked
        $this->paymentFailed('in_test_change', 'evt_failed_after_cancel', now()->addSeconds(2)->timestamp);

        $subscription = $this->subscription->fresh();
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertNull($subscription->pending_change_status);
        $this->assertSame(PlanTier::Business, $subscription->plan);
        $this->assertSame('payment_failed', $upgrade->fresh()->status);
        $this->assertSame('confirmed', $cancel->fresh()->status);

        // and an upgrade can no longer be started on a subscription that is ending
        $this->expectException(\LogicException::class);
        $this->requestUpgrade();
    }

    public function test_an_older_subscription_event_never_overwrites_a_newer_confirmed_state(): void
    {
        $this->requestUpgrade();
        $this->paid('in_test_change', 'evt_paid', now()->addMinute()->timestamp);
        $this->assertSame(PlanTier::Secure, $this->plan());

        // a delayed event that still shows the old price and was created before the payment
        $this->subscriptionEvent($this->currentPrice, 'evt_delayed_old_price', now()->subMinutes(30)->timestamp);

        $this->assertSame(PlanTier::Secure, $this->plan());
        $this->assertSame($this->targetPrice, $this->subscription->fresh()->stripe_price_id);
    }

    public function test_a_paid_upgrade_reprocessed_under_new_event_ids_changes_nothing_further(): void
    {
        $operation = $this->requestUpgrade();
        $this->paid('in_test_change', 'evt_paid_1', now()->timestamp);
        $confirmedAt = $operation->fresh()->confirmed_at;

        $this->paid('in_test_change', 'evt_paid_2', now()->addSeconds(5)->timestamp);
        $this->paid('in_test_change', 'evt_paid_3', now()->addSeconds(9)->timestamp);

        $this->assertSame(PlanTier::Secure, $this->plan());
        $this->assertEquals($confirmedAt, $operation->fresh()->confirmed_at);
        $this->assertSame(1, BillingEmailOutbox::query()->where('notification', 'upgrade_completed')->count());
        $this->assertSame(1, AdminAuditEvent::query()->where('event', 'billing.subscription_change_confirmed')->count());
    }
}
