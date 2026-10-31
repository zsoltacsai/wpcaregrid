<?php

namespace Tests\Feature\Deployment;

use App\Deployment\TransitionPersistenceBridge;
use App\Models\DeploymentTransitionFence;
use App\Models\TransitionBridgeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\TestCase;

class TransitionPersistenceBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const BOOT_ID = 'c5e6f6c3-2c66-49cb-a2ad-c76dd3af7f93';

    private const OPEN_OPERATION = '018f0f8e-7b1c-7abc-8abc-123456789012';

    /** @return array<string, mixed> */
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

    /** @param array<string, mixed> $tuple */
    private function seedFence(array $tuple): void
    {
        DB::table('deployment_transition_fences')->insert([
            'fence_key' => DeploymentTransitionFence::GLOBAL_KEY,
            ...$tuple,
            'permit_manifest_hash' => $tuple['permit_manifest_hash'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
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

    /** @param array<string, mixed> $source @param array<string, mixed> $target
     * @param  array<string, mixed>|null  $release  @return array<string, mixed>
     */
    private function envelope(array $source, array $target, ?array $release = null, ?string $requestId = null): array
    {
        $requestId ??= '018f0f8e-7b1c-7abc-8abc-123456789014';
        $payload = [
            'source' => $source,
            'target' => $target,
            'release' => $release,
            'details' => [
                'rpc_request_id' => $requestId,
                'rpc_request_digest' => str_repeat('0', 64),
            ],
        ];
        $digest = TransitionPersistenceBridge::digestForRequest(
            1,
            $requestId,
            'compare_and_set',
            $payload,
        );
        $payload['details']['rpc_request_digest'] = $digest;

        return [
            'version' => 1,
            'request_id' => $requestId,
            'request_digest' => $digest,
            'operation' => 'compare_and_set',
            'payload' => $payload,
        ];
    }

    private function bridge(?string $bootId = null): TransitionPersistenceBridge
    {
        return new TransitionPersistenceBridge(fn (): string => $bootId ?? self::BOOT_ID);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function rpc(string $operation, array $payload, string $requestId): array
    {
        return [
            'version' => 1,
            'request_id' => $requestId,
            'request_digest' => TransitionPersistenceBridge::digestForRequest(1, $requestId, $operation, $payload),
            'operation' => $operation,
            'payload' => $payload,
        ];
    }

    public function test_empty_json_objects_keep_object_canonicalization_and_read_operations_match_wire_contract(): void
    {
        $source = $this->openTuple();
        $this->seedFence($source);
        $id = '018f0f8e-7b1c-7abc-8abc-123456789021';
        self::assertSame(
            hash('sha256', "{\"operation\":\"read_tuple\",\"payload\":{},\"request_id\":\"{$id}\",\"version\":1}\n"),
            TransitionPersistenceBridge::digestForRequest(1, $id, 'read_tuple', []),
        );

        $tupleResponse = $this->bridge()->handleRpc($this->rpc('read_tuple', [], $id));
        self::assertSame('OK', $tupleResponse['status']);
        self::assertSame($source, $tupleResponse['result']);

        $manifestResponse = $this->bridge()->handleRpc($this->rpc(
            'read_permit_manifest_hash',
            [],
            '018f0f8e-7b1c-7abc-8abc-123456789022',
        ));
        self::assertNull($manifestResponse['result']);

        $target = $this->holdTarget($source);
        $preflight = $this->bridge()->handleRpc($this->rpc(
            'verify_transition_preconditions',
            ['source' => $source, 'target' => $target, 'details' => [], 'permits' => []],
            '018f0f8e-7b1c-7abc-8abc-123456789023',
        ));
        self::assertSame(['valid' => true], $preflight['result']);
    }

    public function test_permit_manifest_and_release_preflight_are_exact_and_read_only(): void
    {
        $closed = [
            'state' => 'CLOSED',
            'operation_id' => '018f0f8e-7b1c-7abc-8abc-123456789013',
            'generation' => 1,
            'boot_id' => self::BOOT_ID,
            'journal_seq' => 3,
            'journal_head_hash' => str_repeat('a', 64),
            'evidence_digest' => str_repeat('b', 64),
        ];
        $this->seedFence($closed);
        $manifest = hash('sha256', TransitionBridgeRequest::canonicalJson([
            'operation_id' => $closed['operation_id'],
            'generation' => $closed['generation'],
            'cutoff_seq' => $closed['journal_seq'],
            'permits' => [],
        ]));
        $validated = $this->bridge()->handleRpc($this->rpc(
            'validate_permit_manifest',
            ['source' => $closed, 'permits' => [], 'digest' => $manifest],
            '018f0f8e-7b1c-7abc-8abc-123456789024',
        ));
        self::assertSame(['valid' => true], $validated['result']);
        self::assertDatabaseCount('transition_bridge_requests', 0);

        DB::table('deployment_transition_fences')->where('fence_key', 'global')->update([
            'state' => 'READY_PENDING_OWNER',
            'journal_seq' => 8,
            'permit_manifest_hash' => null,
        ]);
        $ready = [
            ...$closed,
            'state' => 'READY_PENDING_OWNER',
            'journal_seq' => 8,
        ];
        $nonceDigest = hash('sha256', 'release-nonce-test');
        $binding = [
            'approval_ref' => 'owner-approval-test',
            'expected_journal_seq' => 9,
            'generation' => 1,
            'host_id' => 'test-host',
            'nonce_digest' => $nonceDigest,
            'operation_id' => $ready['operation_id'],
            'php_profile' => '8.4',
            'rollback_evidence' => str_repeat('f', 64),
            'source_sha' => str_repeat('1', 40),
            'target_sha' => str_repeat('2', 40),
        ];
        $validatedRelease = $this->bridge()->handleRpc($this->rpc(
            'validate_release',
            ['authorization' => $binding, 'source' => $ready, 'nonce_digest' => $nonceDigest],
            '018f0f8e-7b1c-7abc-8abc-123456789025',
        ));
        self::assertSame(['valid' => true], $validatedRelease['result']);
    }

    public function test_unresolved_drain_effect_query_is_scoped_and_fails_closed_on_unknown(): void
    {
        $source = [
            'state' => 'DRAINING',
            'operation_id' => '018f0f8e-7b1c-7abc-8abc-123456789013',
            'generation' => 1,
            'boot_id' => self::BOOT_ID,
            'journal_seq' => 4,
            'journal_head_hash' => str_repeat('a', 64),
            'evidence_digest' => str_repeat('b', 64),
            'permit_manifest_hash' => str_repeat('c', 64),
        ];
        $this->seedFence($source);
        self::assertSame(
            $source['permit_manifest_hash'],
            $this->bridge()->handleRpc($this->rpc('read_permit_manifest_hash', [], '018f0f8e-7b1c-7abc-8abc-123456789028'))['result'],
        );
        $payload = ['operation_id' => $source['operation_id'], 'generation' => $source['generation']];
        $request = $this->rpc('assert_no_unresolved_drain_effects', $payload, '018f0f8e-7b1c-7abc-8abc-123456789026');
        self::assertSame(['clear' => true], $this->bridge()->handleRpc($request)['result']);

        $admissionId = DB::table('transition_admissions')->insertGetId([
            'operation_id' => $source['operation_id'],
            'generation' => $source['generation'],
            'execution_id' => 'exec-unknown',
            'work_id' => 'work-unknown',
            'payload_hash' => str_repeat('d', 64),
            'tenant_id' => 'tenant-test',
            'site_id' => 'site-test',
            'worker_identity' => 'worker-test',
            'effect_steps' => '[]',
            'effect_step_plan_hash' => str_repeat('e', 64),
            'status' => 'ISSUED',
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transition_executions')->insert([
            'transition_admission_id' => $admissionId,
            'effect_step_index' => 0,
            'step_id' => 'step-1',
            'idempotency_key' => 'unknown-effect-test',
            'payload_hash' => str_repeat('f', 64),
            'status' => 'UNKNOWN',
            'intent_at' => now(),
            'outcome_at' => null,
            'outcome_digest' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request['request_id'] = '018f0f8e-7b1c-7abc-8abc-123456789027';
        $request['request_digest'] = TransitionPersistenceBridge::digestForRequest(1, $request['request_id'], $request['operation'], $payload);
        self::assertSame(['clear' => false], $this->bridge()->handleRpc($request)['result']);
    }

    public function test_typed_rpc_cas_is_atomic_and_exact_replay_returns_its_receipt(): void
    {
        $source = $this->openTuple();
        $target = $this->holdTarget($source);
        $request = $this->envelope($source, $target);
        $this->seedFence($source);

        $first = $this->bridge()->handleRpc($request);
        self::assertSame('COMMITTED', $first['status']);
        self::assertSame($request['request_id'], $first['result']['request_id']);
        self::assertSame($request['request_digest'], $first['result']['request_digest']);
        self::assertSame($target, $first['result']['target']);
        self::assertSame($target, DeploymentTransitionFence::query()->firstOrFail()->completeTuple());
        self::assertDatabaseHas('transition_bridge_requests', [
            'request_id' => $request['request_id'],
            'request_digest' => $request['request_digest'],
            'action' => 'compare_and_set',
            'release_nonce_digest' => null,
        ]);

        self::assertSame(
            TransitionBridgeRequest::canonicalJson($first),
            TransitionBridgeRequest::canonicalJson($this->bridge()->handleRpc($request)),
        );
        self::assertSame(1, TransitionBridgeRequest::query()->count());
    }

    public function test_journal_hash_replay_with_same_request_digest_is_rejected_by_full_tuple_receipt(): void
    {
        $source = $this->openTuple();
        $target = $this->holdTarget($source);
        $request = $this->envelope($source, $target);
        $this->seedFence($source);
        $this->bridge()->handleRpc($request);

        $replay = $request;
        $replay['payload']['target']['journal_head_hash'] = str_repeat('e', 64);
        self::assertSame($request['request_digest'], TransitionPersistenceBridge::digestForRequest(
            1,
            $request['request_id'],
            $request['operation'],
            $replay['payload'],
        ));

        $this->expectException(RuntimeException::class);
        $this->bridge()->handleRpc($replay);
    }

    public function test_envelope_rejects_invalid_digest_and_unsupported_operation(): void
    {
        $source = $this->openTuple();
        $request = $this->envelope($source, $this->holdTarget($source));
        $request['request_digest'] = str_repeat('f', 64);
        try {
            $this->bridge()->handleRpc($request);
            self::fail('A mismatched request digest must be refused.');
        } catch (RuntimeException $exception) {
            self::assertSame('The transition RPC digest does not match the canonical envelope projection.', $exception->getMessage());
        }

        $request['operation'] = 'execute_sql';
        $this->expectException(RuntimeException::class);
        $this->bridge()->handleRpc($request);
    }

    public function test_lost_cas_receipt_can_only_be_read_back_by_exact_identity(): void
    {
        $source = $this->openTuple();
        $target = $this->holdTarget($source);
        $request = $this->envelope($source, $target);
        $this->seedFence($source);
        $this->bridge()->handleRpc($request);

        $read = [
            'version' => 1,
            'request_id' => '018f0f8e-7b1c-7abc-8abc-123456789015',
            'operation' => 'read_transition_receipt',
            'payload' => [
                'request_id' => $request['request_id'],
                'request_digest' => $request['request_digest'],
            ],
        ];
        $read['request_digest'] = TransitionPersistenceBridge::digestForRequest(
            1,
            $read['request_id'],
            $read['operation'],
            $read['payload'],
        );
        $response = $this->bridge()->handleRpc($read);

        self::assertSame('OK', $response['status']);
        self::assertTrue($response['result']['committed']);
        self::assertSame(TransitionBridgeRequest::canonicalJson($target), TransitionBridgeRequest::canonicalJson($response['result']['target']));
        self::assertSame(TransitionBridgeRequest::canonicalJson($source), TransitionBridgeRequest::canonicalJson($response['result']['source']));
    }

    public function test_release_nonce_is_recorded_once_and_only_as_a_digest(): void
    {
        $bridge = $this->bridge();
        $source = $this->openTuple();
        $this->seedFence($source);

        foreach ([
            ['HOLD_REQUESTED', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
            ['CLOSING', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
            ['CLOSED', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
            ['DRAINING', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
            ['QUIESCED', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
            ['READY_PENDING_OWNER', '018f0f8e-7b1c-7abc-8abc-123456789013', 1],
        ] as [$state, $operationId, $generation]) {
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

            $bridge->handleRpc($this->envelope($source, $target, requestId: sprintf('018f0f8e-7b1c-7abc-8abc-%012d', $target['journal_seq'] + 20)));
            $source = $target;
        }

        $nonceDigest = hash('sha256', 'one-use-owner-nonce');
        $release = [
            'approval_ref' => 'owner-approval-test',
            'expected_journal_seq' => $source['journal_seq'] + 1,
            'generation' => $source['generation'],
            'host_id' => 'test-host',
            'nonce_digest' => $nonceDigest,
            'operation_id' => $source['operation_id'],
            'php_profile' => '8.4',
            'rollback_evidence' => str_repeat('f', 64),
            'source_sha' => str_repeat('1', 40),
            'target_sha' => str_repeat('2', 40),
        ];
        $target = [
            'state' => 'OPEN',
            'operation_id' => $source['operation_id'],
            'generation' => $source['generation'],
            'boot_id' => self::BOOT_ID,
            'journal_seq' => $source['journal_seq'] + 1,
            'journal_head_hash' => str_repeat('3', 64),
            'evidence_digest' => str_repeat('4', 64),
        ];
        $request = $this->envelope($source, $target, $release, '018f0f8e-7b1c-7abc-8abc-123456789099');
        $bridge->handleRpc($request);
        self::assertDatabaseHas('transition_bridge_requests', ['release_nonce_digest' => $nonceDigest]);
        self::assertStringNotContainsString('one-use-owner-nonce', DB::table('transition_bridge_requests')->value('receipt_payload'));

        $nonceRead = [
            'version' => 1,
            'request_id' => '018f0f8e-7b1c-7abc-8abc-123456789098',
            'operation' => 'nonce_consumed',
            'payload' => ['nonce_digest' => $nonceDigest],
        ];
        $nonceRead['request_digest'] = TransitionPersistenceBridge::digestForRequest(1, $nonceRead['request_id'], $nonceRead['operation'], $nonceRead['payload']);
        self::assertTrue($bridge->handleRpc($nonceRead)['result']['consumed']);
    }

    public function test_receipt_rows_remain_immutable(): void
    {
        $source = $this->openTuple();
        $this->seedFence($source);
        $this->bridge()->handleRpc($this->envelope($source, $this->holdTarget($source)));
        $receipt = TransitionBridgeRequest::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $receipt->action = 'read_transition_receipt';
        $receipt->save();
    }

    public function test_rpc_preflight_cache_source_contract_guards_are_explicit(): void
    {
        $source = file_get_contents(__FILE__);
        self::assertIsString($source);

        $marker = "\$scriptText = <<<'PY'\n";
        $helperStart = strpos($source, $marker);
        self::assertIsInt($helperStart);
        $helperStart += strlen($marker);
        $helperEnd = strpos($source, "\nPY;", $helperStart);
        self::assertIsInt($helperEnd);
        $helper = substr($source, $helperStart, $helperEnd - $helperStart);

        // Source-contract fixtures only: these assert isolation and no-follow cleanup structure, not filesystem behavior.
        self::assertStringNotContainsString("os.path.join(root, 'bootstrap', 'cache'", $helper);
        self::assertStringContainsString("preflight_env['APP_CONFIG_CACHE'] = os.path.join(preflight_cache_dir, 'config-cache.php')", $helper);
        self::assertStringContainsString("preflight_env['APP_PACKAGES_CACHE'] = os.path.join(preflight_cache_dir, 'packages.php')", $helper);
        self::assertStringContainsString("preflight_env['APP_SERVICES_CACHE'] = os.path.join(preflight_cache_dir, 'services.php')", $helper);
        self::assertStringContainsString('os.mkdir(candidate, 0o700, dir_fd=preflight_tmp_fd)', $helper);
        self::assertStringContainsString('except FileExistsError:', $helper);
        self::assertStringContainsString('stat.S_ISVTX', $helper);
        self::assertStringContainsString('os.fchmod(preflight_root_fd, 0o711)', $helper);
        self::assertStringContainsString('info.st_nlink != 1', $helper);
        self::assertStringContainsString('opened_info.st_nlink != 1', $helper);
        self::assertStringContainsString('current.st_nlink != 1', $helper);
        self::assertStringContainsString('expected.st_nlink != 1', $helper);
        self::assertStringContainsString('preflight_cache_created = True', $helper);
        self::assertStringContainsString('if preflight_cache_fd is not None:', $helper);
        self::assertStringContainsString("os.rmdir('preflight-cache', dir_fd=preflight_root_fd)", $helper);
        self::assertStringContainsString('preflight_root_identity = (root_entry.st_dev, root_entry.st_ino)', $helper);
        self::assertStringContainsString('os.stat(preflight_tmp_name, dir_fd=preflight_tmp_fd, follow_symlinks=False)', $helper);
        self::assertStringContainsString("os.stat('preflight-cache', dir_fd=preflight_root_fd, follow_symlinks=False)", $helper);
        self::assertStringContainsString('os.rmdir(preflight_tmp_name, dir_fd=preflight_tmp_fd)', $helper);
        self::assertStringNotContainsString('os.rmdir(preflight_cache_dir)', $helper);
        self::assertStringContainsString("capture_diagnostic_output = probe == 'artisan_version'", $helper);
        self::assertStringContainsString("captured_stderr = tempfile.TemporaryFile(mode='w+b')", $helper);
        self::assertStringContainsString('stdout=subprocess.PIPE if capture_diagnostic_output else captured_stdout if captured_stdout is not None else subprocess.DEVNULL', $helper);
        self::assertStringContainsString('stderr=subprocess.STDOUT if capture_diagnostic_output else subprocess.DEVNULL', $helper);
        self::assertStringContainsString('diagnostic = stream.read(8192)', $helper);
        self::assertStringContainsString("'; cause='+classify_artisan_failure(captured_stderr)", $helper);
        self::assertStringContainsString('remaining = 8192 - destination.tell()', $helper);
        self::assertStringContainsString('if remaining > 0:', $helper);
        self::assertStringContainsString('os.fchmod(captured_stderr.fileno(), 0o600)', $helper);
        self::assertStringContainsString('if child is None:', $helper);
        self::assertStringContainsString('child_status = child.poll()', $helper);
        self::assertStringContainsString('child_state=\'+child_state+child_exit+\'; last_marker=\'+last_progress_marker', $helper);
        self::assertStringContainsString('signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGTERM})', $helper);
        self::assertStringContainsString('child = subprocess.Popen([\'setpriv\'', $helper);
        self::assertStringContainsString('signal.pthread_sigmask(signal.SIG_SETMASK, spawn_signal_mask)', $helper);
        self::assertStringContainsString('helper_deadline = time.monotonic() + 30', $helper);
        self::assertStringContainsString('preflight.wait(timeout=min(8, probe_remaining))', $helper);
        self::assertStringContainsString('deadline = min(helper_deadline, time.monotonic() + 20)', $helper);
        self::assertStringContainsString('child.wait(timeout=min(20, child_remaining))', $helper);
        self::assertStringContainsString('str_starts_with($match[0], \'phase=wrapper_signal;\')', $source);
        foreach ([
            'preflight_php_version_begin',
            'preflight_artisan_version_begin',
            'preflight_cache_lock_begin',
            'preflight_artisan_list_begin',
            'preflight_command_help_begin',
            'socket_setup_begin',
            'child_spawn_begin',
            'socket_accept_begin',
            'request_send_begin',
            'response_read_begin',
            'child_wait_begin',
            'cleanup_begin',
            'preflight_reap_begin',
            'child_reap_begin',
            'cache_cleanup_begin',
            'phase=socket_setup; state=failed',
            'phase=child_start; state=failed',
            'phase=peer_credentials; state=invalid',
            'phase=peer_credentials; state=mismatch',
            'phase=request; state=send_failed',
            'phase=response; state=framing_invalid',
            'phase=response; state=decode_failed',
            'phase=response; state=envelope_invalid',
            'phase=response; state=canonical_invalid',
            'phase=response; state=identity_mismatch',
            'phase=response; state=tuple_mismatch',
            'EMPTY_DIAGNOSTIC',
            'APP_PROVIDER_BOOT',
            'SQLITE_OR_PDO_BOOT',
            'SYMFONY_CONSOLE_BOOT_OR_REGISTRATION',
            'PHP_PARSE_OR_RUNTIME_FATAL',
            'INPUT_PARENT_TRAVERSE_DENIED',
            'INPUT_SCRIPT_MISSING',
            'INPUT_SCRIPT_NOT_REGULAR',
            'INPUT_SCRIPT_UNREADABLE',
            'INPUT_SCRIPT_READABLE',
            'INPUT_ACCESS_CHECK_FAILED',
            'PHP_STARTUP',
            'APP_KEY_CONFIG',
            'PHP_WARNING',
            'OTHER_NONEMPTY_OUTPUT',
        ] as $diagnosticToken) {
            self::assertStringContainsString($diagnosticToken, $helper);
        }
        self::assertStringContainsString("'phase=root_helper; state=failed'", $source);
        self::assertStringContainsString('phase=root_helper; state=(?:failed|spawn_failed|script_write_failed|script_mode_failed|request_encode_failed|temp_setup_failed|fixture_permissions_failed)', $source);

        $bootstrapPosition = strpos($helper, "run_preflight_probe('artisan_version'");
        $lockPosition = strpos($helper, 'lock_preflight_cache()', $bootstrapPosition);
        $listPosition = strpos($helper, "run_preflight_probe('artisan_list'", $bootstrapPosition);
        self::assertIsInt($bootstrapPosition);
        self::assertIsInt($lockPosition);
        self::assertIsInt($listPosition);
        self::assertTrue($bootstrapPosition < $lockPosition && $lockPosition < $listPosition);

        $lockDefinition = strpos($helper, 'def lock_preflight_cache():');
        $dropDirectoryWrite = strpos($helper, 'os.fchown(preflight_cache_fd, 0, 0)', $lockDefinition);
        $inspectEntries = strpos($helper, 'names = os.listdir(preflight_cache_fd)', $lockDefinition);
        self::assertIsInt($lockDefinition);
        self::assertIsInt($dropDirectoryWrite);
        self::assertIsInt($inspectEntries);
        self::assertTrue($lockDefinition < $dropDirectoryWrite && $dropDirectoryWrite < $inspectEntries);
    }

    public function test_artisan_rpc_runs_as_unprivileged_peer_and_checks_so_peercred(): void
    {
        if (PHP_OS_FAMILY !== 'Linux'
            || ! function_exists('posix_geteuid')
            || trim((string) shell_exec('sudo -n true >/dev/null 2>&1; printf "%s" $?')) !== '0'
            || trim((string) shell_exec('command -v setpriv')) === '') {
            self::markTestSkipped('Requires Linux, passwordless sudo, and setpriv for the isolated root-peer integration fixture.');
        }

        $wwwData = trim((string) shell_exec('getent passwd www-data'));
        if ($wwwData === '') {
            self::markTestSkipped('The isolated root-peer fixture requires the www-data account.');
        }
        $parts = explode(':', $wwwData);
        $uid = (int) $parts[2];
        $gid = (int) $parts[3];
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcaregrid-rpc-peer-'.bin2hex(random_bytes(8));
        self::assertTrue(@mkdir($directory, 0711, true), 'phase=root_helper; state=temp_setup_failed');
        $database = $directory.DIRECTORY_SEPARATOR.'rpc.sqlite';
        $script = $directory.DIRECTORY_SEPARATOR.'root-peer.py';
        $socket = $directory.DIRECTORY_SEPARATOR.'root'.DIRECTORY_SEPARATOR.'rpc.sock';

        try {
            $pdo = new \PDO('sqlite:'.$database);
            $pdo->exec('CREATE TABLE deployment_transition_fences (id INTEGER PRIMARY KEY, fence_key TEXT, state TEXT, operation_id TEXT, generation INTEGER, boot_id TEXT, journal_seq INTEGER, journal_head_hash TEXT, evidence_digest TEXT, permit_manifest_hash TEXT, created_at TEXT, updated_at TEXT)');
            $tuple = $this->openTuple();
            $statement = $pdo->prepare('INSERT INTO deployment_transition_fences (fence_key,state,operation_id,generation,boot_id,journal_seq,journal_head_hash,evidence_digest,permit_manifest_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute(['global', ...array_values($tuple), null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            self::assertTrue(@chmod($database, 0644), 'phase=root_helper; state=fixture_permissions_failed');

            $requestId = '018f0f8e-7b1c-7abc-8abc-123456789077';
            $payload = [];
            $request = [
                'version' => 1,
                'request_id' => $requestId,
                'operation' => 'read_tuple',
                'payload' => $payload,
            ];
            $request['request_digest'] = TransitionPersistenceBridge::digestForRequest(1, $requestId, 'read_tuple', $payload);
            $wireRequest = $request;
            $wireRequest['payload'] = (object) [];
            $scriptText = <<<'PY'
import json, os, signal, socket, stat, struct, subprocess, sys, tempfile, threading, time
php, artisan, root, socket_path, uid, gid, database, request_json = sys.argv[1:]
uid, gid = int(uid), int(gid)
parent = os.path.dirname(socket_path)
server = None
conn = None
child = None
preflight = None
preflight_tmp_fd = None
preflight_tmp_name = None
preflight_tmp_identity = None
preflight_tmp_created = False
preflight_root_fd = None
preflight_root_identity = None
preflight_cache_fd = None
preflight_cache_identity = None
preflight_cache_created = False
preflight_cache_dir = None
child_stderr = None
child_cleanup_unconfirmed = False
preflight_cleanup_unconfirmed = False
response = bytearray()
progress_marker_values = {
    'helper_begin',
    'preflight_php_version_begin', 'preflight_php_version_end',
    'preflight_artisan_version_begin', 'preflight_artisan_version_end',
    'preflight_cache_lock_begin', 'preflight_cache_lock_end',
    'preflight_artisan_list_begin', 'preflight_artisan_list_end',
    'preflight_command_help_begin', 'preflight_command_help_end',
    'socket_setup_begin', 'socket_setup_end',
    'child_spawn_begin', 'child_spawn_end',
    'socket_accept_begin', 'socket_accept_end',
    'peer_credentials_begin', 'peer_credentials_end',
    'request_send_begin', 'request_send_end',
    'response_read_begin', 'response_read_end',
    'child_wait_begin', 'child_wait_end',
    'cleanup_begin', 'preflight_reap_begin', 'preflight_reap_end',
    'child_reap_begin', 'child_reap_end',
    'cache_cleanup_begin', 'cache_cleanup_end', 'cleanup_end',
}
last_progress_marker = 'helper_begin'

def mark_progress(marker):
    global last_progress_marker
    if marker not in progress_marker_values:
        return
    last_progress_marker = marker
    try:
        sys.stderr.write('phase=progress; marker='+marker+'\n')
        sys.stderr.flush()
    except OSError:
        pass

child_diagnostic_markers = {
    'phase=handler_entry',
    'phase=unprivileged_identity_pass',
    'phase=socket_option_pass',
    'phase=connect_attempt',
    'phase=connect_success',
}
def handle_shutdown(signum, frame):
    if child is None:
        child_state = 'not_started'
        child_exit = ''
    else:
        child_status = child.poll()
        if child_status is None:
            child_state = 'running'
            child_exit = ''
        else:
            child_state = 'exited'
            child_exit = '; exit_code='+str(child_status)
    raise SystemExit('phase=wrapper_signal; child_state='+child_state+child_exit+'; last_marker='+last_progress_marker)
signal.signal(signal.SIGTERM, handle_shutdown)

def emit_child_marker():
    if child_stderr is None or child is None or child.returncode in (None, 0):
        return
    marker = 'no_marker'
    try:
        child_stderr.flush()
        child_stderr.seek(0, os.SEEK_END)
        size = child_stderr.tell()
        child_stderr.seek(max(0, size - 8192), os.SEEK_SET)
        for line in child_stderr.read(8192).splitlines():
            candidate = line.decode('ascii', errors='ignore').strip()
            if candidate in child_diagnostic_markers:
                marker = candidate.removeprefix('phase=')
    except OSError:
        marker = 'no_marker'
    sys.stderr.write('phase=child_marker; marker='+marker+'\n')
    sys.stderr.flush()

def stop_preflight_bounded(process):
    if process is None:
        return True
    if process.poll() is None:
        try:
            process.terminate()
        except ProcessLookupError:
            pass
    try:
        process.wait(timeout=min(2, max(0.01, helper_deadline - time.monotonic())))
    except subprocess.TimeoutExpired:
        try:
            process.kill()
        except ProcessLookupError:
            pass
        try:
            process.wait(timeout=min(2, max(0.01, helper_deadline - time.monotonic())))
        except subprocess.TimeoutExpired:
            return False
    return process.poll() is not None

def create_preflight_cache():
    global preflight_tmp_fd, preflight_tmp_name, preflight_tmp_identity
    global preflight_tmp_created, preflight_root_fd, preflight_root_identity
    global preflight_cache_fd, preflight_cache_identity
    global preflight_cache_created, preflight_cache_dir
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    preflight_tmp_fd = os.open('/tmp', flags)
    tmp_info = os.fstat(preflight_tmp_fd)
    tmp_path_info = os.stat('/tmp', follow_symlinks=False)
    if (not stat.S_ISDIR(tmp_info.st_mode) or tmp_info.st_uid != 0
            or not (tmp_info.st_mode & stat.S_ISVTX) or not (tmp_info.st_mode & 0o002)
            or (tmp_info.st_dev, tmp_info.st_ino) != (tmp_path_info.st_dev, tmp_path_info.st_ino)):
        raise OSError
    preflight_tmp_identity = (tmp_info.st_dev, tmp_info.st_ino)
    for _ in range(8):
        candidate = 'wpcaregrid-rpc-preflight-'+os.urandom(16).hex()
        try:
            os.mkdir(candidate, 0o700, dir_fd=preflight_tmp_fd)
            preflight_tmp_name = candidate
            preflight_tmp_created = True
            break
        except FileExistsError:
            continue
    if preflight_tmp_name is None:
        raise OSError
    root_entry = os.stat(preflight_tmp_name, dir_fd=preflight_tmp_fd, follow_symlinks=False)
    if not stat.S_ISDIR(root_entry.st_mode) or root_entry.st_uid != 0:
        raise OSError
    preflight_root_identity = (root_entry.st_dev, root_entry.st_ino)
    preflight_root_fd = os.open(preflight_tmp_name, flags, dir_fd=preflight_tmp_fd)
    root_info = os.fstat(preflight_root_fd)
    if (not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid != 0
            or (root_info.st_dev, root_info.st_ino) != preflight_root_identity):
        raise OSError
    os.fchmod(preflight_root_fd, 0o711)
    os.mkdir('preflight-cache', 0o700, dir_fd=preflight_root_fd)
    preflight_cache_created = True
    cache_entry = os.stat('preflight-cache', dir_fd=preflight_root_fd, follow_symlinks=False)
    if not stat.S_ISDIR(cache_entry.st_mode) or cache_entry.st_uid != 0:
        raise OSError
    preflight_cache_identity = (cache_entry.st_dev, cache_entry.st_ino)
    preflight_cache_fd = os.open('preflight-cache', flags, dir_fd=preflight_root_fd)
    cache_info = os.fstat(preflight_cache_fd)
    if (not stat.S_ISDIR(cache_info.st_mode) or cache_info.st_uid != 0
            or (cache_info.st_dev, cache_info.st_ino) != preflight_cache_identity):
        raise OSError
    os.fchown(preflight_cache_fd, uid, gid)
    os.fchmod(preflight_cache_fd, 0o700)
    preflight_cache_dir = os.path.join('/tmp', preflight_tmp_name, 'preflight-cache')

def lock_preflight_cache():
    cache_info = os.fstat(preflight_cache_fd)
    if (not stat.S_ISDIR(cache_info.st_mode) or cache_info.st_uid != uid
            or (cache_info.st_dev, cache_info.st_ino) != preflight_cache_identity):
        raise OSError
    # Remove directory mutation rights from the app identity before inspecting or locking entries.
    os.fchown(preflight_cache_fd, 0, 0)
    os.fchmod(preflight_cache_fd, 0o700)
    names = os.listdir(preflight_cache_fd)
    if set(names) != {'packages.php', 'services.php'}:
        raise OSError
    opened = {}
    try:
        for name in ('packages.php', 'services.php'):
            info = os.stat(name, dir_fd=preflight_cache_fd, follow_symlinks=False)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_nlink != 1:
                raise OSError
            descriptor = os.open(name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=preflight_cache_fd)
            opened[name] = descriptor
            opened_info = os.fstat(descriptor)
            if ((opened_info.st_dev, opened_info.st_ino) != (info.st_dev, info.st_ino)
                    or not stat.S_ISREG(opened_info.st_mode) or opened_info.st_nlink != 1):
                raise OSError
            os.fchown(descriptor, 0, 0)
            os.fchmod(descriptor, 0o444)
        for name, descriptor in opened.items():
            expected = os.fstat(descriptor)
            current = os.stat(name, dir_fd=preflight_cache_fd, follow_symlinks=False)
            if (not stat.S_ISREG(current.st_mode) or current.st_nlink != 1
                    or (current.st_dev, current.st_ino, current.st_uid, current.st_mode & 0o777) != (
                        expected.st_dev, expected.st_ino, 0, 0o444)
                    or expected.st_nlink != 1):
                raise OSError
        os.fchmod(preflight_cache_fd, 0o555)
        cache_info = os.fstat(preflight_cache_fd)
        if (cache_info.st_dev, cache_info.st_ino, cache_info.st_uid, cache_info.st_mode & 0o777) != (
                preflight_cache_identity[0], preflight_cache_identity[1], 0, 0o555):
            raise OSError
    finally:
        for descriptor in opened.values():
            os.close(descriptor)

def cleanup_preflight_cache():
    global preflight_cleanup_unconfirmed
    if preflight_tmp_fd is None:
        return
    try:
        if not preflight_tmp_created or preflight_tmp_name is None:
            return
        tmp_info = os.fstat(preflight_tmp_fd)
        tmp_path_info = os.stat('/tmp', follow_symlinks=False)
        if (tmp_info.st_uid != 0 or not (tmp_info.st_mode & stat.S_ISVTX)
                or (tmp_info.st_dev, tmp_info.st_ino) != preflight_tmp_identity
                or (tmp_path_info.st_dev, tmp_path_info.st_ino) != preflight_tmp_identity):
            raise OSError
        root_entry = os.stat(preflight_tmp_name, dir_fd=preflight_tmp_fd, follow_symlinks=False)
        if not stat.S_ISDIR(root_entry.st_mode) or root_entry.st_uid != 0:
            raise OSError
        if preflight_root_identity is not None and (
                root_entry.st_dev, root_entry.st_ino) != preflight_root_identity:
            raise OSError
        if preflight_cache_created:
            if preflight_root_fd is None:
                raise OSError
            cache_entry = os.stat('preflight-cache', dir_fd=preflight_root_fd, follow_symlinks=False)
            if not stat.S_ISDIR(cache_entry.st_mode) or cache_entry.st_uid not in (0, uid):
                raise OSError
            if preflight_cache_identity is not None and (
                    cache_entry.st_dev, cache_entry.st_ino) != preflight_cache_identity:
                raise OSError
            if preflight_cache_fd is not None:
                cache_info = os.fstat(preflight_cache_fd)
                if (not stat.S_ISDIR(cache_info.st_mode)
                        or (preflight_cache_identity is not None
                            and (cache_info.st_dev, cache_info.st_ino) != preflight_cache_identity)):
                    raise OSError
                os.fchown(preflight_cache_fd, 0, 0)
                os.fchmod(preflight_cache_fd, 0o700)
                for name in ('config-cache.php', 'packages.php', 'services.php'):
                    try:
                        info = os.stat(name, dir_fd=preflight_cache_fd, follow_symlinks=False)
                    except FileNotFoundError:
                        continue
                    if not (stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)):
                        raise OSError
                    os.unlink(name, dir_fd=preflight_cache_fd)
            os.rmdir('preflight-cache', dir_fd=preflight_root_fd)
        os.rmdir(preflight_tmp_name, dir_fd=preflight_tmp_fd)
    except Exception:
        preflight_cleanup_unconfirmed = True
    finally:
        for descriptor_name in ('preflight_cache_fd', 'preflight_root_fd', 'preflight_tmp_fd'):
            descriptor = globals()[descriptor_name]
            if descriptor is not None:
                try:
                    os.close(descriptor)
                except OSError:
                    preflight_cleanup_unconfirmed = True
                globals()[descriptor_name] = None

def run_preflight_probe(probe, command, capture_stdout=False):
    global preflight, preflight_cleanup_unconfirmed
    probe_progress = {
        'php_version': ('preflight_php_version_begin', 'preflight_php_version_end'),
        'artisan_version': ('preflight_artisan_version_begin', 'preflight_artisan_version_end'),
        'artisan_list': ('preflight_artisan_list_begin', 'preflight_artisan_list_end'),
        'command_help': ('preflight_command_help_begin', 'preflight_command_help_end'),
    }.get(probe)
    if probe_progress is not None:
        mark_progress(probe_progress[0])
    captured_stdout = None
    captured_stderr = None
    stderr_reader = None

    def drain_bounded_diagnostic_output(pipe, destination):
        try:
            while True:
                chunk = pipe.read(4096)
                if not chunk:
                    return
                remaining = 8192 - destination.tell()
                if remaining > 0:
                    destination.write(chunk[:remaining])
        except OSError:
            return
        finally:
            try:
                pipe.close()
            except OSError:
                pass

    try:
        if capture_stdout:
            captured_stdout = tempfile.TemporaryFile(mode='w+b')
        capture_diagnostic_output = probe == 'artisan_version'
        if capture_diagnostic_output:
            captured_stderr = tempfile.TemporaryFile(mode='w+b')
            os.fchmod(captured_stderr.fileno(), 0o600)
    except Exception:
        raise SystemExit('phase=preflight; probe='+probe+'; state=failed')

    def classify_artisan_failure(stream):
        try:
            stream.flush()
            stream.seek(0)
            diagnostic = stream.read(8192).lower()
        except Exception:
            return 'UNCLASSIFIED'
        if not diagnostic.strip():
            return 'EMPTY_DIAGNOSTIC'
        if any(token in diagnostic for token in (
                b'could not open input file', b'no input file specified')):
            return classify_artisan_input_access()
        if any(token in diagnostic for token in (
                b'php startup:', b'unable to load dynamic library', b'failed loading')):
            return 'PHP_STARTUP'
        if any(token in diagnostic for token in (
                b'no application encryption key', b'app_key is invalid', b'application key is invalid')):
            return 'APP_KEY_CONFIG'
        cache_names = (b'packages.php', b'services.php')
        cache_name_present = any(name in diagnostic for name in cache_names)
        file_open_failure = any(token in diagnostic for token in (
            b'failed to open stream', b'failed opening required', b'file does not exist at path',
            b'no such file or directory', b'permission denied',
            b'operation not permitted', b'read-only file system',
        ))
        if b'config-cache.php' in diagnostic and (
                file_open_failure or b'require(' in diagnostic or b'include(' in diagnostic
                or b'syntax error' in diagnostic or b'unexpected token' in diagnostic):
            return 'CONFIG_CACHE_LOAD'
        manifest_write_family = b'directory must be present and writable' in diagnostic or (
            cache_name_present and file_open_failure and any(token in diagnostic for token in (
                b'file_put_contents(', b'tempnam(', b'rename(',
            ))
        )
        if manifest_write_family:
            return 'CACHE_MANIFEST_WRITE'
        if cache_name_present and file_open_failure and (
                b'require(' in diagnostic or b'include(' in diagnostic
                or b'file does not exist at path' in diagnostic):
            return 'CACHE_MANIFEST_READ'
        if any(name in diagnostic for name in (b'autoload.php', b'bootstrap/app.php')) and file_open_failure:
            return 'PHP_AUTOLOAD_OR_BOOTSTRAP'
        if any(token in diagnostic for token in (
                b'pdoexception', b'pdo sqlite', b'sqlite error', b'sqliteexception',
                b'sqlite driver', b'could not find driver')):
            return 'SQLITE_OR_PDO_BOOT'
        if any(token in diagnostic for token in (
                b'sqlstate[', b'connection refused', b'database is locked',
                b'database connection', b'unable to open database file')):
            return 'DB_OR_PROVIDER_BOOT'
        if any(token in diagnostic for token in (
                b'appserviceprovider', b'serviceprovider', b'service provider', b'provider boot')):
            return 'APP_PROVIDER_BOOT'
        if any(token in diagnostic for token in (
                b'console kernel', b'console application', b'bootstrapwith',
                b'application bootstrap', b'command application')):
            return 'SYMFONY_CONSOLE_BOOT_OR_REGISTRATION'
        if any(token in diagnostic for token in (
                b'parse error', b'fatal error', b'uncaught error', b'uncaught typeerror')):
            return 'PHP_PARSE_OR_RUNTIME_FATAL'
        if any(token in diagnostic for token in (
                b'php warning:', b'php notice:', b'php deprecated:')):
            return 'PHP_WARNING'
        if b'fatal error' in diagnostic or b'uncaught ' in diagnostic or b'exception:' in diagnostic:
            return 'OTHER_EXCEPTION'
        return 'OTHER_NONEMPTY_OUTPUT'

    def classify_artisan_input_access():
        probe_code = '\n'.join((
            'import os, stat, sys',
            'path = sys.argv[1]',
            'try:',
            '    info = os.stat(path)',
            'except PermissionError:',
            "    print('INPUT_PARENT_TRAVERSE_DENIED')",
            '    raise SystemExit(0)',
            'except FileNotFoundError:',
            "    print('INPUT_SCRIPT_MISSING')",
            '    raise SystemExit(0)',
            'if not stat.S_ISREG(info.st_mode):',
            "    print('INPUT_SCRIPT_NOT_REGULAR')",
            'elif not os.access(path, os.R_OK):',
            "    print('INPUT_SCRIPT_UNREADABLE')",
            'else:',
            "    print('INPUT_SCRIPT_READABLE')",
        ))
        remaining = helper_deadline - time.monotonic()
        if remaining <= 0:
            return 'INPUT_ACCESS_CHECK_FAILED'
        try:
            result = subprocess.run(
                ['setpriv', '--reuid='+str(uid), '--regid='+str(gid), '--init-groups',
                 sys.executable, '-c', probe_code, artisan],
                cwd='/', env=env, stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                timeout=min(1.0, remaining), check=False,
            )
        except Exception:
            return 'INPUT_ACCESS_CHECK_FAILED'
        category = result.stdout.decode('ascii', errors='ignore').strip()
        allowed_categories = {
            'INPUT_PARENT_TRAVERSE_DENIED', 'INPUT_SCRIPT_MISSING',
            'INPUT_SCRIPT_NOT_REGULAR', 'INPUT_SCRIPT_UNREADABLE', 'INPUT_SCRIPT_READABLE',
        }
        if result.returncode != 0 or category not in allowed_categories:
            return 'INPUT_ACCESS_CHECK_FAILED'
        return category

    try:
        try:
            preflight = subprocess.Popen(
                ['setpriv','--reuid='+str(uid),'--regid='+str(gid),'--init-groups',*command],
                cwd=root,
                env=preflight_env,
                stdout=subprocess.PIPE if capture_diagnostic_output else captured_stdout if captured_stdout is not None else subprocess.DEVNULL,
                stderr=subprocess.STDOUT if capture_diagnostic_output else subprocess.DEVNULL,
            )
        except Exception:
            raise SystemExit('phase=preflight; probe='+probe+'; state=failed')
        if capture_diagnostic_output:
            stderr_reader = threading.Thread(
                target=drain_bounded_diagnostic_output,
                args=(preflight.stdout, captured_stderr),
                daemon=True,
            )
            stderr_reader.start()
        try:
            probe_remaining = helper_deadline - time.monotonic()
            if probe_remaining <= 0:
                raise subprocess.TimeoutExpired(command, 0)
            preflight.wait(timeout=min(8, probe_remaining))
        except subprocess.TimeoutExpired:
            preflight_cleanup_unconfirmed = not stop_preflight_bounded(preflight)
            if stderr_reader is not None:
                stderr_reader.join(timeout=2)
            status = 'phase=preflight; probe='+probe+'; state=timeout'
            if preflight.returncode is not None:
                status += '; exit_code='+str(preflight.returncode)
            raise SystemExit(status)
        except Exception:
            raise SystemExit('phase=preflight; probe='+probe+'; state=failed')
        if stderr_reader is not None:
            stderr_reader.join(timeout=2)
        if preflight.returncode != 0:
            status = 'phase=preflight; probe='+probe+'; state=failed; exit_code='+str(preflight.returncode)
            if capture_diagnostic_output:
                status += '; cause='+classify_artisan_failure(captured_stderr)
            raise SystemExit(status)
        if captured_stdout is not None:
            try:
                captured_stdout.seek(0)
                command_registered = any(
                    fields and fields[0] == b'deployment:transition-bridge'
                    for fields in (line.strip().split(None, 1) for line in captured_stdout)
                )
            except Exception:
                raise SystemExit('phase=preflight; probe=artisan_list; state=failed')
            if not command_registered:
                raise SystemExit('phase=preflight; probe=artisan_list; state=command_missing; exit_code=0')
        if probe_progress is not None:
            mark_progress(probe_progress[1])
    finally:
        if captured_stdout is not None:
            captured_stdout.close()
        if captured_stderr is not None:
            captured_stderr.close()

try:
    helper_deadline = time.monotonic() + 30
    mark_progress('helper_begin')
    env = os.environ.copy()
    for credential_name in ('MYSQL_PWD', 'M9_MYSQL_VERIFY_PASSWORD', 'DB_PASSWORD', 'DB_USERNAME', 'DATABASE_URL', 'MYSQL_ROOT_PASSWORD'):
        env.pop(credential_name, None)
    env.update({'APP_ENV':'testing','APP_KEY':'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','CAREGRID_DEPLOYMENT_ENV':'testing','CAREGRID_TRANSITION_RPC_TEST_DIAGNOSTICS':'1','DB_CONNECTION':'sqlite','DB_DATABASE':database,'DB_URL':'','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','LOG_CHANNEL':'stderr'})
    preflight_env = env.copy()
    preflight_env['DB_DATABASE'] = ':memory:'
    try:
        create_preflight_cache()
    except Exception:
        raise SystemExit('phase=preflight; probe=cache_setup; state=failed')
    preflight_env['APP_CONFIG_CACHE'] = os.path.join(preflight_cache_dir, 'config-cache.php')
    preflight_env['APP_PACKAGES_CACHE'] = os.path.join(preflight_cache_dir, 'packages.php')
    preflight_env['APP_SERVICES_CACHE'] = os.path.join(preflight_cache_dir, 'services.php')
    # Laravel can generate missing manifests during this first bounded Artisan bootstrap, outside the checkout.
    run_preflight_probe('php_version', [php, '--version'])
    run_preflight_probe('artisan_version', [php, artisan, '--version'])
    mark_progress('preflight_cache_lock_begin')
    try:
        lock_preflight_cache()
    except Exception:
        raise SystemExit('phase=preflight; probe=cache_setup; state=failed')
    mark_progress('preflight_cache_lock_end')
    env.update({
        'APP_CONFIG_CACHE': preflight_env['APP_CONFIG_CACHE'],
        'APP_PACKAGES_CACHE': preflight_env['APP_PACKAGES_CACHE'],
        'APP_SERVICES_CACHE': preflight_env['APP_SERVICES_CACHE'],
    })
    run_preflight_probe('artisan_list', [php, artisan, 'list', '--raw'], capture_stdout=True)
    run_preflight_probe('command_help', [php, artisan, 'deployment:transition-bridge', '--connect-socket', socket_path, '--help'])

    mark_progress('socket_setup_begin')
    try:
        os.mkdir(parent, 0o710)
        os.chown(parent, 0, gid)
        server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        server.bind(socket_path)
        os.chown(socket_path, 0, gid)
        os.chmod(socket_path, 0o620)
        server.listen(1)
        server.settimeout(0.1)
    except Exception:
        raise SystemExit('phase=socket_setup; state=failed')
    mark_progress('socket_setup_end')
    child_stderr = tempfile.TemporaryFile(mode='w+b')
    mark_progress('child_spawn_begin')
    spawn_signal_mask = None
    try:
        spawn_signal_mask = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGTERM})
        child = subprocess.Popen(['setpriv','--reuid='+str(uid),'--regid='+str(gid),'--init-groups',php,artisan,'deployment:transition-bridge','--connect-socket',socket_path], cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=child_stderr)
    except Exception:
        if spawn_signal_mask is not None:
            signal.pthread_sigmask(signal.SIG_SETMASK, spawn_signal_mask)
        raise SystemExit('phase=child_start; state=failed')
    signal.pthread_sigmask(signal.SIG_SETMASK, spawn_signal_mask)
    mark_progress('child_spawn_end')
    deadline = min(helper_deadline, time.monotonic() + 20)
    mark_progress('socket_accept_begin')
    while conn is None:
        if time.monotonic() >= deadline:
            child_status = child.poll()
            if child_status is None:
                raise SystemExit('phase=accept_timeout; child_state=running')
            raise SystemExit('phase=accept; child_state=exited; exit_code='+str(child_status))
        try:
            conn, _ = server.accept()
        except socket.timeout:
            child_status = child.poll()
            if child_status is not None:
                raise SystemExit('phase=accept; child_state=exited; exit_code='+str(child_status))
        except OSError:
            raise SystemExit('phase=accept; state=failed')
    mark_progress('socket_accept_end')
    mark_progress('peer_credentials_begin')
    try:
        pid, peer_uid, peer_gid = struct.unpack('3i', conn.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize('3i')))
    except Exception:
        raise SystemExit('phase=peer_credentials; state=invalid')
    if (pid, peer_uid, peer_gid) != (child.pid, uid, gid):
        raise SystemExit('phase=peer_credentials; state=mismatch')
    mark_progress('peer_credentials_end')
    mark_progress('request_send_begin')
    try:
        conn.settimeout(0.1)
        conn.sendall((json.dumps(json.loads(request_json), sort_keys=True, separators=(',',':'))+'\n').encode())
    except Exception:
        raise SystemExit('phase=request; state=send_failed')
    mark_progress('request_send_end')
    mark_progress('response_read_begin')
    while b'\n' not in response:
        if time.monotonic() >= deadline:
            child_status = child.poll()
            if child_status is None:
                raise SystemExit('phase=response_timeout; child_state=running')
            raise SystemExit('phase=response; child_state=exited; exit_code='+str(child_status))
        try:
            chunk = conn.recv(4096)
        except socket.timeout:
            child_status = child.poll()
            if child_status is not None:
                raise SystemExit('phase=response; child_state=exited; exit_code='+str(child_status))
            continue
        except Exception:
            raise SystemExit('phase=response; state=receive_failed')
        if not chunk:
            break
        if len(response) + len(chunk) > 65536:
            raise SystemExit('phase=response; state=framing_invalid')
        response.extend(chunk)
    mark_progress('response_read_end')
    mark_progress('child_wait_begin')
    try:
        child_remaining = helper_deadline - time.monotonic()
        if child_remaining <= 0:
            raise subprocess.TimeoutExpired('child', 0)
        child.wait(timeout=min(20, child_remaining))
    except subprocess.TimeoutExpired:
        raise SystemExit('phase=child_wait_timeout; child_state=running')
    except Exception:
        raise SystemExit('phase=child; state=wait_failed')
    mark_progress('child_wait_end')
    if child.returncode != 0:
        raise SystemExit('phase=child; child_state=exited; exit_code='+str(child.returncode))
    if len(response) > 65536 or not response.endswith(b'\n') or response.count(b'\n') != 1:
        raise SystemExit('phase=response; state=framing_invalid')
    try:
        decoded = json.loads(response)
    except Exception:
        raise SystemExit('phase=response; state=decode_failed')
    if not isinstance(decoded, dict) or set(decoded) != {'version','request_id','request_digest','status','result'} or decoded['status'] != 'OK':
        raise SystemExit('phase=response; state=envelope_invalid')
    try:
        canonical_response = (json.dumps(decoded, sort_keys=True, separators=(',',':'))+'\n').encode()
    except Exception:
        raise SystemExit('phase=response; state=canonical_invalid')
    if canonical_response != bytes(response):
        raise SystemExit('phase=response; state=canonical_invalid')
    try:
        request_envelope = json.loads(request_json)
        identity_matches = (
            decoded['request_id'] == request_envelope['request_id']
            and decoded['request_digest'] == request_envelope['request_digest']
        )
    except Exception:
        identity_matches = False
    if not identity_matches:
        raise SystemExit('phase=response; state=identity_mismatch')
    if not isinstance(decoded.get('result'), dict) or decoded['result'].get('state') != 'OPEN':
        raise SystemExit('phase=response; state=tuple_mismatch')
finally:
    mark_progress('cleanup_begin')
    try:
        mark_progress('preflight_reap_begin')
        if preflight is not None and not stop_preflight_bounded(preflight):
            preflight_cleanup_unconfirmed = True
        mark_progress('preflight_reap_end')
    finally:
        try:
            if conn is not None:
                conn.close()
        finally:
            try:
                if server is not None:
                    server.close()
            finally:
                try:
                    mark_progress('child_reap_begin')
                    if child is not None:
                        if child.poll() is None:
                            try:
                                child.terminate()
                            except ProcessLookupError:
                                pass
                        try:
                            child.wait(timeout=2)
                        except subprocess.TimeoutExpired:
                            try:
                                try:
                                    child.kill()
                                except ProcessLookupError:
                                    pass
                                child.wait(timeout=2)
                            except subprocess.TimeoutExpired:
                                child_cleanup_unconfirmed = True
                    mark_progress('child_reap_end')
                finally:
                    try:
                        emit_child_marker()
                    finally:
                        try:
                            if child_stderr is not None:
                                child_stderr.close()
                        finally:
                            try:
                                os.unlink(socket_path)
                            except FileNotFoundError:
                                pass
                            try:
                                os.rmdir(parent)
                            except FileNotFoundError:
                                pass
                            mark_progress('cache_cleanup_begin')
                            cleanup_preflight_cache()
                            mark_progress('cache_cleanup_end')
                if child_cleanup_unconfirmed:
                    raise SystemExit('phase=cleanup; cleanup_state=unconfirmed')
                if preflight_cleanup_unconfirmed:
                    raise SystemExit('phase=cleanup; cleanup_state=unconfirmed')
                mark_progress('cleanup_end')
PY;
            self::assertSame(strlen($scriptText), @file_put_contents($script, $scriptText), 'phase=root_helper; state=script_write_failed');
            self::assertTrue(@chmod($script, 0644), 'phase=root_helper; state=script_mode_failed');
            try {
                $requestJson = json_encode($wireRequest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                self::fail('phase=root_helper; state=request_encode_failed');
            }
            $command = [
                'sudo', '-n', 'python3', $script, PHP_BINARY, base_path('artisan'), base_path(),
                $socket, (string) $uid, (string) $gid, $database,
                $requestJson,
            ];
            $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process, 'phase=root_helper; state=spawn_failed');
            $openPipes = $pipes;
            $captured = [1 => '', 2 => ''];
            foreach ($openPipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            $drainPipes = static function (?array $ready = null) use (&$openPipes, &$captured): void {
                foreach ($openPipes as $index => $pipe) {
                    if ($ready !== null && ! in_array($pipe, $ready, true)) {
                        continue;
                    }
                    $drained = 0;
                    while ($drained < 65536) {
                        $chunk = fread($pipe, min(8192, 65536 - $drained));
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $drained += strlen($chunk);
                        $captured[$index] = substr($captured[$index].$chunk, -4096);
                    }
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($openPipes[$index]);
                    }
                }
            };
            $deadline = microtime(true) + 45.0;
            $observedExitCode = null;
            $timedOut = false;
            while ($openPipes !== [] || $observedExitCode === null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    $timedOut = true;
                    break;
                }
                if ($openPipes !== []) {
                    $read = array_values($openPipes);
                    $write = [];
                    $except = [];
                    $waitMicros = (int) min(100000, ceil($remaining * 1000000));
                    $ready = @stream_select($read, $write, $except, 0, $waitMicros);
                    if ($ready !== false && $ready > 0) {
                        $drainPipes($read);
                    } elseif ($ready === false) {
                        $drainPipes();
                    }
                } else {
                    usleep(20000);
                }
                $drainPipes();
                $status = proc_get_status($process);
                if (! $status['running'] && $observedExitCode === null) {
                    $observedExitCode = $status['exitcode'];
                }
            }

            $cleanupConfirmed = $observedExitCode !== null;
            if ($timedOut) {
                proc_terminate($process);
                $terminateDeadline = microtime(true) + 5.0;
                while (microtime(true) < $terminateDeadline) {
                    $drainPipes();
                    $status = proc_get_status($process);
                    if (! $status['running']) {
                        $observedExitCode = $status['exitcode'];
                        $cleanupConfirmed = true;
                        break;
                    }
                    usleep(20000);
                }
                if (! $cleanupConfirmed) {
                    proc_terminate($process, 9);
                    $killDeadline = microtime(true) + 2.0;
                    while (microtime(true) < $killDeadline) {
                        $drainPipes();
                        $status = proc_get_status($process);
                        if (! $status['running']) {
                            $observedExitCode = $status['exitcode'];
                            $cleanupConfirmed = true;
                            break;
                        }
                        usleep(20000);
                    }
                }
            }

            $drainPipes();
            foreach ($openPipes as $index => $pipe) {
                fclose($pipe);
                unset($openPipes[$index]);
            }
            $processExitCode = $cleanupConfirmed ? proc_close($process) : -1;
            if ($processExitCode < 0 && $observedExitCode !== null) {
                $processExitCode = $observedExitCode;
            }
            $diagnostic = 'phase=root_helper; state=failed';
            $signalDiagnostic = null;
            $helperOutput = $captured[1]."\n".$captured[2];
            if (preg_match_all('/(?:phase=preflight; probe=(?:php_version|cache_setup|artisan_list|command_help); state=(?:timeout|failed|command_missing)(?:; exit_code=-?\\d+)?|phase=preflight; probe=artisan_version; state=timeout(?:; exit_code=-?\\d+)?|phase=preflight; probe=artisan_version; state=failed(?:; exit_code=-?\\d+)?(?:; cause=(?:CACHE_MANIFEST_WRITE|CACHE_MANIFEST_READ|CONFIG_CACHE_LOAD|PHP_AUTOLOAD_OR_BOOTSTRAP|DB_OR_PROVIDER_BOOT|CONSOLE_BOOTSTRAP|APP_PROVIDER_BOOT|SQLITE_OR_PDO_BOOT|SYMFONY_CONSOLE_BOOT_OR_REGISTRATION|PHP_PARSE_OR_RUNTIME_FATAL|EMPTY_DIAGNOSTIC|INPUT_PARENT_TRAVERSE_DENIED|INPUT_SCRIPT_MISSING|INPUT_SCRIPT_NOT_REGULAR|INPUT_SCRIPT_UNREADABLE|INPUT_SCRIPT_READABLE|INPUT_ACCESS_CHECK_FAILED|PHP_STARTUP|APP_KEY_CONFIG|PHP_WARNING|OTHER_EXCEPTION|OTHER_NONEMPTY_OUTPUT|UNCLASSIFIED))?|phase=socket_setup; state=failed|phase=child_start; state=failed|phase=peer_credentials; state=(?:invalid|mismatch)|phase=request; state=send_failed|phase=response; state=(?:framing_invalid|receive_failed|decode_failed|envelope_invalid|canonical_invalid|identity_mismatch|tuple_mismatch)|phase=root_helper; state=(?:failed|spawn_failed|script_write_failed|script_mode_failed|request_encode_failed|temp_setup_failed|fixture_permissions_failed)|phase=accept; state=failed|phase=child; state=wait_failed|phase=progress; marker=(?:helper_begin|preflight_php_version_begin|preflight_php_version_end|preflight_artisan_version_begin|preflight_artisan_version_end|preflight_cache_lock_begin|preflight_cache_lock_end|preflight_artisan_list_begin|preflight_artisan_list_end|preflight_command_help_begin|preflight_command_help_end|socket_setup_begin|socket_setup_end|child_spawn_begin|child_spawn_end|socket_accept_begin|socket_accept_end|peer_credentials_begin|peer_credentials_end|request_send_begin|request_send_end|response_read_begin|response_read_end|child_wait_begin|child_wait_end|cleanup_begin|preflight_reap_begin|preflight_reap_end|child_reap_begin|child_reap_end|cache_cleanup_begin|cache_cleanup_end|cleanup_end)|phase=child_marker; marker=(?:handler_entry|unprivileged_identity_pass|socket_option_pass|connect_attempt|connect_success|no_marker)|phase=(?:accept_timeout|response_timeout|child_wait_timeout); child_state=running|phase=wrapper_signal; child_state=(?:not_started|running|exited)(?:; exit_code=-?\\d+)?; last_marker=(?:helper_begin|preflight_php_version_begin|preflight_php_version_end|preflight_artisan_version_begin|preflight_artisan_version_end|preflight_cache_lock_begin|preflight_cache_lock_end|preflight_artisan_list_begin|preflight_artisan_list_end|preflight_command_help_begin|preflight_command_help_end|socket_setup_begin|socket_setup_end|child_spawn_begin|child_spawn_end|socket_accept_begin|socket_accept_end|peer_credentials_begin|peer_credentials_end|request_send_begin|request_send_end|response_read_begin|response_read_end|child_wait_begin|child_wait_end|cleanup_begin|preflight_reap_begin|preflight_reap_end|child_reap_begin|child_reap_end|cache_cleanup_begin|cache_cleanup_end|cleanup_end)|phase=(?:accept|response|child); child_state=exited; exit_code=-?\\d+|phase=cleanup; cleanup_state=unconfirmed)/', $helperOutput, $matches, PREG_SET_ORDER) > 0) {
                $signalDiagnostic = null;
                foreach ($matches as $match) {
                    if (str_starts_with($match[0], 'phase=wrapper_signal;')) {
                        $signalDiagnostic = $match[0];
                    }
                }
                $diagnostic = trim($signalDiagnostic ?? $matches[count($matches) - 1][0]);
            }
            if ($timedOut && $signalDiagnostic === null) {
                $diagnostic = $cleanupConfirmed
                    ? 'phase=wrapper_timeout; cleanup_state=confirmed'
                    : 'phase=wrapper_timeout; cleanup_state=unconfirmed';
            }
            self::assertSame(0, $processExitCode, $diagnostic);
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (\Throwable) {
            self::fail('phase=root_helper; state=failed');
        } finally {
            foreach ([$script, $database] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            if (is_file($socket)) {
                @unlink($socket);
            }
            if (is_dir(dirname($socket))) {
                @rmdir(dirname($socket));
            }
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }
}
