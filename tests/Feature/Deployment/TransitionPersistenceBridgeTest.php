<?php

namespace Tests\Feature\Deployment;

use App\Deployment\TransitionPersistenceBridge;
use App\Models\DeploymentTransitionFence;
use App\Models\TransitionBridgeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
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
        mkdir($directory, 0711, true);
        $database = $directory.DIRECTORY_SEPARATOR.'rpc.sqlite';
        $script = $directory.DIRECTORY_SEPARATOR.'root-peer.py';
        $socket = $directory.DIRECTORY_SEPARATOR.'root'.DIRECTORY_SEPARATOR.'rpc.sock';

        try {
            $pdo = new \PDO('sqlite:'.$database);
            $pdo->exec('CREATE TABLE deployment_transition_fences (id INTEGER PRIMARY KEY, fence_key TEXT, state TEXT, operation_id TEXT, generation INTEGER, boot_id TEXT, journal_seq INTEGER, journal_head_hash TEXT, evidence_digest TEXT, permit_manifest_hash TEXT, created_at TEXT, updated_at TEXT)');
            $tuple = $this->openTuple();
            $statement = $pdo->prepare('INSERT INTO deployment_transition_fences (fence_key,state,operation_id,generation,boot_id,journal_seq,journal_head_hash,evidence_digest,permit_manifest_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute(['global', ...array_values($tuple), null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            chmod($database, 0644);

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
import json, os, socket, struct, subprocess, sys
php, artisan, root, socket_path, uid, gid, database, request_json = sys.argv[1:]
uid, gid = int(uid), int(gid)
parent = os.path.dirname(socket_path)
os.mkdir(parent, 0o710)
os.chown(parent, 0, gid)
server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
server.bind(socket_path)
os.chown(socket_path, 0, gid)
os.chmod(socket_path, 0o620)
server.listen(1)
env = os.environ.copy()
env.update({'APP_ENV':'testing','APP_KEY':'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','CAREGRID_DEPLOYMENT_ENV':'testing','DB_CONNECTION':'sqlite','DB_DATABASE':database,'DB_URL':'','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','LOG_CHANNEL':'stderr'})
child = subprocess.Popen(['setpriv','--reuid='+str(uid),'--regid='+str(gid),'--init-groups',php,artisan,'deployment:transition-bridge','--connect-socket',socket_path], cwd=root, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
conn, _ = server.accept()
pid, peer_uid, peer_gid = struct.unpack('3i', conn.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize('3i')))
if (pid, peer_uid, peer_gid) != (child.pid, uid, gid):
    raise SystemExit('SO_PEERCRED mismatch')
conn.sendall((json.dumps(json.loads(request_json), sort_keys=True, separators=(',',':'))+'\n').encode())
response = bytearray()
while len(response) <= 65536 and b'\n' not in response:
    chunk = conn.recv(4096)
    if not chunk: break
    response.extend(chunk)
conn.close(); server.close()
out, err = child.communicate(timeout=20)
if child.returncode != 0: raise SystemExit('Artisan child failed: '+err.decode(errors='replace')[-1000:])
if len(response) > 65536 or not response.endswith(b'\n') or response.count(b'\n') != 1: raise SystemExit('Invalid RPC response framing')
decoded = json.loads(response)
if set(decoded) != {'version','request_id','request_digest','status','result'} or decoded['status'] != 'OK': raise SystemExit('Invalid RPC response envelope')
if (json.dumps(decoded, sort_keys=True, separators=(',',':'))+'\n').encode() != bytes(response): raise SystemExit('RPC response is not canonical compact ASCII JSON')
if decoded['request_id'] != json.loads(request_json)['request_id'] or decoded['request_digest'] != json.loads(request_json)['request_digest']: raise SystemExit('RPC identity mismatch')
if decoded['result']['state'] != 'OPEN': raise SystemExit('Unexpected fence tuple')
os.unlink(socket_path); os.rmdir(parent)
PY;
            file_put_contents($script, $scriptText);
            chmod($script, 0644);
            $command = [
                'sudo', '-n', 'python3', $script, PHP_BINARY, base_path('artisan'), base_path(),
                $socket, (string) $uid, (string) $gid, $database,
                json_encode($wireRequest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), trim($stderr.' '.$stdout));
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
