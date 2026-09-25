<?php

namespace App\Connector;

use App\Models\Connector;
use App\Models\Website;
use App\Models\WebsiteJob;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Queues and hands out connector jobs. Authorisation is checked when a job
 * is queued and again when it is delivered, since the subscription or the
 * connector may have changed in between.
 */
final class JobQueue
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectorError when the job is not allowed
     * @throws ValidationException when the payload is invalid
     */
    public function queue(Website $website, JobType $type, array $payload = [], string $requestedBy = 'system'): WebsiteJob
    {
        $payload = $type->validatePayload($payload);

        if ($reason = $this->refusal($website, $website->activeConnector, $type)) {
            throw ConnectorError::jobNotAllowed($reason);
        }

        return $website->jobs()->create([
            'type' => $type,
            'payload' => $payload,
            'status' => JobStatus::Queued,
            'requested_by' => $requestedBy,
        ]);
    }

    /** @return Collection<int, WebsiteJob> Jobs to hand to this connector now. */
    public function deliver(Connector $connector): Collection
    {
        $website = $connector->website;

        $jobs = $website->jobs()
            ->whereIn('status', [JobStatus::Queued, JobStatus::Delivered])
            ->oldest('id')
            ->limit(config('connector.jobs_per_poll'))
            ->get();

        return $jobs->filter(function (WebsiteJob $job) use ($website, $connector) {
            if ($job->attempts >= config('connector.max_job_attempts')) {
                $job->update(['status' => JobStatus::Expired, 'error' => ['code' => 'NOT_REPORTED', 'message' => 'The connector did not report a result.']]);

                return false;
            }

            if ($reason = $this->refusal($website, $connector, $job->type)) {
                $job->update(['status' => JobStatus::Cancelled, 'error' => ['code' => 'JOB_NOT_ALLOWED', 'message' => $reason]]);

                return false;
            }

            $job->forceFill([
                'status' => JobStatus::Delivered,
                'connector_id' => $connector->id,
                'delivered_at' => now(),
                'attempts' => $job->attempts + 1,
            ])->save();

            return true;
        })->values();
    }

    /** Why a job may not run for this website and connector, or null when it may. */
    public function refusal(Website $website, ?Connector $connector, JobType $type): ?string
    {
        return match (true) {
            ! $connector?->isActive() || $connector->website_id !== $website->id => 'website is not connected',
            ! $website->allowsServiceOperations() => 'subscription is not active',
            ! $connector->can($type->requiredCapability()) => "connector does not support {$type->requiredCapability()->value}",
            default => null,
        };
    }
}
