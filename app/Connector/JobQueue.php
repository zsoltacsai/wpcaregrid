<?php

namespace App\Connector;

use App\Backups\PreUpdateBackups;
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
     * Queue a job. Updates go through PreUpdateBackups: when the plan
     * requires it they wait for a verified backup first.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{backup_id?: int, restore_id?: int, status?: JobStatus}  $links
     *
     * @throws ConnectorError when the job is not allowed
     * @throws ValidationException when the payload is invalid
     */
    public function queue(Website $website, JobType $type, array $payload = [], string $requestedBy = 'system', array $links = []): WebsiteJob
    {
        $payload = $type->validatePayload($payload);

        if ($reason = $this->refusal($website, $website->activeConnector, $type, $payload)) {
            throw ConnectorError::jobNotAllowed($reason);
        }

        if ($type->isUpdate() && ! isset($links['status'])) {
            return app(PreUpdateBackups::class)->queueUpdate($website, $type, $payload, $requestedBy);
        }

        return $website->jobs()->create([
            'type' => $type,
            'payload' => $payload,
            'status' => $links['status'] ?? JobStatus::Queued,
            'requested_by' => $requestedBy,
            'backup_id' => $links['backup_id'] ?? null,
            'restore_id' => $links['restore_id'] ?? null,
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
                app(JobLifecycle::class)->finished($job);

                return false;
            }

            if ($reason = $this->refusal($website, $connector, $job->type, $job->payload ?? [])) {
                $job->update(['status' => JobStatus::Cancelled, 'error' => ['code' => 'JOB_NOT_ALLOWED', 'message' => $reason]]);
                app(JobLifecycle::class)->finished($job);

                return false;
            }

            $job->forceFill([
                'status' => JobStatus::Delivered,
                'connector_id' => $connector->id,
                'delivered_at' => now(),
                'attempts' => $job->attempts + 1,
            ])->save();
            app(JobLifecycle::class)->delivered($job);

            return true;
        })->values();
    }

    /**
     * Why a job may not run for this website and connector, or null when it may.
     *
     * @param  array<string, mixed>  $payload
     */
    public function refusal(Website $website, ?Connector $connector, JobType $type, array $payload = []): ?string
    {
        $capability = $type->requiredCapability($payload);

        return match (true) {
            ! $connector?->isActive() || $connector->website_id !== $website->id => 'website is not connected',
            ! $website->allowsServiceOperations() => 'subscription is not active',
            ! $connector->can($capability) => "connector does not support {$capability->value}",
            // Overwriting a site needs a connector that is reporting in right now.
            $type === JobType::Restore && ! config('backup.restore.enabled') => 'restore is not enabled',
            $type === JobType::Restore && $connector->presence() !== Presence::Connected => 'connector is not currently reporting in',
            default => null,
        };
    }
}
