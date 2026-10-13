<?php

namespace Tests\Feature;

use App\Jobs\ProcessStripeWebhookInboxJob;
use App\Models\StripeWebhookInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class StripeWebhookInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_drain_requeues_old_unprocessed_receipt_after_a_dispatch_gap(): void
    {
        Bus::fake();
        $receipt = StripeWebhookInbox::create([
            'stripe_event_id' => 'evt_dispatch_gap',
            'type' => 'invoice.paid',
            'object_id' => 'in_123',
            'livemode' => false,
            'payload' => json_encode(['id' => 'evt_dispatch_gap', 'object' => 'event', 'type' => 'invoice.paid', 'livemode' => false, 'data' => ['object' => ['id' => 'in_123']]], JSON_THROW_ON_ERROR),
            'status' => StripeWebhookInbox::STATUS_RECEIVED,
            'attempts' => 0,
        ]);
        $receipt->forceFill(['created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3)])->save();

        $this->artisan('stripe:webhooks:drain')->assertExitCode(0);

        Bus::assertDispatched(ProcessStripeWebhookInboxJob::class, fn (ProcessStripeWebhookInboxJob $job) => $job->receiptId === $receipt->id);
    }

    public function test_drain_does_not_requeue_a_receipt_with_a_live_processing_lease(): void
    {
        Bus::fake();
        $receipt = StripeWebhookInbox::create([
            'stripe_event_id' => 'evt_live_lease',
            'type' => 'invoice.paid',
            'object_id' => 'in_456',
            'livemode' => false,
            'payload' => '{}',
            'status' => StripeWebhookInbox::STATUS_PROCESSING,
            'attempts' => 1,
        ]);
        $receipt->forceFill(['lease_expires_at' => now()->addMinutes(2), 'updated_at' => now()->subMinutes(3)])->save();

        $this->artisan('stripe:webhooks:drain')->assertExitCode(0);

        Bus::assertNothingDispatched();
    }
}
