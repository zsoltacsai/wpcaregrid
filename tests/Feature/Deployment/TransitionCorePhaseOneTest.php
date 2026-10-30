<?php

namespace Tests\Feature\Deployment;

use App\Deployment\TransitionAdmission as TransitionAdmissionPolicy;
use App\Deployment\TransitionStatusProjection;
use App\Models\DeploymentTransitionFence;
use App\Models\TransitionAdmission;
use App\Models\TransitionExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

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
        foreach (['transition-status.json', '.transition-status.next'] as $name) {
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
        TransitionExecution::create([
            'transition_admission_id' => $permit->id,
            'effect_step_index' => 0,
            'step_id' => 'send-notice',
            'idempotency_key' => 'step-0-key',
            'payload_hash' => $permit->payload_hash,
            'status' => 'INTENT',
            'intent_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->policy()->assertDrainPermit($this->fence($tuple, $manifestHash), $permit, $this->binding());
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
            ['step_index' => 0, 'step_id' => 'send-notice', 'idempotency_key' => 'step-0-key'],
            ['step_index' => 1, 'step_id' => 'record-result', 'idempotency_key' => 'step-1-key'],
        ];
        $plan = json_encode($steps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

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

    /** @return array{execution_id:string,work_id:string,payload_hash:string,tenant_id:string,site_id:string,worker_identity:string,step_index:int,idempotency_key:string} */
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
            'idempotency_key' => 'step-0-key',
        ];
    }
}
