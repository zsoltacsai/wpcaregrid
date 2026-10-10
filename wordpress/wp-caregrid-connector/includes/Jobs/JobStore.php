<?php

namespace WPCareGrid\Connector\Jobs;

/**
 * Local record of every job this site has seen, so the same job UUID is
 * never executed twice, and of results not yet delivered (the outbox).
 *
 * States: running → completed. A job left "running" (the process died
 * mid-job) is never re-run; it is reported as interrupted.
 */
final class JobStore
{
    private const OPTION = 'wp_caregrid_jobs';

    private const MUTATION_OPTION_PREFIX = 'wp_caregrid_restore_mutated_';

    private const KEEP = 300;

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public function find(string $uuid): ?array
    {
        return $this->all()[$uuid] ?? null;
    }

    /** Record the start of a job before anything is executed. */
    public function begin(string $uuid, string $type, array $payload = []): void
    {
        $jobs = $this->all();
        $jobs[$uuid] = [
            'type' => $type,
            // Validated payload: identifiers only, kept so an interrupted backup can resume.
            'payload' => $payload,
            'state' => self::RUNNING,
            'started_at' => gmdate('c'),
            'completed_at' => null,
            'result' => null,
            'delivered' => false,
            'delivery_attempts' => 0,
        ];
        $this->save($jobs);
    }

    public function complete(string $uuid, JobResult $result, ?string $started_at = null): void
    {
        $jobs = $this->all();
        $job = $jobs[$uuid] ?? ['type' => 'UNKNOWN', 'delivered' => false, 'delivery_attempts' => 0];

        $jobs[$uuid] = array_merge(
            $job,
            [
                'state' => self::COMPLETED,
                'started_at' => $started_at ?? ($job['started_at'] ?? gmdate('c')),
                'completed_at' => gmdate('c'),
                'result' => [
                    'status' => $result->status,
                    'result_code' => $result->code,
                    'data' => $result->data,
                    'error' => $result->error,
                ],
                'delivered' => false,
            ]
        );
        $this->save($jobs);
    }

    /** Persist restore phase before the operation can be interrupted by a process restart. */
    public function mark_restore_progress(string $uuid, bool $destructive, string $stage): void
    {
        $jobUuid = $this->restore_job_uuid($uuid);
        if ($jobUuid === null) {
            return;
        }

        $job = $this->find($jobUuid);
        if ($destructive) {
            // A separate, immutable option is the durable source of truth. Its
            // unique option_name makes concurrent inserts monotonic: no stale
            // progress write can change true back to false.
            add_option($this->mutation_option($jobUuid), '1', '', 'no');
            if (! $this->has_destructive_mutation_started($jobUuid)) {
                throw new \RuntimeException('The restore mutation marker could not be persisted.');
            }
        }

        $this->patch($jobUuid, [
            'destructive_mutation_started' => $destructive
                || $this->has_destructive_mutation_started($jobUuid)
                || ($job['destructive_mutation_started'] ?? false) === true,
            'failure_stage' => $stage,
        ]);
    }

    /** Durable across the atomic WordPress table swap and worker restarts. */
    public function has_destructive_mutation_started(string $uuid): bool
    {
        $jobUuid = $this->restore_job_uuid($uuid) ?? $uuid;

        return get_option($this->mutation_option($jobUuid), false) === '1';
    }

    /** @return array<string, array> Completed jobs whose result has not been accepted yet. */
    public function undelivered(): array
    {
        return array_filter($this->all(), fn ($job) => $job['state'] === self::COMPLETED && ! $job['delivered']);
    }

    public function mark_delivered(string $uuid): void
    {
        $this->patch($uuid, ['delivered' => true]);
    }

    public function mark_undelivered(string $uuid): void
    {
        $this->patch($uuid, ['delivered' => false]);
    }

    public function record_delivery_attempt(string $uuid): int
    {
        $attempts = (int) ($this->find($uuid)['delivery_attempts'] ?? 0) + 1;
        $this->patch($uuid, ['delivery_attempts' => $attempts]);

        return $attempts;
    }

    public function all(): array
    {
        $jobs = get_option(self::OPTION, []);

        return is_array($jobs) ? $jobs : [];
    }

    private function patch(string $uuid, array $values): void
    {
        $jobs = $this->all();
        if (isset($jobs[$uuid])) {
            $jobs[$uuid] = array_merge($jobs[$uuid], $values);
            $this->save($jobs);
        }
    }

    private function save(array $jobs): void
    {
        // Keep the newest entries, but never drop an undelivered result.
        if (count($jobs) > self::KEEP) {
            $delivered = array_filter($jobs, fn ($job) => $job['state'] === self::COMPLETED && $job['delivered']);
            foreach (array_slice(array_keys($delivered), 0, count($jobs) - self::KEEP) as $old) {
                unset($jobs[$old]);
            }
        }

        update_option(self::OPTION, $jobs, false);
    }

    /** Resolve the restore UUID used by RestoreHandler to its queue job UUID. */
    private function restore_job_uuid(string $uuid): ?string
    {
        $job = $this->find($uuid);
        if (($job['type'] ?? null) === 'RESTORE' && ($job['state'] ?? null) === self::RUNNING) {
            return $uuid;
        }

        foreach ($this->all() as $jobUuid => $candidate) {
            if (($candidate['type'] ?? null) === 'RESTORE'
                && ($candidate['state'] ?? null) === self::RUNNING
                && ($candidate['payload']['restore_uuid'] ?? null) === $uuid) {
                return (string) $jobUuid;
            }
        }

        return null;
    }

    private function mutation_option(string $jobUuid): string
    {
        return self::MUTATION_OPTION_PREFIX.$jobUuid;
    }
}
