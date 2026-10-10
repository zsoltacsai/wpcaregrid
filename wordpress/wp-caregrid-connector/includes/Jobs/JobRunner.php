<?php

namespace WPCareGrid\Connector\Jobs;

use WPCareGrid\Connector\AdminAccess\AdminAccess;
use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Backups\Maintenance;
use WPCareGrid\Connector\Backups\TempDir;
use WPCareGrid\Connector\Core\Connection;
use WPCareGrid\Connector\Core\Lock;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Security\Logger;

/**
 * Polls for jobs, runs each at most once, and delivers results.
 *
 * Running a job and delivering its result are separate steps. A result
 * that cannot be delivered (network error, server error) stays in the
 * outbox and is retried on the next poll; the job itself is never run again.
 */
final class JobRunner
{
    private const MAX_TRIES = 50;

    public function __construct(
        private readonly Client $api,
        private readonly JobRegistry $registry,
        private readonly JobStore $store,
        private readonly Connection $connection,
    ) {}

    /** @return array{executed: int, delivered: int} */
    public function poll(): array
    {
        $summary = ['executed' => 0, 'delivered' => 0];

        if (! Settings::is_connected() || ! $this->lock()) {
            return $summary;
        }

        try {
            // Housekeeping: a restore that died must not leave the site in
            // maintenance mode; stale temporary backup files are removed.
            Maintenance::cleanup_stale();
            TempDir::cleanup_stale($this->store);

            $summary['delivered'] += $this->flush_outbox();

            $response = $this->api->get('jobs');

            if ($response->is_revoked()) {
                $this->connection->handle_revocation();

                return $summary;
            }

            if (! $response->ok()) {
                Settings::update(['last_error' => $response->error_code]);

                return $summary;
            }

            Settings::update(['last_poll_at' => gmdate('c'), 'last_success_at' => gmdate('c'), 'last_error' => null]);
            $this->connection->apply_schedule($response->data);
            if (is_array($response->data['admin_access'] ?? null)) {
                AdminAccess::reconcile($response->data['admin_access']);
            }

            foreach ((array) ($response->data['jobs'] ?? []) as $job) {
                if (is_array($job) && $this->process($job)) {
                    $summary['executed']++;
                }
            }

            $summary['delivered'] += $this->flush_outbox();
        } finally {
            $this->unlock();
        }

        return $summary;
    }

    /** @return bool Whether the job was executed now. */
    public function process(array $job): bool
    {
        $uuid = (string) ($job['uuid'] ?? '');
        $type = (string) ($job['type'] ?? '');

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            Logger::warning('job.malformed', ['type' => $type]);

            return false;
        }

        $known = $this->store->find($uuid);

        if ($known) {
            // Seen before: never execute again. A backup that was interrupted
            // while uploading continues the upload; it is not made again.
            $resumable = $this->registry->handler((string) $known['type']);
            if ($known['state'] === JobStore::RUNNING && $resumable instanceof ResumableJob && $resumable->can_resume((array) ($known['payload'] ?? []))) {
                Logger::info('job.resumed', ['job' => $uuid, 'type' => $known['type']]);
                try {
                    $result = $resumable->resume((array) $known['payload']);
                } catch (\Throwable $e) {
                    $result = JobResult::failed('JOB_EXCEPTION', 'The job failed unexpectedly.', ['exception' => get_class($e)]);
                }
                $this->store->complete($uuid, $result, $known['started_at'] ?? null);

                return true;
            }

            if ($known['state'] === JobStore::RUNNING) {
                $details = [];
                if (($known['type'] ?? null) === 'RESTORE') {
                    $stage = is_string($known['failure_stage'] ?? null) ? $known['failure_stage'] : 'unknown';
                    // A database-swap interruption without the durable marker
                    // is ambiguous. Never treat that state as proof that no
                    // live mutation happened.
                    $destructive = $this->store->has_destructive_mutation_started($uuid)
                        || ($known['destructive_mutation_started'] ?? false) === true
                        ? true
                        : ($stage === 'database_swap' ? null : false);
                    $details = [
                        'destructive_mutation_started' => $destructive,
                        'failure_stage' => $stage,
                    ];
                }
                $this->store->complete($uuid, JobResult::failed('JOB_INTERRUPTED', 'The job started but did not finish; it was not run again.', $details), $known['started_at'] ?? null);
                Logger::warning('job.interrupted', ['job' => $uuid, 'type' => $known['type']]);
            } else {
                $this->store->mark_undelivered($uuid);
                Logger::info('job.duplicate_ignored', ['job' => $uuid, 'type' => $type]);
            }

            return false;
        }

        $handler = $this->registry->handler($type);

        // Defence in depth: refuse anything not on the local whitelist.
        if (! $handler) {
            $this->store->complete($uuid, JobResult::failed('UNSUPPORTED_JOB_TYPE', 'This job type is not supported by the connector.', ['type' => substr($type, 0, 64)]));
            Logger::warning('job.unsupported', ['job' => $uuid, 'type' => substr($type, 0, 64)]);

            return false;
        }

        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        try {
            $payload = $handler->validate($payload);
        } catch (InvalidPayload $e) {
            $this->store->complete($uuid, JobResult::failed('INVALID_PAYLOAD', $e->getMessage(), ['type' => $type]));
            Logger::warning('job.invalid_payload', ['job' => $uuid, 'type' => $type]);

            return false;
        }

        // Only what this site can actually do (e.g. no database jobs without MySQL).
        if (! in_array($handler->capability($payload), JobRegistry::capabilities(), true)) {
            $this->store->complete($uuid, JobResult::failed('CAPABILITY_UNAVAILABLE', 'This site does not support this job.', ['type' => $type]));
            Logger::warning('job.capability_unavailable', ['job' => $uuid, 'type' => $type]);

            return false;
        }

        // Recorded before execution: if this process dies, the job is not repeated.
        $this->store->begin($uuid, $type, $payload);
        Logger::info('job.started', ['job' => $uuid, 'type' => $type]);

        try {
            $result = $handler->handle($payload);
        } catch (\Throwable $e) {
            $result = JobResult::failed('JOB_EXCEPTION', 'The job failed unexpectedly.', ['exception' => get_class($e)]);
        }

        $this->store->complete($uuid, $result);
        Logger::info('job.finished', ['job' => $uuid, 'type' => $type, 'status' => $result->status, 'code' => $result->code]);

        return true;
    }

    /** Deliver stored results. Returns how many were accepted. */
    public function flush_outbox(): int
    {
        $delivered = 0;

        foreach ($this->store->undelivered() as $uuid => $job) {
            $attempts = $this->store->record_delivery_attempt($uuid);

            $response = $this->api->post(
                "jobs/{$uuid}/result",
                array_merge($job['result'], ['started_at' => $job['started_at'], 'completed_at' => $job['completed_at']])
            );

            if ($response->is_revoked()) {
                $this->connection->handle_revocation();

                return $delivered;
            }

            if ($response->ok()) {
                $this->store->mark_delivered($uuid);
                $delivered++;

                continue;
            }

            // The server will never accept it (unknown/undelivered job, invalid): stop retrying.
            if (! $response->is_temporary() || $attempts >= self::MAX_TRIES) {
                $this->store->mark_delivered($uuid);
                Logger::error('job.result_rejected', ['job' => $uuid, 'status' => $response->status, 'code' => $response->error_code]);

                continue;
            }

            // Temporary: keep it for the next poll, and stop hammering a failing server.
            break;
        }

        return $delivered;
    }

    private function lock(): bool
    {
        return Lock::acquire();
    }

    private function unlock(): void
    {
        Lock::release();
    }
}
