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
        $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
        self::assertIsString($workflow);
        self::assertStringContainsString('r"phase=root_helper; state=failed|"', $workflow);
        self::assertStringContainsString('safe_prefix = "GATE1_SAFE_RUNTIME_DIAGNOSTIC:"', $workflow);
        self::assertStringContainsString('operation=(?:application_source_cleanup_begin|root_entry_stat|root_entry_validate|root_open|root_fstat|root_identity_validate|directory_list|entry_stat|entry_type_validate|child_directory_open|child_directory_validate|child_contents_remove|child_directory_close|child_directory_remove|file_owner_validate|file_remove|root_directory_close|root_directory_remove); context=(?:cleanup_only|secondary); exception=(?:TimeoutError|PermissionError|FileNotFoundError|FileExistsError|NotADirectoryError|IsADirectoryError|OSError|NameError|AttributeError|TypeError|ValueError|RuntimeError|OtherException)', $workflow);
        self::assertStringContainsString('operation=(?:mkdir_application_source|stat_application_source|open_application_source|fstat_application_source|validate_application_source)', $workflow);
        self::assertStringContainsString('payload_match = safe_diagnostic.fullmatch(candidate)', $workflow);
        self::assertStringNotContainsString('operation=.*', $workflow);
        self::assertStringContainsString("'GATE1_SAFE_RUNTIME_DIAGNOSTIC:'", $source);
        self::assertStringContainsString('stage=assertion_failure', $workflow);
        self::assertStringContainsString('state=started; producer=php_wrapper; stage=peer_rpc_test_entry', $workflow);
        self::assertStringContainsString('reason=(?:platform_or_privilege_prerequisite_missing|www_data_account_missing)', $workflow);
        self::assertStringContainsString('diagnostic_payload.startswith("phase=root_helper; state=progress;") or diagnostic_payload.startswith("phase=progress;")', $workflow);
        self::assertStringContainsString('if latest_progress_diagnostic is not None:', $workflow);
        self::assertStringContainsString('if not has_terminal_diagnostic and latest_lifecycle_diagnostic is not None:', $workflow);
        self::assertStringContainsString('latest_lifecycle_diagnostic = diagnostic', $workflow);
        self::assertSame(1, substr_count($workflow, 'payload_match = safe_diagnostic.fullmatch(candidate[marker_position + len(safe_prefix):])'));
        self::assertSame(1, substr_count($workflow, 'payload_match = safe_rpc_diagnostic.fullmatch(candidate[marker_position + len(safe_prefix):])'));
        self::assertSame(2, substr_count($workflow, 'if candidate.count(safe_prefix) == 1:'));
        self::assertSame(2, substr_count($workflow, 'diagnostic = safe_prefix + payload_match.group(0)'));
        self::assertStringNotContainsString('safe_diagnostic.fullmatch(candidate[marker_position:])', $workflow);
        self::assertStringNotContainsString('safe_rpc_diagnostic.fullmatch(candidate[marker_position:])', $workflow);
        $progressPrintPosition = strpos($workflow, 'if latest_progress_diagnostic is not None:');
        $cleanupPrintPosition = strpos($workflow, 'for diagnostic in sorted(cleanup_diagnostics):', $progressPrintPosition);
        $terminalPrintPosition = strpos($workflow, 'for diagnostic in (latest_safe_diagnostic, wrapper_signal_diagnostic, wrapper_timeout_diagnostic):', $cleanupPrintPosition);
        self::assertIsInt($progressPrintPosition);
        self::assertIsInt($cleanupPrintPosition);
        self::assertIsInt($terminalPrintPosition);
        self::assertTrue($progressPrintPosition < $cleanupPrintPosition && $cleanupPrintPosition < $terminalPrintPosition);
        self::assertStringContainsString('has_terminal_diagnostic = any(', $workflow);
        $peerRpcMethodStart = strpos($source, 'public function test_artisan_rpc_runs_as_unprivileged_peer_and_checks_so_peercred(): void');
        self::assertIsInt($peerRpcMethodStart);
        $peerRpcMethodEnd = strpos($source, "\n    }\n}", $peerRpcMethodStart);
        self::assertIsInt($peerRpcMethodEnd);
        $peerRpcMethod = substr($source, $peerRpcMethodStart, $peerRpcMethodEnd - $peerRpcMethodStart);
        $emitterPosition = strpos($peerRpcMethod, '$emitSafeDiagnostic = static function');
        self::assertStringContainsString('$safeLifecycleEmitted, &$safeProgressEmitted, &$safeOutcomeEmitted', $peerRpcMethod);
        self::assertStringContainsString('$safeCleanupEmitted = false;', $peerRpcMethod);
        self::assertStringContainsString('in_array($cleanupMatch[1], $safeCleanupOperations, true)', $peerRpcMethod);
        self::assertStringContainsString('context=(cleanup_only|secondary)', $peerRpcMethod);
        self::assertStringContainsString('$safeOutcomeEmitted = true;', $peerRpcMethod);
        self::assertStringContainsString('$safeProgressStages = [', $peerRpcMethod);
        $entryMarker = "\$emitSafeDiagnostic('phase=root_helper; state=started; producer=php_wrapper; stage=peer_rpc_test_entry')";
        $entryMarkerPosition = strpos($peerRpcMethod, $entryMarker);
        $prerequisitePosition = strpos($peerRpcMethod, "if (PHP_OS_FAMILY !== 'Linux'");
        $progressStages = ['prerequisites_passed', 'identity_parsed', 'temp_name_created', 'mkdir_begin', 'mkdir_complete', 'fixture_database_opened', 'fixture_schema_created', 'fixture_row_inserted', 'fixture_permissions_set', 'helper_script_built', 'helper_script_written', 'helper_script_permissions_set', 'request_json_ready', 'helper_spawn_begin', 'helper_spawn_complete', 'helper_wait_begin', 'helper_wait_complete', 'helper_reap_begin', 'helper_reap_complete', 'cleanup_begin', 'cleanup_complete', 'method_body_complete'];
        $additionalProgressStages = ['assertion_catch_entered', 'throwable_catch_entered', 'terminal_outcome_suppressed'];
        $workflowStagePattern = 'stage=(?:'.implode('|', [...$progressStages, ...$additionalProgressStages]).')';
        self::assertSame(2, substr_count($workflow, $workflowStagePattern));
        $boundedPatternStart = strpos($workflow, 'safe_diagnostic = re.compile(');
        $boundedPatternEnd = strpos($workflow, "\n          )", $boundedPatternStart);
        $fullPatternStart = strpos($workflow, 'safe_rpc_diagnostic = re.compile(');
        $fullPatternEnd = strpos($workflow, "\n          )", $fullPatternStart);
        self::assertIsInt($boundedPatternStart);
        self::assertIsInt($boundedPatternEnd);
        self::assertIsInt($fullPatternStart);
        self::assertIsInt($fullPatternEnd);
        $boundedPattern = substr($workflow, $boundedPatternStart, $boundedPatternEnd - $boundedPatternStart + strlen("\n          )"));
        $fullPattern = substr($workflow, $fullPatternStart, $fullPatternEnd - $fullPatternStart + strlen("\n          )"));
        $boundedPattern = str_replace('safe_diagnostic = re.compile(', 'safe_rpc_diagnostic = re.compile(', $boundedPattern);
        self::assertSame($fullPattern, $boundedPattern);
        $progressPositions = [];
        foreach ($progressStages as $progressStage) {
            $progressPosition = strpos($peerRpcMethod, 'state=progress; producer=php_wrapper; stage='.$progressStage);
            self::assertIsInt($progressPosition);
            $progressPositions[] = $progressPosition;
        }
        $finallyPosition = strrpos($peerRpcMethod, "\n        } finally {");
        $cleanupBeginPosition = strpos($peerRpcMethod, 'state=progress; producer=php_wrapper; stage=cleanup_begin');
        $cleanupCompletePosition = strpos($peerRpcMethod, 'state=progress; producer=php_wrapper; stage=cleanup_complete');
        $methodBodyCompletePosition = strpos($peerRpcMethod, 'state=progress; producer=php_wrapper; stage=method_body_complete');
        $assertionCatchPosition = strpos($peerRpcMethod, '} catch (AssertionFailedError $failure) {');
        $assertionCatchStagePosition = strpos($peerRpcMethod, 'state=progress; producer=php_wrapper; stage=assertion_catch_entered');
        $throwableCatchPosition = strpos($peerRpcMethod, '} catch (\\Throwable $failure) {');
        $throwableCatchStagePosition = strpos($peerRpcMethod, 'state=progress; producer=php_wrapper; stage=throwable_catch_entered');
        $suppressedOutcomeStagePosition = strpos($peerRpcMethod, 'state=progress; producer=php_wrapper; stage=terminal_outcome_suppressed');
        $outerTryPosition = strpos($peerRpcMethod, "\n        try {\n            \$pdo = new");
        $catchCallPosition = strpos($peerRpcMethod, '$emitSafeDiagnostic(', $outerTryPosition === false ? 0 : $outerTryPosition);
        self::assertIsInt($emitterPosition);
        self::assertIsInt($entryMarkerPosition);
        self::assertIsInt($prerequisitePosition);
        self::assertIsInt($outerTryPosition);
        self::assertIsInt($catchCallPosition);
        self::assertIsInt($finallyPosition);
        self::assertIsInt($cleanupBeginPosition);
        self::assertIsInt($cleanupCompletePosition);
        self::assertIsInt($methodBodyCompletePosition);
        self::assertIsInt($assertionCatchPosition);
        self::assertIsInt($assertionCatchStagePosition);
        self::assertIsInt($throwableCatchPosition);
        self::assertIsInt($throwableCatchStagePosition);
        self::assertIsInt($suppressedOutcomeStagePosition);
        self::assertTrue($emitterPosition < $entryMarkerPosition && $entryMarkerPosition < $prerequisitePosition);
        self::assertTrue($prerequisitePosition < $progressPositions[0]);
        for ($index = 1, $stageCount = count($progressPositions); $index < $stageCount; $index++) {
            self::assertTrue($progressPositions[$index - 1] < $progressPositions[$index]);
        }
        self::assertTrue($progressPositions[4] < $outerTryPosition);
        self::assertTrue($finallyPosition < $cleanupBeginPosition
            && $cleanupBeginPosition < $cleanupCompletePosition
            && $cleanupCompletePosition < $methodBodyCompletePosition);
        self::assertTrue($assertionCatchPosition < $assertionCatchStagePosition
            && $assertionCatchStagePosition < $throwableCatchPosition
            && $throwableCatchPosition < $throwableCatchStagePosition
            && $throwableCatchStagePosition < $finallyPosition);
        self::assertTrue($emitterPosition < $outerTryPosition && $outerTryPosition < $catchCallPosition);

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
        self::assertStringContainsString("raise SystemExit('phase=child_wait_timeout; child_state=running')", $helper);
        self::assertStringContainsString("raise SystemExit('phase=child; child_state=exited; exit_code='+str(child.returncode))", $helper);
        self::assertStringContainsString("raise SystemExit('phase=response; child_state=exited; exit_code='+str(child_status))", $helper);
        self::assertStringContainsString('signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGTERM})', $helper);
        self::assertStringContainsString('child = subprocess.Popen([\'setpriv\'', $helper);
        self::assertStringContainsString('signal.pthread_sigmask(signal.SIG_SETMASK, spawn_signal_mask)', $helper);
        self::assertStringContainsString('helper_started_at = time.monotonic()', $helper);
        self::assertStringContainsString('helper_deadline = helper_started_at + 30', $helper);
        self::assertStringContainsString('preflight.wait(timeout=min(8, probe_remaining))', $helper);
        self::assertStringContainsString('def preflight_timeout_diagnostic(probe, timeout_source, child_state, observed_exit_code):', $helper);
        self::assertStringContainsString("allowed_timeout_sources = {'helper_budget_exhausted', 'child_wait_timeout'}", $helper);
        self::assertStringContainsString("allowed_child_states = {'not_started', 'running', 'exited'}", $helper);
        self::assertStringContainsString("'phase=preflight; probe='+probe+'; state=timeout; timeout_source='+timeout_source", $helper);
        self::assertStringContainsString("'; elapsed_ms='+str(elapsed_ms)", $helper);
        self::assertStringContainsString("'; remaining_ms='+str(remaining_ms)", $helper);
        self::assertStringContainsString("'; child_state='+child_state+exit_code_text+'; last_marker='+last_marker", $helper);
        self::assertStringContainsString("status += '; errno='+str(preflight_start_error.errno)", $helper);
        self::assertStringContainsString('deadline = min(helper_deadline, time.monotonic() + 20)', $helper);
        self::assertStringContainsString('child.wait(timeout=min(20, child_remaining))', $helper);
        self::assertStringContainsString('str_starts_with($match[0], \'phase=wrapper_signal;\')', $source);
        self::assertStringContainsString('timeout_source=(?:helper_budget_exhausted|child_wait_timeout); elapsed_ms=', $source);
        self::assertStringContainsString('; elapsed_ms=\\\\d+; remaining_ms=\\\\d+; child_state=(?:not_started|running|exited)', $source);
        self::assertStringContainsString('last_marker=preflight_artisan_version_begin', $source);
        self::assertStringContainsString('phase=source_copy; state=(?:failed|timeout); stage=(?:root_setup(?:; operation=(?:mkdir_application_source|stat_application_source|open_application_source|fstat_application_source|validate_application_source))?|tracked_manifest|checkout_root|tracked_files|required_entries|vendor_root|vendor_files|storage_setup|finalize|checkout_write_check); exception=(?:TimeoutError|PermissionError|FileNotFoundError|FileExistsError|NotADirectoryError|IsADirectoryError|OSError|NameError|AttributeError|TypeError|ValueError|RuntimeError|OtherException)(?:; errno=-?\\d+)?', $source);
        self::assertStringContainsString('phase=wrapper_timeout; cleanup_state=(?:confirmed|unconfirmed)|phase=cleanup; component=application_source; state=unconfirmed; operation=(?:application_source_cleanup_begin|root_entry_stat|root_entry_validate|root_open|root_fstat|root_identity_validate|directory_list|entry_stat|entry_type_validate|child_directory_open|child_directory_validate|child_contents_remove|child_directory_close|child_directory_remove|file_owner_validate|file_remove|root_directory_close|root_directory_remove); context=(?:cleanup_only|secondary); exception=(?:TimeoutError|PermissionError|FileNotFoundError|FileExistsError|NotADirectoryError|IsADirectoryError|OSError|NameError|AttributeError|TypeError|ValueError|RuntimeError|OtherException)|phase=cleanup; component=(?:child_reap|preflight_reap|preflight_cache|temporary_root|socket_cleanup); state=unconfirmed', $source);
        self::assertStringContainsString('def copy_application_source():', $helper);
        self::assertStringContainsString(chr(10).'flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW'.chr(10).'def create_preflight_cache():', $helper);
        self::assertStringContainsString("return 'phase=source_copy; state='+state+'; stage='+stage+operation_text+'; exception='+exception_class+errno_text", $helper);
        self::assertStringContainsString("source_copy_operation = 'mkdir_application_source'", $helper);
        self::assertStringContainsString("allowed_exception_classes = {'TimeoutError', 'PermissionError', 'FileNotFoundError', 'FileExistsError', 'NotADirectoryError', 'IsADirectoryError', 'OSError', 'NameError', 'AttributeError', 'TypeError', 'ValueError', 'RuntimeError'}", $helper);
        self::assertStringContainsString("'; operation='+source_copy_operation", $helper);
        self::assertStringContainsString('def root_helper_failure_diagnostic(error):', $helper);
        self::assertStringContainsString("allowed_root_exception_classes = {'TimeoutError', 'PermissionError', 'FileNotFoundError', 'FileExistsError', 'NotADirectoryError', 'IsADirectoryError', 'OSError', 'NameError', 'AttributeError', 'TypeError', 'ValueError', 'RuntimeError'}", $helper);
        self::assertStringContainsString("'phase=root_helper; state='+'failed; producer='+'python_helper; stage='+stage+'; exception='+exception_class+errno_text", $helper);
        self::assertStringContainsString('raise SystemExit(root_helper_failure_diagnostic(root_helper_error)) from None', $helper);
        self::assertStringContainsString('phase=root_helper; state=failed; producer=python_helper; stage=(?:helper_begin|source_copy_begin|source_copy_end|preflight_php_version_begin|preflight_php_version_end|preflight_artisan_version_begin|preflight_artisan_version_end|preflight_cache_lock_begin|preflight_cache_lock_end|preflight_artisan_list_begin|preflight_artisan_list_end|preflight_command_help_begin|preflight_command_help_end|socket_setup_begin|socket_setup_end|child_spawn_begin|child_spawn_end|socket_accept_begin|socket_accept_end|peer_credentials_begin|peer_credentials_end|request_send_begin|request_send_end|response_read_begin|response_read_end|child_wait_begin|child_wait_end|cleanup_begin|preflight_reap_begin|preflight_reap_end|child_reap_begin|child_reap_end|cache_cleanup_begin|cache_cleanup_end|cleanup_end); exception=(?:TimeoutError|PermissionError|FileNotFoundError|FileExistsError|NotADirectoryError|IsADirectoryError|OSError|NameError|AttributeError|TypeError|ValueError|RuntimeError|OtherException)(?:; errno=-?\\d+)?', $source);
        self::assertStringContainsString("errno_text = '; errno='+str(error_number)", $helper);
        self::assertStringContainsString('def open_source_directory(parent_fd, name):', $helper);
        self::assertStringContainsString('os.open(name, flags, dir_fd=parent_fd)', $helper);
        self::assertStringContainsString('opened.st_ino) != (before.st_dev, before.st_ino)', $helper);
        self::assertStringContainsString('allowed_entries = (', $helper);
        self::assertStringContainsString("'git', '-c', 'safe.directory='+source_root, '-C', source_root, 'ls-files', '-z'", $helper);
        self::assertStringContainsString("tracked_result.stdout.split(b'\\0')", $helper);
        self::assertStringContainsString('def copy_vendor_directory(', $helper);
        self::assertStringContainsString('os.listdir(source_directory_fd)', $helper);
        self::assertStringContainsString("child_relative_path == 'vendor/bin'", $helper);
        self::assertStringContainsString('application_source_name', $helper);
        self::assertStringContainsString('def remove_application_source():', $helper);
        self::assertStringContainsString('application_source_cleanup_operations = {', $helper);
        self::assertStringContainsString('application_source_cleanup_failure = cleanup_operation', $helper);
        self::assertStringContainsString("application_source_cleanup_exception = (", $helper);
        self::assertStringContainsString('application_source_cleanup_exceptions = {', $helper);
        self::assertStringContainsString("cleanup_detail = '; operation='+application_source_cleanup_failure+'; context='+cleanup_context", $helper);
        self::assertStringContainsString("cleanup_context = 'secondary' if primary_failure_pending else 'cleanup_only'", $helper);
        self::assertStringContainsString("sys.stderr.write('phase=cleanup; component='+component+'; state=unconfirmed'+cleanup_detail+'\\n')", $helper);
        foreach ([
            'application_source_cleanup_begin', 'root_entry_stat', 'root_entry_validate',
            'root_open', 'root_fstat', 'root_identity_validate', 'directory_list',
            'entry_stat', 'entry_type_validate', 'child_directory_open',
            'child_directory_validate', 'child_contents_remove', 'child_directory_close',
            'child_directory_remove', 'file_owner_validate', 'file_remove',
            'root_directory_close', 'root_directory_remove',
        ] as $cleanupOperation) {
            self::assertStringContainsString(
                "application_source_cleanup_operation = '{$cleanupOperation}'",
                $helper,
            );
            self::assertStringContainsString("'{$cleanupOperation}'", $peerRpcMethod);
        }
        self::assertStringNotContainsString('str(cleanup_error)', $helper);
        self::assertStringContainsString('VIEW_COMPILED_PATH', $helper);
        foreach ([
            'source_copy_begin',
            'source_copy_end',
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
        self::assertStringContainsString("'phase=root_helper; state='.'failed; producer='.'php_wrapper; stage=output_unclassified'", $source);
        self::assertStringContainsString('producer=php_wrapper; stage=throwable_catch; exception=', $source);

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
        $safeLifecycleEmitted = false;
        $safeProgressEmitted = [];
        $safeOutcomeEmitted = false;
        $safeCleanupEmitted = false;
        $safeProgressStages = ['prerequisites_passed', 'identity_parsed', 'temp_name_created', 'mkdir_begin', 'mkdir_complete', 'fixture_database_opened', 'fixture_schema_created', 'fixture_row_inserted', 'fixture_permissions_set', 'helper_script_built', 'helper_script_written', 'helper_script_permissions_set', 'request_json_ready', 'helper_spawn_begin', 'helper_spawn_complete', 'helper_wait_begin', 'helper_wait_complete', 'helper_reap_begin', 'helper_reap_complete', 'assertion_catch_entered', 'throwable_catch_entered', 'terminal_outcome_suppressed', 'cleanup_begin', 'cleanup_complete', 'method_body_complete'];
        $safeCleanupOperations = [
            'application_source_cleanup_begin', 'root_entry_stat', 'root_entry_validate',
            'root_open', 'root_fstat', 'root_identity_validate', 'directory_list',
            'entry_stat', 'entry_type_validate', 'child_directory_open',
            'child_directory_validate', 'child_contents_remove', 'child_directory_close',
            'child_directory_remove', 'file_owner_validate', 'file_remove',
            'root_directory_close', 'root_directory_remove',
        ];
        $emitSafeDiagnostic = static function (string $line) use (&$safeLifecycleEmitted, &$safeProgressEmitted, &$safeOutcomeEmitted, &$safeCleanupEmitted, $safeProgressStages, $safeCleanupOperations): void {
            if (! str_starts_with($line, 'phase=')) {
                return;
            }

            $cleanupMatch = [];
            if (preg_match('/^phase=cleanup; component=application_source; state=unconfirmed; operation=([a-z_]+); context=(cleanup_only|secondary); exception=(TimeoutError|PermissionError|FileNotFoundError|FileExistsError|NotADirectoryError|IsADirectoryError|OSError|NameError|AttributeError|TypeError|ValueError|RuntimeError|OtherException)$/D', $line, $cleanupMatch) === 1
                && in_array($cleanupMatch[1], $safeCleanupOperations, true)) {
                if (! $safeCleanupEmitted) {
                    $safeCleanupEmitted = true;
                    fwrite(STDERR, 'GATE1_SAFE_RUNTIME_DIAGNOSTIC:'.$line.PHP_EOL);
                    fflush(STDERR);
                }

                return;
            }

            if ($line === 'phase=root_helper; state=started; producer=php_wrapper; stage=peer_rpc_test_entry') {
                if ($safeLifecycleEmitted) {
                    return;
                }

                $safeLifecycleEmitted = true;
            } elseif (str_starts_with($line, 'phase=root_helper; state=progress; producer=php_wrapper; stage=')) {
                $stage = substr($line, strlen('phase=root_helper; state=progress; producer=php_wrapper; stage='));
                if (! in_array($stage, $safeProgressStages, true) || isset($safeProgressEmitted[$stage])) {
                    return;
                }

                $safeProgressEmitted[$stage] = true;
            } else {
                if ($safeOutcomeEmitted) {
                    if (! isset($safeProgressEmitted['terminal_outcome_suppressed'])) {
                        $safeProgressEmitted['terminal_outcome_suppressed'] = true;
                        fwrite(STDERR, 'GATE1_SAFE_RUNTIME_DIAGNOSTIC:phase=root_helper; state=progress; producer=php_wrapper; stage=terminal_outcome_suppressed'.PHP_EOL);
                        fflush(STDERR);
                    }

                    return;
                }

                $safeOutcomeEmitted = true;
            }

            fwrite(STDERR, 'GATE1_SAFE_RUNTIME_DIAGNOSTIC:'.$line.PHP_EOL);
            fflush(STDERR);
        };
        $emitSafeDiagnostic('phase=root_helper; state=started; producer=php_wrapper; stage=peer_rpc_test_entry');

        if (PHP_OS_FAMILY !== 'Linux'
            || ! function_exists('posix_geteuid')
            || trim((string) shell_exec('sudo -n true >/dev/null 2>&1; printf "%s" $?')) !== '0'
            || trim((string) shell_exec('command -v setpriv')) === '') {
            $emitSafeDiagnostic(
                'phase=root_helper; state=skipped; producer=php_wrapper; reason=platform_or_privilege_prerequisite_missing');
            self::markTestSkipped('Requires Linux, passwordless sudo, and setpriv for the isolated root-peer integration fixture.');
        }
        $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=prerequisites_passed');

        $wwwData = trim((string) shell_exec('getent passwd www-data'));
        if ($wwwData === '') {
            $emitSafeDiagnostic(
                'phase=root_helper; state=skipped; producer=php_wrapper; reason=www_data_account_missing');
            self::markTestSkipped('The isolated root-peer fixture requires the www-data account.');
        }
        $parts = explode(':', $wwwData);
        $uid = (int) $parts[2];
        $gid = (int) $parts[3];
        $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=identity_parsed');
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcaregrid-rpc-peer-'.bin2hex(random_bytes(8));
        $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=temp_name_created');
        $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=mkdir_begin');
        self::assertTrue(@mkdir($directory, 0711, true), 'phase=root_helper; state=temp_setup_failed');
        $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=mkdir_complete');
        $database = $directory.DIRECTORY_SEPARATOR.'rpc.sqlite';
        $script = $directory.DIRECTORY_SEPARATOR.'root-peer.py';
        $socket = $directory.DIRECTORY_SEPARATOR.'root'.DIRECTORY_SEPARATOR.'rpc.sock';

        try {
            $pdo = new \PDO('sqlite:'.$database);
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=fixture_database_opened');
            $pdo->exec('CREATE TABLE deployment_transition_fences (id INTEGER PRIMARY KEY, fence_key TEXT, state TEXT, operation_id TEXT, generation INTEGER, boot_id TEXT, journal_seq INTEGER, journal_head_hash TEXT, evidence_digest TEXT, permit_manifest_hash TEXT, created_at TEXT, updated_at TEXT)');
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=fixture_schema_created');
            $tuple = $this->openTuple();
            $statement = $pdo->prepare('INSERT INTO deployment_transition_fences (fence_key,state,operation_id,generation,boot_id,journal_seq,journal_head_hash,evidence_digest,permit_manifest_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute(['global', ...array_values($tuple), null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=fixture_row_inserted');
            self::assertTrue(@chmod($database, 0644), 'phase=root_helper; state=fixture_permissions_failed');
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=fixture_permissions_set');

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
original_source_root = os.path.realpath(root)
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
application_source_name = 'application-source'
application_source_created = False
application_source_identity = None
application_source_path = None
application_source_directories = []
application_source_cleanup_operations = {
    'application_source_cleanup_begin', 'root_entry_stat', 'root_entry_validate',
    'root_open', 'root_fstat', 'root_identity_validate', 'directory_list',
    'entry_stat', 'entry_type_validate', 'child_directory_open',
    'child_directory_validate', 'child_contents_remove', 'child_directory_close',
    'child_directory_remove', 'file_owner_validate', 'file_remove',
    'root_directory_close', 'root_directory_remove',
}
application_source_cleanup_operation = 'application_source_cleanup_begin'
application_source_cleanup_failure = None
application_source_cleanup_exception = None
application_source_cleanup_exceptions = {
    'TimeoutError', 'PermissionError', 'FileNotFoundError', 'FileExistsError',
    'NotADirectoryError', 'IsADirectoryError', 'OSError', 'NameError',
    'AttributeError', 'TypeError', 'ValueError', 'RuntimeError', 'OtherException',
}
source_copy_stage = 'root_setup'
source_copy_operation = 'mkdir_application_source'
source_copy_stages = {
    'root_setup', 'tracked_manifest', 'checkout_root', 'tracked_files',
    'required_entries', 'vendor_root', 'vendor_files', 'storage_setup',
    'finalize', 'checkout_write_check',
}
child_stderr = None
cleanup_unconfirmed_components = set()
cleanup_in_progress = False
primary_failure_pending = False
deferred_shutdown_signal = False
response = bytearray()
progress_marker_values = {
    'helper_begin',
    'source_copy_begin', 'source_copy_end',
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
    if deferred_shutdown_signal and not cleanup_in_progress:
        raise SystemExit('phase=wrapper_signal; state=deferred')
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
    global deferred_shutdown_signal
    deferred_shutdown_signal = True
signal.signal(signal.SIGTERM, handle_shutdown)

def emit_child_marker():
    if child is None:
        return
    if child_stderr is None or child.returncode in (None, 0):
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

flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW

def create_preflight_cache():
    global preflight_tmp_fd, preflight_tmp_name, preflight_tmp_identity
    global preflight_tmp_created, preflight_root_fd, preflight_root_identity
    global preflight_cache_fd, preflight_cache_identity
    global preflight_cache_created, preflight_cache_dir
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

def copy_application_source():
    global root, artisan, application_source_created, application_source_identity
    global application_source_path, application_source_directories, env, preflight_env
    global source_copy_stage, source_copy_operation
    source_root = original_source_root
    source_copy_stage = 'root_setup'
    source_copy_operation = 'mkdir_application_source'
    os.mkdir(application_source_name, 0o700, dir_fd=preflight_root_fd)
    application_source_created = True
    source_copy_operation = 'stat_application_source'
    source_info = os.stat(application_source_name, dir_fd=preflight_root_fd, follow_symlinks=False)
    source_copy_operation = 'validate_application_source'
    if not stat.S_ISDIR(source_info.st_mode) or source_info.st_uid != 0:
        raise OSError
    application_source_identity = (source_info.st_dev, source_info.st_ino)
    source_copy_operation = 'open_application_source'
    source_fd = os.open(application_source_name, flags, dir_fd=preflight_root_fd)
    source_copy_operation = 'fstat_application_source'
    opened_info = os.fstat(source_fd)
    source_copy_operation = 'validate_application_source'
    if (not stat.S_ISDIR(opened_info.st_mode) or opened_info.st_uid != 0
            or (opened_info.st_dev, opened_info.st_ino) != application_source_identity):
        os.close(source_fd)
        raise OSError
    os.close(source_fd)
    application_source_path = os.path.join('/tmp', preflight_tmp_name, application_source_name)
    application_source_directories = [application_source_path]

    def open_source_directory(parent_fd, name):
        before = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
        if stat.S_ISLNK(before.st_mode) or not stat.S_ISDIR(before.st_mode):
            raise OSError
        descriptor = os.open(name, flags, dir_fd=parent_fd)
        opened = os.fstat(descriptor)
        if (not stat.S_ISDIR(opened.st_mode)
                or (opened.st_dev, opened.st_ino) != (before.st_dev, before.st_ino)):
            os.close(descriptor)
            raise OSError
        return descriptor

    def copy_source_file(parent_fd, source_name, destination_path):
        if time.monotonic() >= helper_deadline:
            raise TimeoutError
        source_info = os.stat(source_name, dir_fd=parent_fd, follow_symlinks=False)
        if stat.S_ISLNK(source_info.st_mode):
            raise OSError
        if not stat.S_ISREG(source_info.st_mode):
            raise OSError
        source_fd = os.open(source_name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=parent_fd)
        destination_fd = None
        try:
            opened_source = os.fstat(source_fd)
            if (not stat.S_ISREG(opened_source.st_mode)
                    or (opened_source.st_dev, opened_source.st_ino) != (
                        source_info.st_dev, source_info.st_ino)):
                raise OSError
            destination_fd = os.open(
                destination_path,
                os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                0o600,
            )
            while True:
                if time.monotonic() >= helper_deadline:
                    raise TimeoutError
                chunk = os.read(source_fd, 1024 * 1024)
                if not chunk:
                    break
                offset = 0
                while offset < len(chunk):
                    if deferred_shutdown_signal:
                        raise SystemExit('phase=wrapper_signal; state=deferred')
                    offset += os.write(destination_fd, chunk[offset:])
            os.fchmod(destination_fd, 0o444)
        finally:
            if destination_fd is not None:
                os.close(destination_fd)
            os.close(source_fd)

    allowed_entries = (
        'artisan', 'composer.json', 'composer.lock', 'app', 'bootstrap', 'config',
        'database', 'lang', 'resources', 'routes',
    )
    required_entries = {'artisan', 'composer.json', 'composer.lock', 'app', 'bootstrap', 'config', 'routes'}
    source_copy_stage = 'tracked_manifest'
    try:
        tracked_result = subprocess.run(
            ['git', '-c', 'safe.directory='+source_root, '-C', source_root, 'ls-files', '-z'],
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            env=preflight_env,
            timeout=max(0.1, helper_deadline - time.monotonic()),
            check=False,
        )
    except subprocess.TimeoutExpired:
        raise TimeoutError
    if tracked_result.returncode != 0:
        raise OSError
    source_copy_stage = 'checkout_root'
    source_root_fd = os.open(source_root, flags)
    source_root_info = os.fstat(source_root_fd)
    if not stat.S_ISDIR(source_root_info.st_mode):
        os.close(source_root_fd)
        raise OSError
    allowed_top_level = set(allowed_entries)
    copied_entries = set()
    source_copy_stage = 'tracked_files'
    for raw_path in tracked_result.stdout.split(b'\0'):
        if deferred_shutdown_signal:
            raise SystemExit('phase=wrapper_signal; state=deferred')
        if not raw_path:
            continue
        relative_path = os.fsdecode(raw_path)
        parts = relative_path.split('/')
        if (parts[0] not in allowed_top_level or any(
                part in ('.', '..', '.git', 'node_modules', 'storage')
                or part.startswith('.env') for part in parts)):
            continue
        if len(parts) > 1 and parts[0] == 'bootstrap' and parts[1] == 'cache':
            continue
        extension = os.path.splitext(parts[-1])[1].lower()
        if extension in ('.db', '.sqlite', '.sqlite3', '.sql', '.dump', '.bak'):
            continue
        if time.monotonic() >= helper_deadline:
            raise TimeoutError
        destination_path = os.path.join(application_source_path, *parts)
        source_parent_fd = os.dup(source_root_fd)
        destination_parent = application_source_path
        try:
            for part in parts[:-1]:
                child_fd = open_source_directory(source_parent_fd, part)
                os.close(source_parent_fd)
                source_parent_fd = child_fd
                destination_parent = os.path.join(destination_parent, part)
                try:
                    os.mkdir(destination_parent, 0o700)
                    application_source_directories.append(destination_parent)
                except FileExistsError:
                    destination_info = os.lstat(destination_parent)
                    if (stat.S_ISLNK(destination_info.st_mode)
                            or not stat.S_ISDIR(destination_info.st_mode)):
                        raise OSError
            copy_source_file(source_parent_fd, parts[-1], destination_path)
        finally:
            os.close(source_parent_fd)
        copied_entries.add(parts[0] if len(parts) == 1 else parts[0] + '/' + parts[1])
    source_copy_stage = 'required_entries'
    copied_top_level = {entry.split('/')[0] for entry in copied_entries}
    if not required_entries.issubset(copied_top_level):
        raise OSError

    vendor_destination = os.path.join(application_source_path, 'vendor')
    source_copy_stage = 'vendor_root'
    vendor_source_fd = open_source_directory(source_root_fd, 'vendor')

    def copy_vendor_directory(source_directory_fd, destination_directory, relative_path):
        if deferred_shutdown_signal:
            raise SystemExit('phase=wrapper_signal; state=deferred')
        if time.monotonic() >= helper_deadline:
            raise TimeoutError
        os.mkdir(destination_directory, 0o700)
        application_source_directories.append(destination_directory)
        for name in os.listdir(source_directory_fd):
            if deferred_shutdown_signal:
                raise SystemExit('phase=wrapper_signal; state=deferred')
            if (name in ('.git', 'node_modules', 'storage')
                    or name.startswith('.env')):
                continue
            extension = os.path.splitext(name)[1].lower()
            if extension in ('.db', '.sqlite', '.sqlite3', '.sql', '.dump', '.bak'):
                continue
            child_relative_path = os.path.join(relative_path, name)
            if child_relative_path == 'vendor/bin':
                continue
            child_info = os.stat(name, dir_fd=source_directory_fd, follow_symlinks=False)
            if stat.S_ISLNK(child_info.st_mode):
                raise OSError
            child_destination = os.path.join(destination_directory, name)
            if stat.S_ISDIR(child_info.st_mode):
                child_fd = open_source_directory(source_directory_fd, name)
                try:
                    copy_vendor_directory(child_fd, child_destination, child_relative_path)
                finally:
                    os.close(child_fd)
            elif stat.S_ISREG(child_info.st_mode):
                copy_source_file(source_directory_fd, name, child_destination)
            else:
                raise OSError

    try:
        source_copy_stage = 'vendor_files'
        copy_vendor_directory(vendor_source_fd, vendor_destination, 'vendor')
    finally:
        os.close(vendor_source_fd)
        os.close(source_root_fd)
    if not os.path.isfile(os.path.join(vendor_destination, 'autoload.php')):
        raise OSError

    storage_root = os.path.join(application_source_path, 'storage')
    storage_directories = (
        storage_root,
        os.path.join(storage_root, 'framework'),
        os.path.join(storage_root, 'framework', 'views'),
        os.path.join(storage_root, 'framework', 'cache'),
        os.path.join(storage_root, 'framework', 'cache', 'data'),
        os.path.join(storage_root, 'framework', 'sessions'),
        os.path.join(storage_root, 'logs'),
    )
    source_copy_stage = 'storage_setup'
    for directory in storage_directories:
        os.mkdir(directory, 0o700)
        os.chown(directory, uid, gid)
        os.chmod(directory, 0o700)
    source_copy_stage = 'finalize'
    for directory in reversed(application_source_directories):
        os.chown(directory, 0, 0)
        os.chmod(directory, 0o555)

    root = application_source_path
    artisan = os.path.join(root, 'artisan')
    preflight_env['VIEW_COMPILED_PATH'] = os.path.join(storage_root, 'framework', 'views')
    env['VIEW_COMPILED_PATH'] = preflight_env['VIEW_COMPILED_PATH']

root_helper_exception_classes = {'TimeoutError', 'PermissionError', 'FileNotFoundError', 'FileExistsError', 'NotADirectoryError', 'IsADirectoryError', 'OSError', 'NameError', 'AttributeError', 'TypeError', 'ValueError', 'RuntimeError'}

def root_helper_failure_diagnostic(error):
    stage = last_progress_marker if last_progress_marker in progress_marker_values else 'helper_begin'
    exception_class = type(error).__name__ if type(error).__name__ in root_helper_exception_classes else 'OtherException'
    error_number = getattr(error, 'errno', None)
    errno_text = '; errno='+str(error_number) if type(error_number) is int else ''
    return 'phase=root_helper; state='+'failed; producer='+'python_helper; stage='+stage+'; exception='+exception_class+errno_text

def source_copy_failure_diagnostic(state, error):
    stage = source_copy_stage if source_copy_stage in source_copy_stages else 'root_setup'
    allowed_exception_classes = {'TimeoutError', 'PermissionError', 'FileNotFoundError', 'FileExistsError', 'NotADirectoryError', 'IsADirectoryError', 'OSError', 'NameError', 'AttributeError', 'TypeError', 'ValueError', 'RuntimeError'}
    exception_class = type(error).__name__ if type(error).__name__ in allowed_exception_classes else 'OtherException'
    error_number = getattr(error, 'errno', None)
    errno_text = '; errno='+str(error_number) if type(error_number) is int else ''
    if state not in ('failed', 'timeout'):
        state = 'failed'
    operation_text = '; operation='+source_copy_operation if stage == 'root_setup' else ''
    return 'phase=source_copy; state='+state+'; stage='+stage+operation_text+'; exception='+exception_class+errno_text

def remove_application_source():
    global application_source_created, application_source_cleanup_operation
    global application_source_cleanup_failure
    application_source_cleanup_operation = 'application_source_cleanup_begin'
    if not application_source_created:
        return
    if preflight_root_fd is None or application_source_identity is None:
        raise OSError
    application_source_cleanup_operation = 'root_entry_stat'
    entry = os.stat(application_source_name, dir_fd=preflight_root_fd, follow_symlinks=False)
    application_source_cleanup_operation = 'root_entry_validate'
    if (not stat.S_ISDIR(entry.st_mode) or entry.st_uid != 0
            or (entry.st_dev, entry.st_ino) != application_source_identity):
        raise OSError
    application_source_cleanup_operation = 'root_open'
    source_fd = os.open(application_source_name, flags, dir_fd=preflight_root_fd)
    application_source_cleanup_operation = 'root_fstat'
    opened = os.fstat(source_fd)
    application_source_cleanup_operation = 'root_identity_validate'
    if ((opened.st_dev, opened.st_ino) != application_source_identity
            or not stat.S_ISDIR(opened.st_mode) or opened.st_uid != 0):
        application_source_cleanup_operation = 'root_directory_close'
        os.close(source_fd)
        application_source_cleanup_operation = 'root_identity_validate'
        raise OSError

    def remove_contents(directory_fd):
        global application_source_cleanup_operation
        application_source_cleanup_operation = 'directory_list'
        for name in os.listdir(directory_fd):
            application_source_cleanup_operation = 'entry_stat'
            info = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
            application_source_cleanup_operation = 'entry_type_validate'
            if stat.S_ISDIR(info.st_mode):
                if info.st_uid not in (0, uid):
                    raise OSError
                application_source_cleanup_operation = 'child_directory_open'
                child_fd = os.open(name, flags, dir_fd=directory_fd)
                application_source_cleanup_operation = 'child_directory_validate'
                child = os.fstat(child_fd)
                if ((child.st_dev, child.st_ino) != (info.st_dev, info.st_ino)
                        or not stat.S_ISDIR(child.st_mode) or child.st_uid not in (0, uid)):
                    application_source_cleanup_operation = 'child_directory_close'
                    os.close(child_fd)
                    application_source_cleanup_operation = 'child_directory_validate'
                    raise OSError
                application_source_cleanup_operation = 'child_contents_remove'
                remove_contents(child_fd)
                application_source_cleanup_operation = 'child_directory_close'
                os.close(child_fd)
                application_source_cleanup_operation = 'child_directory_remove'
                os.rmdir(name, dir_fd=directory_fd)
            elif stat.S_ISREG(info.st_mode):
                application_source_cleanup_operation = 'file_owner_validate'
                if info.st_uid not in (0, uid) or info.st_nlink != 1:
                    raise OSError
                application_source_cleanup_operation = 'file_remove'
                os.unlink(name, dir_fd=directory_fd)
            else:
                raise OSError

    try:
        remove_contents(source_fd)
    except Exception:
        application_source_cleanup_failure = application_source_cleanup_operation
        raise
    finally:
        application_source_cleanup_operation = 'root_directory_close'
        os.close(source_fd)
    application_source_cleanup_operation = 'root_directory_remove'
    os.rmdir(application_source_name, dir_fd=preflight_root_fd)
    application_source_created = False

def verify_original_checkout_is_not_writable():
    probe_code = '\n'.join((
        'import os, sys',
        "if os.access(sys.argv[1], os.W_OK):",
        "    print('CHECKOUT_WRITE_ALLOWED')",
        '    raise SystemExit(1)',
        "print('CHECKOUT_WRITE_DENIED')",
    ))
    remaining = helper_deadline - time.monotonic()
    if remaining <= 0:
        raise TimeoutError
    try:
        result = subprocess.run(
            ['setpriv', '--reuid='+str(uid), '--regid='+str(gid), '--init-groups',
             sys.executable, '-c', probe_code, original_source_root],
            cwd='/', env=preflight_env, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            timeout=min(1.0, remaining), check=False,
        )
    except subprocess.TimeoutExpired:
        raise TimeoutError
    if result.returncode != 0 or result.stdout != b'CHECKOUT_WRITE_DENIED\n':
        raise OSError

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
    global cleanup_unconfirmed_components, application_source_cleanup_failure
    global application_source_cleanup_exception
    if preflight_tmp_fd is None:
        return
    cleanup_component = 'temporary_root'
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
            cleanup_component = 'preflight_cache'
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
        cleanup_component = 'application_source'
        try:
            remove_application_source()
        except Exception as cleanup_error:
            if application_source_cleanup_failure is None:
                cleanup_operation = (
                    application_source_cleanup_operation
                    if application_source_cleanup_operation in application_source_cleanup_operations
                    else 'application_source_cleanup_begin'
                )
                application_source_cleanup_failure = cleanup_operation
            if application_source_cleanup_exception is None:
                cleanup_exception = type(cleanup_error).__name__
                application_source_cleanup_exception = (
                    cleanup_exception
                    if cleanup_exception in application_source_cleanup_exceptions
                    else 'OtherException'
                )
            raise
        cleanup_component = 'temporary_root'
        os.rmdir(preflight_tmp_name, dir_fd=preflight_tmp_fd)
    except Exception:
        cleanup_unconfirmed_components.add(cleanup_component)
        if cleanup_component != 'temporary_root':
            cleanup_unconfirmed_components.add('temporary_root')
    finally:
        for descriptor_name in ('preflight_cache_fd', 'preflight_root_fd', 'preflight_tmp_fd'):
            descriptor = globals()[descriptor_name]
            if descriptor is not None:
                try:
                    os.close(descriptor)
                except OSError:
                    cleanup_unconfirmed_components.add('temporary_root')
                globals()[descriptor_name] = None

def preflight_timeout_diagnostic(probe, timeout_source, child_state, observed_exit_code):
    allowed_probes = {'php_version', 'artisan_version', 'artisan_list', 'command_help'}
    allowed_timeout_sources = {'helper_budget_exhausted', 'child_wait_timeout'}
    allowed_child_states = {'not_started', 'running', 'exited'}
    if probe not in allowed_probes:
        probe = 'artisan_version'
    if timeout_source not in allowed_timeout_sources:
        timeout_source = 'helper_budget_exhausted'
    if child_state not in allowed_child_states:
        child_state = 'not_started'
    last_marker = last_progress_marker if last_progress_marker in progress_marker_values else 'helper_begin'
    elapsed_ms = max(0, int((time.monotonic() - helper_started_at) * 1000))
    remaining_ms = max(0, int((helper_deadline - time.monotonic()) * 1000))
    exit_code_text = '; exit_code='+str(observed_exit_code) if type(observed_exit_code) is int else ''
    return (
        'phase=preflight; probe='+probe+'; state=timeout; timeout_source='+timeout_source
        + '; elapsed_ms='+str(elapsed_ms)+'; remaining_ms='+str(remaining_ms)
        + '; child_state='+child_state+exit_code_text+'; last_marker='+last_marker
    )

def run_preflight_probe(probe, command, capture_stdout=False):
    global preflight, cleanup_unconfirmed_components
    probe_progress = {
        'php_version': ('preflight_php_version_begin', 'preflight_php_version_end'),
        'artisan_version': ('preflight_artisan_version_begin', 'preflight_artisan_version_end'),
        'artisan_list': ('preflight_artisan_list_begin', 'preflight_artisan_list_end'),
        'command_help': ('preflight_command_help_begin', 'preflight_command_help_end'),
    }.get(probe)
    if probe_progress is not None:
        mark_progress(probe_progress[0])
    probe_remaining = helper_deadline - time.monotonic()
    if probe_remaining <= 0:
        if probe == 'artisan_version':
            raise SystemExit(preflight_timeout_diagnostic(
                probe, 'helper_budget_exhausted', 'not_started', None))
        raise SystemExit('phase=preflight; probe='+probe+'; state=timeout')
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
                cwd='/', env=preflight_env, stdin=subprocess.DEVNULL,
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
        except OSError as preflight_start_error:
            status = 'phase=preflight; probe='+probe+'; state=failed'
            if type(preflight_start_error.errno) is int:
                status += '; errno='+str(preflight_start_error.errno)
            raise SystemExit(status) from None
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
            timeout_source = 'helper_budget_exhausted' if probe_remaining <= 8 else 'child_wait_timeout'
            observed_exit_code = preflight.poll() if preflight is not None else None
            child_state = 'not_started' if preflight is None else 'exited' if observed_exit_code is not None else 'running'
            status = None
            if probe == 'artisan_version':
                status = preflight_timeout_diagnostic(
                    probe, timeout_source, child_state, observed_exit_code)
            else:
                status = 'phase=preflight; probe='+probe+'; state=timeout'
            if not stop_preflight_bounded(preflight):
                cleanup_unconfirmed_components.add('preflight_reap')
            if stderr_reader is not None:
                stderr_reader.join(timeout=2)
            raise SystemExit(status)
        except OSError as preflight_wait_error:
            status = 'phase=preflight; probe='+probe+'; state=failed'
            if type(preflight_wait_error.errno) is int:
                status += '; errno='+str(preflight_wait_error.errno)
            raise SystemExit(status) from None
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
    helper_started_at = time.monotonic()
    helper_deadline = helper_started_at + 30
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
    mark_progress('source_copy_begin')
    try:
        copy_application_source()
    except Exception as source_copy_error:
        source_copy_state = 'timeout' if isinstance(source_copy_error, TimeoutError) else 'failed'
        raise SystemExit(source_copy_failure_diagnostic(source_copy_state, source_copy_error)) from None
    mark_progress('source_copy_end')
    source_copy_stage = 'checkout_write_check'
    try:
        verify_original_checkout_is_not_writable()
    except Exception as source_copy_error:
        source_copy_state = 'timeout' if isinstance(source_copy_error, TimeoutError) else 'failed'
        raise SystemExit(source_copy_failure_diagnostic(source_copy_state, source_copy_error)) from None
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
        child = subprocess.Popen(['setpriv','--reuid='+str(uid),'--regid='+str(gid),'--init-groups',sys.executable,'-c','import os,signal,sys; signal.pthread_sigmask(signal.SIG_UNBLOCK, {signal.SIGTERM}); os.execv(sys.argv[1], sys.argv[1:])',php,artisan,'deployment:transition-bridge','--connect-socket',socket_path], cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=child_stderr)
    except Exception:
        if spawn_signal_mask is not None:
            signal.pthread_sigmask(signal.SIG_SETMASK, spawn_signal_mask)
        raise SystemExit('phase=child_start; state=failed')
    signal.pthread_sigmask(signal.SIG_SETMASK, spawn_signal_mask)
    mark_progress('child_spawn_end')
    deadline = min(helper_deadline, time.monotonic() + 20)
    mark_progress('socket_accept_begin')
    while conn is None:
        if deferred_shutdown_signal:
            raise SystemExit('phase=wrapper_signal; state=deferred')
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
        if deferred_shutdown_signal:
            raise SystemExit('phase=wrapper_signal; state=deferred')
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
except Exception as root_helper_error:
    raise SystemExit(root_helper_failure_diagnostic(root_helper_error)) from None
finally:
    cleanup_in_progress = True
    primary_failure_pending = sys.exc_info()[0] is not None
    mark_progress('cleanup_begin')
    mark_progress('preflight_reap_begin')
    try:
        if preflight is not None and not stop_preflight_bounded(preflight):
            cleanup_unconfirmed_components.add('preflight_reap')
    except BaseException:
        cleanup_unconfirmed_components.add('preflight_reap')
    mark_progress('preflight_reap_end')
    for resource, component in ((conn, 'socket_cleanup'), (server, 'socket_cleanup')):
        if resource is not None:
            try:
                resource.close()
            except BaseException:
                cleanup_unconfirmed_components.add(component)
    mark_progress('child_reap_begin')
    if child is not None:
        try:
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
                    cleanup_unconfirmed_components.add('child_reap')
        except BaseException:
            cleanup_unconfirmed_components.add('child_reap')
    mark_progress('child_reap_end')
    try:
        emit_child_marker()
    except BaseException:
        cleanup_unconfirmed_components.add('child_reap')
    if child_stderr is not None:
        try:
            child_stderr.close()
        except BaseException:
            cleanup_unconfirmed_components.add('child_reap')
    for operation in (
            lambda: os.unlink(socket_path), lambda: os.rmdir(parent)):
        try:
            operation()
        except FileNotFoundError:
            pass
        except BaseException:
            cleanup_unconfirmed_components.add('socket_cleanup')
    mark_progress('cache_cleanup_begin')
    try:
        cleanup_preflight_cache()
    except BaseException:
        cleanup_unconfirmed_components.add('temporary_root')
    mark_progress('cache_cleanup_end')
    cleanup_order = (
        'child_reap', 'preflight_reap', 'preflight_cache', 'application_source',
        'temporary_root', 'socket_cleanup',
    )
    cleanup_components = [
        component for component in cleanup_order
        if component in cleanup_unconfirmed_components
    ]
    for component in cleanup_components:
        try:
            cleanup_detail = ''
            if component == 'application_source' and application_source_cleanup_failure is not None:
                cleanup_context = 'secondary' if primary_failure_pending else 'cleanup_only'
                cleanup_detail = (
                    '; operation='+application_source_cleanup_failure+'; context='+cleanup_context
                    +'; exception='+application_source_cleanup_exception
                )
            sys.stderr.write('phase=cleanup; component='+component+'; state=unconfirmed'+cleanup_detail+'\n')
            sys.stderr.flush()
        except BaseException:
            pass
    if deferred_shutdown_signal:
        try:
            sys.stderr.write('phase=wrapper_signal; state=deferred\n')
            sys.stderr.flush()
        except BaseException:
            pass
    if cleanup_components and not primary_failure_pending:
        raise SystemExit(
            'phase=cleanup; component='+cleanup_components[0]+'; state=unconfirmed')
    if deferred_shutdown_signal and not primary_failure_pending:
        raise SystemExit('phase=wrapper_signal; state=deferred')
    mark_progress('cleanup_end')
PY;
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=helper_script_built');
            self::assertSame(strlen($scriptText), @file_put_contents($script, $scriptText), 'phase=root_helper; state=script_write_failed');
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=helper_script_written');
            self::assertTrue(@chmod($script, 0644), 'phase=root_helper; state=script_mode_failed');
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=helper_script_permissions_set');
            try {
                $requestJson = json_encode($wireRequest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                self::fail('phase=root_helper; state=request_encode_failed');
            }
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=request_json_ready');
            $command = [
                'sudo', '-n', 'python3', $script, PHP_BINARY, base_path('artisan'), base_path(),
                $socket, (string) $uid, (string) $gid, $database,
                $requestJson,
            ];
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=helper_spawn_begin');
            $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process, 'phase=root_helper; state=spawn_failed');
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=helper_spawn_complete');
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
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=helper_wait_begin');
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

            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=helper_wait_complete');
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
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=helper_reap_begin');
            $processExitCode = $cleanupConfirmed ? proc_close($process) : -1;
            if ($processExitCode < 0 && $observedExitCode !== null) {
                $processExitCode = $observedExitCode;
            }
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=helper_reap_complete');
            $diagnostic = 'phase=root_helper; state='.'failed; producer='.'php_wrapper; stage=output_unclassified';
            $signalDiagnostic = null;
            $primaryDiagnostic = null;
            $wrapperTimeoutDiagnostic = null;
            $cleanupDiagnostics = [];
            $helperOutput = $captured[1]."\n".$captured[2];
            if (preg_match_all('/(?:phase=preflight; probe=(?:php_version|cache_setup|artisan_list|command_help); state=(?:timeout|failed|command_missing)(?:; exit_code=-?\\d+)?(?:; errno=-?\\d+)?|phase=preflight; probe=artisan_version; state=timeout; timeout_source=(?:helper_budget_exhausted|child_wait_timeout); elapsed_ms=\\d+; remaining_ms=\\d+; child_state=(?:not_started|running|exited)(?:; exit_code=-?\\d+)?; last_marker=preflight_artisan_version_begin|phase=preflight; probe=artisan_version; state=failed(?:; exit_code=-?\\d+)?(?:; errno=-?\\d+)?(?:; cause=(?:CACHE_MANIFEST_WRITE|CACHE_MANIFEST_READ|CONFIG_CACHE_LOAD|PHP_AUTOLOAD_OR_BOOTSTRAP|DB_OR_PROVIDER_BOOT|CONSOLE_BOOTSTRAP|APP_PROVIDER_BOOT|SQLITE_OR_PDO_BOOT|SYMFONY_CONSOLE_BOOT_OR_REGISTRATION|PHP_PARSE_OR_RUNTIME_FATAL|EMPTY_DIAGNOSTIC|INPUT_PARENT_TRAVERSE_DENIED|INPUT_SCRIPT_MISSING|INPUT_SCRIPT_NOT_REGULAR|INPUT_SCRIPT_UNREADABLE|INPUT_SCRIPT_READABLE|INPUT_ACCESS_CHECK_FAILED|PHP_STARTUP|APP_KEY_CONFIG|PHP_WARNING|OTHER_EXCEPTION|OTHER_NONEMPTY_OUTPUT|UNCLASSIFIED))?|phase=source_copy; state=(?:failed|timeout); stage=(?:root_setup(?:; operation=(?:mkdir_application_source|stat_application_source|open_application_source|fstat_application_source|validate_application_source))?|tracked_manifest|checkout_root|tracked_files|required_entries|vendor_root|vendor_files|storage_setup|finalize|checkout_write_check); exception=(?:TimeoutError|PermissionError|FileNotFoundError|FileExistsError|NotADirectoryError|IsADirectoryError|OSError|NameError|AttributeError|TypeError|ValueError|RuntimeError|OtherException)(?:; errno=-?\\d+)?|phase=socket_setup; state=failed|phase=child_start; state=failed|phase=peer_credentials; state=(?:invalid|mismatch)|phase=request; state=send_failed|phase=response; state=(?:framing_invalid|receive_failed|decode_failed|envelope_invalid|canonical_invalid|identity_mismatch|tuple_mismatch)|phase=root_helper; state=failed; producer=python_helper; stage=(?:helper_begin|source_copy_begin|source_copy_end|preflight_php_version_begin|preflight_php_version_end|preflight_artisan_version_begin|preflight_artisan_version_end|preflight_cache_lock_begin|preflight_cache_lock_end|preflight_artisan_list_begin|preflight_artisan_list_end|preflight_command_help_begin|preflight_command_help_end|socket_setup_begin|socket_setup_end|child_spawn_begin|child_spawn_end|socket_accept_begin|socket_accept_end|peer_credentials_begin|peer_credentials_end|request_send_begin|request_send_end|response_read_begin|response_read_end|child_wait_begin|child_wait_end|cleanup_begin|preflight_reap_begin|preflight_reap_end|child_reap_begin|child_reap_end|cache_cleanup_begin|cache_cleanup_end|cleanup_end); exception=(?:TimeoutError|PermissionError|FileNotFoundError|FileExistsError|NotADirectoryError|IsADirectoryError|OSError|NameError|AttributeError|TypeError|ValueError|RuntimeError|OtherException)(?:; errno=-?\\d+)?|phase=root_helper; state=failed; producer=php_wrapper; stage=output_unclassified|phase=root_helper; state=failed; producer=php_wrapper; stage=throwable_catch; exception=(?:TypeError|ValueError|ErrorException|JsonException|PDOException|RuntimeException|LogicException|Error|Exception|OtherThrowable)(?:; exception_code=-?\\d+)?|phase=root_helper; state=(?:failed|spawn_failed|script_write_failed|script_mode_failed|request_encode_failed|temp_setup_failed|fixture_permissions_failed)|phase=accept; state=failed|phase=child; state=wait_failed|phase=progress; marker=(?:helper_begin|source_copy_begin|source_copy_end|preflight_php_version_begin|preflight_php_version_end|preflight_artisan_version_begin|preflight_artisan_version_end|preflight_cache_lock_begin|preflight_cache_lock_end|preflight_artisan_list_begin|preflight_artisan_list_end|preflight_command_help_begin|preflight_command_help_end|socket_setup_begin|socket_setup_end|child_spawn_begin|child_spawn_end|socket_accept_begin|socket_accept_end|peer_credentials_begin|peer_credentials_end|request_send_begin|request_send_end|response_read_begin|response_read_end|child_wait_begin|child_wait_end|cleanup_begin|preflight_reap_begin|preflight_reap_end|child_reap_begin|child_reap_end|cache_cleanup_begin|cache_cleanup_end|cleanup_end)|phase=child_marker; marker=(?:handler_entry|unprivileged_identity_pass|socket_option_pass|connect_attempt|connect_success|no_marker)|phase=(?:accept_timeout|response_timeout|child_wait_timeout); child_state=running|phase=wrapper_signal; state=deferred|phase=wrapper_signal; child_state=(?:not_started|running|exited)(?:; exit_code=-?\\d+)?; last_marker=(?:helper_begin|source_copy_begin|source_copy_end|preflight_php_version_begin|preflight_php_version_end|preflight_artisan_version_begin|preflight_artisan_version_end|preflight_cache_lock_begin|preflight_cache_lock_end|preflight_artisan_list_begin|preflight_artisan_list_end|preflight_command_help_begin|preflight_command_help_end|socket_setup_begin|socket_setup_end|child_spawn_begin|child_spawn_end|socket_accept_begin|socket_accept_end|peer_credentials_begin|peer_credentials_end|request_send_begin|request_send_end|response_read_begin|response_read_end|child_wait_begin|child_wait_end|cleanup_begin|preflight_reap_begin|preflight_reap_end|child_reap_begin|child_reap_end|cache_cleanup_begin|cache_cleanup_end|cleanup_end)|phase=(?:accept|response|child); child_state=exited; exit_code=-?\\d+|phase=wrapper_timeout; cleanup_state=(?:confirmed|unconfirmed)|phase=cleanup; component=application_source; state=unconfirmed; operation=(?:application_source_cleanup_begin|root_entry_stat|root_entry_validate|root_open|root_fstat|root_identity_validate|directory_list|entry_stat|entry_type_validate|child_directory_open|child_directory_validate|child_contents_remove|child_directory_close|child_directory_remove|file_owner_validate|file_remove|root_directory_close|root_directory_remove); context=(?:cleanup_only|secondary); exception=(?:TimeoutError|PermissionError|FileNotFoundError|FileExistsError|NotADirectoryError|IsADirectoryError|OSError|NameError|AttributeError|TypeError|ValueError|RuntimeError|OtherException)|phase=cleanup; component=(?:child_reap|preflight_reap|preflight_cache|temporary_root|socket_cleanup); state=unconfirmed)/', $helperOutput, $matches, PREG_SET_ORDER) > 0) {
                $signalDiagnostic = null;
                foreach ($matches as $match) {
                    if (str_starts_with($match[0], 'phase=cleanup; component=')) {
                        $cleanupDiagnostics[] = $match[0];
                    } elseif (str_starts_with($match[0], 'phase=progress;')) {
                        continue;
                    } elseif (str_starts_with($match[0], 'phase=wrapper_signal;')) {
                        $signalDiagnostic = $match[0];
                    } elseif (str_starts_with($match[0], 'phase=child_marker;')) {
                        continue;
                    } elseif (str_starts_with($match[0], 'phase=wrapper_timeout;')) {
                        $wrapperTimeoutDiagnostic = $match[0];
                    } else {
                        $primaryDiagnostic = $match[0];
                    }
                }
            }
            $diagnosticParts = [];
            if ($processExitCode !== 0 && $primaryDiagnostic === null) {
                $primaryDiagnostic = 'phase=root_helper; state=failed; producer=php_wrapper; stage=output_unclassified';
            }
            if ($primaryDiagnostic !== null) {
                $diagnosticParts[] = $primaryDiagnostic;
            }
            if ($signalDiagnostic !== null) {
                $diagnosticParts[] = $signalDiagnostic;
            }
            if ($timedOut) {
                $diagnosticParts[] = $cleanupConfirmed
                    ? 'phase=wrapper_timeout; cleanup_state=confirmed'
                    : 'phase=wrapper_timeout; cleanup_state=unconfirmed';
            } elseif ($wrapperTimeoutDiagnostic !== null) {
                $diagnosticParts[] = $wrapperTimeoutDiagnostic;
            }
            $diagnosticParts = array_merge($diagnosticParts, $cleanupDiagnostics);
            if ($diagnosticParts !== []) {
                $diagnostic = implode("\n", array_unique($diagnosticParts));
            }
            if ($processExitCode !== 0) {
                foreach ($cleanupDiagnostics as $cleanupDiagnostic) {
                    if (str_starts_with(
                        $cleanupDiagnostic,
                        'phase=cleanup; component=application_source; state=unconfirmed; operation=',
                    )) {
                        $emitSafeDiagnostic($cleanupDiagnostic);
                    }
                }
                $emitSafeDiagnostic(explode("\n", $diagnostic, 2)[0]);
            }
            self::assertSame(0, $processExitCode, $diagnostic);
        } catch (AssertionFailedError $failure) {
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=assertion_catch_entered');
            $emitSafeDiagnostic('phase=root_helper; state=failed; producer=php_wrapper; stage=assertion_failure');
            throw $failure;
        } catch (\Throwable $failure) {
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=throwable_catch_entered');
            $throwableClass = match (true) {
                $failure instanceof \TypeError => 'TypeError',
                $failure instanceof \ValueError => 'ValueError',
                $failure instanceof \ErrorException => 'ErrorException',
                $failure instanceof \JsonException => 'JsonException',
                $failure instanceof \PDOException => 'PDOException',
                $failure instanceof RuntimeException => 'RuntimeException',
                $failure instanceof LogicException => 'LogicException',
                $failure instanceof \Error => 'Error',
                $failure instanceof \Exception => 'Exception',
                default => 'OtherThrowable',
            };
            $throwableCode = $failure->getCode();
            $throwableCodeText = is_int($throwableCode) ? '; exception_code='.$throwableCode : '';
            $safeDiagnostic = 'phase=root_helper; state='.'failed; producer='.'php_wrapper; stage=throwable_catch; exception='.$throwableClass.$throwableCodeText;
            $emitSafeDiagnostic($safeDiagnostic);
            self::fail($safeDiagnostic);
        } finally {
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=cleanup_begin');
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
            $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=cleanup_complete');
        }

        $emitSafeDiagnostic('phase=root_helper; state=progress; producer=php_wrapper; stage=method_body_complete');
    }
}
