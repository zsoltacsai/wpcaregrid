<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Inventory\InventoryCollector;
use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;

final class GetInventoryHandler implements JobHandler
{
    public function __construct(private readonly InventoryCollector $inventory) {}

    public function capability(): string
    {
        return 'inventory';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, []);

        return $payload;
    }

    public function handle(array $payload): JobResult
    {
        return JobResult::success('INVENTORY_COLLECTED', ['inventory' => $this->inventory->collect()]);
    }
}
