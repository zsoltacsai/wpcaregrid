<?php

namespace Tests\Fakes;

use App\Billing\SubscriptionManagementGateway;
use App\Models\Subscription;
use Closure;
use Throwable;

class FakeSubscriptionManagementGateway implements SubscriptionManagementGateway
{
    /** @var list<array{subscription: string, cancel: bool, key: string}> */
    public array $calls = [];

    /** @var list<array{subscription: string, price_id: string, immediate: bool, operation_uuid: string, key: string}> */
    public array $priceChanges = [];

    public ?Throwable $failure = null;

    public ?string $scheduleId = 'sub_sched_test';

    /** @var Closure():void|null */
    public ?Closure $duringPriceChange = null;

    public function setCancelAtPeriodEnd(Subscription $subscription, bool $cancel, string $idempotencyKey): array
    {
        if ($this->failure) {
            throw $this->failure;
        }

        $this->calls[] = ['subscription' => $subscription->stripe_subscription_id, 'cancel' => $cancel, 'key' => $idempotencyKey];

        return ['request_id' => 'req_test_123'];
    }

    public function changePrice(Subscription $subscription, string $priceId, bool $immediate, string $operationUuid, string $idempotencyKey): array
    {
        if ($this->failure) {
            throw $this->failure;
        }

        $this->priceChanges[] = [
            'subscription' => $subscription->stripe_subscription_id,
            'price_id' => $priceId,
            'immediate' => $immediate,
            'operation_uuid' => $operationUuid,
            'key' => $idempotencyKey,
        ];
        if ($this->duringPriceChange) {
            ($this->duringPriceChange)();
        }

        return $immediate
            ? ['request_id' => 'req_price_123', 'invoice_id' => 'in_test_change']
            : array_filter(['request_id' => 'req_price_123', 'invoice_id' => null, 'schedule_id' => $this->scheduleId], static fn ($value) => $value !== null);
    }
}
