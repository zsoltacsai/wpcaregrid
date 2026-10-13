<?php

namespace Tests\Feature;

use App\Billing\QueueBillingEmail;
use App\Jobs\ProcessBillingEmailOutboxJob;
use App\Mail\BillingLifecycleNotice;
use App\Models\BillingEmailOutbox;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

final class BillingEmailOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_lifecycle_event_has_one_outbox_record_and_one_delivery(): void
    {
        Mail::fake();
        Queue::fake();
        $customer = Customer::factory()->withUser()->create();
        $customer->user->forceFill(['locale' => 'hu'])->save();
        $subscription = Subscription::factory()->for($customer)->create();

        $first = app(QueueBillingEmail::class)->enqueue('subscription:test:reactivated', 'subscription_reactivated', $customer, $subscription);
        $second = app(QueueBillingEmail::class)->enqueue('subscription:test:reactivated', 'subscription_reactivated', $customer, $subscription);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('hu', $first->locale);
        $this->assertDatabaseCount('billing_email_outbox', 1);
        Queue::assertPushed(ProcessBillingEmailOutboxJob::class, 1);

        $job = new ProcessBillingEmailOutboxJob($first->id);
        $job->handle();
        $job->handle();

        $this->assertSame('sent', $first->fresh()->status);
        Mail::assertSent(BillingLifecycleNotice::class, 1);
    }

    public function test_scheduled_change_notice_names_the_target_billing_interval(): void
    {
        Mail::fake();
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create();
        $row = app(QueueBillingEmail::class)->enqueue(
            'subscription:test:change-scheduled',
            'downgrade_scheduled',
            $customer,
            $subscription,
            payload: ['plan_name' => 'Basic', 'interval' => 'monthly'],
        );

        (new ProcessBillingEmailOutboxJob($row->id))->handle();

        Mail::assertSent(BillingLifecycleNotice::class, function (BillingLifecycleNotice $mail): bool {
            return str_contains($mail->render(), 'Basic (monthly)');
        });
    }

    public function test_expired_delivery_lease_becomes_unknown_and_is_not_redispatched(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create();
        $row = BillingEmailOutbox::create([
            'event_key' => 'subscription:expired:grace',
            'customer_id' => $customer->id,
            'notification' => 'grace_started',
            'locale' => 'en',
            'status' => 'processing',
            'attempts' => 1,
            'lease_expires_at' => now()->subMinute(),
            'updated_at' => now()->subMinutes(20),
        ]);

        $this->artisan('billing:emails:drain')->assertSuccessful();

        $this->assertSame('delivery_unknown', $row->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_connection_failure_before_smtp_session_is_retried_with_bounded_backoff_without_changing_billing(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create(['status' => 'active']);
        Queue::fake();
        $row = app(QueueBillingEmail::class)->enqueue('subscription:transient:notice', 'grace_started', $customer, $subscription);
        Mail::shouldReceive('to')->once()->with($customer->email)->andThrow(new TransportException('Connection refused'));

        (new ProcessBillingEmailOutboxJob($row->id))->handle();

        $this->assertSame('pending', $row->fresh()->status);
        $this->assertSame(1, $row->fresh()->attempts);
        $this->assertSame('transient_connectivity', $row->fresh()->failure_category);
        $this->assertTrue($row->fresh()->next_attempt_at->isFuture());
        $this->assertSame('active', $subscription->fresh()->status->value);
    }

    public function test_smtp_authentication_failure_is_not_retried(): void
    {
        $customer = Customer::factory()->withUser()->create();
        Queue::fake();
        $row = app(QueueBillingEmail::class)->enqueue('subscription:auth:notice', 'grace_started', $customer);
        Mail::shouldReceive('to')->once()->with($customer->email)->andThrow(new TransportException('Could not authenticate.'));

        (new ProcessBillingEmailOutboxJob($row->id))->handle();

        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame(1, $row->fresh()->attempts);
        $this->assertSame('provider_authentication', $row->fresh()->failure_category);
        $this->assertNull($row->fresh()->next_attempt_at);
    }

    public function test_transient_smtp_retries_stop_after_three_connection_attempts(): void
    {
        $customer = Customer::factory()->create();
        Queue::fake();
        $row = BillingEmailOutbox::create([
            'event_key' => 'subscription:transient:exhausted',
            'customer_id' => $customer->id,
            'notification' => 'grace_started',
            'locale' => 'en',
            'status' => 'pending',
            'attempts' => 2,
        ]);
        Mail::shouldReceive('to')->once()->with($customer->email)->andThrow(new TransportException('Connection timed out'));

        (new ProcessBillingEmailOutboxJob($row->id))->handle();

        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame(3, $row->fresh()->attempts);
        $this->assertSame('connectivity_retry_exhausted', $row->fresh()->failure_category);
    }
}
