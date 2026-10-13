<?php

namespace App\Billing;

use App\Checkout\AcceptStripeWebhook;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\StripeWebhookInbox;
use App\Models\Subscription;
use App\Models\User;
use App\Owner\AdminAudit;
use App\SyntheticFixtures\StagingIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Owner-triggered, TEST-mode lifecycle simulation for explicitly tagged M11 email aliases. */
final class StagingBillingLifecycleHarness
{
    public const CONFIRMATION = 'RUN M11 STAGING LIFECYCLE TEST';

    public const SCENARIOS = [
        'payment_failure',
        'duplicate_failure',
        'recovery',
        'grace_expiry',
        'webhook_replay',
        'out_of_order',
        'reconcile',
    ];

    /** @var array<string, scalar|null>|null State captured while the request-scoped clock is advanced. */
    private ?array $simulatedState = null;

    public function __construct(
        private readonly AcceptStripeWebhook $webhooks,
        private readonly ReconcileStripeSubscriptions $reconciler,
    ) {}

    public function available(): bool
    {
        return app(StagingIdentity::class)->allowsCurrentDeployment()
            && config('services.stripe.mode') === 'test'
            && str_starts_with((string) config('services.stripe.secret'), 'sk_test_')
            && is_string(config('services.stripe.webhook_secret'))
            && config('services.stripe.webhook_secret') !== '';
    }

    /** @return array<string, scalar|null> */
    public function run(User $actor, string $email, string $scenario, string $confirmation): array
    {
        $runId = (string) Str::uuid();
        $before = null;
        $eventIds = [];
        $outcome = 'blocked';
        $reason = 'validation_failed';

        try {
            abort_unless($this->available(), 404);
            if ($confirmation !== self::CONFIRMATION) {
                throw ValidationException::withMessages(['confirmation_phrase' => 'Enter the displayed M11 confirmation phrase.']);
            }
            if (! in_array($scenario, self::SCENARIOS, true)) {
                throw ValidationException::withMessages(['scenario' => 'Choose a supported M11 validation scenario.']);
            }

            $customer = $this->syntheticCustomer($email);
            $subscription = $this->syntheticSubscription($customer);
            $before = $this->state($subscription);
            $reason = $scenario;
            $this->simulatedState = null;

            $result = match ($scenario) {
                'payment_failure' => $this->paymentFailure($subscription, $eventIds),
                'duplicate_failure' => $this->replayFailure($subscription, $eventIds),
                'recovery' => $this->recover($subscription, $eventIds),
                'grace_expiry' => $this->expireGrace($subscription, $eventIds),
                'webhook_replay' => $this->replaySubscriptionEvent($subscription, $eventIds),
                'out_of_order' => $this->outOfOrder($subscription, $eventIds),
                'reconcile' => $this->reconcile($subscription),
            };

            $subscription->refresh();
            $after = $this->state($subscription);
            $outcome = 'completed';
            $reason = $scenario;
            $this->audit($actor, $runId, $scenario, $outcome, $reason, $subscription, $before, $after, $eventIds, $this->simulatedState);

            return ['run_id' => $runId, 'scenario' => $scenario, 'result' => $result, 'state' => $after];
        } catch (\Throwable $exception) {
            $reason = $exception instanceof ValidationException ? 'validation_failed' : class_basename($exception);
            $this->audit($actor, $runId, $scenario, $outcome, $reason, null, $before, null, $eventIds);
            throw $exception;
        }
    }

    private function syntheticCustomer(string $email): Customer
    {
        $email = Customer::normalizeEmail($email);
        abort_unless(preg_match('/\Acontact\+m11-[a-z0-9-]+@zsoltacsai\.com\z/D', $email) === 1, 404);

        $customer = Customer::query()->where('email', $email)->firstOrFail();
        abort_unless($customer->stripe_customer_id !== null, 404);

        return $customer;
    }

    private function syntheticSubscription(Customer $customer): Subscription
    {
        $subscriptions = $customer->subscriptions()
            ->where('billing_source', 'stripe')
            ->whereNotNull('stripe_subscription_id')
            ->whereNotNull('stripe_customer_id')
            ->whereNotNull('checkout_intent_id')
            ->with('checkoutIntent')
            ->get();

        abort_unless($subscriptions->count() === 1, 409);
        $subscription = $subscriptions->sole();
        abort_unless($subscription->checkoutIntent?->status?->value === 'completed'
            && hash_equals((string) $customer->stripe_customer_id, (string) $subscription->stripe_customer_id), 409);

        return $subscription;
    }

    /** @param  list<string>  $eventIds */
    private function paymentFailure(Subscription $subscription, array &$eventIds): string
    {
        abort_unless($subscription->status->grantsAccess() && $subscription->grace_started_at === null, 409);
        $eventIds[] = $this->sendEvent('customer.subscription.updated', $this->subscriptionObject($subscription, 'past_due'), $this->nextEventTime($subscription));
        $eventIds[] = $this->sendEvent('invoice.payment_failed', $this->invoiceObject($subscription, 'open', 0), now()->timestamp);

        return 'signed_test_mode_payment_failure_processed';
    }

    /** @param  list<string>  $eventIds */
    private function replayFailure(Subscription $subscription, array &$eventIds): string
    {
        abort_unless($subscription->grace_started_at !== null, 409);
        $invoice = BillingInvoice::query()->where('subscription_id', $subscription->id)->where('invoice_status', 'open')->latest('id')->firstOrFail();
        $receipt = StripeWebhookInbox::query()->where('type', 'invoice.payment_failed')
            ->where('object_id', $invoice->stripe_invoice_id)->where('status', StripeWebhookInbox::STATUS_PROCESSED)->firstOrFail();
        $eventIds[] = $receipt->stripe_event_id;
        $this->replayReceipt($receipt);

        return 'same_signed_event_replayed_idempotently';
    }

    /** @param  list<string>  $eventIds */
    private function recover(Subscription $subscription, array &$eventIds): string
    {
        abort_unless($subscription->status === SubscriptionStatus::PastDue && $subscription->grace_started_at !== null, 409);
        $eventIds[] = $this->sendEvent('invoice.paid', $this->invoiceObject($subscription, 'paid', (int) $subscription->amount), $this->nextEventTime($subscription));
        $subscription->refresh();
        $eventIds[] = $this->sendEvent('customer.subscription.updated', $this->subscriptionObject($subscription, 'active'), $this->nextEventTime($subscription));

        return 'signed_test_mode_payment_recovery_processed';
    }

    /** @param  list<string>  $eventIds */
    private function expireGrace(Subscription $subscription, array &$eventIds): string
    {
        abort_unless($subscription->status->grantsAccess() && $subscription->grace_started_at === null, 409);
        $eventIds[] = $this->sendEvent('customer.subscription.updated', $this->subscriptionObject($subscription, 'past_due'), $this->nextEventTime($subscription));
        $eventIds[] = $this->sendEvent('invoice.payment_failed', $this->invoiceObject($subscription, 'open', 0), now()->timestamp);
        $subscription->refresh();
        $expiry = $subscription->grace_ends_at?->copy()->addSecond();
        abort_unless($expiry !== null, 500);

        try {
            Carbon::setTestNow($expiry);
            $exit = Artisan::call('billing:subscriptions:expire-grace', [
                '--limit' => 1,
                '--subscription' => $subscription->uuid,
            ]);
            abort_if($exit !== 0, 500);
            $subscription->refresh();
            abort_unless($subscription->grace_expired_at !== null, 500);
            $this->simulatedState = $this->state($subscription);
        } finally {
            Carbon::setTestNow();
        }

        // Restore the synthetic account to the provider's real TEST-mode
        // active state after recording the simulated restricted snapshot.
        $this->recover($subscription->refresh(), $eventIds);

        return 'grace_expiry_verified_under_request_scoped_clock_then_synthetic_account_recovered';
    }

    /** @param  list<string>  $eventIds */
    private function replaySubscriptionEvent(Subscription $subscription, array &$eventIds): string
    {
        $receipt = StripeWebhookInbox::query()
            ->where('object_id', $subscription->stripe_subscription_id)
            ->whereIn('type', ['customer.subscription.created', 'customer.subscription.updated'])
            ->where('livemode', false)->where('status', StripeWebhookInbox::STATUS_PROCESSED)
            ->latest('stripe_created_at')->firstOrFail();
        $eventIds[] = $receipt->stripe_event_id;
        $this->replayReceipt($receipt);

        return 'previous_test_mode_subscription_event_replayed_idempotently';
    }

    /** @param  list<string>  $eventIds */
    private function outOfOrder(Subscription $subscription, array &$eventIds): string
    {
        $created = max(1, ($subscription->stripe_synced_at?->timestamp ?? now()->timestamp) - 120);
        $eventIds[] = $this->sendEvent('customer.subscription.updated', $this->subscriptionObject($subscription, 'active'), $created);

        return 'older_signed_event_processed_without_regressing_provider_state';
    }

    private function reconcile(Subscription $subscription): string
    {
        $result = $this->reconciler->run(1, $subscription->id);

        return 'targeted_provider_reconciliation_checked_'.$result['checked'].'_changed_'.$result['changed'].'_failed_'.$result['failed'];
    }

    private function replayReceipt(StripeWebhookInbox $receipt): void
    {
        $this->acceptSignedPayload($receipt->payload);
    }

    private function sendEvent(string $type, array $object, int $created): string
    {
        $eventId = 'evt_m11_'.Str::lower(Str::random(24));
        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'livemode' => false,
            'type' => $type,
            'created' => $created,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->acceptSignedPayload($payload);

        return $eventId;
    }

    private function acceptSignedPayload(string $payload): void
    {
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, (string) config('services.stripe.webhook_secret'));
        abort_unless($this->webhooks->accept($payload, 't='.$timestamp.',v1='.$signature) === 204, 502);
    }

    private function subscriptionObject(Subscription $subscription, string $status): array
    {
        return [
            'id' => $subscription->stripe_subscription_id,
            'object' => 'subscription',
            'customer' => $subscription->stripe_customer_id,
            'status' => $status,
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'metadata' => [],
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_m11_validation',
                'object' => 'subscription_item',
                'current_period_start' => $subscription->current_period_start?->timestamp ?? now()->timestamp,
                'current_period_end' => $subscription->current_period_end?->timestamp ?? now()->addMonth()->timestamp,
                'price' => [
                    'id' => $subscription->stripe_price_id,
                    'object' => 'price',
                    'unit_amount' => $subscription->amount,
                    'currency' => strtolower((string) $subscription->currency),
                    'recurring' => ['interval' => $subscription->interval->value],
                ],
            ]]],
        ];
    }

    private function invoiceObject(Subscription $subscription, string $status, int $amountPaid): array
    {
        $id = 'in_m11_'.Str::lower(Str::random(24));

        return [
            'id' => $id,
            'object' => 'invoice',
            'customer' => $subscription->stripe_customer_id,
            'subscription' => $subscription->stripe_subscription_id,
            'currency' => strtolower((string) $subscription->currency),
            'status' => $status,
            'attempted' => $status === 'open',
            'attempt_count' => $status === 'open' ? 1 : 0,
            'amount_due' => max(0, (int) $subscription->amount - $amountPaid),
            'amount_paid' => $amountPaid,
            'subtotal' => (int) $subscription->amount,
            'total' => (int) $subscription->amount,
            'billing_reason' => 'subscription_cycle',
            'created' => now()->timestamp,
            'status_transitions' => ['paid_at' => $status === 'paid' ? now()->timestamp : null],
            'parent' => ['subscription_details' => ['subscription' => $subscription->stripe_subscription_id, 'metadata' => []]],
        ];
    }

    private function nextEventTime(Subscription $subscription): int
    {
        return max(now()->timestamp, ($subscription->stripe_synced_at?->timestamp ?? 0) + 1);
    }

    /** @return array<string, scalar|null> */
    private function state(Subscription $subscription): array
    {
        $subscription->loadMissing('customer');

        return [
            'subscription_status' => $subscription->status->value,
            'grace_started_at' => $subscription->grace_started_at?->toIso8601String(),
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
            'grace_expired_at' => $subscription->grace_expired_at?->toIso8601String(),
            'commercial_access' => $subscription->customer?->hasActiveSubscription() ?? false,
            'restore_safety_entitlement' => app(SubscriptionEntitlements::class)->allows($subscription, 'restore'),
        ];
    }

    /** @param  list<string>  $eventIds */
    private function audit(User $actor, string $runId, string $scenario, string $outcome, string $reason, ?Subscription $subscription, ?array $before, ?array $after, array $eventIds, ?array $simulated = null): void
    {
        AdminAudit::record($actor, 'billing.m11_validation_run', 'm11_lifecycle_run', $runId, [
            'scenario' => in_array($scenario, self::SCENARIOS, true) ? $scenario : 'invalid',
            'outcome' => $outcome,
            'reason' => $reason,
            'subscription_uuid' => $subscription?->uuid,
            'before_status' => $before['subscription_status'] ?? null,
            'after_status' => $after['subscription_status'] ?? null,
            'before_grace_ends_at' => $before['grace_ends_at'] ?? null,
            'after_grace_ends_at' => $after['grace_ends_at'] ?? null,
            'simulated_status' => $simulated['subscription_status'] ?? null,
            'simulated_commercial_access' => $simulated['commercial_access'] ?? null,
            'simulated_restore_entitlement' => $simulated['restore_safety_entitlement'] ?? null,
            'event_ids' => $eventIds,
        ]);
    }
}
