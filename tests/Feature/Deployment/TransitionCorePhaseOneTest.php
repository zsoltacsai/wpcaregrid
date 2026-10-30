<?php

namespace Tests\Feature\Deployment;

use App\Deployment\TransitionAdmission as TransitionAdmissionPolicy;
use App\Deployment\TransitionStatusProjection;
use App\Models\DeploymentTransitionFence;
use App\Models\TransitionAdmission;
use App\Models\TransitionExecution;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class TransitionCorePhaseOneTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $bootId = 'c5e6f6c3-2c66-49cb-a2ad-c76dd3af7f93';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcaregrid-transition-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([
            'transition-status.json',
            '.transition-status.next',
            'claim-start',
            'claim-result-1',
            'claim-result-2',
            'terminal-start',
            'terminal-result-1',
            'terminal-result-2',
        ] as $name) {
            $path = $this->directory.DIRECTORY_SEPARATOR.$name;
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_migration_rollback_refuses_to_drop_durable_fencing_evidence(): void
    {
        $now = CarbonImmutable::now();
        $operationId = '018f0f8e-7b1c-7abc-8abc-123456789012';
        $bootId = 'c5e6f6c3-2c66-49cb-a2ad-c76dd3af7f93';
        $fenceId = DB::table('deployment_transition_fences')->insertGetId([
            'fence_key' => 'global',
            'state' => 'DRAINING',
            'operation_id' => $operationId,
            'generation' => 1,
            'boot_id' => $bootId,
            'journal_seq' => 1,
            'journal_head_hash' => str_repeat('a', 64),
            'evidence_digest' => str_repeat('b', 64),
            'permit_manifest_hash' => str_repeat('c', 64),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $admissionId = DB::table('transition_admissions')->insertGetId([
            'operation_id' => $operationId,
            'generation' => 1,
            'execution_id' => 'execution-rollback-guard',
            'work_id' => 'work-rollback-guard',
            'payload_hash' => str_repeat('d', 64),
            'tenant_id' => 'tenant-1',
            'site_id' => 'site-1',
            'worker_identity' => 'worker-1',
            'effect_steps' => json_encode(['effect-1'], JSON_THROW_ON_ERROR),
            'effect_step_plan_hash' => str_repeat('e', 64),
            'status' => 'ISSUED',
            'issued_at' => $now,
            'expires_at' => $now->addHour(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('transition_executions')->insert([
            'transition_admission_id' => $admissionId,
            'effect_step_index' => 0,
            'step_id' => 'effect-1',
            'idempotency_key' => 'rollback-guard-idempotency-key',
            'payload_hash' => str_repeat('f', 64),
            'status' => 'INTENT',
            'intent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require database_path('migrations/2026_10_30_000001_create_deployment_transition_fences.php');

        try {
            $migration->down();
            self::fail('Rolling back populated fencing tables must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Cannot roll back deployment transition fencing while durable evidence exists.', $exception->getMessage());
        }

        self::assertTrue(Schema::hasTable('deployment_transition_fences'));
        self::assertTrue(Schema::hasTable('transition_admissions'));
        self::assertTrue(Schema::hasTable('transition_executions'));
        self::assertDatabaseHas('deployment_transition_fences', ['id' => $fenceId, 'operation_id' => $operationId]);
        self::assertDatabaseHas('transition_admissions', ['id' => $admissionId, 'execution_id' => 'execution-rollback-guard']);
        self::assertDatabaseHas('transition_executions', ['transition_admission_id' => $admissionId, 'status' => 'INTENT']);
    }

    public function test_projection_accepts_a_complete_canonical_tuple_and_operation_uuid_v7(): void
    {
        $tuple = $this->tuple();
        $this->writeProjection($tuple);

        self::assertSame($tuple, $this->reader()->read());
        self::assertTrue($this->fence($tuple)->hasValidTuple());
        self::assertFalse((new DeploymentTransitionFence($tuple + ['fence_key' => 'global']))->hasValidTuple());
    }

    public function test_projection_rejects_missing_extra_corrupt_and_invalid_tuple_values(): void
    {
        $invalidTuples = [];
        $missing = $this->tuple();
        unset($missing['journal_head_hash']);
        $invalidTuples[] = $missing;

        $extra = $this->tuple();
        $extra['journal_attestation'] = true;
        $invalidTuples[] = $extra;

        foreach ([
            ['state', 'UNKNOWN'],
            ['operation_id', '018f0f8e-7b1c-4ab5-8ab3-123456789012'],
            ['generation', -1],
            ['generation', '2'],
            ['boot_id', 'not-a-boot-id'],
            ['journal_seq', -1],
            ['journal_head_hash', str_repeat('A', 64)],
            ['evidence_digest', 'bad'],
        ] as [$field, $value]) {
            $tuple = $this->tuple();
            $tuple[$field] = $value;
            $invalidTuples[] = $tuple;
        }

        foreach ($invalidTuples as $tuple) {
            $this->writeProjection($tuple);
            try {
                $this->reader()->read();
                self::fail('Invalid tuple must fail closed.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'transition-status.json', '{broken');
        $this->expectException(RuntimeException::class);
        $this->reader()->read();
    }

    public function test_projection_rejects_a_missing_or_stale_boot_id(): void
    {
        $reader = new TransitionStatusProjection(
            $this->statusPath(),
            $this->pendingPath(),
            fn (): string => 'b3f971cf-8dc1-47b7-a532-6ef041fe61a3',
        );
        $this->writeProjection($this->tuple());

        $this->expectException(RuntimeException::class);
        $reader->read();
    }

    public function test_pending_next_marker_blocks_admission_even_when_empty_or_stale(): void
    {
        $tuple = $this->tuple();
        $this->writeProjection($tuple);
        $policy = $this->policy();

        foreach (['', '{old or partial marker'] as $contents) {
            file_put_contents($this->pendingPath(), $contents);
            try {
                $policy->assertOpen($this->fence($tuple));
                self::fail('A pending marker must deny admission regardless of its contents.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            } finally {
                unlink($this->pendingPath());
            }
        }
    }

    public function test_open_requires_the_entire_projection_and_database_tuple_to_match(): void
    {
        $tuple = $this->tuple();
        $this->writeProjection($tuple);
        self::assertSame($tuple, $this->policy()->assertOpen($this->fence($tuple)));

        foreach (['state', 'operation_id', 'generation', 'boot_id', 'journal_seq', 'journal_head_hash', 'evidence_digest'] as $field) {
            $different = $tuple;
            $different[$field] = match ($field) {
                'state' => 'CLOSED',
                'operation_id' => '018f0f8e-7b1c-7abc-8ab3-123456789012',
                'generation', 'journal_seq' => $tuple[$field] + 1,
                'boot_id' => 'b3f971cf-8dc1-47b7-a532-6ef041fe61a3',
                default => str_repeat('b', 64),
            };
            try {
                $this->policy()->assertOpen($this->fence($different));
                self::fail("Tuple mismatch in {$field} must deny admission.");
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        $invalidGeneration = $this->fence($tuple);
        $invalidGeneration->generation = -1;
        self::assertFalse($invalidGeneration->hasValidTuple());

        $corruptGeneration = $this->fence($tuple);
        $corruptGeneration->setRawAttributes(array_replace($corruptGeneration->getAttributes(), ['generation' => 'not-a-number']));
        self::assertFalse($corruptGeneration->hasValidTuple());
    }

    public function test_only_open_state_is_admitted_and_all_contract_states_parse(): void
    {
        foreach (DeploymentTransitionFence::STATES as $state) {
            $tuple = $this->tuple(['state' => $state]);
            $this->writeProjection($tuple);
            self::assertSame($state, $this->reader()->read()['state']);

            if ($state === 'OPEN') {
                self::assertSame($tuple, $this->policy()->assertOpen($this->fence($tuple)));
            } else {
                try {
                    $this->policy()->assertOpen($this->fence($tuple));
                    self::fail("{$state} must deny normal admission.");
                } catch (RuntimeException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function test_admission_policy_refuses_to_run_without_the_shared_lock(): void
    {
        $tuple = $this->tuple();
        $this->writeProjection($tuple);
        $policy = new TransitionAdmissionPolicy($this->reader(), static fn (): bool => false);

        $this->expectException(RuntimeException::class);
        $policy->assertOpen($this->fence($tuple));
    }

    public function test_draining_requires_a_live_permit_bound_to_exact_work_tenant_site_worker_and_step(): void
    {
        $tuple = $this->tuple(['state' => 'DRAINING']);
        $permit = $this->permit($tuple);
        $manifestHash = TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation']);
        $tuple['permit_manifest_hash'] = $manifestHash;
        $this->writeProjection($tuple);

        $this->policy()->assertDrainPermit($this->fence($tuple, $manifestHash), $permit, $this->binding());

        foreach ([
            'execution_id' => 'exec-other',
            'work_id' => 'work-other',
            'payload_hash' => str_repeat('b', 64),
            'tenant_id' => 'tenant-other',
            'site_id' => 'site-other',
            'worker_identity' => 'worker-other',
            'idempotency_key' => 'step-other-key',
        ] as $field => $value) {
            $binding = $this->binding();
            $binding[$field] = $value;
            try {
                $this->policy()->assertDrainPermit($this->fence($tuple, $manifestHash), $permit, $binding);
                self::fail("Permit binding mismatch in {$field} must be denied.");
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        $wrongGeneration = $this->permit($tuple, ['generation' => $tuple['generation'] + 1]);
        try {
            $this->policy()->assertDrainPermit($this->fence($tuple, $manifestHash), $wrongGeneration, $this->binding());
            self::fail('Wrong generation must be denied.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $wrongTuple = $this->tuple(['state' => 'DRAINING', 'journal_seq' => $tuple['journal_seq'] + 1]);
        try {
            $this->policy()->assertDrainPermit($this->fence($wrongTuple, $manifestHash), $permit, $this->binding());
            self::fail('Sequence mismatch must be denied for drain.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }

    public function test_drain_permit_absence_expiry_closed_status_and_out_of_plan_steps_are_denied(): void
    {
        $tuple = $this->tuple(['state' => 'DRAINING']);
        $policy = $this->policy();

        $invalidPermits = [
            new TransitionAdmission,
            $this->permit($tuple, ['execution_id' => 'expired-execution', 'work_id' => 'expired-work', 'expires_at' => now()->subSecond()]),
            $this->permit($tuple, ['execution_id' => 'future-execution', 'work_id' => 'future-work', 'issued_at' => now()->addMinute(), 'expires_at' => now()->addMinutes(2)]),
            $this->permit($tuple, ['execution_id' => 'closed-execution', 'work_id' => 'closed-work', 'status' => 'CLOSED', 'closed_at' => now()]),
        ];
        foreach ($invalidPermits as $permit) {
            $manifestHash = $permit->exists
                ? TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation'])
                : str_repeat('e', 64);
            $projectionTuple = array_replace($tuple, ['permit_manifest_hash' => $manifestHash]);
            $this->writeProjection($projectionTuple);
            try {
                $policy->assertDrainPermit($this->fence($tuple, $manifestHash), $permit, $this->binding());
                self::fail('Missing, expired, or closed permit must be denied.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        $binding = $this->binding();
        $binding['step_index'] = 9;
        $permit = $this->permit($tuple);
        $manifestHash = TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation']);
        $this->writeProjection(array_replace($tuple, ['permit_manifest_hash' => $manifestHash]));
        $this->expectException(RuntimeException::class);
        $policy->assertDrainPermit($this->fence($tuple, $manifestHash), $permit, $binding);
    }

    public function test_drain_step_intent_is_a_permanent_replay_fence(): void
    {
        $tuple = $this->tuple(['state' => 'DRAINING']);
        $permit = $this->permit($tuple);
        $manifestHash = TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation']);
        $this->writeProjection(array_replace($tuple, ['permit_manifest_hash' => $manifestHash]));
        $this->policy()->claimDrainStepIntent($this->fence($tuple, $manifestHash), $permit, $this->binding());

        $this->expectException(RuntimeException::class);
        $this->policy()->assertDrainPermit($this->fence($tuple, $manifestHash), $permit, $this->binding());
    }

    public function test_execution_claim_is_atomic_intent_first_and_rejects_duplicate_claims(): void
    {
        $tuple = $this->tuple(['state' => 'DRAINING']);
        $permit = $this->permit($tuple);
        $manifestHash = TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation']);
        $this->writeProjection(array_replace($tuple, ['permit_manifest_hash' => $manifestHash]));

        $execution = $this->policy()->claimDrainStepIntent($this->fence($tuple, $manifestHash), $permit, $this->binding());

        self::assertSame('INTENT', $execution->status);
        self::assertSame('send-notice', $execution->step_id);
        self::assertSame('step-0-key', $execution->idempotency_key);
        self::assertSame($permit->payload_hash, $execution->payload_hash);

        try {
            $this->policy()->claimDrainStepIntent($this->fence($tuple, $manifestHash), $permit, $this->binding());
            self::fail('A duplicate or racing step claim must fail closed.');
        } catch (RuntimeException) {
            self::assertDatabaseCount('transition_executions', 1);
        }

        $execution->status = 'SUCCEEDED';
        $execution->outcome_at = now();
        $execution->outcome_digest = str_repeat('a', 64);
        $execution->save();
        self::assertSame('SUCCEEDED', $execution->fresh()->status);
    }

    public function test_stale_execution_instances_cannot_overwrite_a_terminal_outcome(): void
    {
        $tuple = $this->tuple(['state' => 'DRAINING']);
        $permit = $this->permit($tuple);
        $manifestHash = TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation']);
        $this->writeProjection(array_replace($tuple, ['permit_manifest_hash' => $manifestHash]));
        $claimed = $this->policy()->claimDrainStepIntent($this->fence($tuple, $manifestHash), $permit, $this->binding());

        $winner = TransitionExecution::query()->findOrFail($claimed->getKey());
        $stale = TransitionExecution::query()->findOrFail($claimed->getKey());

        $winner->status = 'SUCCEEDED';
        $winner->outcome_at = now();
        $winner->outcome_digest = str_repeat('a', 64);
        $winner->save();

        $stale->status = 'FAILED';
        $stale->outcome_at = now();
        $stale->outcome_digest = str_repeat('b', 64);
        try {
            $stale->save();
            self::fail('A stale INTENT instance must not overwrite an already persisted terminal outcome.');
        } catch (RuntimeException $exception) {
            self::assertSame(TransitionExecution::OUTCOME_ALREADY_RECORDED, $exception->getMessage());
            $persisted = $claimed->fresh();
            self::assertSame('SUCCEEDED', $persisted->status);
            self::assertSame(str_repeat('a', 64), $persisted->outcome_digest);
        }
    }

    public function test_concurrent_terminal_outcomes_compare_and_set_the_intent_row(): void
    {
        $tuple = $this->tuple(['state' => 'DRAINING']);
        $permit = $this->permit($tuple);
        $manifestHash = TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation']);
        $this->writeProjection(array_replace($tuple, ['permit_manifest_hash' => $manifestHash]));
        $claimed = $this->policy()->claimDrainStepIntent($this->fence($tuple, $manifestHash), $permit, $this->binding());

        if (DB::connection()->getDriverName() !== 'mysql') {
            $winner = TransitionExecution::query()->findOrFail($claimed->getKey());
            $stale = TransitionExecution::query()->findOrFail($claimed->getKey());
            $winner->status = 'SUCCEEDED';
            $winner->outcome_at = now();
            $winner->outcome_digest = str_repeat('a', 64);
            $winner->save();
            $stale->status = 'FAILED';
            $stale->outcome_at = now();
            $stale->outcome_digest = str_repeat('b', 64);

            try {
                $stale->save();
                self::fail('A stale INTENT instance must not overwrite an already persisted terminal outcome.');
            } catch (RuntimeException $exception) {
                self::assertSame(TransitionExecution::OUTCOME_ALREADY_RECORDED, $exception->getMessage());
                self::assertSame('SUCCEEDED', $claimed->fresh()->status);
                self::assertSame(str_repeat('a', 64), $claimed->fresh()->outcome_digest);
            }

            return;
        }

        self::assertTrue(function_exists('pcntl_fork'), 'MySQL terminal-outcome concurrency coverage requires pcntl.');

        $connection = DB::connection();
        $connection->commit();
        $startPath = $this->directory.DIRECTORY_SEPARATOR.'terminal-start';
        $resultPaths = [
            $this->directory.DIRECTORY_SEPARATOR.'terminal-result-1',
            $this->directory.DIRECTORY_SEPARATOR.'terminal-result-2',
        ];
        $children = [];
        $waited = [];
        $processErrors = [];

        try {
            foreach ([['SUCCEEDED', 'a'], ['FAILED', 'b']] as $index => [$status, $digest]) {
                $resultPath = $resultPaths[$index];
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Could not start a terminal outcome test process.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        $execution = TransitionExecution::query()->findOrFail($claimed->getKey());
                        $deadline = microtime(true) + 30;
                        while (! is_file($startPath) && microtime(true) < $deadline) {
                            usleep(10_000);
                        }
                        if (! is_file($startPath)) {
                            throw new RuntimeException('The concurrent terminal outcome start barrier timed out.');
                        }

                        $execution->status = $status;
                        $execution->outcome_at = now();
                        $execution->outcome_digest = str_repeat($digest, 64);
                        $execution->save();
                        file_put_contents($resultPath, 'SAVED:'.$status, LOCK_EX);
                        exit(0);
                    } catch (RuntimeException $exception) {
                        if ($exception->getMessage() === TransitionExecution::OUTCOME_ALREADY_RECORDED) {
                            file_put_contents($resultPath, 'DENIED', LOCK_EX);
                            exit(0);
                        }
                        file_put_contents($resultPath, 'ERROR:RuntimeException:'.str_replace(["\r", "\n"], ' ', $exception->getMessage()), LOCK_EX);
                        exit(1);
                    } catch (Throwable $exception) {
                        file_put_contents($resultPath, 'ERROR:'.get_class($exception).':'.str_replace(["\r", "\n"], ' ', $exception->getMessage()), LOCK_EX);
                        exit(1);
                    }
                }

                $children[] = $pid;
            }

            file_put_contents($startPath, 'go', LOCK_EX);
            foreach ($children as $pid) {
                $status = 0;
                if (pcntl_waitpid($pid, $status) !== $pid || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    $processErrors[] = 'pid '.$pid.' exit status '.(pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 'abnormal');
                }
                $waited[] = $pid;
            }

            $results = array_map(static fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : 'MISSING_RESULT', $resultPaths);
            if ($processErrors !== []) {
                throw new RuntimeException('A concurrent terminal outcome process did not exit cleanly: '.implode('; ', $processErrors).'; results: '.implode(' | ', $results));
            }
            sort($results);
            self::assertCount(1, array_filter($results, static fn (string $result): bool => str_starts_with($result, 'SAVED:')));
            self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'DENIED')));

            $persisted = TransitionExecution::query()->findOrFail($claimed->getKey());
            self::assertContains($persisted->status, ['SUCCEEDED', 'FAILED']);
            self::assertSame(str_repeat($persisted->status === 'SUCCEEDED' ? 'a' : 'b', 64), $persisted->outcome_digest);
        } finally {
            foreach (array_diff($children, $waited) as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }

            DB::table('transition_executions')->where('id', $claimed->getKey())->delete();
            DB::table('transition_admissions')->where('id', $permit->getKey())->delete();
            DB::table('deployment_transition_fences')->where('id', $this->fence($tuple, $manifestHash)->getKey())->delete();
            if (DB::connection()->transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }

    public function test_execution_model_rejects_terminal_inserts_misbound_steps_and_inactive_permits(): void
    {
        $tuple = $this->tuple(['state' => 'DRAINING']);
        $permit = $this->permit($tuple);
        $manifestHash = TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation']);
        $this->writeProjection(array_replace($tuple, ['permit_manifest_hash' => $manifestHash]));

        $terminal = array_replace($this->executionAttributes($permit), [
            'status' => 'SUCCEEDED',
            'outcome_at' => now(),
            'outcome_digest' => str_repeat('a', 64),
        ]);
        try {
            TransitionExecution::create($terminal);
            self::fail('A terminal outcome cannot be inserted without a prior INTENT.');
        } catch (InvalidArgumentException) {
            self::assertDatabaseCount('transition_executions', 0);
        }

        foreach ([
            ['step_id' => 'other-step'],
            ['idempotency_key' => 'other-key'],
            ['payload_hash' => str_repeat('b', 64)],
        ] as $changes) {
            try {
                TransitionExecution::create(array_replace($this->executionAttributes($permit), $changes));
                self::fail('An execution row must match the exact permit step, idempotency key and payload.');
            } catch (InvalidArgumentException) {
                self::assertDatabaseCount('transition_executions', 0);
            }

            try {
                $this->policy()->claimDrainStepIntent(
                    $this->fence($tuple, $manifestHash),
                    $permit,
                    array_replace($this->binding(), $changes),
                );
                self::fail('The atomic claim must reject a mismatched permit step binding.');
            } catch (RuntimeException) {
                self::assertDatabaseCount('transition_executions', 0);
            }
        }

        foreach ([
            $this->permit($tuple, ['execution_id' => 'expired-execution', 'work_id' => 'expired-work', 'expires_at' => now()->subSecond()]),
            $this->permit($tuple, ['execution_id' => 'closed-execution', 'work_id' => 'closed-work', 'status' => 'CLOSED', 'closed_at' => now()]),
        ] as $inactivePermit) {
            try {
                TransitionExecution::create($this->executionAttributes($inactivePermit));
                self::fail('A new INTENT cannot be inserted for an expired or closed permit.');
            } catch (InvalidArgumentException) {
                self::assertDatabaseCount('transition_executions', 0);
            }

            $manifestHash = TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation']);
            $this->writeProjection(array_replace($tuple, ['permit_manifest_hash' => $manifestHash]));
            try {
                $this->policy()->claimDrainStepIntent(
                    $this->fence($tuple, $manifestHash),
                    $inactivePermit,
                    array_replace($this->binding(), [
                        'execution_id' => $inactivePermit->execution_id,
                        'work_id' => $inactivePermit->work_id,
                    ]),
                );
                self::fail('An expired or closed permit cannot claim a new INTENT.');
            } catch (RuntimeException) {
                self::assertDatabaseCount('transition_executions', 0);
            }
        }
    }

    public function test_concurrent_duplicate_intent_claims_fail_closed(): void
    {
        $tuple = $this->tuple(['state' => 'DRAINING']);
        $permit = $this->permit($tuple);
        $manifestHash = TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation']);
        $this->writeProjection(array_replace($tuple, ['permit_manifest_hash' => $manifestHash]));
        $fence = $this->fence($tuple, $manifestHash);

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->policy()->claimDrainStepIntent($fence, $permit, $this->binding());
            try {
                $this->policy()->claimDrainStepIntent($fence, $permit, $this->binding());
                self::fail('A duplicate step claim must fail closed.');
            } catch (RuntimeException) {
                self::assertDatabaseCount('transition_executions', 1);
            }

            return;
        }

        self::assertTrue(function_exists('pcntl_fork'), 'MySQL process-concurrency coverage requires pcntl.');

        $connection = DB::connection();
        $connection->commit();
        $startPath = $this->directory.DIRECTORY_SEPARATOR.'claim-start';
        $resultPaths = [
            $this->directory.DIRECTORY_SEPARATOR.'claim-result-1',
            $this->directory.DIRECTORY_SEPARATOR.'claim-result-2',
        ];
        $children = [];
        $waited = [];

        try {
            foreach ($resultPaths as $resultPath) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Could not start the duplicate claim test process.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        $deadline = microtime(true) + 30;
                        while (! is_file($startPath) && microtime(true) < $deadline) {
                            usleep(10_000);
                        }
                        if (! is_file($startPath)) {
                            throw new RuntimeException('The concurrent claim start barrier timed out.');
                        }

                        $childFence = DeploymentTransitionFence::query()->whereKey($fence->getKey())->firstOrFail();
                        $childPermit = TransitionAdmission::query()->whereKey($permit->getKey())->firstOrFail();
                        $this->policy()->claimDrainStepIntent($childFence, $childPermit, $this->binding());
                        file_put_contents($resultPath, 'CLAIMED', LOCK_EX);
                        exit(0);
                    } catch (RuntimeException) {
                        file_put_contents($resultPath, 'DENIED', LOCK_EX);
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($resultPath, 'ERROR:'.get_class($exception), LOCK_EX);
                        exit(1);
                    }
                }

                $children[] = $pid;
            }

            file_put_contents($startPath, 'go', LOCK_EX);
            foreach ($children as $pid) {
                $status = 0;
                if (pcntl_waitpid($pid, $status) !== $pid || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    throw new RuntimeException('A concurrent claim test process did not exit cleanly.');
                }
                $waited[] = $pid;
            }

            $results = array_map(static fn (string $path): string => (string) file_get_contents($path), $resultPaths);
            sort($results);
            self::assertSame(['CLAIMED', 'DENIED'], $results);
            self::assertDatabaseCount('transition_executions', 1);
        } finally {
            foreach (array_diff($children, $waited) as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }

            DB::table('transition_executions')->where('transition_admission_id', $permit->getKey())->delete();
            DB::table('transition_admissions')->where('id', $permit->getKey())->delete();
            DB::table('deployment_transition_fences')->where('id', $fence->getKey())->delete();
            if (DB::connection()->transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }

    public function test_drain_manifest_must_match_the_projection_database_and_persisted_permit_set(): void
    {
        $tuple = $this->tuple(['state' => 'DRAINING']);
        $permit = $this->permit($tuple);
        $manifestHash = TransitionAdmission::manifestHashForScope($tuple['operation_id'], $tuple['generation']);
        $this->writeProjection(array_replace($tuple, ['permit_manifest_hash' => $manifestHash]));

        $this->expectException(RuntimeException::class);
        $this->policy()->assertDrainPermit(
            $this->fence($tuple, str_repeat('f', 64)),
            $permit,
            $this->binding(),
        );
    }

    public function test_permit_requires_a_finite_hashed_plan_and_immutable_scope(): void
    {
        $tuple = $this->tuple(['state' => 'DRAINING']);
        $permit = $this->permit($tuple);
        $permit->site_id = 'substituted-site';

        $this->expectException(InvalidArgumentException::class);
        $permit->save();
    }

    public function test_non_open_states_and_db_projection_mismatches_fail_without_fallback(): void
    {
        $tuple = $this->tuple(['state' => 'CLOSED']);
        $this->writeProjection($tuple);

        $this->expectException(RuntimeException::class);
        $this->policy()->assertOpen($this->fence($tuple));
    }

    /** @param array<string, mixed> $changes @return array<string, int|string> */
    private function tuple(array $changes = []): array
    {
        $tuple = array_replace([
            'state' => 'OPEN',
            'operation_id' => '018f0f8e-7b1c-7abc-8ab3-123456789012',
            'generation' => 2,
            'boot_id' => $this->bootId,
            'journal_seq' => 11,
            'journal_head_hash' => str_repeat('a', 64),
            'evidence_digest' => str_repeat('c', 64),
        ], $changes);

        if ($tuple['state'] === 'DRAINING' && ! isset($tuple['permit_manifest_hash'])) {
            $tuple['permit_manifest_hash'] = str_repeat('e', 64);
        }

        return $tuple;
    }

    /** @param array<string, mixed> $tuple */
    private function fence(array $tuple, ?string $manifestHash = null): DeploymentTransitionFence
    {
        $attributes = array_intersect_key($tuple, array_flip([
            'state', 'operation_id', 'generation', 'boot_id', 'journal_seq', 'journal_head_hash', 'evidence_digest',
        ]));

        return DeploymentTransitionFence::query()->updateOrCreate(
            ['fence_key' => DeploymentTransitionFence::GLOBAL_KEY],
            $attributes + ['permit_manifest_hash' => $manifestHash],
        );
    }

    private function reader(): TransitionStatusProjection
    {
        return new TransitionStatusProjection($this->statusPath(), $this->pendingPath(), fn (): string => $this->bootId);
    }

    private function policy(): TransitionAdmissionPolicy
    {
        return new TransitionAdmissionPolicy($this->reader(), static fn (): bool => true);
    }

    /** @param array<string, mixed> $tuple */
    private function writeProjection(array $tuple): void
    {
        file_put_contents($this->statusPath(), json_encode($tuple, JSON_THROW_ON_ERROR));
    }

    private function statusPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'transition-status.json';
    }

    private function pendingPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'.transition-status.next';
    }

    /** @param array<string, mixed> $overrides */
    private function permit(array $tuple, array $overrides = []): TransitionAdmission
    {
        $steps = [
            ['idempotency_key' => 'step-0-key', 'step_id' => 'send-notice', 'step_index' => 0],
            ['idempotency_key' => 'step-1-key', 'step_id' => 'record-result', 'step_index' => 1],
        ];
        $canonicalSteps = array_map(static fn (array $step): array => [
            'step_index' => $step['step_index'],
            'step_id' => $step['step_id'],
            'idempotency_key' => $step['idempotency_key'],
        ], $steps);
        $plan = json_encode($canonicalSteps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return TransitionAdmission::create(array_replace([
            'operation_id' => $tuple['operation_id'],
            'generation' => $tuple['generation'],
            'execution_id' => 'execution-1',
            'work_id' => 'work-1',
            'payload_hash' => str_repeat('d', 64),
            'tenant_id' => 'tenant-1',
            'site_id' => 'site-1',
            'worker_identity' => 'worker-1',
            'effect_steps' => $steps,
            'effect_step_plan_hash' => hash('sha256', $plan),
            'status' => 'ISSUED',
            'issued_at' => now()->subMinute(),
            'expires_at' => now()->addMinute(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function executionAttributes(TransitionAdmission $permit, array $overrides = []): array
    {
        return array_replace([
            'transition_admission_id' => $permit->getKey(),
            'effect_step_index' => 0,
            'step_id' => 'send-notice',
            'idempotency_key' => 'step-0-key',
            'payload_hash' => $permit->payload_hash,
            'status' => 'INTENT',
            'intent_at' => now(),
        ], $overrides);
    }

    /** @return array{execution_id:string,work_id:string,payload_hash:string,tenant_id:string,site_id:string,worker_identity:string,step_index:int,step_id:string,idempotency_key:string} */
    private function binding(): array
    {
        return [
            'execution_id' => 'execution-1',
            'work_id' => 'work-1',
            'payload_hash' => str_repeat('d', 64),
            'tenant_id' => 'tenant-1',
            'site_id' => 'site-1',
            'worker_identity' => 'worker-1',
            'step_index' => 0,
            'step_id' => 'send-notice',
            'idempotency_key' => 'step-0-key',
        ];
    }
}
