<?php

namespace App\Billing;

use App\Models\Subscription;
use RuntimeException;
use Stripe\StripeClient;

final class StripeSubscriptionManagementGateway implements SubscriptionManagementGateway
{
    public function __construct(private readonly ?string $secret, private readonly bool $allowLiveMode = false) {}

    public function setCancelAtPeriodEnd(Subscription $subscription, bool $cancel, string $idempotencyKey): array
    {
        if (! $this->secret) {
            throw new RuntimeException('Stripe is not configured.');
        }
        if (str_starts_with($this->secret, 'sk_live_') && ! $this->allowLiveMode) {
            throw new RuntimeException('Stripe live mode is disabled.');
        }
        if (! str_starts_with($this->secret, 'sk_test_') && ! ($this->allowLiveMode && str_starts_with($this->secret, 'sk_live_'))) {
            throw new RuntimeException('Stripe key mode is not recognised.');
        }

        $updated = (new StripeClient($this->secret))->subscriptions->update(
            (string) $subscription->stripe_subscription_id,
            ['cancel_at_period_end' => $cancel],
            ['idempotency_key' => $idempotencyKey],
        );

        return ['request_id' => $updated->getLastResponse()?->headers['Request-Id'] ?? null];
    }

    public function changePrice(Subscription $subscription, string $priceId, bool $immediate, string $operationUuid, string $idempotencyKey): array
    {
        if (! $this->secret) {
            throw new RuntimeException('Stripe is not configured.');
        }
        if (str_starts_with($this->secret, 'sk_live_') && ! $this->allowLiveMode) {
            throw new RuntimeException('Stripe live mode is disabled.');
        }
        if (! str_starts_with($this->secret, 'sk_test_') && ! ($this->allowLiveMode && str_starts_with($this->secret, 'sk_live_'))) {
            throw new RuntimeException('Stripe key mode is not recognised.');
        }

        $client = new StripeClient($this->secret);
        $remote = $client->subscriptions->retrieve((string) $subscription->stripe_subscription_id, []);
        $items = $remote->items->data ?? [];
        if (count($items) !== 1 || ! isset($items[0]->id)) {
            throw new RuntimeException('Only single-item subscriptions can be changed safely.');
        }

        $metadata = is_object($remote->metadata ?? null) ? $remote->metadata->toArray() : [];
        $metadata['caregrid_operation_uuid'] = $operationUuid;
        $metadata['caregrid_target_price_id'] = $priceId;
        $updated = $client->subscriptions->update((string) $subscription->stripe_subscription_id, [
            'items' => [[
                'id' => $items[0]->id,
                'price' => $priceId,
                'quantity' => (int) ($items[0]->quantity ?? 1),
            ]],
            'metadata' => $metadata,
            'billing_cycle_anchor' => 'unchanged',
            'proration_behavior' => $immediate ? 'always_invoice' : 'none',
            'payment_behavior' => $immediate ? 'pending_if_incomplete' : 'error_if_incomplete',
        ], ['idempotency_key' => $idempotencyKey]);

        $invoice = $updated->latest_invoice ?? null;
        $invoiceId = is_string($invoice) ? $invoice : (is_object($invoice) ? ($invoice->id ?? null) : null);

        return [
            'request_id' => $updated->getLastResponse()?->headers['Request-Id'] ?? null,
            'invoice_id' => is_string($invoiceId) ? $invoiceId : null,
        ];
    }
}
