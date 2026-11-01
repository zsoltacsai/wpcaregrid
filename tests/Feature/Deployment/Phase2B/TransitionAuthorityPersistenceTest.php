<?php

namespace Tests\Feature\Deployment\Phase2B;

use App\Deployment\Phase2B\TransitionHistoryStore;
use App\Deployment\Phase2B\TransitionV2RequestStore;
use App\Models\TransitionHistoryEvent;
use App\Models\TransitionV2Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class TransitionAuthorityPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = '018f0f8e-7b1c-7abc-8abc-123456789012';

    private const BOOT_ID = 'c5e6f6c3-2c66-49cb-a2ad-c76dd3af7f93';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('deployment_transition_fences')->insert([
            'fence_key' => 'global',
            'state' => 'OPEN',
            'operation_id' => self::OPERATION_ID,
            'generation' => 3,
            'boot_id' => self::BOOT_ID,
            'journal_seq' => 41,
            'journal_head_hash' => str_repeat('1', 64),
            'evidence_digest' => str_repeat('2', 64),
            'permit_manifest_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_additive_migrations_preserve_phase2a_tuple_and_default_phase2b_to_hold(): void
    {
        self::assertTrue(Schema::hasColumn('deployment_transition_fences', 'phase2b_state'));
        self::assertTrue(Schema::hasColumn('deployment_transition_fences', 'phase2b_source_sha'));
        self::assertTrue(Schema::hasTable('transition_v2_requests'));
        self::assertTrue(Schema::hasTable('transition_history_events'));

        $legacy = DB::table('deployment_transition_fences')->where('fence_key', 'global')->first();
        self::assertSame('OPEN', $legacy->state);
        self::assertSame(self::OPERATION_ID, $legacy->operation_id);
        self::assertSame('HOLD', $legacy->phase2b_state);
        self::assertNull($legacy->phase2b_operation_id);
        self::assertSame(0, (int) $legacy->phase2b_event_sequence);

        (require database_path('migrations/2026_11_02_000001_add_phase2b_fence_tuple_columns.php'))->up();
        (require database_path('migrations/2026_11_02_000002_create_transition_v2_requests_table.php'))->up();
        (require database_path('migrations/2026_11_02_000003_create_transition_history_events_table.php'))->up();

        self::assertSame(1, DB::table('deployment_transition_fences')->count());
        self::assertSame(0, DB::table('transition_v2_requests')->count());
        self::assertSame(0, DB::table('transition_history_events')->count());
    }

    public function test_empty_phase2b_migrations_can_be_rolled_back_and_reapplied(): void
    {
        $connection = DB::connection();
        $mysql = $connection->getDriverName() === 'mysql';
        if ($mysql) {
            $connection->commit();
        }

        $fenceMigration = require database_path('migrations/2026_11_02_000001_add_phase2b_fence_tuple_columns.php');
        $requestMigration = require database_path('migrations/2026_11_02_000002_create_transition_v2_requests_table.php');
        $historyMigration = require database_path('migrations/2026_11_02_000003_create_transition_history_events_table.php');

        try {
            $historyMigration->down();
            $requestMigration->down();
            $fenceMigration->down();

            self::assertFalse(Schema::hasTable('transition_v2_requests'));
            self::assertFalse(Schema::hasTable('transition_history_events'));
            self::assertFalse(Schema::hasColumn('deployment_transition_fences', 'phase2b_state'));

            $fenceMigration->up();
            $requestMigration->up();
            $historyMigration->up();

            self::assertTrue(Schema::hasTable('transition_v2_requests'));
            self::assertTrue(Schema::hasTable('transition_history_events'));
            self::assertSame(0, DB::table('transition_v2_requests')->count());
            self::assertSame(0, DB::table('transition_history_events')->count());
        } finally {
            $fenceMigration->up();
            $requestMigration->up();
            $historyMigration->up();
            if ($mysql) {
                DB::table('deployment_transition_fences')->where('fence_key', 'global')->delete();
                if (DB::connection()->transactionLevel() === 0) {
                    DB::beginTransaction();
                }
            }
        }
    }

    public function test_migration_fails_closed_on_a_same_name_trigger_with_wrong_target_or_definition(): void
    {
        $migration = require database_path('migrations/2026_11_02_000003_create_transition_history_events_table.php');
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            $migration->up();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                foreach (['insert' => 'INSERT', 'update' => 'UPDATE', 'delete' => 'DELETE'] as $suffix => $event) {
                    $trigger = DB::table('information_schema.triggers')
                        ->where('trigger_schema', DB::raw('DATABASE()'))
                        ->where('trigger_name', 'transition_history_events_immutable_'.$suffix)
                        ->first();
                    self::assertNotNull($trigger);
                    self::assertSame('transition_history_events', $trigger->event_object_table);
                    self::assertSame($event, $trigger->event_manipulation);
                    self::assertSame('BEFORE', $trigger->action_timing);
                }
            }

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS transition_history_events_immutable_update');
        DB::unprepared('CREATE TRIGGER transition_history_events_immutable_update BEFORE UPDATE ON transition_v2_requests BEGIN SELECT 1; END');
        try {
            $migration->up();
            self::fail('A same-name trigger attached to another table must not satisfy the migration guard.');
        } catch (RuntimeException $exception) {
            self::assertSame('An existing Phase 2B SQLite trigger has an unexpected table or definition.', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS transition_history_events_immutable_update');
            $migration->up();
        }

        DB::unprepared('DROP TRIGGER IF EXISTS transition_history_events_immutable_delete');
        DB::unprepared('CREATE TRIGGER transition_history_events_immutable_delete BEFORE DELETE ON transition_history_events BEGIN SELECT 1; END');
        try {
            $migration->up();
            self::fail('A modified same-table trigger definition must not satisfy the migration guard.');
        } catch (RuntimeException $exception) {
            self::assertSame('An existing Phase 2B SQLite trigger has an unexpected table or definition.', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS transition_history_events_immutable_delete');
            $migration->up();
        }
    }

    public function test_hold_tuple_cas_receipt_and_history_commit_atomically(): void
    {
        $target = $this->tuple();
        $requestId = $this->requestId(1);
        $requestDigest = TransitionV2Request::digestForRequest($requestId, null, $target);

        $receipt = $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);

        self::assertTrue($receipt['committed']);
        self::assertSame($target, $this->store()->readTuple());
        self::assertSame(1, DB::table('transition_v2_requests')->count());
        self::assertSame(1, DB::table('transition_history_events')->count());
        self::assertDatabaseHas('transition_v2_requests', [
            'request_id' => $requestId,
            'request_digest' => $requestDigest,
            'operation' => TransitionV2Request::OPERATION,
            'operation_id' => self::OPERATION_ID,
            'generation' => 3,
            'target_tuple_digest' => TransitionV2Request::digestForTuple($target),
            'history_sequence' => 1,
        ]);
        self::assertDatabaseHas('transition_history_events', [
            'event_sequence' => 1,
            'request_id' => $requestId,
            'operation_id' => self::OPERATION_ID,
            'generation' => 3,
            'target_tuple_digest' => TransitionV2Request::digestForTuple($target),
        ]);
        self::assertSame(
            TransitionV2Request::canonicalJson($receipt),
            TransitionV2Request::canonicalJson($this->store()->readReceipt($requestId, $requestDigest) ?? []),
        );
        self::assertSame('OPEN', DB::table('deployment_transition_fences')->value('state'));
    }

    public function test_exact_request_replay_returns_receipt_without_duplicate_history(): void
    {
        $target = $this->tuple();
        $requestId = $this->requestId(2);
        $requestDigest = TransitionV2Request::digestForRequest($requestId, null, $target);
        $first = $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);
        $replay = $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);

        self::assertSame(
            TransitionV2Request::canonicalJson($first),
            TransitionV2Request::canonicalJson($replay),
        );
        self::assertSame(1, DB::table('transition_v2_requests')->count());
        self::assertSame(1, DB::table('transition_history_events')->count());
        self::assertSame(1, (int) DB::table('deployment_transition_fences')->value('phase2b_event_sequence'));
    }

    public function test_exact_replay_fails_closed_if_the_authoritative_fence_loses_its_committed_tuple(): void
    {
        $target = $this->tuple();
        $requestId = $this->requestId(23);
        $requestDigest = TransitionV2Request::digestForRequest($requestId, null, $target);
        $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);

        DB::table('deployment_transition_fences')->where('fence_key', 'global')->update([
            'phase2b_state' => 'HOLD',
            'phase2b_operation_id' => null,
            'phase2b_generation' => null,
            'phase2b_boot_id' => null,
            'phase2b_source_sha' => null,
            'phase2b_journal_seq' => null,
            'phase2b_journal_head_hash' => null,
            'phase2b_evidence_digest' => null,
            'phase2b_tuple_digest' => null,
            'phase2b_event_sequence' => 0,
        ]);

        try {
            $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);
            self::fail('A replay cannot be acknowledged after the authoritative tuple is missing.');
        } catch (RuntimeException $exception) {
            self::assertSame('An incomplete Phase 2B tuple has prior history and remains HOLD.', $exception->getMessage());
        }
    }

    public function test_reused_request_id_with_changed_digest_is_rejected(): void
    {
        $requestId = $this->requestId(3);
        $firstTarget = $this->tuple();
        $firstDigest = TransitionV2Request::digestForRequest($requestId, null, $firstTarget);
        $this->store()->compareAndSetHoldTuple($requestId, $firstDigest, null, $firstTarget);

        $changedTarget = $this->tuple(['journal_seq' => 1, 'journal_head_hash' => str_repeat('4', 64)]);
        $changedDigest = TransitionV2Request::digestForRequest($requestId, null, $changedTarget);

        try {
            $this->store()->compareAndSetHoldTuple($requestId, $changedDigest, null, $changedTarget);
            self::fail('The request ID cannot be reused with a changed digest.');
        } catch (RuntimeException $exception) {
            self::assertSame('The Phase 2B request ID is already bound to a different digest.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('transition_v2_requests')->count());
        self::assertSame(1, DB::table('transition_history_events')->count());
    }

    public function test_competing_initial_cas_requests_cannot_both_replace_the_same_fence(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->assertCompetingCasWinnerAndLoser();

            return;
        }

        self::assertTrue(function_exists('pcntl_fork'), 'MySQL Phase 2B CAS contention requires pcntl.');
        DB::connection()->commit();
        $stem = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-fnd1-cas-'.bin2hex(random_bytes(8));
        $startPath = $stem.'-start';
        $resultPaths = [$stem.'-result-1', $stem.'-result-2'];
        $children = [];
        $waited = [];

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Could not start a Phase 2B CAS contention process.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        $deadline = microtime(true) + 20;
                        while (! is_file($startPath) && microtime(true) < $deadline) {
                            usleep(10_000);
                        }
                        if (! is_file($startPath)) {
                            throw new RuntimeException('The Phase 2B CAS contention barrier timed out.');
                        }

                        $target = $this->tuple([
                            'journal_seq' => $index,
                            'journal_head_hash' => str_repeat((string) ($index + 6), 64),
                        ]);
                        $requestId = $this->requestId(24 + $index);
                        $requestDigest = TransitionV2Request::digestForRequest($requestId, null, $target);
                        $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);
                        file_put_contents($resultPaths[$index], 'COMMITTED', LOCK_EX);
                        exit(0);
                    } catch (RuntimeException $exception) {
                        if ($exception->getMessage() !== 'The Phase 2B fence tuple does not match the exact CAS source.') {
                            file_put_contents($resultPaths[$index], 'ERROR:RuntimeException', LOCK_EX);
                            exit(1);
                        }
                        file_put_contents($resultPaths[$index], 'DENIED', LOCK_EX);
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($resultPaths[$index], 'ERROR:'.get_class($exception), LOCK_EX);
                        exit(1);
                    }
                }

                $children[] = $pid;
            }

            file_put_contents($startPath, 'go', LOCK_EX);
            $deadline = microtime(true) + 30;
            foreach ($children as $pid) {
                do {
                    $status = 0;
                    $result = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($result === $pid) {
                        $waited[] = $pid;
                        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                            throw new RuntimeException('A Phase 2B CAS contention process did not exit cleanly.');
                        }
                        break;
                    }
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('A Phase 2B CAS contention process exceeded its bounded wait.');
                    }
                    usleep(10_000);
                } while (true);
            }

            $results = array_map(static fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : 'MISSING', $resultPaths);
            sort($results);
            self::assertSame(['COMMITTED', 'DENIED'], $results);
            self::assertSame(1, DB::table('transition_v2_requests')->count());
            self::assertSame(1, DB::table('transition_history_events')->count());
            self::assertSame(1, (int) DB::table('deployment_transition_fences')->value('phase2b_event_sequence'));
        } finally {
            foreach (array_diff($children, $waited) as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }

            $this->resetCommittedCasFixture();
            foreach ([$startPath, ...$resultPaths] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function assertCompetingCasWinnerAndLoser(): void
    {
        $winner = $this->tuple();
        $winnerId = $this->requestId(12);
        $winnerDigest = TransitionV2Request::digestForRequest($winnerId, null, $winner);
        $this->store()->compareAndSetHoldTuple($winnerId, $winnerDigest, null, $winner);

        $loser = $this->tuple(['journal_seq' => 1, 'journal_head_hash' => str_repeat('6', 64)]);
        $loserId = $this->requestId(13);
        $loserDigest = TransitionV2Request::digestForRequest($loserId, null, $loser);
        try {
            $this->store()->compareAndSetHoldTuple($loserId, $loserDigest, null, $loser);
            self::fail('Only one initial compare-and-set can win the global fence.');
        } catch (RuntimeException $exception) {
            self::assertSame('The Phase 2B fence tuple does not match the exact CAS source.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('transition_v2_requests')->count());
        self::assertSame(1, DB::table('transition_history_events')->count());
        self::assertSame($winner, $this->store()->readTuple());
    }

    private function resetCommittedCasFixture(): void
    {
        DB::purge();
        try {
            DB::unprepared('DROP TRIGGER IF EXISTS transition_history_events_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS transition_history_events_immutable_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS transition_v2_requests_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS transition_v2_requests_immutable_delete');
            DB::table('transition_history_events')->delete();
            DB::table('transition_v2_requests')->delete();
            DB::table('deployment_transition_fences')->where('fence_key', 'global')->update([
                'phase2b_state' => 'HOLD',
                'phase2b_operation_id' => null,
                'phase2b_generation' => null,
                'phase2b_boot_id' => null,
                'phase2b_source_sha' => null,
                'phase2b_journal_seq' => null,
                'phase2b_journal_head_hash' => null,
                'phase2b_evidence_digest' => null,
                'phase2b_tuple_digest' => null,
                'phase2b_event_sequence' => 0,
            ]);
        } finally {
            (require database_path('migrations/2026_11_02_000002_create_transition_v2_requests_table.php'))->up();
            (require database_path('migrations/2026_11_02_000003_create_transition_history_events_table.php'))->up();
            if (DB::connection()->transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }

    public function test_operation_or_generation_substitution_requires_an_authorized_boundary(): void
    {
        $initial = $this->tuple();
        $initialId = $this->requestId(14);
        $initialDigest = TransitionV2Request::digestForRequest($initialId, null, $initial);
        $this->store()->compareAndSetHoldTuple($initialId, $initialDigest, null, $initial);

        foreach ([
            $this->tuple(['operation_id' => '018f0f8e-7b1c-7abc-8abc-223456789012']),
            $this->tuple(['generation' => 4]),
        ] as $index => $substituted) {
            $id = $this->requestId(15 + $index);
            $digest = TransitionV2Request::digestForRequest($id, $initial, $substituted);
            try {
                $this->store()->compareAndSetHoldTuple($id, $digest, $initial, $substituted);
                self::fail('A CAS cannot silently substitute operation or generation identity.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('boundary', $exception->getMessage());
            }
        }

        $otherOperation = $this->tuple(['operation_id' => '018f0f8e-7b1c-7abc-8abc-323456789012']);
        $otherOperationId = $this->requestId(18);
        $otherOperationDigest = TransitionV2Request::digestForRequest($otherOperationId, null, $otherOperation);
        try {
            $this->store()->compareAndSetHoldTuple($otherOperationId, $otherOperationDigest, null, $otherOperation);
            self::fail('An initial tuple must match the authoritative global fence identity.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('authoritative global fence', $exception->getMessage());
        }

        self::assertSame($initial, $this->store()->readTuple());
        self::assertSame(1, DB::table('transition_v2_requests')->count());
    }

    public function test_stale_source_and_non_hold_target_fail_without_persistence(): void
    {
        $first = $this->tuple();
        $firstId = $this->requestId(4);
        $firstDigest = TransitionV2Request::digestForRequest($firstId, null, $first);
        $this->store()->compareAndSetHoldTuple($firstId, $firstDigest, null, $first);

        $stale = $this->tuple(['journal_seq' => 9]);
        $staleId = $this->requestId(5);
        $staleDigest = TransitionV2Request::digestForRequest($staleId, null, $stale);
        try {
            $this->store()->compareAndSetHoldTuple($staleId, $staleDigest, null, $stale);
            self::fail('An initialized tuple cannot be replaced using an empty source.');
        } catch (RuntimeException $exception) {
            self::assertSame('The Phase 2B fence tuple does not match the exact CAS source.', $exception->getMessage());
        }

        $openTarget = $this->tuple(['state' => 'OPEN']);
        $openId = $this->requestId(6);
        $openDigest = TransitionV2Request::digestForRequest($openId, $first, $openTarget);
        $this->expectException(InvalidArgumentException::class);
        $this->store()->compareAndSetHoldTuple($openId, $openDigest, $first, $openTarget);
    }

    public function test_partial_or_corrupt_tuple_never_becomes_an_initial_hold_tuple(): void
    {
        DB::table('deployment_transition_fences')
            ->where('fence_key', 'global')
            ->update(['phase2b_operation_id' => self::OPERATION_ID]);

        try {
            $this->store()->readTuple();
            self::fail('A partial Phase 2B tuple must remain fail-closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('The Phase 2B fence tuple is incomplete or corrupt and remains HOLD.', $exception->getMessage());
        }

        $target = $this->tuple();
        $requestId = $this->requestId(7);
        $requestDigest = TransitionV2Request::digestForRequest($requestId, null, $target);
        try {
            $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);
            self::fail('A partial Phase 2B tuple must not be initialized over.');
        } catch (RuntimeException $exception) {
            self::assertSame('The Phase 2B fence tuple is incomplete or corrupt and remains HOLD.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('transition_v2_requests')->count());
        self::assertSame(0, DB::table('transition_history_events')->count());
    }

    public function test_hold_cas_appends_monotonic_history_and_request_models_are_immutable(): void
    {
        $first = $this->tuple();
        $firstId = $this->requestId(8);
        $firstDigest = TransitionV2Request::digestForRequest($firstId, null, $first);
        $this->store()->compareAndSetHoldTuple($firstId, $firstDigest, null, $first);

        $second = $this->tuple(['journal_seq' => 1, 'journal_head_hash' => str_repeat('5', 64)]);
        $secondId = $this->requestId(9);
        $secondDigest = TransitionV2Request::digestForRequest($secondId, $first, $second);
        $this->store()->compareAndSetHoldTuple($secondId, $secondDigest, $first, $second);

        $events = TransitionHistoryEvent::query()->orderBy('event_sequence')->get();
        self::assertSame([1, 2], $events->pluck('event_sequence')->all());
        self::assertNull($events[0]->source_tuple_digest);
        self::assertSame(TransitionV2Request::digestForTuple($first), $events[0]->target_tuple_digest);
        self::assertSame(TransitionV2Request::digestForTuple($first), $events[1]->source_tuple_digest);
        self::assertSame(TransitionV2Request::digestForTuple($second), $events[1]->target_tuple_digest);
        self::assertCount(2, app(TransitionHistoryStore::class)->orderedForScope(self::OPERATION_ID, 3));

        $request = TransitionV2Request::query()->where('request_id', $secondId)->firstOrFail();
        $request->operation = 'other';
        try {
            $request->save();
            self::fail('A committed request receipt cannot be changed.');
        } catch (LogicException $exception) {
            self::assertSame('A committed Phase 2B request receipt is immutable.', $exception->getMessage());
        }
        try {
            $request->delete();
            self::fail('A committed request receipt cannot be deleted.');
        } catch (LogicException $exception) {
            self::assertSame('A committed Phase 2B request receipt cannot be deleted.', $exception->getMessage());
        }

        $event = $events[1];
        $event->event_type = 'OTHER';
        try {
            $event->save();
            self::fail('An existing history event cannot be changed.');
        } catch (LogicException $exception) {
            self::assertSame('A Phase 2B transition history event is immutable.', $exception->getMessage());
        }
        try {
            $event->delete();
            self::fail('An existing history event cannot be deleted.');
        } catch (LogicException $exception) {
            self::assertSame('A Phase 2B transition history event cannot be deleted.', $exception->getMessage());
        }
    }

    public function test_receipt_digest_and_readback_fail_closed_when_history_proof_is_unavailable(): void
    {
        $target = $this->tuple();
        $requestId = $this->requestId(10);
        $requestDigest = TransitionV2Request::digestForRequest($requestId, null, $target);
        $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);

        $request = TransitionV2Request::query()->where('request_id', $requestId)->firstOrFail();
        $request->setAttribute('operation', 'forged_operation');
        self::assertNull($request->verifiedReceipt($requestDigest));
        $request->setAttribute('operation', TransitionV2Request::OPERATION);
        $request->setAttribute('request_digest', str_repeat('d', 64));
        self::assertNull($request->verifiedReceipt($requestDigest));
        $request->setAttribute('request_digest', $requestDigest);
        $request->setAttribute('receipt_digest', str_repeat('f', 64));
        self::assertNull($request->verifiedReceipt($requestDigest));

        DB::table('deployment_transition_fences')->where('fence_key', 'global')->update([
            'phase2b_tuple_digest' => str_repeat('e', 64),
        ]);
        try {
            $this->store()->readTuple();
            self::fail('A corrupt tuple digest must remain HOLD.');
        } catch (RuntimeException $exception) {
            self::assertSame('The Phase 2B fence tuple is incomplete or corrupt and remains HOLD.', $exception->getMessage());
        }

        DB::table('deployment_transition_fences')->where('fence_key', 'global')->update([
            'phase2b_tuple_digest' => TransitionV2Request::digestForTuple($target),
        ]);
        $mismatchedEventRequestId = $this->requestId(19);
        $tupleDigest = TransitionV2Request::digestForTuple($target);
        $mismatchedEventRequestDigest = TransitionV2Request::digestForRequest($mismatchedEventRequestId, $target, $target);
        $mismatchedEventReceiptPayload = TransitionV2Request::canonicalJson([
            'committed' => true,
            'history_sequence' => 2,
            'operation' => TransitionV2Request::OPERATION,
            'request_digest' => $mismatchedEventRequestDigest,
            'request_id' => $mismatchedEventRequestId,
            'source' => $target,
            'target' => $target,
        ]);
        DB::table('deployment_transition_fences')->where('fence_key', 'global')->update([
            'phase2b_event_sequence' => 2,
        ]);
        DB::table('transition_v2_requests')->insert([
            'request_id' => $mismatchedEventRequestId,
            'request_digest' => $mismatchedEventRequestDigest,
            'operation' => TransitionV2Request::OPERATION,
            'operation_id' => self::OPERATION_ID,
            'generation' => 3,
            'source_tuple_digest' => $tupleDigest,
            'target_tuple_digest' => $tupleDigest,
            'history_sequence' => 2,
            'receipt_payload' => $mismatchedEventReceiptPayload,
            'receipt_digest' => hash('sha256', $mismatchedEventReceiptPayload),
            'committed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mismatchedHistoryInsertRejected = false;
        try {
            DB::table('transition_history_events')->insert([
                'event_sequence' => 2,
                'operation_id' => self::OPERATION_ID,
                'generation' => 3,
                'request_id' => $mismatchedEventRequestId,
                'request_digest' => str_repeat('d', 64),
                'event_type' => TransitionHistoryEvent::HOLD_TUPLE_COMMITTED,
                'source_tuple_digest' => $tupleDigest,
                'target_tuple_digest' => $tupleDigest,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            $mismatchedHistoryInsertRejected = true;
        }
        self::assertTrue($mismatchedHistoryInsertRejected, 'The database boundary must reject history whose request digest differs from its receipt.');
        self::assertDatabaseMissing('transition_history_events', ['request_id' => $mismatchedEventRequestId]);
        try {
            $this->store()->readReceipt($requestId, $requestDigest);
            self::fail('A request receipt without a matching history event must not be returned as verified.');
        } catch (RuntimeException $exception) {
            self::assertSame('The Phase 2B immutable history sequence is incomplete.', $exception->getMessage());
        }
    }

    public function test_history_readback_rejects_a_disconnected_source_digest_chain(): void
    {
        $target = $this->tuple();
        $firstId = $this->requestId(26);
        $firstDigest = TransitionV2Request::digestForRequest($firstId, null, $target);
        $this->store()->compareAndSetHoldTuple($firstId, $firstDigest, null, $target);

        $wrongSource = $this->tuple(['journal_seq' => 9, 'journal_head_hash' => str_repeat('9', 64)]);
        $wrongSourceDigest = TransitionV2Request::digestForTuple($wrongSource);
        $targetDigest = TransitionV2Request::digestForTuple($target);
        $requestId = $this->requestId(27);
        $requestDigest = TransitionV2Request::digestForRequest($requestId, $wrongSource, $target);
        $receiptPayload = TransitionV2Request::canonicalJson([
            'committed' => true,
            'history_sequence' => 2,
            'operation' => TransitionV2Request::OPERATION,
            'request_digest' => $requestDigest,
            'request_id' => $requestId,
            'source' => $wrongSource,
            'target' => $target,
        ]);

        DB::table('deployment_transition_fences')->where('fence_key', 'global')->update([
            'phase2b_event_sequence' => 2,
        ]);
        DB::table('transition_v2_requests')->insert([
            'request_id' => $requestId,
            'request_digest' => $requestDigest,
            'operation' => TransitionV2Request::OPERATION,
            'operation_id' => self::OPERATION_ID,
            'generation' => 3,
            'source_tuple_digest' => $wrongSourceDigest,
            'target_tuple_digest' => $targetDigest,
            'history_sequence' => 2,
            'receipt_payload' => $receiptPayload,
            'receipt_digest' => hash('sha256', $receiptPayload),
            'committed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transition_history_events')->insert([
            'event_sequence' => 2,
            'operation_id' => self::OPERATION_ID,
            'generation' => 3,
            'request_id' => $requestId,
            'request_digest' => $requestDigest,
            'event_type' => TransitionHistoryEvent::HOLD_TUPLE_COMMITTED,
            'source_tuple_digest' => $wrongSourceDigest,
            'target_tuple_digest' => $targetDigest,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(TransitionHistoryStore::class)->orderedForScope(self::OPERATION_ID, 3);
            self::fail('History readback must reject a source digest disconnected from the previous target.');
        } catch (RuntimeException $exception) {
            self::assertSame('Phase 2B history readback has a disconnected sequence or source digest.', $exception->getMessage());
        }
    }

    public function test_history_readback_rejects_a_gap_in_the_global_event_sequence(): void
    {
        $target = $this->tuple();
        $firstId = $this->requestId(30);
        $firstDigest = TransitionV2Request::digestForRequest($firstId, null, $target);
        $this->store()->compareAndSetHoldTuple($firstId, $firstDigest, null, $target);

        $tupleDigest = TransitionV2Request::digestForTuple($target);
        $requestId = $this->requestId(31);
        $requestDigest = TransitionV2Request::digestForRequest($requestId, $target, $target);
        $receiptPayload = TransitionV2Request::canonicalJson([
            'committed' => true,
            'history_sequence' => 3,
            'operation' => TransitionV2Request::OPERATION,
            'request_digest' => $requestDigest,
            'request_id' => $requestId,
            'source' => $target,
            'target' => $target,
        ]);

        DB::table('deployment_transition_fences')->where('fence_key', 'global')->update([
            'phase2b_event_sequence' => 3,
        ]);
        DB::table('transition_v2_requests')->insert([
            'request_id' => $requestId,
            'request_digest' => $requestDigest,
            'operation' => TransitionV2Request::OPERATION,
            'operation_id' => self::OPERATION_ID,
            'generation' => 3,
            'source_tuple_digest' => $tupleDigest,
            'target_tuple_digest' => $tupleDigest,
            'history_sequence' => 3,
            'receipt_payload' => $receiptPayload,
            'receipt_digest' => hash('sha256', $receiptPayload),
            'committed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transition_history_events')->insert([
            'event_sequence' => 3,
            'operation_id' => self::OPERATION_ID,
            'generation' => 3,
            'request_id' => $requestId,
            'request_digest' => $requestDigest,
            'event_type' => TransitionHistoryEvent::HOLD_TUPLE_COMMITTED,
            'source_tuple_digest' => $tupleDigest,
            'target_tuple_digest' => $tupleDigest,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(TransitionHistoryStore::class)->orderedForScope(self::OPERATION_ID, 3);
            self::fail('History readback must reject a gap in the event sequence.');
        } catch (RuntimeException $exception) {
            self::assertSame('Phase 2B history readback has a disconnected sequence or source digest.', $exception->getMessage());
        }
    }

    public function test_immutable_receipts_and_history_reject_replace_without_changing_committed_rows(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        self::assertContains($driver, ['sqlite', 'mysql', 'mariadb']);
        $originalRecursiveTriggers = $driver === 'sqlite'
            ? (int) DB::selectOne('PRAGMA recursive_triggers')->recursive_triggers
            : null;

        try {
            if ($driver === 'sqlite') {
                DB::statement('PRAGMA recursive_triggers = 0');
                self::assertSame(0, (int) DB::selectOne('PRAGMA recursive_triggers')->recursive_triggers);
            }

            $target = $this->tuple();
            $requestId = $this->requestId(30);
            $requestDigest = TransitionV2Request::digestForRequest($requestId, null, $target);
            $receipt = $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);
            self::assertSame(
                TransitionV2Request::canonicalJson($receipt),
                TransitionV2Request::canonicalJson($this->store()->readReceipt($requestId, $requestDigest) ?? []),
            );

            $historyBefore = (array) DB::table('transition_history_events')->where('request_id', $requestId)->first();
            self::assertNotEmpty($historyBefore);
            $historyReplacement = $historyBefore;
            $historyReplacement['occurred_at'] = '2000-01-01 00:00:00';
            $historyReplacement['created_at'] = '2000-01-01 00:00:00';
            $historyReplacement['updated_at'] = '2000-01-01 00:00:00';

            foreach ($driver === 'sqlite' ? [0, 1] : [null] as $recursiveTriggers) {
                if ($recursiveTriggers !== null) {
                    DB::statement('PRAGMA recursive_triggers = '.$recursiveTriggers);
                    self::assertSame($recursiveTriggers, (int) DB::selectOne('PRAGMA recursive_triggers')->recursive_triggers);
                }

                $columns = array_keys($historyReplacement);
                $quotedColumns = implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', $columns));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $sql = $driver === 'sqlite'
                    ? 'INSERT OR REPLACE INTO `transition_history_events` ('.$quotedColumns.') VALUES ('.$placeholders.')'
                    : 'REPLACE INTO `transition_history_events` ('.$quotedColumns.') VALUES ('.$placeholders.')';
                $replaceRejected = false;
                try {
                    DB::statement($sql, array_values($historyReplacement));
                } catch (\Throwable) {
                    $replaceRejected = true;
                }
                self::assertTrue($replaceRejected, 'Replacing a referenced immutable history event must be rejected.');
                self::assertSame($historyBefore, (array) DB::table('transition_history_events')->where('request_id', $requestId)->first());
            }

            $orphanRequestId = $this->requestId(31);
            $orphanSequence = (int) DB::table('deployment_transition_fences')->value('phase2b_event_sequence') + 1;
            $tupleDigest = TransitionV2Request::digestForTuple($target);
            $orphanRequestDigest = TransitionV2Request::digestForRequest($orphanRequestId, $target, $target);
            $orphanReceiptPayload = TransitionV2Request::canonicalJson([
                'committed' => true,
                'history_sequence' => $orphanSequence,
                'operation' => TransitionV2Request::OPERATION,
                'request_digest' => $orphanRequestDigest,
                'request_id' => $orphanRequestId,
                'source' => $target,
                'target' => $target,
            ]);
            DB::table('deployment_transition_fences')->where('fence_key', 'global')->update([
                'phase2b_event_sequence' => $orphanSequence,
            ]);
            DB::table('transition_v2_requests')->insert([
                'request_id' => $orphanRequestId,
                'request_digest' => $orphanRequestDigest,
                'operation' => TransitionV2Request::OPERATION,
                'operation_id' => self::OPERATION_ID,
                'generation' => 3,
                'source_tuple_digest' => $tupleDigest,
                'target_tuple_digest' => $tupleDigest,
                'history_sequence' => $orphanSequence,
                'receipt_payload' => $orphanReceiptPayload,
                'receipt_digest' => hash('sha256', $orphanReceiptPayload),
                'committed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            self::assertDatabaseMissing('transition_history_events', ['request_id' => $orphanRequestId]);
            $orphanReceipt = TransitionV2Request::query()->where('request_id', $orphanRequestId)->firstOrFail();
            self::assertSame(
                $orphanReceiptPayload,
                TransitionV2Request::canonicalJson($orphanReceipt->verifiedReceipt($orphanRequestDigest) ?? []),
            );
            $orphanRequestBefore = (array) DB::table('transition_v2_requests')->where('request_id', $orphanRequestId)->first();
            $orphanRequestReplacement = $orphanRequestBefore;
            $orphanRequestReplacement['committed_at'] = '2000-01-01 00:00:00';
            $orphanRequestReplacement['created_at'] = '2000-01-01 00:00:00';
            $orphanRequestReplacement['updated_at'] = '2000-01-01 00:00:00';

            foreach ($driver === 'sqlite' ? [0, 1] : [null] as $recursiveTriggers) {
                if ($recursiveTriggers !== null) {
                    DB::statement('PRAGMA recursive_triggers = '.$recursiveTriggers);
                    self::assertSame($recursiveTriggers, (int) DB::selectOne('PRAGMA recursive_triggers')->recursive_triggers);
                }

                $columns = array_keys($orphanRequestReplacement);
                $quotedColumns = implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', $columns));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $sql = $driver === 'sqlite'
                    ? 'INSERT OR REPLACE INTO `transition_v2_requests` ('.$quotedColumns.') VALUES ('.$placeholders.')'
                    : 'REPLACE INTO `transition_v2_requests` ('.$quotedColumns.') VALUES ('.$placeholders.')';
                $replaceRejected = false;
                try {
                    DB::statement($sql, array_values($orphanRequestReplacement));
                } catch (\Throwable) {
                    $replaceRejected = true;
                }
                self::assertTrue($replaceRejected, 'Replacing an unreferenced immutable receipt must be rejected by its insert guard.');
                self::assertSame($orphanRequestBefore, (array) DB::table('transition_v2_requests')->where('request_id', $orphanRequestId)->first());
                self::assertSame(
                    $orphanReceiptPayload,
                    TransitionV2Request::canonicalJson(TransitionV2Request::query()->where('request_id', $orphanRequestId)->firstOrFail()->verifiedReceipt($orphanRequestDigest) ?? []),
                );
            }
        } finally {
            if ($originalRecursiveTriggers !== null) {
                DB::statement('PRAGMA recursive_triggers = '.$originalRecursiveTriggers);
            }
        }
    }

    public function test_receipts_and_history_reject_database_level_update_and_delete(): void
    {
        self::assertFalse(method_exists(TransitionHistoryStore::class, 'appendCommittedTuple'));
        self::assertTrue((new \ReflectionMethod(TransitionV2RequestStore::class, 'appendHistoryEvent'))->isPrivate());

        $target = $this->tuple();
        $requestId = $this->requestId(20);
        $requestDigest = TransitionV2Request::digestForRequest($requestId, null, $target);
        $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);

        $forgedRequestId = $this->requestId(28);
        try {
            DB::table('transition_v2_requests')->insert([
                'request_id' => $forgedRequestId,
                'request_digest' => str_repeat('4', 64),
                'operation' => 'forged_operation',
                'operation_id' => self::OPERATION_ID,
                'generation' => 3,
                'source_tuple_digest' => null,
                'target_tuple_digest' => TransitionV2Request::digestForTuple($target),
                'history_sequence' => 2,
                'receipt_payload' => '{}',
                'receipt_digest' => hash('sha256', '{}'),
                'committed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::fail('The database boundary must reject a forged request operation.');
        } catch (\Throwable) {
            self::assertDatabaseMissing('transition_v2_requests', ['request_id' => $forgedRequestId]);
        }

        $unboundEventId = $this->requestId(29);
        try {
            DB::table('transition_history_events')->insert([
                'event_sequence' => 2,
                'operation_id' => self::OPERATION_ID,
                'generation' => 3,
                'request_id' => $unboundEventId,
                'request_digest' => str_repeat('5', 64),
                'event_type' => TransitionHistoryEvent::HOLD_TUPLE_COMMITTED,
                'source_tuple_digest' => TransitionV2Request::digestForTuple($target),
                'target_tuple_digest' => TransitionV2Request::digestForTuple($target),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::fail('The database boundary must reject a history event without its fence-bound receipt.');
        } catch (\Throwable) {
            self::assertDatabaseMissing('transition_history_events', ['request_id' => $unboundEventId]);
        }

        foreach ([
            ['table' => 'transition_v2_requests', 'identity' => ['request_id' => $requestId]],
            ['table' => 'transition_history_events', 'identity' => ['request_id' => $requestId]],
        ] as $record) {
            try {
                DB::table($record['table'])->where($record['identity'])->update(['request_digest' => str_repeat('e', 64)]);
                self::fail('Direct database updates to committed evidence must be rejected.');
            } catch (\Throwable) {
                self::assertDatabaseHas($record['table'], $record['identity'] + ['request_digest' => $requestDigest]);
            }
            try {
                DB::table($record['table'])->where($record['identity'])->delete();
                self::fail('Direct database deletes of committed evidence must be rejected.');
            } catch (\Throwable) {
                self::assertDatabaseHas($record['table'], $record['identity'] + ['request_digest' => $requestDigest]);
            }
        }
    }

    public function test_fence_is_read_before_receipts_and_history_and_failed_history_write_rolls_back_cas(): void
    {
        $target = $this->tuple();
        $requestId = $this->requestId(21);
        $requestDigest = TransitionV2Request::digestForRequest($requestId, null, $target);
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $injectFailure = true;
        $connection->beforeExecuting(static function (string $query) use (&$injectFailure): void {
            if ($injectFailure && str_contains(strtolower($query), 'insert') && str_contains(strtolower($query), 'transition_history_events')) {
                $injectFailure = false;
                throw new RuntimeException('Injected history persistence failure.');
            }
        });

        try {
            $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);
            self::fail('A history write failure must abort the fence CAS transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected history persistence failure.', $exception->getMessage());
        } finally {
            $queries = array_map(static fn (array $query): string => strtolower($query['query']), $connection->getQueryLog());
            $connection->disableQueryLog();
        }

        $firstFenceRead = collect($queries)->search(static fn (string $sql): bool => str_contains($sql, 'select') && str_contains($sql, 'deployment_transition_fences'));
        $firstEvidenceWrite = collect($queries)->search(static fn (string $sql): bool => str_starts_with(ltrim($sql), 'insert')
            && (str_contains($sql, 'transition_v2_requests') || str_contains($sql, 'transition_history_events')));
        self::assertNotFalse($firstFenceRead);
        self::assertNotFalse($firstEvidenceWrite);
        self::assertLessThan($firstEvidenceWrite, $firstFenceRead);
        self::assertSame(0, DB::table('transition_v2_requests')->count());
        self::assertSame(0, DB::table('transition_history_events')->count());
        self::assertDatabaseMissing('transition_v2_requests', ['request_id' => $requestId]);
        self::assertDatabaseMissing('transition_history_events', ['request_id' => $requestId]);
        self::assertSame('HOLD', DB::table('deployment_transition_fences')->value('phase2b_state'));
        self::assertNull(DB::table('deployment_transition_fences')->value('phase2b_tuple_digest'));
    }

    public function test_migrations_refuse_to_drop_committed_fence_or_history_evidence(): void
    {
        $target = $this->tuple();
        $requestId = $this->requestId(11);
        $requestDigest = TransitionV2Request::digestForRequest($requestId, null, $target);
        $this->store()->compareAndSetHoldTuple($requestId, $requestDigest, null, $target);

        foreach ([
            ['file' => '2026_11_02_000003_create_transition_history_events_table.php', 'message' => 'Cannot roll back append-only Phase 2B transition history.'],
            ['file' => '2026_11_02_000002_create_transition_v2_requests_table.php', 'message' => 'Cannot roll back committed Phase 2B transition requests.'],
            ['file' => '2026_11_02_000001_add_phase2b_fence_tuple_columns.php', 'message' => 'Cannot roll back Phase 2B fence columns while durable evidence exists.'],
        ] as $migrationCase) {
            $migration = require database_path('migrations/'.$migrationCase['file']);
            try {
                $migration->down();
                self::fail('Durable Phase 2B evidence must prevent migration rollback.');
            } catch (RuntimeException $exception) {
                self::assertSame($migrationCase['message'], $exception->getMessage());
            }
        }

        self::assertTrue(Schema::hasTable('transition_v2_requests'));
        self::assertTrue(Schema::hasTable('transition_history_events'));
        self::assertTrue(Schema::hasColumn('deployment_transition_fences', 'phase2b_tuple_digest'));
    }

    private function store(): TransitionV2RequestStore
    {
        return app(TransitionV2RequestStore::class);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function tuple(array $changes = []): array
    {
        return array_replace([
            'state' => 'HOLD',
            'operation_id' => self::OPERATION_ID,
            'generation' => 3,
            'boot_id' => self::BOOT_ID,
            'source_sha' => str_repeat('a', 40),
            'journal_seq' => 0,
            'journal_head_hash' => str_repeat('b', 64),
            'evidence_digest' => str_repeat('c', 64),
        ], $changes);
    }

    private function requestId(int $suffix): string
    {
        return '018f0f8e-7b1c-7abc-8abc-'.str_pad(dechex($suffix), 12, '0', STR_PAD_LEFT);
    }
}
