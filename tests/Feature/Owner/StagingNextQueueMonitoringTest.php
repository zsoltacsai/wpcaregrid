<?php

namespace Tests\Feature\Owner;

use App\Connector\HealthStatus;
use App\Console\Commands\Monitoring\RunMonitoring;
use App\Jobs\Monitoring\RecordWorkerPulse;
use App\Models\MonitoringIncident;
use App\Models\MonitoringResult;
use App\Models\MonitoringTransition;
use App\Monitoring\MonitoringReason;
use App\Monitoring\MonitoringScope;
use App\Monitoring\MonitoringSeverity;
use App\Monitoring\MonitoringEngine;
use App\Owner\SystemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->assertFalse(MonitoringResult::query()->where('check_id', 'platform.worker')
            ->where('subject_type', 'queue')->where('subject_id', 'backups')->exists());

    }

    public function test_staging_next_resolves_legacy_paused_worker_state_without_pulsing_it(): void
    {
        config(['app.deployment_environment' => 'staging_next']);
        Queue::fake();

        $now = now();
        $scope = MonitoringScope::Platform;
        $workerIdentity = [
            'check_id' => 'platform.worker',
            'scope' => $scope->value,
            'subject_type' => 'queue',
            'subject_id' => 'default',
        ];
        MonitoringResult::query()->create($workerIdentity + [
            'state' => HealthStatus::Healthy,
            'severity' => null,
            'reason_code' => MonitoringReason::MONITORING_OK,
            'source' => 'queue',
            'evidence' => ['pulse_age_seconds' => 1],
            'observed_at' => $now,
            'fresh_until' => $now->copy()->addMinutes(15),
            'next_check_at' => $now->copy()->addMinutes(15),
            'consecutive_failures' => 0,
            'consecutive_successes' => 1,
        ]);

        $pausedIdentity = [
            'check_id' => 'platform.worker',
            'scope' => $scope->value,
            'subject_type' => 'queue',
            'subject_id' => 'backups',
        ];
        MonitoringResult::query()->create($pausedIdentity + [
            'state' => HealthStatus::Critical,
            'severity' => MonitoringSeverity::Critical,
            'reason_code' => MonitoringReason::WORKER_STALE,
            'source' => 'queue',
            'evidence' => ['pulse_age_seconds' => 1800],
            'observed_at' => $now->copy()->subMinutes(30),
            'fresh_until' => $now->copy()->subMinutes(15),
            'next_check_at' => $now->copy()->subMinutes(15),
            'consecutive_failures' => 2,
            'consecutive_successes' => 0,
        ]);

        $incidentKey = hash('sha256', implode('|', [
            'platform.worker', $scope->value, 'queue', 'backups',
        ]));
        MonitoringIncident::query()->create([
            'incident_key' => $incidentKey,
            'generation' => 1,
            'check_id' => 'platform.worker',
            'scope' => $scope,
            'subject_type' => 'queue',
            'subject_id' => 'backups',
            'severity' => MonitoringSeverity::Critical,
            'reason_code' => MonitoringReason::WORKER_STALE,
            'status' => 'open',
            'evidence' => ['pulse_age_seconds' => 1800],
            'opened_at' => $now->copy()->subMinutes(30),
            'last_observed_at' => $now->copy()->subMinutes(20),
            'observation_count' => 2,
        ]);

        $method = new ReflectionMethod(RunMonitoring::class, 'workers');
        $method->invoke(app(RunMonitoring::class), app(MonitoringEngine::class));

        $paused = MonitoringResult::query()->where($pausedIdentity)->firstOrFail();
        $this->assertSame(HealthStatus::Healthy, $paused->state);
        $this->assertSame(MonitoringReason::WORKER_PAUSED, $paused->reason_code);
        $this->assertSame('backups', $paused->evidence['queue']);
        $this->assertSame(false, $paused->evidence['configured']);
        $this->assertSame('resolved', MonitoringIncident::query()
            ->where('incident_key', $incidentKey)->value('status'));
        $this->assertCount(1, Queue::pushed(RecordWorkerPulse::class));
        $this->assertSame('default', Queue::pushed(RecordWorkerPulse::class)[0]['job']->queue);

        $firstObservedAt = $paused->observed_at?->toDateTimeString();
        $incidentId = MonitoringIncident::query()->where('incident_key', $incidentKey)->value('id');
        $transitionCount = MonitoringTransition::query()->where('incident_id', $incidentId)->count();

        Carbon::setTestNow($now->copy()->addMinute());
        try {
            $method->invoke(app(RunMonitoring::class), app(MonitoringEngine::class));

            $this->assertSame(
                $firstObservedAt,
                MonitoringResult::query()->where($pausedIdentity)->firstOrFail()->observed_at?->toDateTimeString(),
            );
            $this->assertSame($transitionCount, MonitoringTransition::query()->where('incident_id', $incidentId)->count());
            $this->assertCount(1, Queue::pushed(RecordWorkerPulse::class));
            $this->assertSame('default', Queue::pushed(RecordWorkerPulse::class)[0]['job']->queue);
        } finally {
            Carbon::setTestNow();
        }
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

    public function test_diagnostic_record_allows_exception_class_but_never_message(): void
    {
        $exception = new \RuntimeException('synthetic-secret-message');
        $record = $this->diagnosticRecord('dispatch_active_default_pulse', $exception);

        $this->assertStringContainsString('|exception_class=RuntimeException|', $record);
        $this->assertStringContainsString('|sqlstate=UNKNOWN|db_code=UNKNOWN', $record);
        $this->assertStringNotContainsString('synthetic-secret-message', $record);
    }

    public function test_diagnostic_record_extracts_only_structured_pdo_metadata_from_wrapped_error(): void
    {
        $pdo = new \PDOException('synthetic-secret-sql');
        $pdo->errorInfo = ['HY000', 1205, 'synthetic-secret-detail'];
        $wrapped = new \RuntimeException('synthetic-secret-wrapper', 0, $pdo);
        $record = $this->diagnosticRecord('resolve_legacy_paused_worker', $wrapped);

        $this->assertStringContainsString('|exception_class=RuntimeException|', $record);
        $this->assertStringContainsString('|sqlstate=HY000|db_code=1205', $record);
        $this->assertStringNotContainsString('synthetic-secret', $record);
    }

    public function test_diagnostic_record_rejects_unexpected_pdo_metadata(): void
    {
        $pdo = new \PDOException('synthetic-secret');
        $pdo->errorInfo = ["HY000;secret", "1205;secret", 'synthetic-secret'];
        $record = $this->diagnosticRecord('dispatch_active_default_pulse', $pdo);

        $this->assertStringContainsString('|exception_class=PDOException|', $record);
        $this->assertStringContainsString('|sqlstate=UNKNOWN|db_code=UNKNOWN', $record);
        $this->assertStringNotContainsString('secret', $record);
    }

    public function test_non_target_failure_hook_rethrows_the_same_throwable(): void
    {
        $original = new \RuntimeException('synthetic-secret');
        try {
            $this->onNotSuccessfulTest($original);
        } catch (\Throwable $caught) {
            $this->assertSame($original, $caught);

            return;
        }

        $this->fail('Expected PHPUnit failure hook to rethrow the original throwable.');
    }

    public function test_diagnostic_record_emits_only_allowlisted_origin_file_and_valid_line(): void
    {
        $exception = new \Error('synthetic-secret-message');
        $record = $this->diagnosticRecord('dispatch_active_default_pulse', $exception);
        $fields = array_column(array_map(static fn (string $field): array => explode('=', $field, 2), explode('|', $record)), 1, 0);

        $this->assertSame('tests/Feature/Owner/StagingNextQueueMonitoringTest.php', $fields['origin_file']);
        $this->assertMatchesRegularExpression('/\A[1-9][0-9]{0,4}\z/D', $fields['origin_line']);
        $this->assertLessThanOrEqual(99999, (int) $fields['origin_line']);
    }

    public function test_diagnostic_record_rejects_absolute_and_unexpected_origin_paths(): void
    {
        $secretPath = tempnam(sys_get_temp_dir(), 'synthetic-secret-origin-');
        $this->assertIsString($secretPath);
        try {
            file_put_contents($secretPath, "<?php throw new \\Error('synthetic-secret-message');");
            try {
                include $secretPath;
            } catch (\Throwable $exception) {
                $record = $this->diagnosticRecord('dispatch_active_default_pulse', $exception);
                $this->assertStringContainsString('|origin_file=UNKNOWN|origin_line=UNKNOWN', $record);
                $this->assertTrue(! str_contains($record, $secretPath));
                $this->assertTrue(! str_contains($record, 'synthetic-secret'));
            }
        } finally {
            @unlink($secretPath);
        }
    }

    public function test_diagnostic_record_rejects_malformed_or_out_of_range_origin_lines(): void
    {
        $validator = new \ReflectionMethod(self::class, 'safeDiagnosticOriginLine');
        foreach ([0, -1, 100001, '12', '12secret', null, 1.5] as $line) {
            $result = $validator->invoke($this, $line, 'tests/Feature/Owner/StagingNextQueueMonitoringTest.php');
            $this->assertSame('UNKNOWN', $result);
        }
        $this->assertSame('UNKNOWN', $validator->invoke($this, 12, 'UNKNOWN'));
        $this->assertSame('12', $validator->invoke(
            $this,
            12,
            'tests/Feature/Owner/StagingNextQueueMonitoringTest.php',
        ));
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

    /**
     * Temporary diagnostics for only the two #350 MySQL failures.
     * PHPUnit 13 calls this failure hook; the original throwable is rethrown.
     */
    protected function onNotSuccessfulTest(\Throwable $exception): never
    {
        $testIds = [
            'test_staging_next_dispatches_only_the_active_default_worker_pulse' => 'dispatch_active_default_pulse',
            'test_staging_next_resolves_legacy_paused_worker_state_without_pulsing_it' => 'resolve_legacy_paused_worker',
        ];
        $testId = $testIds[$this->name()] ?? null;

        if ($testId !== null) {
            try {
                @fwrite(STDERR, $this->diagnosticRecord($testId, $exception).PHP_EOL);
            } catch (\Throwable) {
                // Diagnostics must never replace the original test failure.
            }
        }

        parent::onNotSuccessfulTest($exception);
    }

    private function diagnosticRecord(string $testId, \Throwable $exception): string
    {
        $allowed = [
            'dispatch_active_default_pulse',
            'resolve_legacy_paused_worker',
        ];
        if (! in_array($testId, $allowed, true)) {
            return 'WPCG_TEST_DIAG_V1|test=UNKNOWN|step=UNKNOWN|exception_class=UNKNOWN|sqlstate=UNKNOWN|db_code=UNKNOWN|origin_file=UNKNOWN|origin_line=UNKNOWN';
        }

        $class = $this->safeDiagnosticClass($exception::class);
        $sqlState = 'UNKNOWN';
        $databaseCode = 'UNKNOWN';
        $current = $exception;

        for ($depth = 0; $depth < 8; $depth++) {
            if ($current instanceof \PDOException) {
                $errorInfo = $current->errorInfo ?? null;
                if (is_array($errorInfo)) {
                    $candidateState = $errorInfo[0] ?? null;
                    $candidateCode = $errorInfo[1] ?? null;
                    if (is_string($candidateState)) {
                        $candidateState = strtoupper($candidateState);
                        if (preg_match('/\A[A-Z0-9]{5}\z/D', $candidateState) === 1) {
                            $sqlState = $candidateState;
                        }
                    }
                    if ((is_int($candidateCode) || is_string($candidateCode))
                        && preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/D', (string) $candidateCode) === 1
                        && (int) $candidateCode <= 65535) {
                        $databaseCode = (string) $candidateCode;
                    }
                }
                break;
            }

            $previous = $current->getPrevious();
            if (! $previous instanceof \Throwable) {
                break;
            }
            $current = $previous;
        }

        $originFile = $this->safeDiagnosticOriginFile($exception);
        $originLine = $this->safeDiagnosticOriginLine($exception->getLine(), $originFile);

        return implode('|', [
            'WPCG_TEST_DIAG_V1',
            'test='.$testId,
            'step=phpunit_on_not_successful_test',
            'exception_class='.$class,
            'sqlstate='.$sqlState,
            'db_code='.$databaseCode,
            'origin_file='.$originFile,
            'origin_line='.$originLine,
        ]);
    }

    private function safeDiagnosticOriginFile(\Throwable $exception): string
    {
        $file = $exception->getFile();
        $canonicalFile = realpath($file);
        if ($canonicalFile === false) {
            return 'UNKNOWN';
        }

        $allowedFiles = [
            'tests/Feature/Owner/StagingNextQueueMonitoringTest.php',
            'tests/TestCase.php',
            'app/Console/Commands/Monitoring/RunMonitoring.php',
            'app/Owner/SystemStatus.php',
            'app/Release/StagingWorkerQueuePolicy.php',
            'app/Monitoring/MonitoringEngine.php',
            'app/Monitoring/MonitoringObservation.php',
            'app/Models/MonitoringResult.php',
            'app/Models/MonitoringIncident.php',
            'app/Models/MonitoringTransition.php',
        ];
        foreach ($allowedFiles as $identifier) {
            $canonicalAllowedFile = realpath(base_path($identifier));
            if ($canonicalAllowedFile !== false && hash_equals($canonicalAllowedFile, $canonicalFile)) {
                return $identifier;
            }
        }

        return 'UNKNOWN';
    }

    private function safeDiagnosticOriginLine(mixed $line, string $originFile): string
    {
        if ($originFile === 'UNKNOWN' || ! is_int($line) || $line < 1 || $line > 99999) {
            return 'UNKNOWN';
        }

        return (string) $line;
    }

    private function safeDiagnosticClass(string $class): string
    {
        if ($class === '' || strlen($class) > 192) {
            return 'UNKNOWN';
        }

        foreach (explode('\\', $class) as $segment) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $segment) !== 1) {
                return 'UNKNOWN';
            }
        }

        return $class;
    }

}
