<?php

namespace WPCareGrid\Connector\Jobs;

/**
 * A job that can continue after its process died, without redoing work
 * that changes anything: backups resume their upload from the server's
 * received offset. Never used to run a job a second time.
 */
interface ResumableJob
{
    /** @param  array  $payload  The validated payload recorded when the job started. */
    public function can_resume(array $payload): bool;

    public function resume(array $payload): JobResult;
}
