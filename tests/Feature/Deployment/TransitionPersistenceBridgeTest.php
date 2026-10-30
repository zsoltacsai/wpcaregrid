<?php

namespace Tests\Feature\Deployment;

use App\Deployment\TransitionPersistenceBridge;
use App\Models\DeploymentTransitionFence;
use App\Models\TransitionBridgeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class TransitionPersistenceBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const BOOT_ID = 'c5e6f6c3-2c66-49cb-a2ad-c76dd3af7f93';

    private const OPEN_OPERATION = '018f0f8e-7b1c-7abc-8abc-123456789012';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcaregrid-bridge-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (['bridge-start', 'bridge-result-1', 'bridge-result-2'] as $name) {
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

    private function bridge(?string $bootId = null): TransitionPersistenceBridge
    {
        return new TransitionPersistenceBridge(fn (): string => $bootId ?? self::BOOT_ID);
    }

    /** @return array<string, int|string> */
    private function openTuple(): array
    {
        return [
            'state' => 'OPEN',
            'operation_id' => self::OPEN_OPERATION,
            'generation' => 0,
            'boot_id' => self::BOOT_ID,
            'journal_seq' => 0,
            'journal_head_hash' => str_repeat('a', 64),
            'evidence_digest' => str_repeat('b', 64),
        ];
    }

    /** @param array<string, int|string> $tuple */
    private function seedFence(array $tuple): void
    {
        DB::table('deployment_transition_fences')->insert([
            'fence_key' => DeploymentTransitionFence::GLOBAL_KEY,
            ...$tuple,
            'permit_manifest_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, int|string> $source @return array<string, int|string> */
    private function holdTarget(array $source): array
    {
        return [
            'state' => 'HOLD_REQUESTED',
            'operation_id' => '018f0f8e-7b1c-7abc-8abc-123456789013',
            'generation' => 1,
            'boot_id' => self::BOOT_ID,
            'journal_seq' => $source['journal_seq'] + 1,
            'journal_head_hash' => str_repeat('c', 64),
            'evidence_digest' => str_repeat('d', 64),
        ];
    }

    /** @param array<string, int|string> $source @param array<string, int|string> $target @param array<string, mixed>|null $release @return array<string, mixed> */
    private function request(array $source, array $target, ?array $release = null, ?string $requestId = null): array
    {
        $request = [
            'protocol_version' => TransitionPersistenceBridge::PROTOCOL_VERSION,
            'action' => 'transition',
            'request_id' => $requestId ?? '018f0f8e-7b1c-7abc-8abc-123456789014',
            'source_tuple' => $source,
            'target_tuple' => $target,
            'permits' => [],
            'release_binding' => $release,
        ];
        $request['request_digest'] = TransitionPersistenceBridge::digestForRequest($request);

        return $request;
    }

    public function test_transition_and_receipt_commit_atomically_and_exact_replay_returns_receipt(): void
    {
        $source = $this->openTuple();
        $target = $this->holdTarget($source);
        $request = $this->request($source, $target);
        $bridge = $this->bridge();
        $this->seedFence($source);

        $first = $bridge->handle($request);
        self::assertSame('COMMITTED', $first['status']);
        self::assertSame($request['request_id'], $first['receipt']['request_id']);
        self::assertSame($target, $first['receipt']['target_tuple']);
        self::assertSame($target, DeploymentTransitionFence::query()->firstOrFail()->completeTuple());
        self::assertDatabaseHas('transition_bridge_requests', [
            'request_id' => $request['request_id'],
            'request_digest' => $request['request_digest'],
            'action' => 'transition',
            'release_nonce_digest' => null,
        ]);

        self::assertSame(
            TransitionBridgeRequest::canonicalJson($first),
            TransitionBridgeRequest::canonicalJson($bridge->handle($request)),
        );
        self::assertSame(1, TransitionBridgeRequest::query()->count());

        $readback = [
            'protocol_version' => TransitionPersistenceBridge::PROTOCOL_VERSION,
            'action' => 'readback',
            'request_id' => $request['request_id'],
            'request_digest' => $request['request_digest'],
            'source_tuple' => $source,
            'target_tuple' => $target,
        ];
        $verified = $bridge->handle($readback);
        self::assertSame('COMMITTED', $verified['status']);
        self::assertSame($request['request_digest'], $verified['receipt']['request_digest']);
    }

    public function test_request_id_cannot_be_replayed_with_a_different_digest(): void
    {
        $source = $this->openTuple();
        $request = $this->request($source, $this->holdTarget($source));
        $bridge = $this->bridge();
        $this->seedFence($source);
        $bridge->handle($request);

        $changed = $request;
        $changed['target_tuple']['evidence_digest'] = str_repeat('e', 64);
        $changed['request_digest'] = TransitionPersistenceBridge::digestForRequest($changed);

        $this->expectException(RuntimeException::class);
        $bridge->handle($changed);
    }

    public function test_concurrent_mysql_replays_return_one_verified_commit_receipt(): void
    {
        $source = $this->openTuple();
        $target = $this->holdTarget($source);
        $this->seedFence($source);
        $request = $this->request($source, $target, requestId: '018f0f8e-7b1c-7abc-8abc-123456789090');

        if (DB::connection()->getDriverName() !== 'mysql') {
            $bridge = $this->bridge();
            $first = $bridge->handle($request);
            $second = $bridge->handle($request);
            self::assertSame(
                TransitionBridgeRequest::canonicalJson($first),
                TransitionBridgeRequest::canonicalJson($second),
            );
            self::assertDatabaseCount('transition_bridge_requests', 1);

            return;
        }

        self::assertTrue(function_exists('pcntl_fork'), 'MySQL bridge replay concurrency coverage requires pcntl.');
        DB::connection()->commit();
        $startPath = $this->directory.DIRECTORY_SEPARATOR.'bridge-start';
        $resultPaths = [
            $this->directory.DIRECTORY_SEPARATOR.'bridge-result-1',
            $this->directory.DIRECTORY_SEPARATOR.'bridge-result-2',
        ];
        $children = [];
        $waited = [];

        try {
            foreach ($resultPaths as $resultPath) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Could not start a bridge replay test process.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        $deadline = microtime(true) + 30;
                        while (! is_file($startPath) && microtime(true) < $deadline) {
                            usleep(10_000);
                        }
                        if (! is_file($startPath)) {
                            throw new RuntimeException('The bridge replay start barrier timed out.');
                        }

                        $result = $this->bridge()->handle($request);
                        file_put_contents($resultPath, hash('sha256', TransitionBridgeRequest::canonicalJson($result)), LOCK_EX);
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($resultPath, 'ERROR:'.$exception::class, LOCK_EX);
                        exit(1);
                    }
                }
                $children[] = $pid;
            }

            file_put_contents($startPath, 'go', LOCK_EX);
            foreach ($children as $pid) {
                $status = 0;
                if (pcntl_waitpid($pid, $status) !== $pid || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    throw new RuntimeException('A concurrent bridge replay process did not exit cleanly.');
                }
                $waited[] = $pid;
            }

            $resultDigests = array_map(static fn (string $path): string => (string) file_get_contents($path), $resultPaths);
            self::assertSame($resultDigests[0], $resultDigests[1]);
            self::assertSame($target, DeploymentTransitionFence::query()->firstOrFail()->completeTuple());
            self::assertDatabaseCount('transition_bridge_requests', 1);
        } finally {
            foreach (array_diff($children, $waited) as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }

            DB::table('transition_bridge_requests')->where('request_id', $request['request_id'])->delete();
            DB::table('deployment_transition_fences')->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)->delete();
            if (DB::connection()->transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }

    public function test_readback_reports_not_committed_only_when_exact_source_tuple_remains_current(): void
    {
        $source = $this->openTuple();
        $target = $this->holdTarget($source);
        $request = $this->request($source, $target);
        $this->seedFence($source);

        $readback = [
            'protocol_version' => TransitionPersistenceBridge::PROTOCOL_VERSION,
            'action' => 'readback',
            'request_id' => $request['request_id'],
            'source_tuple' => $source,
            'target_tuple' => $target,
        ];
        $readback['request_digest'] = TransitionPersistenceBridge::digestForRequest($readback);

        $result = $this->bridge()->handle($readback);
        self::assertSame('NOT_COMMITTED', $result['status']);
        self::assertSame($source, $result['current_tuple']);
        self::assertDatabaseCount('transition_bridge_requests', 0);
    }

    public function test_invalid_edge_stale_boot_and_nonempty_effect_catalog_fail_closed(): void
    {
        $source = $this->openTuple();
        $target = $this->holdTarget($source);
        $this->seedFence($source);

        $invalidEdge = $target;
        $invalidEdge['state'] = 'DRAINING';
        $invalidEdge['permit_manifest_hash'] = str_repeat('e', 64);
        try {
            $this->bridge()->handle($this->request($source, $invalidEdge));
            self::fail('An invalid state edge must be rejected.');
        } catch (RuntimeException) {
            self::assertDatabaseCount('transition_bridge_requests', 0);
        }

        $validTarget = $this->holdTarget($source);
        $this->expectException(RuntimeException::class);
        $this->bridge('b3f971cf-8dc1-47b7-a532-6ef041fe61a3')->handle($this->request($source, $validTarget));
    }

    public function test_draining_fails_closed_if_any_permit_already_exists_for_the_scope(): void
    {
        $source = [
            'state' => 'CLOSED',
            'operation_id' => '018f0f8e-7b1c-7abc-8abc-123456789013',
            'generation' => 1,
            'boot_id' => self::BOOT_ID,
            'journal_seq' => 3,
            'journal_head_hash' => str_repeat('a', 64),
            'evidence_digest' => str_repeat('b', 64),
        ];
        $target = [
            'state' => 'DRAINING',
            'operation_id' => $source['operation_id'],
            'generation' => $source['generation'],
            'boot_id' => self::BOOT_ID,
            'journal_seq' => 4,
            'journal_head_hash' => str_repeat('c', 64),
            'evidence_digest' => str_repeat('d', 64),
            'permit_manifest_hash' => hash('sha256', TransitionBridgeRequest::canonicalJson([
                'operation_id' => $source['operation_id'],
                'generation' => $source['generation'],
                'cutoff_seq' => $source['journal_seq'],
                'permits' => [],
            ])),
        ];
        $this->seedFence($source);
        DB::table('transition_admissions')->insert([
            'operation_id' => $source['operation_id'],
            'generation' => $source['generation'],
            'execution_id' => 'legacy-effect',
            'work_id' => 'work-1',
            'payload_hash' => str_repeat('e', 64),
            'tenant_id' => 'tenant-1',
            'site_id' => 'site-1',
            'worker_identity' => 'worker-1',
            'effect_steps' => '[]',
            'effect_step_plan_hash' => str_repeat('f', 64),
            'status' => 'ISSUED',
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->bridge()->handle($this->request($source, $target));
    }

    public function test_committed_receipts_are_immutable_and_migration_rollback_refuses_them(): void
    {
        $source = $this->openTuple();
        $target = $this->holdTarget($source);
        $this->seedFence($source);
        $this->bridge()->handle($this->request($source, $target));
        $receipt = TransitionBridgeRequest::query()->firstOrFail();

        try {
            $receipt->action = 'readback';
            $receipt->save();
            self::fail('Committed receipt updates must be rejected.');
        } catch (LogicException $exception) {
            self::assertSame('A committed transition bridge receipt is immutable.', $exception->getMessage());
        }

        try {
            $receipt->delete();
            self::fail('Committed receipt deletes must be rejected.');
        } catch (LogicException $exception) {
            self::assertSame('A committed transition bridge receipt cannot be deleted.', $exception->getMessage());
        }

        $migration = require database_path('migrations/2026_10_30_000002_create_transition_bridge_requests_table.php');
        try {
            $migration->down();
            self::fail('Migration rollback must refuse committed bridge receipts.');
        } catch (RuntimeException $exception) {
            self::assertSame('Cannot roll back committed transition bridge receipts.', $exception->getMessage());
        }

        self::assertTrue(Schema::hasTable('transition_bridge_requests'));
        self::assertDatabaseHas('transition_bridge_requests', ['request_id' => $receipt->request_id]);
    }

    public function test_release_nonce_is_stored_only_as_a_digest_and_cannot_be_reused(): void
    {
        $bridge = $this->bridge();
        $source = $this->openTuple();
        $this->seedFence($source);

        $chain = [
            ['HOLD_REQUESTED', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
            ['CLOSING', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
            ['CLOSED', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
            ['DRAINING', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
            ['QUIESCED', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
            ['READY_PENDING_OWNER', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
        ];

        foreach ($chain as [$state, $operationId, $generation]) {
            $target = [
                'state' => $state,
                'operation_id' => $operationId,
                'generation' => $generation,
                'boot_id' => self::BOOT_ID,
                'journal_seq' => $source['journal_seq'] + 1,
                'journal_head_hash' => hash('sha256', $state.$source['journal_seq']),
                'evidence_digest' => hash('sha256', 'evidence-'.$state.$source['journal_seq']),
            ];
            if ($state === 'DRAINING') {
                $target['permit_manifest_hash'] = hash('sha256', TransitionBridgeRequest::canonicalJson([
                    'operation_id' => $operationId,
                    'generation' => $generation,
                    'cutoff_seq' => $source['journal_seq'],
                    'permits' => [],
                ]));
            }

            $bridge->handle($this->request($source, $target, requestId: sprintf('018f0f8e-7b1c-7abc-8abc-%012d', $target['journal_seq'] + 20)));
            $source = $target;
        }

        $nonceDigest = hash('sha256', 'one-use-owner-nonce');
        $release = [
            'approval_ref' => 'owner-approval-228',
            'expected_journal_seq' => $source['journal_seq'] + 1,
            'generation' => $source['generation'],
            'host_id' => 'staging-host-1',
            'nonce_digest' => $nonceDigest,
            'operation_id' => $source['operation_id'],
            'php_profile' => '8.4',
            'rollback_evidence' => str_repeat('f', 64),
            'source_sha' => str_repeat('1', 40),
            'target_sha' => str_repeat('2', 40),
        ];
        $openTarget = [
            'state' => 'OPEN',
            'operation_id' => $source['operation_id'],
            'generation' => $source['generation'],
            'boot_id' => self::BOOT_ID,
            'journal_seq' => $source['journal_seq'] + 1,
            'journal_head_hash' => str_repeat('3', 64),
            'evidence_digest' => str_repeat('4', 64),
        ];
        $releaseRequest = $this->request($source, $openTarget, $release, '018f0f8e-7b1c-7abc-8abc-123456789099');
        $bridge->handle($releaseRequest);
        self::assertDatabaseHas('transition_bridge_requests', ['release_nonce_digest' => $nonceDigest]);
        self::assertStringNotContainsString('one-use-owner-nonce', DB::table('transition_bridge_requests')->value('receipt_payload'));

        $source = $openTarget;
        $secondChain = [
            ['HOLD_REQUESTED', '018f0f8e-7b1c-7abc-8abc-123456789015', 2],
            ['CLOSING', '018f0f8e-7b1c-7abc-8abc-123456789015', 2],
            ['CLOSED', '018f0f8e-7b1c-7abc-8abc-123456789015', 2],
            ['DRAINING', '018f0f8e-7b1c-7abc-8abc-123456789015', 2],
            ['QUIESCED', '018f0f8e-7b1c-7abc-8abc-123456789015', 2],
            ['READY_PENDING_OWNER', '018f0f8e-7b1c-7abc-8abc-123456789015', 2],
        ];
        foreach ($secondChain as [$state, $operationId, $generation]) {
            $target = [
                'state' => $state,
                'operation_id' => $operationId,
                'generation' => $generation,
                'boot_id' => self::BOOT_ID,
                'journal_seq' => $source['journal_seq'] + 1,
                'journal_head_hash' => hash('sha256', 'second-'.$state.$source['journal_seq']),
                'evidence_digest' => hash('sha256', 'second-evidence-'.$state.$source['journal_seq']),
            ];
            if ($state === 'DRAINING') {
                $target['permit_manifest_hash'] = hash('sha256', TransitionBridgeRequest::canonicalJson([
                    'operation_id' => $operationId,
                    'generation' => $generation,
                    'cutoff_seq' => $source['journal_seq'],
                    'permits' => [],
                ]));
            }
            $bridge->handle($this->request($source, $target, requestId: sprintf('018f0f8e-7b1c-7abc-8abc-%012d', $target['journal_seq'] + 120)));
            $source = $target;
        }
        $secondOpen = [
            'state' => 'OPEN',
            'operation_id' => $source['operation_id'],
            'generation' => $source['generation'],
            'boot_id' => self::BOOT_ID,
            'journal_seq' => $source['journal_seq'] + 1,
            'journal_head_hash' => str_repeat('5', 64),
            'evidence_digest' => str_repeat('6', 64),
        ];
        $replayedRelease = [...$release, 'expected_journal_seq' => $secondOpen['journal_seq'], 'operation_id' => $source['operation_id'], 'generation' => $source['generation']];
        $this->expectException(RuntimeException::class);
        $bridge->handle($this->request($source, $secondOpen, $replayedRelease, '018f0f8e-7b1c-7abc-8abc-123456789098'));
    }
}
