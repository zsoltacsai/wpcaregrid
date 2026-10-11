<?php

namespace App\Console\Commands\Platform;

use App\Connector\JobType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class QuarantineQueue extends Command
{
    protected $signature = 'platform:recovery-quarantine-queue {--confirm-workers-stopped : Confirm all queue workers are stopped and no job is executing}';

    protected $description = 'Move pending database-queue jobs into an inert, checksum-protected recovery quarantine.';

    public function handle(): int
    {
        if (! config('platform.recovery_mode')) {
            $this->error('Refused: PLATFORM_RECOVERY_MODE must be enabled before queue quarantine.');

            return self::FAILURE;
        }

        if (! $this->option('confirm-workers-stopped')) {
            $this->error('Refused: stop all queue workers, verify no active job, then pass --confirm-workers-stopped.');

            return self::FAILURE;
        }

        $connectionName = (string) config('queue.default');
        $connection = config("queue.connections.{$connectionName}");
        if (($connection['driver'] ?? null) !== 'database') {
            $this->error('Refused: the configured queue is not the supported database driver. Keep workers stopped and use the provider-specific recovery procedure.');

            return self::FAILURE;
        }

        $db = DB::connection($connection['connection'] ?? null);
        if ($db->getName() !== DB::connection()->getName()) {
            $this->error('Refused: Laravel queue and WebsiteJob recovery data must use the same database connection for atomic quarantine.');

            return self::FAILURE;
        }
        $table = (string) ($connection['table'] ?? 'jobs');
        if (! Schema::connection($db->getName())->hasTable($table)
            || ! Schema::connection($db->getName())->hasTable('platform_recovery_quarantined_jobs')
            || ! Schema::connection($db->getName())->hasTable('platform_recovery_quarantined_website_jobs')
            || ! Schema::connection($db->getName())->hasTable('website_jobs')) {
            $this->error('Refused: queue or recovery quarantine table is unavailable.');

            return self::FAILURE;
        }

        if ($db->table($table)->whereNotNull('reserved_at')->exists()) {
            $this->error('Refused: at least one queue row is reserved. Resolve active/stale reservations while workers remain stopped, then retry.');

            return self::FAILURE;
        }

        $moved = 0;
        $websiteJobsMoved = 0;
        try {
            $db->table($table)->orderBy('id')->chunkById(100, function ($jobs) use ($db, $table, &$moved): void {
                $db->transaction(function () use ($db, $table, $jobs, &$moved): void {
                    $ids = $jobs->pluck('id')->all();
                    $locked = $db->table($table)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

                    foreach ($locked as $job) {
                        $db->table('platform_recovery_quarantined_jobs')->insert([
                            'original_job_id' => $job->id,
                            'queue' => $job->queue,
                            'payload' => $job->payload,
                            'payload_sha256' => hash('sha256', $job->payload),
                            'attempts' => $job->attempts,
                            'reserved_at' => $job->reserved_at,
                            'available_at' => $job->available_at,
                            'original_created_at' => $job->created_at,
                            'quarantined_at' => now(),
                        ]);
                    }

                    if ($locked->isNotEmpty()) {
                        $moved += $db->table($table)->whereIn('id', $locked->pluck('id'))->delete();
                    }
                }, attempts: 3);
            });

            $safeTypes = array_map(fn (JobType $type) => $type->value, array_filter(JobType::cases(), fn (JobType $type) => $type->isSafeDuringPlatformRecovery()));
            $db->table('website_jobs')->whereIn('status', ['queued', 'delivered'])->whereNotIn('type', $safeTypes)
                ->orderBy('id')->chunkById(100, function ($jobs) use ($db, $safeTypes, &$websiteJobsMoved): void {
                    $db->transaction(function () use ($db, $jobs, $safeTypes, &$websiteJobsMoved): void {
                        $locked = $db->table('website_jobs')->whereIn('id', $jobs->pluck('id'))
                            ->whereIn('status', ['queued', 'delivered'])->whereNotIn('type', $safeTypes)
                            ->orderBy('id')->lockForUpdate()->get();
                        foreach ($locked as $job) {
                            $payload = is_string($job->payload) ? $job->payload : json_encode($job->payload);
                            $website = $db->table('websites')->where('id', $job->website_id)->value('uuid');
                            $db->table('platform_recovery_quarantined_website_jobs')->insertOrIgnore([
                                'original_job_id' => $job->id,
                                'job_uuid' => $job->uuid,
                                'website_uuid' => (string) $website,
                                'type' => $job->type,
                                'original_status' => $job->status,
                                'payload' => $payload,
                                'payload_sha256' => hash('sha256', $payload),
                                'attempts' => $job->attempts,
                                'delivered_at' => $job->delivered_at,
                                'started_at' => $job->started_at,
                                'quarantined_at' => now(),
                            ]);
                            $db->table('website_jobs')->where('id', $job->id)->update([
                                'status' => 'cancelled',
                                'error' => json_encode(['code' => 'PLATFORM_RECOVERY_QUARANTINED', 'message' => 'Destructive command held during platform database recovery.']),
                                'updated_at' => now(),
                            ]);
                            $websiteJobsMoved++;
                        }
                    }, attempts: 3);
                });
        } catch (\Throwable $error) {
            report($error);
            $this->error('Queue quarantine stopped. Workers must remain stopped; inspect the transaction state and rerun after diagnosis.');

            return self::FAILURE;
        }

        $this->info("Quarantined {$moved} Laravel queue jobs and {$websiteJobsMoved} destructive WebsiteJobs. Payloads remain inert and checksum-verifiable; no jobs were re-dispatched.");

        return self::SUCCESS;
    }
}
