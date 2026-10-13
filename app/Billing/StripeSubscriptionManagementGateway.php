<?php

namespace App\Billing;

use App\Models\Subscription;
use RuntimeException;
use Stripe\StripeClient;

final class StripeSubscriptionManagementGateway implements SubscriptionManagementGateway
{
    public function __construct(
        private readonly ?string $secret,
        private readonly bool $allowLiveMode = false,
        private ?StripeClient $client = null,
    ) {}

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

        $client = $this->client();
        $remote = $client->subscriptions->retrieve((string) $subscription->stripe_subscription_id, [
            'expand' => ['items.data.price', 'schedule'],
        ]);
        $items = $remote->items->data ?? [];
        if (count($items) !== 1 || ! isset($items[0]->id)) {
            throw new RuntimeException('Only single-item subscriptions can be changed safely.');
        }

        if (! $immediate) {
            return $this->schedulePriceChange($client, $subscription, $remote, $items[0], $priceId, $operationUuid, $idempotencyKey);
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

    /**
     * A non-immediate price change must be a Stripe schedule phase. Updating
     * the subscription item with proration disabled still changes its price
     * now; it does not defer the change until the current period ends.
     *
     * @return array{request_id: ?string, invoice_id: null, schedule_id: string}
     */
    private function schedulePriceChange(StripeClient $client, Subscription $subscription, object $remote, object $item, string $priceId, string $operationUuid, string $idempotencyKey): array
    {
        $currentPriceId = is_string($item->price ?? null) ? $item->price : ($item->price->id ?? null);
        $remotePeriodEnd = $item->current_period_end ?? $remote->current_period_end ?? null;
        $currentPeriodEnd = is_numeric($remotePeriodEnd) ? (int) $remotePeriodEnd : null;
        $expectedPeriodEnd = $subscription->current_period_end?->timestamp;
        $currentPrice = $this->value($item, 'price');
        if (! is_string($currentPriceId) || ! hash_equals((string) $subscription->stripe_price_id, $currentPriceId)
            || ! $currentPeriodEnd || ! $expectedPeriodEnd || $currentPeriodEnd !== $expectedPeriodEnd) {
            throw new RuntimeException('Stripe subscription state differs from the local schedule request; reconciliation is required.');
        }
        $targetPrice = $client->prices->retrieve($priceId, []);
        $targetRecurring = $targetPrice->recurring ?? null;
        $targetInterval = $targetRecurring->interval ?? null;
        $targetIntervalCount = $targetRecurring->interval_count ?? 1;
        $currentCurrency = $this->value($currentPrice, 'currency');
        if (! in_array($targetInterval, ['week', 'month', 'year'], true) || ! is_numeric($targetIntervalCount) || (int) $targetIntervalCount < 1
            || ! is_string($currentCurrency) || $currentCurrency !== ($targetPrice->currency ?? null)
            || ($targetPrice->active ?? false) !== true) {
            throw new RuntimeException('Stripe target price is inactive or incompatible with the current subscription.');
        }

        $metadata = [
            'caregrid_operation_uuid' => $operationUuid,
            'caregrid_target_price_id' => $priceId,
        ];

        $remoteSchedule = $remote->schedule ?? null;
        if ($remoteSchedule) {
            $scheduleId = is_string($remoteSchedule) ? $remoteSchedule : ($remoteSchedule->id ?? null);
            if (! is_string($scheduleId) || $scheduleId === '') {
                throw new RuntimeException('Stripe returned an invalid subscription schedule.');
            }
            $schedule = is_object($remoteSchedule) ? $remoteSchedule : $client->subscriptionSchedules->retrieve($scheduleId, []);
            $scheduleMetadata = is_object($schedule->metadata ?? null) && method_exists($schedule->metadata, 'toArray')
                ? $schedule->metadata->toArray()
                : [];
            if (($scheduleMetadata['caregrid_operation_uuid'] ?? null) !== $operationUuid) {
                throw new RuntimeException('A different Stripe subscription schedule already exists; reconciliation is required.');
            }
        } else {
            $schedule = $client->subscriptionSchedules->create(
                ['from_subscription' => (string) $subscription->stripe_subscription_id],
                ['idempotency_key' => $idempotencyKey.'-schedule'],
            );
            $scheduleId = $schedule->id ?? null;
            if (! is_string($scheduleId) || $scheduleId === '') {
                throw new RuntimeException('Stripe did not return the created subscription schedule.');
            }
        }

        $currentPhase = $this->currentSchedulePhase($schedule, $currentPeriodEnd);
        $currentItems = $this->scheduleItems($this->collectionItems($this->value($currentPhase, 'items')));
        if (count($currentItems) !== 1 || $currentItems[0]['price'] !== $currentPriceId) {
            throw new RuntimeException('Stripe schedule phase differs from the current local subscription; reconciliation is required.');
        }

        $nextItems = $currentItems;
        $nextItems[0]['price'] = $priceId;
        $currentPhaseMetadata = is_object($currentPhase->metadata ?? null) && method_exists($currentPhase->metadata, 'toArray')
            ? $currentPhase->metadata->toArray()
            : [];
        $nextPhaseMetadata = array_replace($currentPhaseMetadata, $metadata);

        $phases = [
            $this->phaseParameters($currentPhase, [
                'start_date' => (int) $currentPhase->start_date,
                'end_date' => $currentPeriodEnd,
                'items' => $currentItems,
                'proration_behavior' => 'none',
            ]),
            $this->phaseParameters($currentPhase, [
                'start_date' => $currentPeriodEnd,
                'duration' => ['interval' => $targetInterval, 'interval_count' => (int) $targetIntervalCount],
                'items' => $nextItems,
                'metadata' => $nextPhaseMetadata,
                'proration_behavior' => 'none',
            ]),
        ];

        $updated = $client->subscriptionSchedules->update($scheduleId, [
            'end_behavior' => 'release',
            'metadata' => $metadata,
            'phases' => $phases,
            'proration_behavior' => 'none',
        ], ['idempotency_key' => $idempotencyKey.'-configure']);

        return [
            'request_id' => $updated->getLastResponse()?->headers['Request-Id'] ?? null,
            'invoice_id' => null,
            'schedule_id' => $scheduleId,
        ];
    }

    private function currentSchedulePhase(object $schedule, int $periodEnd): object
    {
        foreach ($this->collectionItems($this->value($schedule, 'phases')) as $phase) {
            if ((int) ($this->value($phase, 'end_date') ?? 0) === $periodEnd) {
                return $phase;
            }
        }

        throw new RuntimeException('Stripe schedule has no current phase ending at the verified billing-period boundary.');
    }

    /** @param array<int, object> $items
     * @return list<array<string, mixed>>
     */
    private function scheduleItems(array $items): array
    {
        $allowed = ['quantity', 'tax_rates', 'billing_thresholds', 'discounts', 'metadata'];
        $result = [];
        foreach ($items as $item) {
            $item = is_object($item) ? $item : (object) $item;
            $itemPrice = $this->value($item, 'price');
            $price = is_string($itemPrice) ? $itemPrice : $this->value($itemPrice, 'id');
            if (! is_string($price) || $price === '') {
                throw new RuntimeException('Stripe schedule phase contains an invalid price.');
            }

            $parameters = ['price' => $price];
            foreach ($allowed as $key) {
                $value = $this->value($item, $key);
                if ($value !== null) {
                    $parameters[$key] = is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : $value;
                }
            }
            $result[] = $parameters;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function phaseParameters(object $source, array $required): array
    {
        $allowed = [
            'add_invoice_items', 'application_fee_percent', 'billing_cycle_anchor', 'collection_method',
            'currency', 'default_payment_method', 'default_tax_rates', 'description', 'discounts',
            'invoice_settings', 'on_behalf_of', 'transfer_data', 'trial_end',
        ];
        $parameters = $required;
        foreach ($allowed as $key) {
            $value = $this->value($source, $key);
            if ($value === null) {
                continue;
            }
            $parameters[$key] = is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : $value;
        }

        $metadata = $this->value($source, 'metadata');
        if (! isset($parameters['metadata']) && $metadata !== null) {
            $parameters['metadata'] = is_object($metadata) && method_exists($metadata, 'toArray') ? $metadata->toArray() : (array) $metadata;
        }

        return $parameters;
    }

    /** @return list<object> */
    private function collectionItems(mixed $collection): array
    {
        if (is_object($collection) && method_exists($collection, 'toArray')) {
            $collection = $collection->toArray();
        }
        if (is_array($collection) && isset($collection['data']) && is_array($collection['data'])) {
            $collection = $collection['data'];
        }
        if (! is_array($collection)) {
            return [];
        }

        return array_map(static fn ($item): object => is_object($item) ? $item : (object) $item, array_values($collection));
    }

    private function value(mixed $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return is_object($source) ? ($source->{$key} ?? null) : null;
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient((string) $this->secret);
    }
}
