<?php

namespace Tests\Feature\Deployment\Phase2B;

use App\Deployment\Phase2B\TransitionLeasePersistenceBridge;
use App\Deployment\Phase2B\TransitionLeaseStore;
use App\Deployment\Phase2B\TransitionV2RequestStore;
use App\Deployment\TransitionPersistenceBridge;
use App\Models\DeploymentTransitionFence;
use App\Models\TransitionLeaseReceipt;
use App\Models\TransitionLeaseState;
use App\Models\TransitionV2Request;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class TransitionLeasePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = '018f0f8e-7b1c-7abc-8abc-123456789012';

    private const BOOT_ID = 'c5e6f6c3-2c66-49cb-a2ad-c76dd3af7f93';

    private static int $sequence = 100;

    private string $operationId;

    private int $generation;

    /** @var array{state:string,operation_id:string,generation:int,boot_id:string,journal_seq:int,journal_head_hash:string,evidence_digest:string} */
    private array $fenceTuple;

    protected function setUp(): void
    {
        parent::setUp();

        $priorFence = DB::table('deployment_transition_fences')->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)->first();
        $operationId = $priorFence?->phase2b_tuple_digest !== null
            ? (string) $priorFence->phase2b_operation_id
            : self::OPERATION_ID;
        $generation = $priorFence?->phase2b_tuple_digest !== null
            ? (int) $priorFence->phase2b_generation
            : 3;
        if ($priorFence?->phase2b_tuple_digest !== null
            && ($priorFence->operation_id !== $operationId || (int) $priorFence->generation !== $generation)) {
            throw new RuntimeException('The test fixture found a Phase 2B tuple that does not match the base global fence.');
        }
        DB::table('deployment_transition_fences')->updateOrInsert(
            ['fence_key' => DeploymentTransitionFence::GLOBAL_KEY],
            [
                'state' => 'OPEN',
                'operation_id' => $operationId,
                'generation' => $generation,
                'boot_id' => self::BOOT_ID,
                'journal_seq' => 41,
                'journal_head_hash' => str_repeat('1', 64),
                'evidence_digest' => str_repeat('2', 64),
                'permit_manifest_hash' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $this->fenceTuple = DeploymentTransitionFence::query()
            ->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
            ->firstOrFail()->tuple();

        $tupleStore = new TransitionV2RequestStore();
        $tuple = $tupleStore->readTuple();
        if ($tuple === null) {
            $tuple = [
                'state' => 'HOLD',
                'operation_id' => $operationId,
                'generation' => $generation,
                'boot_id' => self::BOOT_ID,
                'source_sha' => str_repeat('a', 40),
                'journal_seq' => 41,
                'journal_head_hash' => str_repeat('1', 64),
                'evidence_digest' => str_repeat('2', 64),
            ];
            $requestId = $this->uuid7();
            $tupleStore->compareAndSetHoldTuple(
                $requestId,
                TransitionV2Request::digestForRequest($requestId, null, $tuple),
                null,
                $tuple,
            );
        }

        $this->operationId = $tuple['operation_id'];
        $this->generation = $tuple['generation'];
    }

    public function test_additive_schema_preserves_existing_authority_tables_and_is_idempotent(): void
    {
        $existingTables = [
            'transition_admissions', 'transition_v2_requests', 'transition_history_events',
            'transition_bridge_requests',
        ];
        $columnsBefore = [];
        $rowsBefore = [];
        foreach ($existingTables as $table) {
            self::assertTrue(Schema::hasTable($table), "Expected additive schema table {$table}.");
            $columnsBefore[$table] = Schema::getColumnListing($table);
            $rowsBefore[$table] = DB::table($table)->count();
        }

        $before = DB::table('deployment_transition_fences')->where('fence_key', 'global')->first();
        $receiptCount = DB::table('transition_lease_receipts')->count();
        $stateCount = DB::table('transition_lease_states')->count();
        (require database_path('migrations/2026_11_02_000004_create_transition_lease_store.php'))->up();
        $after = DB::table('deployment_transition_fences')->where('fence_key', 'global')->first();

        foreach ($existingTables as $table) {
            self::assertSame($columnsBefore[$table], Schema::getColumnListing($table));
            self::assertSame($rowsBefore[$table], DB::table($table)->count());
        }
        self::assertSame($before->state, $after->state);
        self::assertSame($before->operation_id, $after->operation_id);
        self::assertSame((int) $before->generation, (int) $after->generation);
        self::assertSame($receiptCount, DB::table('transition_lease_receipts')->count());
        self::assertSame($stateCount, DB::table('transition_lease_states')->count());
        self::assertTrue(Schema::hasTable('transition_lease_receipts'));
        self::assertTrue(Schema::hasTable('transition_lease_states'));
    }

    public function test_create_claim_exact_replay_and_receipt_readback_are_durable_and_bound(): void
    {
        $create = $this->request('create_lease', $this->payload([
            'expires_at' => CarbonImmutable::now('UTC')->addMinutes(30)->setMicrosecond(123456)->format('Y-m-d\\TH:i:s.u\\Z'),
        ]));
        $created = $this->bridge()->handleRpc($create);

        self::assertSame('COMMITTED', $created['status']);
        self::assertSame('ISSUED', $created['result']['state']);
        self::assertDatabaseHas('transition_lease_states', [
            'lease_id' => $create['payload']['lease_id'], 'state_version' => 1, 'state' => 'ISSUED',
        ]);
        self::assertDatabaseHas('transition_lease_receipts', [
            'request_id' => $create['request_id'], 'nonce_digest' => $create['payload']['nonce_digest'],
        ]);
        $createdState = TransitionLeaseState::query()->where('request_id', $create['request_id'])->firstOrFail();
        self::assertSame(
            $create['payload']['expires_at'],
            $createdState->expires_at->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
        );
        self::assertSame($created, $this->bridge()->handleRpc($create));

        $claimPayload = array_intersect_key($create['payload'], array_flip([
            'operation_id', 'generation', 'execution_id', 'lease_id', 'expected_fence_state', 'fence_tuple', 'holder_digest',
        ])) + ['nonce_digest' => hash('sha256', 'synthetic-claim-nonce')];
        $claim = $this->request('claim_lease', $claimPayload);
        $claimed = $this->bridge()->handleRpc($claim);

        self::assertSame('COMMITTED', $claimed['status']);
        self::assertSame('CLAIMED', $claimed['result']['state']);
        self::assertSame(2, $claimed['result']['state_version']);
        $claimedState = TransitionLeaseState::query()->where('request_id', $claim['request_id'])->firstOrFail();
        self::assertSame(
            $create['payload']['expires_at'],
            $claimedState->expires_at->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
        );
        self::assertSame($claimed, $this->bridge()->handleRpc($this->readRequest($claim['request_id'], $claim['request_digest']))['result']['receipt']);

        $invalidState = $createdState->getAttributes();
        unset($invalidState['id']);
        $invalidState['state_digest'] = str_repeat('0', 64);
        $invalidState['request_id'] = $this->uuid7();
        $invalidState['request_digest'] = hash('sha256', 'synthetic-invalid-state-request');
        $invalidState['occurred_at'] = CarbonImmutable::now('UTC');
        try {
            TransitionLeaseState::query()->create($invalidState);
            self::fail('A state digest mismatch must be rejected by the Eloquent guard.');
        } catch (InvalidArgumentException) {
            self::assertDatabaseMissing('transition_lease_states', ['request_id' => $invalidState['request_id']]);
        }
    }

    public function test_append_only_state_guard_accepts_create_and_claim_projections(): void
    {
        $create = new TransitionLeaseState($this->appendOnlyStateAttributes());
        self::assertNull($create->appendOnlyGuardFailureCode());

        $claim = new TransitionLeaseState($this->appendOnlyStateAttributes([
            'state_version' => 2,
            'state' => 'CLAIMED',
            'event_type' => 'CLAIMED',
            'source_state_digest' => str_repeat('4', 64),
        ]));
        self::assertNull($claim->appendOnlyGuardFailureCode());
    }

    public function test_append_only_state_guard_reports_each_fixed_predicate_code(): void
    {
        $cases = [
            'MODEL_ALREADY_EXISTS' => ['exists' => true],
            'LEASE_ID_NOT_UUID_V7' => ['lease_id' => 'synthetic-invalid-lease-id'],
            'OPERATION_ID_NOT_UUID_V7' => ['operation_id' => 'synthetic-invalid-operation-id'],
            'GENERATION_NOT_INTEGER' => ['generation' => null],
            'GENERATION_NOT_NONNEGATIVE' => ['generation' => -1],
            'EXECUTION_ID_NOT_UUID_V7' => ['execution_id' => 'synthetic-invalid-execution-id'],
            'FENCE_TUPLE_DIGEST_INVALID' => ['fence_tuple_digest' => 'synthetic-invalid-fence-digest'],
            'FENCE_STATE_UNSUPPORTED' => ['fence_state' => 'SYNTHETIC_INVALID_FENCE_STATE'],
            'FENCE_BOOT_ID_INVALID' => ['fence_boot_id' => 'synthetic-invalid-boot-id'],
            'FENCE_JOURNAL_SEQ_NOT_INTEGER' => ['fence_journal_seq' => null],
            'FENCE_JOURNAL_SEQ_NOT_NONNEGATIVE' => ['fence_journal_seq' => -1],
            'FENCE_JOURNAL_HEAD_HASH_INVALID' => ['fence_journal_head_hash' => 'synthetic-invalid-head-hash'],
            'FENCE_EVIDENCE_DIGEST_INVALID' => ['fence_evidence_digest' => 'synthetic-invalid-evidence-digest'],
            'FENCE_MANIFEST_MISMATCH' => ['fence_permit_manifest_hash' => str_repeat('5', 64)],
            'STATE_VERSION_NOT_INTEGER' => ['state_version' => null],
            'STATE_VERSION_UNSUPPORTED' => ['state_version' => 3],
            'STATE_UNSUPPORTED' => ['state' => 'SYNTHETIC_INVALID_STATE'],
            'EVENT_TYPE_UNSUPPORTED' => ['event_type' => 'SYNTHETIC_INVALID_EVENT'],
            'HOLDER_DIGEST_INVALID' => ['holder_digest' => 'synthetic-invalid-holder-digest'],
            'STATE_DIGEST_INVALID' => ['state_digest' => 'synthetic-invalid-state-digest'],
            'REQUEST_DIGEST_INVALID' => ['request_digest' => 'synthetic-invalid-request-digest'],
            'SOURCE_STATE_DIGEST_INVALID' => ['source_state_digest' => 'synthetic-invalid-source-digest'],
            'REQUEST_ID_NOT_UUID_V7' => ['request_id' => 'synthetic-invalid-request-id'],
            'EXPIRY_MISSING' => ['expires_at' => null],
            'OCCURRED_AT_MISSING' => ['occurred_at' => null],
            'VERSION_STATE_PAIR_INVALID' => ['event_type' => 'CLAIMED'],
            'FENCE_BINDING_INVALID' => ['fence_tuple_digest' => str_repeat('6', 64)],
            'STATE_DIGEST_MISMATCH' => ['holder_digest' => str_repeat('7', 64)],
        ];

        foreach ($cases as $expectedCode => $overrides) {
            $attributes = $this->appendOnlyStateAttributes();
            if ($expectedCode === 'MODEL_ALREADY_EXISTS') {
                $overrides = [];
            }
            $model = new TransitionLeaseState(array_replace($attributes, $overrides));
            if ($expectedCode === 'MODEL_ALREADY_EXISTS') {
                $model->exists = true;
            }

            self::assertSame($expectedCode, $model->appendOnlyGuardFailureCode(), "Unexpected guard result for {$expectedCode}.");
        }
    }

    public function test_append_only_state_save_diagnostic_contains_only_fixed_stage_and_predicate_codes(): void
    {
        foreach ([
            'CREATE_STATE_SAVE' => ['create_lease', 'CREATE_STATE_SAVE'],
            'CLAIM_STATE_SAVE' => ['claim_lease', 'CLAIM_STATE_SAVE'],
        ] as $expected => [$operation, $stage]) {
            $attributes = $this->appendOnlyStateAttributes(['lease_id' => 'synthetic-sensitive-lease-value']);
            $model = new TransitionLeaseState($attributes);
            $model->setAppendOnlyDiagnosticStage($stage);

            try {
                $model->save();
                self::fail("The {$operation} diagnostic fixture must be rejected.");
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    "Invalid append-only lease state. [{$expected}:LEASE_ID_NOT_UUID_V7]",
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString('synthetic-sensitive-lease-value', $exception->getMessage());
            }
        }
    }

    public function test_mysql_receipt_trigger_accepts_committed_and_refused_json_boolean_flags(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('MySQL JSON boolean trigger semantics require the MySQL server lane.');
        }

        $payload = $this->payload();
        $created = $this->bridge()->handleRpc($this->request('create_lease', $payload));
        self::assertSame('COMMITTED', $created['status']);

        $replayedNonce = $this->payload(['nonce_digest' => $payload['nonce_digest']]);
        $refused = $this->bridge()->handleRpc($this->request('create_lease', $replayedNonce));
        self::assertSame('REFUSED', $refused['status']);
        self::assertSame('NONCE_REPLAY', $refused['result']['result_code']);
    }

    public function test_nonce_replay_and_stale_generation_are_refused_without_state_change(): void
    {
        $firstPayload = $this->payload();
        $first = $this->request('create_lease', $firstPayload);
        $this->bridge()->handleRpc($first);
        $stateCount = TransitionLeaseState::query()->count();
        $admissionCount = DB::table('transition_admissions')->count();

        $replayPayload = $this->payload(['nonce_digest' => $firstPayload['nonce_digest']]);
        $replay = $this->bridge()->handleRpc($this->request('create_lease', $replayPayload));
        self::assertSame('REFUSED', $replay['status']);
        self::assertSame('NONCE_REPLAY', $replay['result']['result_code']);
        self::assertNull($replay['result']['nonce_digest']);
        self::assertSame($stateCount, TransitionLeaseState::query()->count());
        self::assertSame($admissionCount, DB::table('transition_admissions')->count());

        $stalePayload = $this->payload(['generation' => 4]);
        $stalePayload['fence_tuple']['generation'] = 4;
        $stale = $this->bridge()->handleRpc($this->request('create_lease', $stalePayload));
        self::assertSame('STALE_GENERATION', $stale['result']['result_code']);
        self::assertSame($stateCount, TransitionLeaseState::query()->count());
    }

    public function test_same_generation_new_fence_epoch_cannot_claim_an_old_lease(): void
    {
        $payload = $this->payload();
        $createRequest = $this->request('create_lease', $payload);
        self::assertSame('COMMITTED', $this->bridge()->handleRpc($createRequest)['status']);

        // Model Package A advancing through a same-operation/generation transition
        // cycle: its canonical journal epoch advances while the Phase 2B HOLD tuple
        // remains unchanged. A lease bound only to operation/generation would be stale.
        DB::table('deployment_transition_fences')->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
            ->update([
                'journal_seq' => $this->fenceTuple['journal_seq'] + 1,
                'journal_head_hash' => str_repeat('3', 64),
                'evidence_digest' => str_repeat('4', 64),
                'updated_at' => now(),
            ]);

        try {
            $this->bridge()->handleRpc($createRequest);
            self::fail('Exact mutation replay from a stale Package A epoch must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('An exact lease mutation replay belongs to a stale root fence epoch.', $exception->getMessage());
        }

        $claimPayload = array_intersect_key($payload, array_flip([
            'operation_id', 'generation', 'execution_id', 'lease_id', 'expected_fence_state', 'fence_tuple', 'holder_digest',
        ])) + ['nonce_digest' => hash('sha256', 'old-epoch-claim')];
        $claim = $this->bridge()->handleRpc($this->request('claim_lease', $claimPayload));

        self::assertSame('REFUSED', $claim['status']);
        self::assertSame('STALE_FENCE_TUPLE', $claim['result']['result_code']);
        self::assertSame(1, TransitionLeaseState::query()->where('lease_id', $payload['lease_id'])->count());
    }

    public function test_drain_manifest_is_bound_to_the_lease_fence_and_exact_receipt(): void
    {
        DB::table('deployment_transition_fences')->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
            ->update([
                'state' => 'DRAINING',
                'journal_seq' => $this->fenceTuple['journal_seq'] + 1,
                'journal_head_hash' => str_repeat('5', 64),
                'evidence_digest' => str_repeat('6', 64),
                'permit_manifest_hash' => str_repeat('7', 64),
                'updated_at' => now(),
            ]);
        $drainingTuple = DeploymentTransitionFence::query()
            ->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
            ->firstOrFail()->completeTuple();
        $payload = $this->payload([
            'expected_fence_state' => 'DRAINING',
            'fence_tuple' => $drainingTuple,
        ]);
        $create = $this->request('create_lease', $payload);
        $created = $this->bridge()->handleRpc($create);
        self::assertSame('COMMITTED', $created['status']);
        self::assertSame(str_repeat('7', 64), $created['result']['fence_permit_manifest_hash']);
        self::assertSame($created, $this->bridge()->handleRpc($create));

        DB::table('deployment_transition_fences')->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)
            ->update(['permit_manifest_hash' => str_repeat('8', 64), 'updated_at' => now()]);
        $claimPayload = array_intersect_key($payload, array_flip([
            'operation_id', 'generation', 'execution_id', 'lease_id', 'expected_fence_state', 'fence_tuple', 'holder_digest',
        ])) + ['nonce_digest' => hash('sha256', 'draining-stale-manifest-claim')];
        $claim = $this->bridge()->handleRpc($this->request('claim_lease', $claimPayload));
        self::assertSame('REFUSED', $claim['status']);
        self::assertSame('STALE_FENCE_TUPLE', $claim['result']['result_code']);
        self::assertSame(1, TransitionLeaseState::query()->where('lease_id', $payload['lease_id'])->count());
    }

    public function test_reject_requires_the_lease_holder_and_preserves_rejection_reason(): void
    {
        $payload = $this->payload();
        $this->bridge()->handleRpc($this->request('create_lease', $payload));

        $rejectPayload = array_intersect_key($payload, array_flip([
            'operation_id', 'generation', 'execution_id', 'lease_id', 'expected_fence_state', 'fence_tuple',
        ])) + [
            'holder_digest' => hash('sha256', 'different-holder'),
            'nonce_digest' => hash('sha256', 'synthetic-reject-nonce'),
            'rejection_code' => 'OWNER_REVOKED',
        ];
        $refused = $this->bridge()->handleRpc($this->request('reject_lease', $rejectPayload));
        self::assertSame('HOLDER_MISMATCH', $refused['result']['result_code']);
        self::assertSame(1, TransitionLeaseState::query()->count());

        $rejectPayload['holder_digest'] = $payload['holder_digest'];
        $rejectPayload['nonce_digest'] = hash('sha256', 'synthetic-reject-nonce-authorized');
        $committed = $this->bridge()->handleRpc($this->request('reject_lease', $rejectPayload));
        self::assertSame('COMMITTED', $committed['status']);
        self::assertSame('OWNER_REVOKED', $committed['result']['result_code']);
        self::assertSame('REJECTED', $committed['result']['state']);
    }

    public function test_duplicate_request_with_changed_digest_and_mismatched_readback_fail_closed(): void
    {
        $request = $this->request('create_lease', $this->payload());
        $this->bridge()->handleRpc($request);

        $conflict = $request;
        $conflict['payload']['execution_id'] = $this->uuid7();
        $conflict['request_digest'] = TransitionLeasePersistenceBridge::digestForRequest(
            $conflict['request_id'], $conflict['operation'], $conflict['payload'],
        );
        try {
            $this->bridge()->handleRpc($conflict);
            self::fail('A request ID cannot be rebound to another digest.');
        } catch (RuntimeException $exception) {
            self::assertSame('Lease request identity conflicts with or corrupts a durable receipt.', $exception->getMessage());
        }

        try {
            $this->bridge()->handleRpc($this->readRequest($request['request_id'], str_repeat('f', 64)));
            self::fail('Receipt readback must reject a different request digest.');
        } catch (RuntimeException $exception) {
            self::assertSame('Lease receipt readback is absent, corrupt, or bound to another request digest.', $exception->getMessage());
        }
        self::assertSame(1, TransitionLeaseState::query()->count());
    }

    public function test_receipt_database_guard_rejects_action_result_mismatch(): void
    {
        $requestId = $this->uuid7();
        $payload = $this->payload();
        $payload['nonce_digest'] = hash('sha256', 'raw-sql-receipt-nonce');
        $receipt = [
            'action' => 'create_lease', 'committed' => true,
            'execution_id' => $payload['execution_id'], ...$this->fenceColumns($payload['fence_tuple']),
            'generation' => $payload['generation'], 'lease_id' => $payload['lease_id'],
            'nonce_digest' => $payload['nonce_digest'], 'operation_id' => $payload['operation_id'],
            'outcome' => 'COMMITTED', 'request_digest' => str_repeat('a', 64), 'request_id' => $requestId,
            'result_code' => 'CLAIMED', 'source_state_digest' => null, 'state' => 'CLAIMED',
            'state_digest' => str_repeat('b', 64), 'state_version' => 2,
        ];
        $json = TransitionLeaseReceipt::canonicalJson($receipt);

        try {
            DB::table('transition_lease_receipts')->insert([
                'request_id' => $requestId, 'request_digest' => str_repeat('a', 64), 'action' => 'create_lease',
                'operation_id' => $payload['operation_id'], 'generation' => $payload['generation'],
                'execution_id' => $payload['execution_id'], 'lease_id' => $payload['lease_id'],
                ...$this->fenceColumns($payload['fence_tuple']), 'nonce_digest' => $payload['nonce_digest'],
                'outcome' => 'COMMITTED', 'result_code' => 'CLAIMED', 'receipt_payload' => $json,
                'receipt_digest' => hash('sha256', $json), 'committed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::fail('A create receipt cannot claim a lease.');
        } catch (QueryException) {
            self::assertDatabaseMissing('transition_lease_receipts', ['request_id' => $requestId]);
        }
    }

    public function test_readback_holds_on_receipt_digest_corruption(): void
    {
        $requestId = $this->uuid7();
        $payload = $this->payload();
        $requestDigest = str_repeat('e', 64);
        $receipt = [
            'action' => 'create_lease', 'committed' => false,
            'execution_id' => $payload['execution_id'], ...$this->fenceColumns($payload['fence_tuple']),
            'generation' => $payload['generation'], 'lease_id' => $payload['lease_id'],
            'nonce_digest' => hash('sha256', 'corrupt-receipt-synthetic-nonce'),
            'operation_id' => $payload['operation_id'], 'outcome' => 'REFUSED',
            'request_digest' => $requestDigest, 'request_id' => $requestId,
            'result_code' => 'STALE_GENERATION', 'source_state_digest' => null,
            'state' => null, 'state_digest' => null, 'state_version' => null,
        ];
        $json = TransitionLeaseReceipt::canonicalJson($receipt);
        DB::table('transition_lease_receipts')->insert([
            'request_id' => $requestId, 'request_digest' => $requestDigest, 'action' => 'create_lease',
            'operation_id' => $payload['operation_id'], 'generation' => $payload['generation'],
            'execution_id' => $payload['execution_id'], 'lease_id' => $payload['lease_id'],
            ...$this->fenceColumns($payload['fence_tuple']),
            'nonce_digest' => $receipt['nonce_digest'], 'outcome' => 'REFUSED',
            'result_code' => 'STALE_GENERATION', 'receipt_payload' => $json,
            'receipt_digest' => str_repeat('0', 64), 'committed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->bridge()->handleRpc($this->readRequest($requestId, $requestDigest));
            self::fail('Corrupt receipt digest must fail closed during authoritative readback.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Lease receipt readback is absent, corrupt, or bound to another request digest.',
                $exception->getMessage(),
            );
        }
        self::assertSame(0, TransitionLeaseState::query()->count());
    }

    public function test_database_guard_rejects_claim_that_changes_holder_or_expiry(): void
    {
        $payload = $this->payload();
        $this->bridge()->handleRpc($this->request('create_lease', $payload));
        $prior = TransitionLeaseState::query()->where('lease_id', $payload['lease_id'])->firstOrFail();
        $requestId = $this->uuid7();
        $nonceDigest = hash('sha256', 'synthetic-forged-claim-nonce');
        $expiresAt = now('UTC')->addMinutes(20)->format('Y-m-d H:i:s.u');
        $projection = [
            'lease_id' => $payload['lease_id'], 'operation_id' => $payload['operation_id'],
            'generation' => $payload['generation'], 'execution_id' => $payload['execution_id'],
            ...$this->fenceColumns($payload['fence_tuple']), 'state_version' => 2,
            'state' => 'CLAIMED', 'event_type' => 'CLAIMED', 'holder_digest' => hash('sha256', 'changed-holder'),
            'expires_at' => CarbonImmutable::parse($expiresAt)->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
            'source_state_digest' => $prior->state_digest,
        ];
        $stateDigest = TransitionLeaseState::digestForState($projection);
        $requestDigest = str_repeat('c', 64);
        $receipt = [
            'action' => 'claim_lease', 'committed' => true,
            'execution_id' => $payload['execution_id'], ...$this->fenceColumns($payload['fence_tuple']),
            'generation' => $payload['generation'], 'lease_id' => $payload['lease_id'],
            'nonce_digest' => $nonceDigest, 'operation_id' => $payload['operation_id'],
            'outcome' => 'COMMITTED', 'request_digest' => $requestDigest, 'request_id' => $requestId,
            'result_code' => 'CLAIMED', 'source_state_digest' => $prior->state_digest,
            'state' => 'CLAIMED', 'state_digest' => $stateDigest, 'state_version' => 2,
        ];
        $json = TransitionLeaseReceipt::canonicalJson($receipt);
        DB::table('transition_lease_receipts')->insert([
            'request_id' => $requestId, 'request_digest' => $requestDigest, 'action' => 'claim_lease',
            'operation_id' => $payload['operation_id'], 'generation' => $payload['generation'],
            'execution_id' => $payload['execution_id'], 'lease_id' => $payload['lease_id'],
            ...$this->fenceColumns($payload['fence_tuple']), 'nonce_digest' => $nonceDigest,
            'outcome' => 'COMMITTED', 'result_code' => 'CLAIMED', 'receipt_payload' => $json,
            'receipt_digest' => hash('sha256', $json), 'committed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::table('transition_lease_states')->insert([
                ...$projection, 'holder_digest' => hash('sha256', 'changed-holder'),
                'expires_at' => $expiresAt, 'source_state_digest' => $prior->state_digest,
                'state_digest' => $stateDigest, 'request_id' => $requestId,
                'request_digest' => $requestDigest, 'occurred_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::fail('A state transition cannot change the original holder or expiry.');
        } catch (QueryException) {
            self::assertSame(1, TransitionLeaseState::query()->where('lease_id', $payload['lease_id'])->count());
        }
    }

    public function test_database_guard_rejects_claim_that_changes_expiry_alone(): void
    {
        $payload = $this->payload();
        $this->bridge()->handleRpc($this->request('create_lease', $payload));
        $prior = TransitionLeaseState::query()->where('lease_id', $payload['lease_id'])->firstOrFail();
        $requestId = $this->uuid7();
        $nonceDigest = hash('sha256', 'synthetic-forged-expiry-claim-nonce');
        $expiresAt = CarbonImmutable::parse($prior->expires_at)->utc()->addMinute()->format('Y-m-d H:i:s.u');
        $projection = [
            'lease_id' => $payload['lease_id'], 'operation_id' => $payload['operation_id'],
            'generation' => $payload['generation'], 'execution_id' => $payload['execution_id'],
            ...$this->fenceColumns($payload['fence_tuple']), 'state_version' => 2,
            'state' => 'CLAIMED', 'event_type' => 'CLAIMED', 'holder_digest' => $prior->holder_digest,
            'expires_at' => CarbonImmutable::parse($expiresAt)->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
            'source_state_digest' => $prior->state_digest,
        ];
        $stateDigest = TransitionLeaseState::digestForState($projection);
        $requestDigest = str_repeat('d', 64);
        $receipt = [
            'action' => 'claim_lease', 'committed' => true,
            'execution_id' => $payload['execution_id'], ...$this->fenceColumns($payload['fence_tuple']),
            'generation' => $payload['generation'], 'lease_id' => $payload['lease_id'],
            'nonce_digest' => $nonceDigest, 'operation_id' => $payload['operation_id'],
            'outcome' => 'COMMITTED', 'request_digest' => $requestDigest, 'request_id' => $requestId,
            'result_code' => 'CLAIMED', 'source_state_digest' => $prior->state_digest,
            'state' => 'CLAIMED', 'state_digest' => $stateDigest, 'state_version' => 2,
        ];
        $json = TransitionLeaseReceipt::canonicalJson($receipt);
        DB::table('transition_lease_receipts')->insert([
            'request_id' => $requestId, 'request_digest' => $requestDigest, 'action' => 'claim_lease',
            'operation_id' => $payload['operation_id'], 'generation' => $payload['generation'],
            'execution_id' => $payload['execution_id'], 'lease_id' => $payload['lease_id'],
            ...$this->fenceColumns($payload['fence_tuple']), 'nonce_digest' => $nonceDigest,
            'outcome' => 'COMMITTED', 'result_code' => 'CLAIMED', 'receipt_payload' => $json,
            'receipt_digest' => hash('sha256', $json), 'committed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::table('transition_lease_states')->insert([
                ...$projection, 'request_id' => $requestId, 'request_digest' => $requestDigest,
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            self::fail('A state transition cannot change the original expiry.');
        } catch (QueryException) {
            self::assertSame(1, TransitionLeaseState::query()->where('lease_id', $payload['lease_id'])->count());
        }
    }

    public function test_transaction_rollback_leaves_no_receipt_or_lease_state(): void
    {
        $request = $this->request('create_lease', $this->payload());
        try {
            DB::transaction(function () use ($request): void {
                $this->bridge()->handleRpc($request);
                throw new RuntimeException('synthetic rollback after lease receipt and state writes');
            });
            self::fail('The synthetic transaction must roll back.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic rollback after lease receipt and state writes', $exception->getMessage());
        }

        self::assertDatabaseMissing('transition_lease_receipts', ['request_id' => $request['request_id']]);
        self::assertDatabaseMissing('transition_lease_states', ['request_id' => $request['request_id']]);
    }

    public function test_database_triggers_reject_receipt_and_state_update_or_delete(): void
    {
        $request = $this->request('create_lease', $this->payload());
        $this->bridge()->handleRpc($request);

        foreach ([
            ['transition_lease_receipts', 'request_id', $request['request_id']],
            ['transition_lease_states', 'request_id', $request['request_id']],
        ] as [$table, $key, $value]) {
            try {
                DB::table($table)->where($key, $value)->update(['updated_at' => now()]);
                self::fail('Append-only lease rows must reject updates.');
            } catch (QueryException) {
                self::assertDatabaseHas($table, [$key => $value]);
            }
            try {
                DB::table($table)->where($key, $value)->delete();
                self::fail('Append-only lease rows must reject deletes.');
            } catch (QueryException) {
                self::assertDatabaseHas($table, [$key => $value]);
            }
        }
    }

    public function test_committed_receipt_can_reconcile_a_lost_response_without_mutation_replay(): void
    {
        $request = $this->request('create_lease', $this->payload());
        $first = $this->bridge()->handleRpc($request);
        $stateCount = TransitionLeaseState::query()->count();
        $read = $this->bridge()->handleRpc($this->readRequest($request['request_id'], $request['request_digest']));

        self::assertSame('READ_ONLY', $read['status']);
        self::assertTrue($read['result']['found']);
        self::assertSame($first['result'], $read['result']['receipt']);
        self::assertSame($stateCount, TransitionLeaseState::query()->count());
    }

    public function test_process_crash_after_sql_commit_is_reconciled_by_exact_readback(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::assertTrue(true, 'The process-crash durability check runs in the required MySQL 8.4/8.5 jobs.');

            return;
        }

        self::assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'), 'MySQL crash recovery requires process controls.');
        $request = $this->request('create_lease', $this->payload());
        DB::connection()->commit();
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('Could not start the isolated lease crash process.');
        }
        if ($pid === 0) {
            DB::purge();
            (new TransitionLeasePersistenceBridge(new TransitionLeaseStore()))->handleRpc($request);
            posix_kill(getmypid(), SIGKILL);
            exit(255);
        }

        try {
            $status = 0;
            self::assertSame($pid, pcntl_waitpid($pid, $status));
            self::assertTrue(pcntl_wifsignaled($status));
            self::assertSame(SIGKILL, pcntl_wtermsig($status));

            $read = $this->bridge()->handleRpc($this->readRequest($request['request_id'], $request['request_digest']));
            self::assertSame('READ_ONLY', $read['status']);
            self::assertTrue($read['result']['found']);
            self::assertSame('COMMITTED', $read['result']['receipt']['outcome']);
            self::assertDatabaseHas('transition_lease_states', [
                'request_id' => $request['request_id'], 'state' => 'ISSUED', 'state_version' => 1,
            ]);
        } finally {
            if (DB::connection()->transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }

    public function test_unknown_protocol_version_operation_or_extra_keys_are_rejected_without_writes(): void
    {
        $request = $this->request('create_lease', $this->payload());
        $admissionCount = DB::table('transition_admissions')->count();
        $receiptCount = TransitionLeaseReceipt::query()->count();
        $stateCount = TransitionLeaseState::query()->count();
        foreach ([
            array_replace($request, ['version' => 1]),
            array_replace($request, ['operation' => 'release_host']),
            $request + ['unexpected' => true],
        ] as $invalid) {
            try {
                $this->bridge()->handleRpc($invalid);
                self::fail('An unknown or malformed RPC envelope must fail closed.');
            } catch (InvalidArgumentException) {
                self::assertSame($receiptCount, TransitionLeaseReceipt::query()->count());
                self::assertSame($stateCount, TransitionLeaseState::query()->count());
                self::assertSame($admissionCount, DB::table('transition_admissions')->count());
            }
        }

        self::assertSame(1, TransitionPersistenceBridge::PROTOCOL_VERSION);
        self::assertSame(2, TransitionLeasePersistenceBridge::PROTOCOL_VERSION);
    }

    public function test_nonempty_additive_schema_refuses_destructive_rollback(): void
    {
        $request = $this->request('create_lease', $this->payload());
        $this->bridge()->handleRpc($request);
        $migration = require database_path('migrations/2026_11_02_000004_create_transition_lease_store.php');

        try {
            $migration->down();
            self::fail('Durable lease rows must prevent destructive schema rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame('Cannot roll back durable transition lease receipts or state; preserve the data and remain HOLD.', $exception->getMessage());
        }

        self::assertTrue(Schema::hasTable('transition_lease_receipts'));
        self::assertTrue(Schema::hasTable('transition_lease_states'));
        self::assertDatabaseHas('transition_lease_receipts', ['request_id' => $request['request_id']]);
    }

    public function test_parallel_mysql_claims_commit_at_most_one_terminal_state(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::assertTrue(true, 'Process concurrency is asserted in the required MySQL 8.4/8.5 jobs.');

            return;
        }

        self::assertTrue(function_exists('pcntl_fork'), 'MySQL lease concurrency requires process support.');
        $payload = $this->payload();
        $this->bridge()->handleRpc($this->request('create_lease', $payload));
        $claims = [];
        for ($index = 0; $index < 2; $index++) {
            $claim = array_intersect_key($payload, array_flip([
                'operation_id', 'generation', 'execution_id', 'lease_id', 'expected_fence_state', 'fence_tuple', 'holder_digest',
            ])) + ['nonce_digest' => hash('sha256', 'parallel-claim-'.$index)];
            $claims[] = $this->request('claim_lease', $claim);
        }
        DB::connection()->commit();

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-lease-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            self::fail('Could not create the isolated lease concurrency directory.');
        }
        $startPath = $directory.DIRECTORY_SEPARATOR.'start';
        $resultPaths = [$directory.DIRECTORY_SEPARATOR.'one', $directory.DIRECTORY_SEPARATOR.'two'];
        $children = [];
        $waited = [];

        try {
            foreach ($resultPaths as $index => $resultPath) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Could not start a lease claim process.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        $deadline = microtime(true) + 30;
                        while (! is_file($startPath) && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (! is_file($startPath)) {
                            throw new RuntimeException('Concurrent lease claim barrier timed out.');
                        }
                        $response = (new TransitionLeasePersistenceBridge(new TransitionLeaseStore()))
                            ->handleRpc($claims[$index]);
                        file_put_contents($resultPath, $response['result']['result_code'], LOCK_EX);
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
                    throw new RuntimeException('A concurrent lease claim process failed.');
                }
                $waited[] = $pid;
            }

            $results = array_map(static fn (string $path): string => (string) file_get_contents($path), $resultPaths);
            sort($results);
            self::assertSame(['CLAIMED', 'LEASE_NOT_CLAIMABLE'], $results);
            self::assertSame(2, TransitionLeaseState::query()->where('lease_id', $payload['lease_id'])->count());
        } finally {
            foreach (array_diff($children, $waited) as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (array_merge([$startPath], $resultPaths) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            if (DB::connection()->transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }

    private function bridge(): TransitionLeasePersistenceBridge
    {
        return new TransitionLeasePersistenceBridge(new TransitionLeaseStore());
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function appendOnlyStateAttributes(array $overrides = []): array
    {
        $payload = $this->payload();
        $tuple = $payload['fence_tuple'];
        $projection = array_replace([
            'lease_id' => $payload['lease_id'],
            'operation_id' => $payload['operation_id'],
            'generation' => $payload['generation'],
            'execution_id' => $payload['execution_id'],
            'fence_tuple_digest' => TransitionLeasePersistenceBridge::digestForFenceTuple($tuple),
            'fence_state' => $tuple['state'],
            'fence_boot_id' => $tuple['boot_id'],
            'fence_journal_seq' => $tuple['journal_seq'],
            'fence_journal_head_hash' => $tuple['journal_head_hash'],
            'fence_evidence_digest' => $tuple['evidence_digest'],
            'fence_permit_manifest_hash' => $tuple['permit_manifest_hash'] ?? null,
            'state_version' => 1,
            'state' => 'ISSUED',
            'event_type' => 'CREATED',
            'holder_digest' => $payload['holder_digest'],
            'expires_at' => CarbonImmutable::parse($payload['expires_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'source_state_digest' => null,
        ], $overrides);
        $projection['state_digest'] = TransitionLeaseState::digestForState($projection);

        return [
            ...$projection,
            'request_id' => $this->uuid7(),
            'request_digest' => hash('sha256', 'synthetic-request-digest'),
            'occurred_at' => now()->utc(),
        ];
    }

    /** @param array<string, mixed> $tuple @return array<string, mixed> */
    private function fenceColumns(array $tuple): array
    {
        return [
            'fence_tuple_digest' => TransitionLeasePersistenceBridge::digestForFenceTuple($tuple),
            'fence_state' => $tuple['state'],
            'fence_boot_id' => $tuple['boot_id'],
            'fence_journal_seq' => $tuple['journal_seq'],
            'fence_journal_head_hash' => $tuple['journal_head_hash'],
            'fence_evidence_digest' => $tuple['evidence_digest'],
            'fence_permit_manifest_hash' => $tuple['permit_manifest_hash'] ?? null,
        ];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'operation_id' => $this->operationId,
            'generation' => $this->generation,
            'fence_tuple' => $this->fenceTuple,
            'execution_id' => $this->uuid7(),
            'lease_id' => $this->uuid7(),
            'expected_fence_state' => 'OPEN',
            'holder_digest' => hash('sha256', 'synthetic-holder'),
            'expires_at' => now('UTC')->addMinutes(30)->format('Y-m-d\TH:i:s.u\Z'),
            'nonce_digest' => hash('sha256', 'synthetic-nonce-'.self::$sequence++),
        ], $overrides);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $operation, array $payload): array
    {
        $requestId = $this->uuid7();

        return [
            'version' => TransitionLeasePersistenceBridge::PROTOCOL_VERSION,
            'request_id' => $requestId,
            'request_digest' => TransitionLeasePersistenceBridge::digestForRequest($requestId, $operation, $payload),
            'operation' => $operation,
            'payload' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    private function readRequest(string $targetRequestId, string $targetDigest, ?array $fenceTuple = null): array
    {
        return $this->request('read_lease_receipt', [
            'request_id' => $targetRequestId,
            'request_digest' => $targetDigest,
            'fence_tuple' => $fenceTuple ?? DeploymentTransitionFence::query()
                ->where('fence_key', DeploymentTransitionFence::GLOBAL_KEY)->firstOrFail()->completeTuple(),
        ]);
    }

    private function uuid7(): string
    {
        return sprintf('018f0f8e-7b1c-7abc-8abc-%012d', self::$sequence++);
    }
}
