<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TUPLE_COLUMNS = [
        'phase2b_operation_id',
        'phase2b_generation',
        'phase2b_boot_id',
        'phase2b_source_sha',
        'phase2b_journal_seq',
        'phase2b_journal_head_hash',
        'phase2b_evidence_digest',
        'phase2b_tuple_digest',
    ];

    private const ALL_COLUMNS = [
        'phase2b_state',
        ...self::TUPLE_COLUMNS,
        'phase2b_event_sequence',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('deployment_transition_fences')) {
            throw new RuntimeException('The Phase 2A deployment transition fence table is required.');
        }

        if (collect(self::ALL_COLUMNS)->every(fn (string $column): bool => Schema::hasColumn('deployment_transition_fences', $column))) {
            return;
        }

        Schema::table('deployment_transition_fences', function (Blueprint $table): void {
            if (! Schema::hasColumn('deployment_transition_fences', 'phase2b_state')) {
                $table->string('phase2b_state', 16)->default('HOLD');
            }
            if (! Schema::hasColumn('deployment_transition_fences', 'phase2b_operation_id')) {
                $table->char('phase2b_operation_id', 36)->nullable();
            }
            if (! Schema::hasColumn('deployment_transition_fences', 'phase2b_generation')) {
                $table->unsignedBigInteger('phase2b_generation')->nullable();
            }
            if (! Schema::hasColumn('deployment_transition_fences', 'phase2b_boot_id')) {
                $table->char('phase2b_boot_id', 36)->nullable();
            }
            if (! Schema::hasColumn('deployment_transition_fences', 'phase2b_source_sha')) {
                $table->char('phase2b_source_sha', 40)->nullable();
            }
            if (! Schema::hasColumn('deployment_transition_fences', 'phase2b_journal_seq')) {
                $table->unsignedBigInteger('phase2b_journal_seq')->nullable();
            }
            if (! Schema::hasColumn('deployment_transition_fences', 'phase2b_journal_head_hash')) {
                $table->char('phase2b_journal_head_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('deployment_transition_fences', 'phase2b_evidence_digest')) {
                $table->char('phase2b_evidence_digest', 64)->nullable();
            }
            if (! Schema::hasColumn('deployment_transition_fences', 'phase2b_tuple_digest')) {
                $table->char('phase2b_tuple_digest', 64)->nullable();
            }
            if (! Schema::hasColumn('deployment_transition_fences', 'phase2b_event_sequence')) {
                $table->unsignedBigInteger('phase2b_event_sequence')->default(0);
            }
        });
    }

    public function down(): void
    {
        foreach (['transition_history_events', 'transition_v2_requests'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back Phase 2B fence columns while durable evidence exists.');
            }
        }

        if (! Schema::hasTable('deployment_transition_fences')) {
            return;
        }

        $existing = array_values(array_filter(self::ALL_COLUMNS, fn (string $column): bool => Schema::hasColumn('deployment_transition_fences', $column)));
        if ($existing === []) {
            return;
        }

        $hasDurableTuple = DB::table('deployment_transition_fences')
            ->where(function ($query) use ($existing): void {
                foreach (self::TUPLE_COLUMNS as $column) {
                    if (in_array($column, $existing, true)) {
                        $query->orWhereNotNull($column);
                    }
                }
                if (in_array('phase2b_event_sequence', $existing, true)) {
                    $query->orWhere('phase2b_event_sequence', '>', 0);
                }
            })
            ->exists();

        if ($hasDurableTuple) {
            throw new RuntimeException('Cannot roll back Phase 2B fence columns while durable evidence exists.');
        }

        Schema::table('deployment_transition_fences', function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing);
        });
    }
};
