<?php

namespace WPCareGrid\Connector\Jobs;

use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Core\Connection;
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
    private const LOCK = 'wp_caregrid_poll_lock';

    private const LOCK_TTL = 900;

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
            // Seen before: never execute again.
            if ($known['state'] === JobStore::RUNNING) {
                $this->store->complete($uuid, JobResult::failed('JOB_INTERRUPTED', 'The job started but did not finish; it was not run again.'), $known['started_at'] ?? null);
                Logger::warning('job.interrupted', ['job' => $uuid, 'type' => $known['type']]);
            } else {
                $this->store->mark_undelivered($uuid);
                Logger::info('job.duplicate_ignored', ['job' => $uuid, 'type' => $type]);
            }

            return false;
        }

        $handler = $this->registry->handler($type);

        // Defence in depth: refuse anything not on the local whitelist or not advertised.
        if (! $handler || ! in_array($handler->capability(), JobRegistry::CAPABILITIES, true)) {
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

        // Recorded before execution: if this process dies, the job is not repeated.
        $this->store->begin($uuid, $type);
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
        $now = time();

        if (add_option(self::LOCK, $now, '', false)) {
            return true;
        }

        // A lock older than the TTL belongs to a process that died.
        if ((int) get_option(self::LOCK) < $now - self::LOCK_TTL) {
            update_option(self::LOCK, $now, false);

            return true;
        }

        return false;
    }

    private function unlock(): void
    {
        delete_option(self::LOCK);
    }
}
