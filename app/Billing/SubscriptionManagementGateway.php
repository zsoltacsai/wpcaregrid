<?php

namespace App\Billing;

use App\Models\Subscription;

interface SubscriptionManagementGateway
{
    /** @return array{request_id: ?string} */
    public function setCancelAtPeriodEnd(Subscription $subscription, bool $cancel, string $idempotencyKey): array;

    /** @return array{request_id: ?string, invoice_id: ?string, schedule_id?: string} */
    public function changePrice(Subscription $subscription, string $priceId, bool $immediate, string $operationUuid, string $idempotencyKey): array;
}
