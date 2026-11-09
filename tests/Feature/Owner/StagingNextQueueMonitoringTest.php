<?php

namespace Tests\Feature\Owner;

use App\Connector\HealthStatus;
use App\Console\Commands\Monitoring\RunMonitoring;
use App\Jobs\Monitoring\RecordWorkerPulse;
use App\Monitoring\MonitoringEngine;
use App\Owner\SystemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

final class StagingNextQueueMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'default',
            'queue.connections.backups.driver' => 'database',
            'queue.connections.backups.table' => 'jobs',
            'queue.connections.backups.queue' => 'backups',
            'backup.transfer.connection' => 'backups',
            'backup.transfer.queue' => 'backups',
            'backup.transfer.timeout' => 3600,
            'queue.connections.backups.retry_after' => 4200,
            'queue.failed.table' => 'failed_jobs',
            'monitoring.worker_queues' => [
                'default' => ['connection' => 'database', 'queue' => 'default'],
                'backups' => ['connection' => 'backups', 'queue' => 'backups'],
            ],
        ]);
    }

    public function test_staging_next_dispatches_only_the_active_default_worker_pulse(): void
    {
        config(['app.deployment_environment' => 'staging_next']);
        Queue::fake();

        $method = new ReflectionMethod(RunMonitoring::class, 'workers');
        $method->invoke(app(RunMonitoring::class), app(MonitoringEngine::class));

        Queue::assertPushedOn('default', RecordWorkerPulse::class);
        $pulses = Queue::pushed(RecordWorkerPulse::class);
        $this->assertCount(1, $pulses);
        $this->assertSame('default', $pulses[0]['job']->queue);

    }

    public function test_staging_next_does_not_dispatch_a_pulse_when_the_active_queue_identity_mismatches(): void
    {
        config([
            'app.deployment_environment' => 'staging_next',
            'queue.connections.database.queue' => 'backups',
        ]);
        Queue::fake();

        $method = new ReflectionMethod(RunMonitoring::class, 'workers');
        $method->invoke(app(RunMonitoring::class), app(MonitoringEngine::class));

        $this->assertCount(0, Queue::pushed(RecordWorkerPulse::class));
    }

    public function test_staging_next_refuses_a_queue_backed_by_a_different_database_connection(): void
    {
        config([
            'app.deployment_environment' => 'staging_next',
            'queue.connections.database.connection' => 'm9_platform_verify',
        ]);
        Queue::fake();

        $method = new ReflectionMethod(RunMonitoring::class, 'workers');
        $method->invoke(app(RunMonitoring::class), app(MonitoringEngine::class));

        $this->assertCount(0, Queue::pushed(RecordWorkerPulse::class));
        $this->assertSame(HealthStatus::Critical, $this->queueStatus());
    }

    public function test_staging_next_refuses_a_non_database_queue_driver(): void
    {
        config([
            'app.deployment_environment' => 'staging_next',
            'queue.connections.database.driver' => 'sync',
        ]);
        Queue::fake();

        $method = new ReflectionMethod(RunMonitoring::class, 'workers');
        $method->invoke(app(RunMonitoring::class), app(MonitoringEngine::class));

        $this->assertCount(0, Queue::pushed(RecordWorkerPulse::class));
        $this->assertSame(HealthStatus::Critical, $this->queueStatus());
    }

    public function test_production_and_legacy_staging_continue_to_monitor_both_configured_workers(): void
    {
        foreach (['production', 'staging'] as $environment) {
            config(['app.deployment_environment' => $environment]);
            Queue::fake();

            $method = new ReflectionMethod(RunMonitoring::class, 'workers');
            $method->invoke(app(RunMonitoring::class), app(MonitoringEngine::class));

            Queue::assertPushedOn('default', RecordWorkerPulse::class);
            Queue::assertPushedOn('backups', RecordWorkerPulse::class);
        }
    }

    public function test_only_active_default_queue_backlog_affects_staging_next_queue_health_and_inactive_rows_are_preserved(): void
    {
        config(['app.deployment_environment' => 'staging_next']);
        $this->insertJob('backups', now()->subMinutes(30)->timestamp, 23);

        $this->assertSame(HealthStatus::Healthy, $this->queueStatus());
        $this->assertSame(23, DB::table('jobs')->where('queue', 'backups')->count());
        $queueCheck = collect(app(SystemStatus::class)->checks())->firstWhere('key', 'queue');
        $this->assertNotNull($queueCheck);
        $this->assertSame(
            'backups',
            $queueCheck->details[__('owner.system.details.intentionally_paused_queues')],
        );

        $this->insertJob('default', now()->subMinutes(30)->timestamp);
        $this->assertSame(HealthStatus::Critical, $this->queueStatus());
    }

    public function test_staging_next_does_not_suppress_failed_jobs(): void
    {
        config(['app.deployment_environment' => 'staging_next']);
        DB::table('failed_jobs')->insert([
            'uuid' => 'queue-hotfix-test-failed-job',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'synthetic',
            'failed_at' => now(),
        ]);

        $this->assertSame(HealthStatus::Attention, $this->queueStatus());
    }

    public function test_other_environments_keep_existing_all_queue_backlog_detection(): void
    {
        config(['app.deployment_environment' => 'staging']);
        $this->insertJob('backups', now()->subMinutes(30)->timestamp);

        $this->assertSame(HealthStatus::Critical, $this->queueStatus());
    }

    public function test_staging_next_queue_scope_mismatch_fails_closed(): void
    {
        config([
            'app.deployment_environment' => 'staging_next',
            'queue.connections.database.queue' => 'backups',
        ]);

        $this->assertSame(HealthStatus::Critical, $this->queueStatus());
    }

    private function insertJob(string $queue, int $availableAt, int $count = 1): void
    {
        foreach (range(1, $count) as $i) {
            DB::table('jobs')->insert([
                'queue' => $queue,
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $availableAt,
                'created_at' => $availableAt,
            ]);
        }
    }

    private function queueStatus(): HealthStatus
    {
        $check = collect(app(SystemStatus::class)->checks())->firstWhere('key', 'queue');
        $this->assertNotNull($check);

        return $check->status;
    }
}
