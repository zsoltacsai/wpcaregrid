<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transition_history_events')) {
            $this->installAppendOnlyGuards('transition_history_events');

            return;
        }

        if (! Schema::hasTable('transition_v2_requests')) {
            throw new RuntimeException('The Phase 2B request receipt table is required before transition history.');
        }

        Schema::create('transition_history_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_sequence')->unique('transition_history_events_sequence_unique');
            $table->char('operation_id', 36);
            $table->unsignedBigInteger('generation');
            $table->char('request_id', 36)->unique('transition_history_events_request_id_unique');
            $table->char('request_digest', 64);
            $table->string('event_type', 32);
            $table->char('source_tuple_digest', 64)->nullable();
            $table->char('target_tuple_digest', 64);
            $table->timestamp('occurred_at', 6);
            $table->timestamps(6);

            $table->foreign('request_id', 'transition_history_events_request_fk')
                ->references('request_id')->on('transition_v2_requests')->restrictOnDelete();
            $table->index(['operation_id', 'generation', 'event_sequence'], 'transition_history_events_scope_sequence_idx');
        });

        $this->installAppendOnlyGuards('transition_history_events');
    }

    public function down(): void
    {
        if (Schema::hasTable('transition_history_events') && DB::table('transition_history_events')->exists()) {
            throw new RuntimeException('Cannot roll back append-only Phase 2B transition history.');
        }

        $this->removeAppendOnlyGuards('transition_history_events');
        Schema::dropIfExists('transition_history_events');
    }

    private function installAppendOnlyGuards(string $table): void
    {
        $driver = DB::connection()->getDriverName();
        $stem = $table.'_immutable';
        $message = 'Phase 2B history requires the matching committed receipt and authoritative fence and is append-only.';
        $definitions = $this->guardDefinitions($table, $stem, $message);

        match ($driver) {
            'sqlite' => $this->installSqliteGuards($table, $definitions),
            'mysql', 'mariadb' => $this->installMySqlGuards($table, $definitions),
            default => throw new RuntimeException('The database driver cannot enforce Phase 2B append-only history.'),
        };
    }

    /** @return array<string, array{event: string, sqlite: string, mysql: string}> */
    private function guardDefinitions(string $table, string $stem, string $message): array
    {
        $requestConditionSqlite = "r.request_id = NEW.request_id AND r.request_digest = NEW.request_digest AND r.operation = 'compare_and_set_hold' AND r.operation_id = NEW.operation_id AND r.generation = NEW.generation AND r.history_sequence = NEW.event_sequence AND r.source_tuple_digest IS NEW.source_tuple_digest AND r.target_tuple_digest = NEW.target_tuple_digest";
        $requestConditionMysql = "r.request_id = NEW.request_id AND r.request_digest = NEW.request_digest AND r.operation = 'compare_and_set_hold' AND r.operation_id = NEW.operation_id AND r.generation = NEW.generation AND r.history_sequence = NEW.event_sequence AND r.source_tuple_digest <=> NEW.source_tuple_digest AND r.target_tuple_digest = NEW.target_tuple_digest";
        $fenceCondition = "f.operation_id = NEW.operation_id AND f.generation = NEW.generation AND f.phase2b_state = 'HOLD' AND f.phase2b_event_sequence = NEW.event_sequence AND f.phase2b_tuple_digest = NEW.target_tuple_digest";
        $historyConditionSqlite = "NEW.event_type = 'HOLD_TUPLE_COMMITTED' AND EXISTS (SELECT 1 FROM transition_v2_requests AS r JOIN deployment_transition_fences AS f ON f.fence_key = 'global' WHERE {$requestConditionSqlite} AND {$fenceCondition})";
        $historyConditionMysql = "NEW.event_type = 'HOLD_TUPLE_COMMITTED' AND EXISTS (SELECT 1 FROM transition_v2_requests AS r JOIN deployment_transition_fences AS f ON f.fence_key = 'global' WHERE {$requestConditionMysql} AND {$fenceCondition})";
        $historyConflictCondition = "EXISTS (SELECT 1 FROM {$table} AS existing WHERE (NEW.id IS NOT NULL AND existing.id = NEW.id) OR existing.event_sequence = NEW.event_sequence OR existing.request_id = NEW.request_id)";

        return [
            $stem.'_insert' => [
                'event' => 'INSERT',
                'sqlite' => "CREATE TRIGGER {$stem}_insert BEFORE INSERT ON {$table} WHEN NOT ({$historyConditionSqlite}) OR {$historyConflictCondition} BEGIN SELECT RAISE(ABORT, '{$message}'); END",
                'mysql' => "BEGIN IF NOT ({$historyConditionMysql}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END",
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
