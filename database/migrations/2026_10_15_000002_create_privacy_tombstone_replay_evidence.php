<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_tombstone_replay_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('recovery_id')->index();
            $table->string('source_backup_uuid', 36)->nullable();
            $table->string('snapshot_watermark', 26)->nullable();
            $table->string('ending_watermark', 26)->nullable();
            $table->string('status', 24)->index();
            $table->string('verification_status', 24)->default('pending');
            $table->unsignedInteger('events_discovered')->default(0);
            $table->unsignedInteger('events_applied')->default(0);
            $table->unsignedInteger('events_already_applied')->default(0);
            $table->unsignedInteger('events_blocked')->default(0);
            $table->string('failure_code', 48)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['recovery_id', 'snapshot_watermark', 'status'], 'privacy_tombstone_replay_recovery_snapshot_idx');
        });

        Schema::create('privacy_tombstone_replay_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('replay_session_id')->constrained('privacy_tombstone_replay_sessions')->cascadeOnDelete();
            $table->char('event_id', 64);
            $table->string('sequence', 26);
            $table->string('action', 48);
            $table->string('subject_uuid', 36);
            $table->string('outcome', 24);
            $table->string('blocker_code', 48)->nullable();
            $table->timestamp('processed_at');
            $table->unique(['replay_session_id', 'event_id'], 'privacy_tombstone_replay_event_once');
            $table->index(['subject_uuid', 'processed_at']);
        });
    }

    public function down(): void
    {
        foreach (['privacy_tombstone_replay_events', 'privacy_tombstone_replay_sessions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back privacy tombstone replay evidence in {$table}.");
            }
        }
        Schema::dropIfExists('privacy_tombstone_replay_events');
        Schema::dropIfExists('privacy_tombstone_replay_sessions');
    }
};
