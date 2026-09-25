<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Inventory\InventoryCollector;
use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;

final class LicenseCheckHandler implements JobHandler
{
    public function __construct(private readonly InventoryCollector $inventory) {}

    public function capability(): string
    {
        return 'license_check';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, []);

        return $payload;
    }

    public function handle(array $payload): JobResult
    {
        $licenses = array_map(
            fn (array $plugin) => ['file' => $plugin['file']] + $plugin['license'],
            $this->inventory->collect()['plugins']
        );

        return JobResult::success('LICENSES_CHECKED', ['licenses' => $licenses]);
    }
}
