<?php

namespace WPCareGrid\Connector\Jobs;

/** One whitelisted job type. */
interface JobHandler
{
    /**
     * The capability this job needs (must be advertised by this site).
     *
     * @param  array  $payload  Validated payload (RESTORE depends on its scope).
     */
    public function capability(array $payload = []): string;

    /**
     * Validate the payload. Unknown keys, paths, URLs and code are rejected.
     *
     * @throws InvalidPayload
     */
    public function validate(array $payload): array;

    public function handle(array $payload): JobResult;
}
