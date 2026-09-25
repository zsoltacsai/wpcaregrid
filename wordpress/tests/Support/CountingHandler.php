<?php

namespace WPCareGrid\Connector\Tests\Support;

use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;

/** Counts executions, to prove a job ran exactly once (or never). */
final class CountingHandler implements JobHandler
{
    public int $runs = 0;

    public function __construct(private readonly string $capability = 'health', private readonly ?\Throwable $throw = null) {}

    public function capability(array $payload = []): string
    {
        return $this->capability;
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, []);

        return $payload;
    }

    public function handle(array $payload): JobResult
    {
        $this->runs++;

        if ($this->throw) {
            throw $this->throw;
        }

        return JobResult::success('COUNTED', ['runs' => $this->runs]);
    }
}
