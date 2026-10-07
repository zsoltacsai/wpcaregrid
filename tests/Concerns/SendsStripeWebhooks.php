<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Sends correctly signed Stripe webhook requests. */
trait SendsStripeWebhooks
{
    protected string $webhookSecret = 'whsec_test_secret';

    protected function useWebhookSecret(): void
    {
        config(['services.stripe.webhook_secret' => $this->webhookSecret]);
    }

    /** @param  array<string, mixed>  $object */
    protected function stripeEvent(string $type, array $object, ?string $eventId = null, ?int $created = null, ?string $secret = null): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId ?? 'evt_'.Str::random(20),
            'object' => 'event',
            'type' => $type,
            'created' => $created ?? time(),
            'data' => ['object' => $object],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? $this->webhookSecret);

        return $this->call('POST', route('stripe.webhook'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function stripeSubscription(string $id, string $status, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'object' => 'subscription',
            'customer' => 'cus_test_1',
            'status' => $status,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
            'metadata' => [],
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_1',
                'object' => 'subscription_item',
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'price' => ['id' => 'price_wordpress_business_monthly_HUF', 'object' => 'price', 'unit_amount' => 1899000, 'currency' => 'huf'],
            ]]],
        ], $overrides);
    }
}
