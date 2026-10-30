<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_transition_fences', function (Blueprint $table) {
            $table->id();
            $table->string('fence_key', 32)->default('global')->unique();
            $table->string('state', 32);
            $table->char('operation_id', 36);
            $table->unsignedBigInteger('generation');
            $table->char('boot_id', 36);
            $table->unsignedBigInteger('journal_seq');
            $table->char('journal_head_hash', 64);
            $table->char('evidence_digest', 64);
            $table->char('permit_manifest_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('transition_admissions', function (Blueprint $table) {
            $table->id();
            $table->char('operation_id', 36);
            $table->unsignedBigInteger('generation');
            $table->string('execution_id', 191);
            $table->string('work_id', 191);
            $table->char('payload_hash', 64);
            $table->string('tenant_id', 191);
            $table->string('site_id', 191);
            $table->string('worker_identity', 191);
            $table->json('effect_steps');
            $table->char('effect_step_plan_hash', 64);
            $table->string('status', 16)->default('ISSUED');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['operation_id', 'generation', 'execution_id', 'work_id'], 'transition_admission_identity_unique');
            $table->index(['operation_id', 'generation', 'status'], 'transition_admission_scope_idx');
        });

        Schema::create('transition_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transition_admission_id')->constrained('transition_admissions');
            $table->unsignedInteger('effect_step_index');
            $table->string('step_id', 128);
            $table->string('idempotency_key', 191)->unique();
            $table->char('payload_hash', 64);
            $table->string('status', 16);
            $table->timestamp('intent_at');
            $table->timestamp('outcome_at')->nullable();
            $table->char('outcome_digest', 64)->nullable();
            $table->timestamps();
            $table->unique(['transition_admission_id', 'effect_step_index'], 'transition_execution_step_unique');
            $table->index(['transition_admission_id', 'status'], 'transition_execution_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transition_executions');
        Schema::dropIfExists('transition_admissions');
        Schema::dropIfExists('deployment_transition_fences');
    }
};
