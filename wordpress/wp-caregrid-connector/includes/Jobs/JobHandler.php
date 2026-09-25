<?php

namespace WPCareGrid\Connector\Jobs;

/** One whitelisted job type. */
interface JobHandler
{
    /** The capability this job belongs to (must be advertised). */
    public function capability(): string;

    /**
     * Validate the payload. Unknown keys, paths, URLs and code are rejected.
     *
     * @throws InvalidPayload
     */
    public function validate(array $payload): array;

    public function handle(array $payload): JobResult;
}
