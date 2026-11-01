<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transition_v2_requests')) {
            $this->installAppendOnlyGuards('transition_v2_requests');

            return;
        }

        Schema::create('transition_v2_requests', function (Blueprint $table) {
            $table->id();
            $table->char('request_id', 36)->unique('transition_v2_requests_request_id_unique');
            $table->char('request_digest', 64);
            $table->string('operation', 32);
            $table->char('operation_id', 36);
            $table->unsignedBigInteger('generation');
            $table->char('source_tuple_digest', 64)->nullable();
            $table->char('target_tuple_digest', 64);
            $table->unsignedBigInteger('history_sequence')->unique('transition_v2_requests_history_sequence_unique');
            $table->longText('receipt_payload');
            $table->char('receipt_digest', 64);
            $table->timestamp('committed_at', 6);
            $table->timestamps(6);

            $table->index(['operation', 'committed_at'], 'transition_v2_requests_operation_committed_idx');
        });

        $this->installAppendOnlyGuards('transition_v2_requests');
    }

    public function down(): void
    {
        if (Schema::hasTable('transition_v2_requests') && DB::table('transition_v2_requests')->exists()) {
            throw new RuntimeException('Cannot roll back committed Phase 2B transition requests.');
        }

        $this->removeAppendOnlyGuards('transition_v2_requests');
        Schema::dropIfExists('transition_v2_requests');
    }

    private function installAppendOnlyGuards(string $table): void
    {
        $driver = DB::connection()->getDriverName();
        $stem = $table.'_immutable';
        $message = 'Phase 2B request receipts require the locked authoritative fence and are append-only.';
        $definitions = $this->guardDefinitions($table, $stem, $message);

        match ($driver) {
            'sqlite' => $this->installSqliteGuards($table, $definitions),
            'mysql', 'mariadb' => $this->installMySqlGuards($table, $definitions),
            default => throw new RuntimeException('The database driver cannot enforce Phase 2B append-only receipts.'),
        };
    }

    /** @return array<string, array{event: string, sqlite: string, mysql: string}> */
    private function guardDefinitions(string $table, string $stem, string $message): array
    {
        $fenceCondition = "f.fence_key = 'global' AND f.operation_id = NEW.operation_id AND f.generation = NEW.generation AND f.phase2b_state = 'HOLD' AND f.phase2b_event_sequence = NEW.history_sequence AND f.phase2b_tuple_digest = NEW.target_tuple_digest";
        $requestIdentityCondition = "NEW.operation = 'compare_and_set_hold' AND EXISTS (SELECT 1 FROM deployment_transition_fences AS f WHERE {$fenceCondition})";
        $requestConflictCondition = "EXISTS (SELECT 1 FROM {$table} AS existing WHERE (NEW.id IS NOT NULL AND existing.id = NEW.id) OR existing.request_id = NEW.request_id OR existing.history_sequence = NEW.history_sequence)";
        $insertSqlite = "CREATE TRIGGER {$stem}_insert BEFORE INSERT ON {$table} WHEN NOT ({$requestIdentityCondition}) OR {$requestConflictCondition} BEGIN SELECT RAISE(ABORT, '{$message}'); END";
        $insertMysql = "BEGIN IF NOT ({$requestIdentityCondition}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";

        return [
            $stem.'_insert' => [
                'event' => 'INSERT',
                'sqlite' => $insertSqlite,
                'mysql' => $insertMysql,
            ],
            $stem.'_update' => [
                'event' => 'UPDATE',
                'sqlite' => "CREATE TRIGGER {$stem}_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END",
                'mysql' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
            ],
            $stem.'_delete' => [
                'event' => 'DELETE',
                'sqlite' => "CREATE TRIGGER {$stem}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END",
                'mysql' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
            ],
        ];
    }

    /** @param array<string, array{event: string, sqlite: string, mysql: string}> $definitions */
    private function installSqliteGuards(string $table, array $definitions): void
    {
        foreach ($definitions as $name => $definition) {
            $existing = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if ($existing) {
                if ($existing->tbl_name !== $table
                    || $this->normalizeSql((string) $existing->sql) !== $this->normalizeSql($definition['sqlite'])) {
                    throw new RuntimeException('An existing Phase 2B SQLite trigger has an unexpected table or definition.');
                }

                continue;
            }

            DB::unprepared($definition['sqlite']);
        }
    }

    /** @param array<string, array{event: string, sqlite: string, mysql: string}> $definitions */
    private function installMySqlGuards(string $table, array $definitions): void
    {
        foreach ($definitions as $name => $definition) {
            $existing = DB::table('information_schema.triggers')
                ->selectRaw('EVENT_OBJECT_TABLE AS event_object_table, EVENT_MANIPULATION AS event_manipulation, ACTION_TIMING AS action_timing, ACTION_STATEMENT AS action_statement')
                ->where('trigger_schema', DB::raw('DATABASE()'))
                ->where('trigger_name', $name)
                ->first();
            if ($existing) {
                if ($existing->event_object_table !== $table
                    || $existing->event_manipulation !== $definition['event']
                    || $existing->action_timing !== 'BEFORE'
                    || $this->normalizeSql((string) $existing->action_statement) !== $this->normalizeSql($definition['mysql'])) {
                    throw new RuntimeException('An existing Phase 2B MySQL trigger has an unexpected table, event, or definition.');
                }

                continue;
            }

            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$definition['event']} ON {$table} FOR EACH ROW {$definition['mysql']}");
        }
    }

    private function normalizeSql(string $sql): string
    {
        return rtrim(preg_replace('/\s+/', '', strtolower(str_replace(['`', '"'], '', trim($sql)))) ?? '', ';');
    }

    private function removeAppendOnlyGuards(string $table): void
    {
        $driver = DB::connection()->getDriverName();
        $stem = $table.'_immutable';

        match ($driver) {
            'sqlite' => $this->removeSqliteGuards($stem),
            'mysql', 'mariadb' => $this->removeMySqlGuards($stem),
            default => throw new RuntimeException('The database driver cannot remove Phase 2B append-only guards.'),
        };
    }

    private function removeSqliteGuards(string $stem): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS {$stem}_insert");
        DB::unprepared("DROP TRIGGER IF EXISTS {$stem}_update");
        DB::unprepared("DROP TRIGGER IF EXISTS {$stem}_delete");
    }

    private function removeMySqlGuards(string $stem): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS {$stem}_insert");
        DB::unprepared("DROP TRIGGER IF EXISTS {$stem}_update");
        DB::unprepared("DROP TRIGGER IF EXISTS {$stem}_delete");
    }
};
