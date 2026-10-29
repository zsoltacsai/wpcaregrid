<?php

namespace Tests\Feature\Account;

use App\Billing\SubscriptionManagementGateway;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\FakeSubscriptionManagementGateway;
use Tests\TestCase;

class SubscriptionCancellationStatusTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private FakeSubscriptionManagementGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->withUser()->create();
        $this->customer->user->forceFill(['locale' => 'en'])->save();
        $this->gateway = new FakeSubscriptionManagementGateway;
        $this->app->instance(SubscriptionManagementGateway::class, $this->gateway);
    }

    public function test_pending_cancellation_states_persist_on_refresh_and_suppress_duplicate_actions(): void
    {
        $subscriptions = collect(['requested', 'processing', 'awaiting_confirmation'])
            ->map(fn (string $status): Subscription => $this->subscriptionWithOperation($status));
        $superseded = Subscription::factory()->for($this->customer)->create(['billing_source' => 'stripe']);
        $this->operation($superseded, 'confirmed', $this->customer, ['cancel_at_period_end' => true], now());
        $this->operation($superseded, 'awaiting_confirmation', $this->customer, ['cancel_at_period_end' => false], null, 'reactivate');
        $subscriptions->push($superseded);

        $before = SubscriptionOperation::query()->count();
        $response = $this->actingAs($this->customer->user)->get(route('account.subscription'))->assertOk();
        $response->assertSee(__('account.subscription.cancellation_status.pending'));
        $response->assertSee(__('account.subscription.cancellation_status.reactivation_pending'));
        $response->assertSee('role="status" aria-live="polite" aria-atomic="true"', false);
        foreach ($subscriptions as $subscription) {
            $response->assertDontSee('action="'.route('account.subscriptions.cancel', $subscription).'"', false);
            $response->assertDontSee('action="'.route('account.subscriptions.reactivate', $subscription).'"', false);
        }

        // A fresh GET still reads the persisted status; it does not enqueue or call the provider.
        $this->get(route('account.subscription'))->assertOk()->assertSee(__('account.subscription.cancellation_status.pending'));
        $this->assertSame($before, SubscriptionOperation::query()->count());
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_confirmed_status_requires_a_current_matching_provider_mirror(): void
    {
        $subscription = $this->subscriptionWithOperation('confirmed', cancelAtPeriodEnd: true, confirmedAt: now());

        $response = $this->actingAs($this->customer->user)
            ->get(route('account.subscriptions.show', $subscription))
            ->assertOk()
            ->assertSee(__('account.subscription.cancellation_status.confirmed'));

        $response->assertSee('action="'.route('account.subscriptions.reactivate', $subscription).'"', false);
        $response->assertDontSee('action="'.route('account.subscriptions.cancel', $subscription).'"', false);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_reconciliation_required_is_clear_and_does_not_offer_a_retry_action(): void
    {
        $subscription = $this->subscriptionWithOperation('reconciliation_required');

        $response = $this->actingAs($this->customer->user)
            ->get(route('account.subscriptions.show', $subscription))
            ->assertOk()
            ->assertSee(__('account.subscription.cancellation_status.reconciliation_required'));

        $response->assertDontSee('action="'.route('account.subscriptions.cancel', $subscription).'"', false);
        $response->assertDontSee('action="'.route('account.subscriptions.reactivate', $subscription).'"', false);
    }

    public function test_no_operation_keeps_existing_cancel_action_without_claiming_a_request(): void
    {
        $subscription = Subscription::factory()->for($this->customer)->create(['billing_source' => 'stripe']);

        $response = $this->actingAs($this->customer->user)
            ->get(route('account.subscriptions.show', $subscription))
            ->assertOk()
            ->assertSee('action="'.route('account.subscriptions.cancel', $subscription).'"', false)
            ->assertDontSee(__('account.subscription.cancellation_status.pending'));

        $this->assertDatabaseCount('subscription_operations', 0);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_stale_or_conflicting_confirmation_is_never_presented_as_current(): void
    {
        $stale = $this->subscriptionWithOperation('confirmed', confirmedAt: now());
        $conflict = $this->subscriptionWithOperation(
            'confirmed',
            cancelAtPeriodEnd: true,
            confirmedAt: now(),
            snapshot: ['cancel_at_period_end' => false],
        );

        $response = $this->actingAs($this->customer->user)->get(route('account.subscription'))->assertOk();
        $response->assertDontSee(__('account.subscription.cancellation_status.confirmed'));
        $response->assertSee(__('account.subscription.cancellation_status.reconciliation_required'));
        $response->assertDontSee('action="'.route('account.subscriptions.cancel', $stale).'"', false);
        $response->assertDontSee('action="'.route('account.subscriptions.reactivate', $stale).'"', false);
        $response->assertDontSee('action="'.route('account.subscriptions.cancel', $conflict).'"', false);
        $response->assertDontSee('action="'.route('account.subscriptions.reactivate', $conflict).'"', false);
    }

    public function test_pending_reactivation_supersedes_confirmed_cancellation_and_suppresses_both_actions(): void
    {
        $subscription = Subscription::factory()->for($this->customer)->create([
            'billing_source' => 'stripe',
            'cancel_at_period_end' => true,
        ]);
        $this->operation($subscription, 'confirmed', $this->customer, ['cancel_at_period_end' => true], now());
        $this->operation($subscription, 'awaiting_confirmation', $this->customer, ['cancel_at_period_end' => false], null, 'reactivate');

        $response = $this->actingAs($this->customer->user)->get(route('account.subscriptions.show', $subscription))
            ->assertOk()
            ->assertSee(__('account.subscription.cancellation_status.reactivation_pending'))
            ->assertDontSee(__('account.subscription.cancellation_status.confirmed'));

        $this->assertNoCancellationLifecycleActions($response->getContent(), $subscription);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_reconciliation_required_reactivation_suppresses_both_actions(): void
    {
        $subscription = Subscription::factory()->for($this->customer)->create([
            'billing_source' => 'stripe',
            'cancel_at_period_end' => true,
        ]);
        $this->operation($subscription, 'reconciliation_required', $this->customer, ['cancel_at_period_end' => false], null, 'reactivate');

        $response = $this->actingAs($this->customer->user)->get(route('account.subscription'))->assertOk()
            ->assertSee(__('account.subscription.cancellation_status.reconciliation_required'));

        $this->assertNoCancellationLifecycleActions($response->getContent(), $subscription);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_confirmed_reactivation_with_stale_provider_mirror_requires_reconciliation(): void
    {
        $subscription = Subscription::factory()->for($this->customer)->create([
            'billing_source' => 'stripe',
            'cancel_at_period_end' => true,
        ]);
        $this->operation($subscription, 'confirmed', $this->customer, ['cancel_at_period_end' => false], now(), 'reactivate');

        $response = $this->actingAs($this->customer->user)->get(route('account.subscriptions.show', $subscription))->assertOk()
            ->assertSee(__('account.subscription.cancellation_status.reconciliation_required'))
            ->assertDontSee(__('account.subscription.cancellation_status.reactivation_confirmed'));

        $this->assertNoCancellationLifecycleActions($response->getContent(), $subscription);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_successful_reactivation_requires_a_matching_mirror_and_allows_a_new_cancellation(): void
    {
        $subscription = Subscription::factory()->for($this->customer)->create([
            'billing_source' => 'stripe',
            'cancel_at_period_end' => true,
        ]);
        $this->operation($subscription, 'confirmed', $this->customer, ['cancel_at_period_end' => true], now());
        $subscription->forceFill(['cancel_at_period_end' => false])->save();
        $this->operation($subscription, 'confirmed', $this->customer, ['cancel_at_period_end' => false], now(), 'reactivate');

        $reactivated = $this->actingAs($this->customer->user)->get(route('account.subscriptions.show', $subscription))->assertOk()
            ->assertSee(__('account.subscription.cancellation_status.reactivation_confirmed'))
            ->assertDontSee(__('account.subscription.cancellation_status.confirmed'));
        $reactivated->assertSee('action="'.route('account.subscriptions.cancel', $subscription).'"', false);
        $reactivated->assertDontSee('action="'.route('account.subscriptions.reactivate', $subscription).'"', false);

        $this->operation($subscription, 'awaiting_confirmation', $this->customer, ['cancel_at_period_end' => true], null);
        $canceling = $this->get(route('account.subscriptions.show', $subscription))->assertOk()
            ->assertSee(__('account.subscription.cancellation_status.pending'))
            ->assertDontSee(__('account.subscription.cancellation_status.reactivation_confirmed'));
        $this->assertNoCancellationLifecycleActions($canceling->getContent(), $subscription);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_status_lookup_is_scoped_to_authenticated_customer_and_exact_subscription(): void
    {
        $subscription = Subscription::factory()->for($this->customer)->create(['billing_source' => 'stripe']);
        $otherCustomer = Customer::factory()->withUser()->create();
        $foreignSubscription = Subscription::factory()->for($otherCustomer)->create(['billing_source' => 'stripe']);

        // Even a mismatched operation row cannot label this customer's subscription.
        $this->operation($subscription, 'reconciliation_required', $otherCustomer);
        $this->operation($foreignSubscription, 'reconciliation_required', $otherCustomer);

        $response = $this->actingAs($this->customer->user)->get(route('account.subscription'))->assertOk();
        $response->assertDontSee(__('account.subscription.cancellation_status.reconciliation_required'));
        $response->assertSee('action="'.route('account.subscriptions.cancel', $subscription).'"', false);

        $this->get(route('account.subscriptions.show', $foreignSubscription))->assertForbidden();
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_pending_copy_uses_the_customer_saved_hungarian_and_english_locale(): void
    {
        $subscription = $this->subscriptionWithOperation('awaiting_confirmation');
        $user = $this->customer->user;

        $user->forceFill(['locale' => 'hu'])->save();
        $this->actingAs($user)->get(route('account.subscriptions.show', $subscription))
            ->assertOk()->assertSee(__('account.subscription.cancellation_status.pending', [], 'hu'));

        $user->forceFill(['locale' => 'en'])->save();
        $this->actingAs($user)->get(route('account.subscriptions.show', $subscription))
            ->assertOk()->assertSee(__('account.subscription.cancellation_status.pending', [], 'en'));
        $this->assertSame([], $this->gateway->calls);
    }

    private function subscriptionWithOperation(
        string $status,
        bool $cancelAtPeriodEnd = false,
        mixed $confirmedAt = null,
        ?array $snapshot = null,
    ): Subscription {
        $subscription = Subscription::factory()->for($this->customer)->create([
            'billing_source' => 'stripe',
            'cancel_at_period_end' => $cancelAtPeriodEnd,
        ]);

        $this->operation($subscription, $status, $this->customer, $snapshot ?? ['cancel_at_period_end' => true], $confirmedAt);

        return $subscription;
    }

    private function operation(
        Subscription $subscription,
        string $status,
        Customer $customer,
        ?array $snapshot = null,
        mixed $confirmedAt = null,
        string $operationName = 'cancel_at_period_end',
    ): SubscriptionOperation {
        return SubscriptionOperation::query()->create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'operation' => $operationName,
            'operation_key' => 'cancel-status-test-'.Str::uuid(),
            'status' => $status,
            'request_snapshot' => $snapshot ?? ['cancel_at_period_end' => $operationName === 'cancel_at_period_end'],
            'confirmed_at' => $confirmedAt,
        ]);
    }

    private function assertNoCancellationLifecycleActions(string $html, Subscription $subscription): void
    {
        $this->assertStringNotContainsString('action="'.route('account.subscriptions.cancel', $subscription).'"', $html);
        $this->assertStringNotContainsString('action="'.route('account.subscriptions.reactivate', $subscription).'"', $html);
    }
}
