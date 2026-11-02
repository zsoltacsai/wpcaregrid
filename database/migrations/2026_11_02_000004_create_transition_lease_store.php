<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Package B lease storage. Once receipts or state rows exist, rollback is
 * intentionally refused because removing them would erase fencing and replay history.
 * This migration is for isolated development/CI only; production rollback is not authorized.
 */
return new class extends Migration
{
    private const TABLES = ['transition_lease_states', 'transition_lease_receipts'];

    public function up(): void
    {
        if (! Schema::hasTable('deployment_transition_fences')) {
            throw new RuntimeException('The authoritative transition fence is required before lease storage.');
        }

        if (! Schema::hasTable('transition_lease_receipts')) {
            Schema::create('transition_lease_receipts', function (Blueprint $table): void {
                $table->id();
                $table->char('request_id', 36)->unique('transition_lease_receipts_request_unique');
                $table->char('request_digest', 64);
                $table->string('action', 24);
                $table->char('operation_id', 36);
                $table->unsignedBigInteger('generation');
                $table->char('execution_id', 36);
                $table->char('lease_id', 36);
                $table->char('fence_tuple_digest', 64);
                $table->string('fence_state', 24);
                $table->char('fence_boot_id', 36);
                $table->unsignedBigInteger('fence_journal_seq');
                $table->char('fence_journal_head_hash', 64);
                $table->char('fence_evidence_digest', 64);
                $table->char('fence_permit_manifest_hash', 64)->nullable();
                $table->char('nonce_digest', 64)->nullable()->unique('transition_lease_receipts_nonce_unique');
                $table->string('outcome', 16);
                $table->string('result_code', 32)->nullable();
                $table->longText('receipt_payload');
                $table->char('receipt_digest', 64);
                $table->timestamp('committed_at', 6);
                $table->timestamps(6);
                $table->index(['operation_id', 'generation', 'execution_id'], 'transition_lease_receipts_scope_idx');
            });
        }

        if (! Schema::hasTable('transition_lease_states')) {
            Schema::create('transition_lease_states', function (Blueprint $table): void {
                $table->id();
                $table->char('lease_id', 36);
                $table->char('operation_id', 36);
                $table->unsignedBigInteger('generation');
                $table->char('execution_id', 36);
                $table->char('fence_tuple_digest', 64);
                $table->string('fence_state', 24);
                $table->char('fence_boot_id', 36);
                $table->unsignedBigInteger('fence_journal_seq');
                $table->char('fence_journal_head_hash', 64);
                $table->char('fence_evidence_digest', 64);
                $table->char('fence_permit_manifest_hash', 64)->nullable();
                $table->unsignedTinyInteger('state_version');
                $table->string('state', 16);
                $table->string('event_type', 16);
                $table->char('holder_digest', 64);
                $table->timestamp('expires_at', 6);
                $table->char('source_state_digest', 64)->nullable();
                $table->char('state_digest', 64);
                $table->char('request_id', 36)->unique('transition_lease_states_request_unique');
                $table->char('request_digest', 64);
                $table->timestamp('occurred_at', 6);
                $table->timestamps(6);

                $table->unique(['lease_id', 'state_version'], 'transition_lease_states_lease_version_unique');
                $table->unique(['operation_id', 'generation', 'execution_id', 'state_version'], 'transition_lease_states_exec_version_unique');
                $table->foreign('request_id', 'transition_lease_states_receipt_fk')
                    ->references('request_id')->on('transition_lease_receipts')->restrictOnDelete();
                $table->index(['operation_id', 'generation', 'execution_id', 'state_version'], 'transition_lease_states_scope_idx');
            });
        }

        $this->installGuards();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back durable transition lease receipts or state; preserve the data and remain HOLD.');
            }
        }

        if (Schema::hasTable('transition_lease_states')) {
            $this->removeGuards('transition_lease_states');
        }
        if (Schema::hasTable('transition_lease_receipts')) {
            $this->removeGuards('transition_lease_receipts');
        }
        Schema::dropIfExists('transition_lease_states');
        Schema::dropIfExists('transition_lease_receipts');
    }

    private function installGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('The active database driver cannot enforce append-only lease receipts.');
        }

        $definitions = $this->guardDefinitions();
        foreach ($definitions as $table => $triggers) {
            foreach ($triggers as $suffix => $sql) {
                $name = $table.'_immutable_'.$suffix;
                if ($this->triggerExists($name, $table, $suffix, $sql)) {
                    continue;
                }
                if ($driver === 'sqlite') {
                    DB::unprepared($sql);
                } else {
                    $event = strtoupper($suffix);
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW {$sql}");
                }
            }
        }
    }

    /** @return array<string, array<string, string>> */
    private function guardDefinitions(): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $jsonText = static fn (string $field): string => $sqlite
            ? "CAST(json_extract(r.receipt_payload, '$.{$field}') AS TEXT)"
            : "JSON_UNQUOTE(JSON_EXTRACT(r.receipt_payload, '$.{$field}'))";
        $jsonInteger = static fn (string $field): string => $sqlite
            ? "CAST(json_extract(r.receipt_payload, '$.{$field}') AS INTEGER)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(r.receipt_payload, '$.{$field}')) AS UNSIGNED)";
        $newJsonText = static fn (string $field): string => $sqlite
            ? "CAST(json_extract(NEW.receipt_payload, '$.{$field}') AS TEXT)"
            : "JSON_UNQUOTE(JSON_EXTRACT(NEW.receipt_payload, '$.{$field}'))";
        $newJsonInteger = static fn (string $field): string => $sqlite
            ? "CAST(json_extract(NEW.receipt_payload, '$.{$field}') AS INTEGER)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.receipt_payload, '$.{$field}')) AS UNSIGNED)";
        $newJsonIntegerType = static fn (string $field): string => $sqlite
            ? "json_type(NEW.receipt_payload, '$.{$field}') = 'integer'"
            : "JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.{$field}')) = 'INTEGER'";
        $receiptKeyCount = $sqlite
            ? "(SELECT COUNT(*) FROM json_each(NEW.receipt_payload)) = 22"
            : 'JSON_LENGTH(NEW.receipt_payload) = 22';
        $manifestJsonMatch = $sqlite
            ? "((json_type(NEW.receipt_payload, '$.fence_permit_manifest_hash') = 'null' AND NEW.fence_permit_manifest_hash IS NULL) OR (json_type(NEW.receipt_payload, '$.fence_permit_manifest_hash') = 'text' AND CAST(json_extract(NEW.receipt_payload, '$.fence_permit_manifest_hash') AS TEXT) = NEW.fence_permit_manifest_hash))"
            : "((JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.fence_permit_manifest_hash')) = 'NULL' AND NEW.fence_permit_manifest_hash IS NULL) OR (JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.fence_permit_manifest_hash')) = 'STRING' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.receipt_payload, '$.fence_permit_manifest_hash')) = NEW.fence_permit_manifest_hash))";
        $stateManifestJsonMatch = $sqlite
            ? "((json_type(r.receipt_payload, '$.fence_permit_manifest_hash') = 'null' AND NEW.fence_permit_manifest_hash IS NULL) OR (json_type(r.receipt_payload, '$.fence_permit_manifest_hash') = 'text' AND CAST(json_extract(r.receipt_payload, '$.fence_permit_manifest_hash') AS TEXT) = NEW.fence_permit_manifest_hash))"
            : "((JSON_TYPE(JSON_EXTRACT(r.receipt_payload, '$.fence_permit_manifest_hash')) = 'NULL' AND NEW.fence_permit_manifest_hash IS NULL) OR (JSON_TYPE(JSON_EXTRACT(r.receipt_payload, '$.fence_permit_manifest_hash')) = 'STRING' AND JSON_UNQUOTE(JSON_EXTRACT(r.receipt_payload, '$.fence_permit_manifest_hash')) = NEW.fence_permit_manifest_hash))";
        $rowManifestMatch = "((r.fence_permit_manifest_hash IS NULL AND NEW.fence_permit_manifest_hash IS NULL) OR r.fence_permit_manifest_hash = NEW.fence_permit_manifest_hash)";
        $sourceDigestMatch = $sqlite
            ? "((json_type(r.receipt_payload, '$.source_state_digest') = 'null' AND NEW.source_state_digest IS NULL) OR CAST(json_extract(r.receipt_payload, '$.source_state_digest') AS TEXT) = NEW.source_state_digest)"
            : "((JSON_TYPE(JSON_EXTRACT(r.receipt_payload, '$.source_state_digest')) = 'NULL' AND NEW.source_state_digest IS NULL) OR (JSON_TYPE(JSON_EXTRACT(r.receipt_payload, '$.source_state_digest')) = 'STRING' AND JSON_UNQUOTE(JSON_EXTRACT(r.receipt_payload, '$.source_state_digest')) = NEW.source_state_digest))";
        $receiptResultMatch = "((NEW.event_type = 'CREATED' AND r.result_code = 'CREATED') OR (NEW.event_type = 'CLAIMED' AND r.result_code = 'CLAIMED') OR (NEW.event_type = 'REJECTED' AND r.result_code IN ('CANCELLED', 'SUPERSEDED', 'OWNER_REVOKED', 'STALE_BOOT')))";
        $receiptMatch = "EXISTS (SELECT 1 FROM transition_lease_receipts AS r WHERE r.request_id = NEW.request_id AND r.request_digest = NEW.request_digest AND r.action = CASE NEW.event_type WHEN 'CREATED' THEN 'create_lease' WHEN 'CLAIMED' THEN 'claim_lease' WHEN 'REJECTED' THEN 'reject_lease' ELSE '' END AND r.outcome = 'COMMITTED' AND r.operation_id = NEW.operation_id AND r.generation = NEW.generation AND r.execution_id = NEW.execution_id AND r.lease_id = NEW.lease_id AND r.fence_tuple_digest = NEW.fence_tuple_digest AND r.fence_state = NEW.fence_state AND r.fence_boot_id = NEW.fence_boot_id AND r.fence_journal_seq = NEW.fence_journal_seq AND r.fence_journal_head_hash = NEW.fence_journal_head_hash AND r.fence_evidence_digest = NEW.fence_evidence_digest AND {$rowManifestMatch} AND r.nonce_digest IS NOT NULL AND {$receiptResultMatch} AND {$jsonText('request_id')} = r.request_id AND {$jsonText('request_digest')} = r.request_digest AND {$jsonText('action')} = r.action AND {$jsonText('operation_id')} = r.operation_id AND {$jsonInteger('generation')} = r.generation AND {$jsonText('execution_id')} = r.execution_id AND {$jsonText('fence_tuple_digest')} = r.fence_tuple_digest AND {$jsonText('fence_state')} = r.fence_state AND {$jsonText('fence_boot_id')} = r.fence_boot_id AND {$jsonInteger('fence_journal_seq')} = r.fence_journal_seq AND {$jsonText('fence_journal_head_hash')} = r.fence_journal_head_hash AND {$jsonText('fence_evidence_digest')} = r.fence_evidence_digest AND {$stateManifestJsonMatch} AND {$jsonText('lease_id')} = r.lease_id AND {$jsonText('state')} = NEW.state AND {$jsonText('state_digest')} = NEW.state_digest AND {$jsonInteger('state_version')} = NEW.state_version AND {$sourceDigestMatch})";
        $fenceMatch = "EXISTS (SELECT 1 FROM deployment_transition_fences AS f WHERE f.fence_key = 'global' AND f.state = NEW.fence_state AND f.operation_id = NEW.operation_id AND f.generation = NEW.generation AND f.boot_id = NEW.fence_boot_id AND f.journal_seq = NEW.fence_journal_seq AND f.journal_head_hash = NEW.fence_journal_head_hash AND f.evidence_digest = NEW.fence_evidence_digest AND ((f.state = 'DRAINING' AND f.permit_manifest_hash = NEW.fence_permit_manifest_hash) OR (f.state <> 'DRAINING' AND NEW.fence_permit_manifest_hash IS NULL)) AND f.phase2b_state = 'HOLD' AND f.phase2b_operation_id = NEW.operation_id AND f.phase2b_generation = NEW.generation AND f.phase2b_tuple_digest IS NOT NULL AND f.state <> 'RECOVERY_REQUIRED')";
        $priorMatch = "EXISTS (SELECT 1 FROM transition_lease_states AS p WHERE p.lease_id = NEW.lease_id AND p.operation_id = NEW.operation_id AND p.generation = NEW.generation AND p.execution_id = NEW.execution_id AND p.state_version = 1 AND p.state = 'ISSUED' AND p.holder_digest = NEW.holder_digest AND p.expires_at = NEW.expires_at AND p.fence_tuple_digest = NEW.fence_tuple_digest AND p.fence_state = NEW.fence_state AND p.fence_boot_id = NEW.fence_boot_id AND p.fence_journal_seq = NEW.fence_journal_seq AND p.fence_journal_head_hash = NEW.fence_journal_head_hash AND p.fence_evidence_digest = NEW.fence_evidence_digest AND ((p.fence_permit_manifest_hash IS NULL AND NEW.fence_permit_manifest_hash IS NULL) OR p.fence_permit_manifest_hash = NEW.fence_permit_manifest_hash) AND p.state_digest = NEW.source_state_digest)";
        $validState = "((NEW.state_version = 1 AND NEW.event_type = 'CREATED' AND NEW.state = 'ISSUED' AND NEW.source_state_digest IS NULL) OR (NEW.state_version = 2 AND ((NEW.event_type = 'CLAIMED' AND NEW.state = 'CLAIMED') OR (NEW.event_type = 'REJECTED' AND NEW.state = 'REJECTED')) AND NEW.source_state_digest IS NOT NULL AND {$priorMatch}))";
        $sqliteInsert = "CREATE TRIGGER transition_lease_states_immutable_insert BEFORE INSERT ON transition_lease_states WHEN NOT ({$receiptMatch}) OR NOT ({$fenceMatch}) OR NOT ({$validState}) BEGIN SELECT RAISE(ABORT, 'Invalid or unbound append-only lease state.'); END";
        $mysqlInsert = "BEGIN IF NOT ({$receiptMatch}) OR NOT ({$fenceMatch}) OR NOT ({$validState}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or unbound append-only lease state.'; END IF; END";
        $committedPayloadSemantics = $sqlite
            ? "json_extract(NEW.receipt_payload, '$.committed') = 1 AND {$newJsonIntegerType('state_version')} AND ((NEW.action = 'create_lease' AND {$newJsonText('result_code')} = 'CREATED' AND {$newJsonText('state')} = 'ISSUED' AND {$newJsonInteger('state_version')} = 1 AND json_type(NEW.receipt_payload, '$.source_state_digest') = 'null') OR (NEW.action = 'claim_lease' AND {$newJsonText('result_code')} = 'CLAIMED' AND {$newJsonText('state')} = 'CLAIMED' AND {$newJsonInteger('state_version')} = 2 AND length({$newJsonText('source_state_digest')}) = 64) OR (NEW.action = 'reject_lease' AND NEW.result_code IN ('CANCELLED', 'SUPERSEDED', 'OWNER_REVOKED', 'STALE_BOOT') AND {$newJsonText('state')} = 'REJECTED' AND {$newJsonInteger('state_version')} = 2 AND length({$newJsonText('source_state_digest')}) = 64)) AND length({$newJsonText('state_digest')}) = 64"
            : "JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.committed')) = 'BOOLEAN' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.receipt_payload, '$.committed')) = 'true' AND {$newJsonIntegerType('state_version')} AND ((NEW.action = 'create_lease' AND {$newJsonText('result_code')} = 'CREATED' AND {$newJsonText('state')} = 'ISSUED' AND {$newJsonInteger('state_version')} = 1 AND JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.source_state_digest')) = 'NULL') OR (NEW.action = 'claim_lease' AND {$newJsonText('result_code')} = 'CLAIMED' AND {$newJsonText('state')} = 'CLAIMED' AND {$newJsonInteger('state_version')} = 2 AND CHAR_LENGTH({$newJsonText('source_state_digest')}) = 64) OR (NEW.action = 'reject_lease' AND NEW.result_code IN ('CANCELLED', 'SUPERSEDED', 'OWNER_REVOKED', 'STALE_BOOT') AND {$newJsonText('state')} = 'REJECTED' AND {$newJsonInteger('state_version')} = 2 AND CHAR_LENGTH({$newJsonText('source_state_digest')}) = 64)) AND CHAR_LENGTH({$newJsonText('state_digest')}) = 64";
        $refusalPayloadSemantics = $sqlite
            ? "json_extract(NEW.receipt_payload, '$.committed') = 0 AND json_type(NEW.receipt_payload, '$.state') = 'null' AND json_type(NEW.receipt_payload, '$.state_digest') = 'null' AND json_type(NEW.receipt_payload, '$.state_version') = 'null' AND json_type(NEW.receipt_payload, '$.source_state_digest') = 'null'"
            : "JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.committed')) = 'BOOLEAN' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.receipt_payload, '$.committed')) = 'false' AND JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.state')) = 'NULL' AND JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.state_digest')) = 'NULL' AND JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.state_version')) = 'NULL' AND JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.source_state_digest')) = 'NULL'";
        $receiptPayloadMatch = "{$receiptKeyCount} AND {$newJsonText('request_id')} = NEW.request_id AND {$newJsonText('request_digest')} = NEW.request_digest AND {$newJsonText('action')} = NEW.action AND {$newJsonText('operation_id')} = NEW.operation_id AND {$newJsonIntegerType('generation')} AND {$newJsonInteger('generation')} = NEW.generation AND {$newJsonText('execution_id')} = NEW.execution_id AND {$newJsonText('fence_tuple_digest')} = NEW.fence_tuple_digest AND {$newJsonText('fence_state')} = NEW.fence_state AND {$newJsonText('fence_boot_id')} = NEW.fence_boot_id AND {$newJsonIntegerType('fence_journal_seq')} AND {$newJsonInteger('fence_journal_seq')} = NEW.fence_journal_seq AND {$newJsonText('fence_journal_head_hash')} = NEW.fence_journal_head_hash AND {$newJsonText('fence_evidence_digest')} = NEW.fence_evidence_digest AND {$manifestJsonMatch} AND {$newJsonText('lease_id')} = NEW.lease_id AND ((NEW.nonce_digest IS NULL AND ".($sqlite ? "json_type(NEW.receipt_payload, '$.nonce_digest') = 'null'" : "JSON_TYPE(JSON_EXTRACT(NEW.receipt_payload, '$.nonce_digest')) = 'NULL'").") OR {$newJsonText('nonce_digest')} = NEW.nonce_digest) AND {$newJsonText('outcome')} = NEW.outcome AND {$newJsonText('result_code')} = NEW.result_code AND ((NEW.outcome = 'COMMITTED' AND {$committedPayloadSemantics}) OR (NEW.outcome = 'REFUSED' AND {$refusalPayloadSemantics}))";
        $refusalAllowed = "((NEW.action = 'create_lease' AND NEW.result_code IN ('STALE_GENERATION', 'STALE_FENCE_TUPLE', 'FENCE_STATE_MISMATCH', 'EXECUTION_ALREADY_LEASED', 'LEASE_EXPIRED', 'NONCE_REPLAY')) OR (NEW.action = 'claim_lease' AND NEW.result_code IN ('STALE_GENERATION', 'STALE_FENCE_TUPLE', 'FENCE_STATE_MISMATCH', 'LEASE_NOT_FOUND', 'LEASE_EXPIRED', 'LEASE_NOT_CLAIMABLE', 'HOLDER_MISMATCH', 'NONCE_REPLAY')) OR (NEW.action = 'reject_lease' AND NEW.result_code IN ('STALE_GENERATION', 'STALE_FENCE_TUPLE', 'FENCE_STATE_MISMATCH', 'LEASE_NOT_FOUND', 'LEASE_NOT_CLAIMABLE', 'HOLDER_MISMATCH', 'NONCE_REPLAY')))";
        $receiptAllowed = "NEW.action IN ('create_lease', 'claim_lease', 'reject_lease') AND NEW.outcome IN ('COMMITTED', 'REFUSED') AND (((NEW.outcome = 'COMMITTED' AND ((NEW.action = 'create_lease' AND NEW.result_code = 'CREATED') OR (NEW.action = 'claim_lease' AND NEW.result_code = 'CLAIMED') OR (NEW.action = 'reject_lease' AND NEW.result_code IN ('CANCELLED', 'SUPERSEDED', 'OWNER_REVOKED', 'STALE_BOOT'))) AND NEW.nonce_digest IS NOT NULL) OR (NEW.outcome = 'REFUSED' AND {$refusalAllowed} AND ((NEW.result_code = 'NONCE_REPLAY' AND NEW.nonce_digest IS NULL) OR (NEW.result_code <> 'NONCE_REPLAY' AND NEW.nonce_digest IS NOT NULL)))) AND {$receiptPayloadMatch})";
        $sqliteReceipt = "CREATE TRIGGER transition_lease_receipts_immutable_insert BEFORE INSERT ON transition_lease_receipts WHEN NOT ({$receiptAllowed}) BEGIN SELECT RAISE(ABORT, 'Invalid append-only lease receipt.'); END";
        $mysqlReceipt = "BEGIN IF NOT ({$receiptAllowed}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid append-only lease receipt.'; END IF; END";

        return [
            'transition_lease_receipts' => [
                'insert' => DB::connection()->getDriverName() === 'sqlite' ? $sqliteReceipt : $mysqlReceipt,
                'update' => DB::connection()->getDriverName() === 'sqlite' ? "CREATE TRIGGER transition_lease_receipts_immutable_update BEFORE UPDATE ON transition_lease_receipts BEGIN SELECT RAISE(ABORT, 'Lease receipts are append-only.'); END" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lease receipts are append-only.'",
                'delete' => DB::connection()->getDriverName() === 'sqlite' ? "CREATE TRIGGER transition_lease_receipts_immutable_delete BEFORE DELETE ON transition_lease_receipts BEGIN SELECT RAISE(ABORT, 'Lease receipts are append-only.'); END" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lease receipts are append-only.'",
            ],
            'transition_lease_states' => [
                'insert' => DB::connection()->getDriverName() === 'sqlite' ? $sqliteInsert : $mysqlInsert,
                'update' => DB::connection()->getDriverName() === 'sqlite' ? "CREATE TRIGGER transition_lease_states_immutable_update BEFORE UPDATE ON transition_lease_states BEGIN SELECT RAISE(ABORT, 'Lease states are append-only.'); END" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lease states are append-only.'",
                'delete' => DB::connection()->getDriverName() === 'sqlite' ? "CREATE TRIGGER transition_lease_states_immutable_delete BEFORE DELETE ON transition_lease_states BEGIN SELECT RAISE(ABORT, 'Lease states are append-only.'); END" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lease states are append-only.'",
            ],
        ];
    }

    private function triggerExists(string $name, string $table, string $suffix, string $expected): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $existing = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if (! $existing) {
                return false;
            }
            if ($existing->tbl_name !== $table || $this->normalizeSql((string) $existing->sql) !== $this->normalizeSql($expected)) {
                throw new RuntimeException('An existing lease trigger has an unexpected target or definition.');
            }

            return true;
        }

        $existing = DB::table('information_schema.triggers')
            ->selectRaw('EVENT_OBJECT_TABLE AS target_table, EVENT_MANIPULATION AS event_name, ACTION_TIMING AS timing, ACTION_STATEMENT AS statement_text')
            ->where('trigger_schema', DB::raw('DATABASE()'))
            ->where('trigger_name', $name)->first();
        if (! $existing) {
            return false;
        }
        $event = strtoupper($suffix);
        if ($existing->target_table !== $table || $existing->event_name !== $event || $existing->timing !== 'BEFORE'
            || $this->normalizeSql((string) $existing->statement_text) !== $this->normalizeSql($expected)) {
            throw new RuntimeException('An existing lease trigger has an unexpected target or definition.');
        }

        return true;
    }

    private function normalizeSql(string $sql): string
    {
        return rtrim(preg_replace('/\s+/', '', strtolower(str_replace(['`', '"'], '', trim($sql)))) ?? '', ';');
    }

    private function removeGuards(string $table): void
    {
        foreach (['insert', 'update', 'delete'] as $suffix) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$suffix);
        }
    }
};
