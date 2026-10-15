<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_erasure_execution_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('privacy_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('state', 32)->index();
            $table->string('policy_version', 128)->nullable();
            $table->char('source_fingerprint', 64);
            $table->char('preview_hash', 64);
            $table->json('preview');
            $table->json('blocker_codes');
            $table->string('owner_approval_status', 24)->default('pending')->index();
            $table->char('approved_preview_hash', 64)->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->unique(['privacy_request_id', 'version'], 'privacy_erasure_plan_request_version_unique');
            $table->index(['customer_id', 'state'], 'privacy_erasure_plan_customer_state_idx');
        });

        Schema::create('privacy_erasure_execution_actions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('execution_plan_id')->constrained('privacy_erasure_execution_plans')->restrictOnDelete();
            $table->string('action_code', 48);
            $table->string('scope_code', 40);
            $table->string('target_type', 48);
            $table->string('target_reference', 64);
            $table->string('state', 32)->index();
            $table->string('reconciliation_state', 32)->index();
            $table->json('blocker_codes');
            $table->char('idempotency_key', 64)->unique();
            $table->timestamps();
            $table->index(['execution_plan_id', 'action_code'], 'privacy_erasure_action_plan_code_idx');
        });

        Schema::create('privacy_erasure_circuit_breakers', function (Blueprint $table): void {
            $table->string('breaker_key', 64)->primary();
            $table->boolean('is_open')->default(false)->index();
            $table->string('reason_code', 48)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reset_at')->nullable();
            $table->foreignId('reset_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['privacy_erasure_circuit_breakers', 'privacy_erasure_execution_actions', 'privacy_erasure_execution_plans'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back privacy erasure execution evidence in {$table}.");
            }
        }

        Schema::dropIfExists('privacy_erasure_execution_actions');
        Schema::dropIfExists('privacy_erasure_execution_plans');
        Schema::dropIfExists('privacy_erasure_circuit_breakers');
    }
};
