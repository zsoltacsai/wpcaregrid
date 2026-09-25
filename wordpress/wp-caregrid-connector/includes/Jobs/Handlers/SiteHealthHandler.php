<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Health\HealthChecker;
use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;

final class SiteHealthHandler implements JobHandler
{
    public function __construct(private readonly HealthChecker $health) {}

    public function capability(array $payload = []): string
    {
        return 'health';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, []);

        return $payload;
    }

    public function handle(array $payload): JobResult
    {
        return JobResult::success('HEALTH_CHECKED', ['health' => $this->health->run()]);
    }
}
