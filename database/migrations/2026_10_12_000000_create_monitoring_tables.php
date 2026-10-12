<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_results', function (Blueprint $table): void {
            $table->id();
            $table->string('check_id', 96);
            $table->string('scope', 24);
            $table->string('subject_type', 48);
            $table->string('subject_id', 64);
            $table->string('state', 24);
            $table->string('severity', 16)->nullable();
            $table->string('reason_code', 96);
            $table->string('source', 32);
            $table->json('evidence')->nullable();
            $table->timestamp('source_at')->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('fresh_until');
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->string('correlation_key', 128)->nullable();
            $table->timestamps();

            $table->unique(['check_id', 'scope', 'subject_type', 'subject_id'], 'monitor_result_identity_uq');
            $table->index(['scope', 'state', 'fresh_until'], 'monitor_result_state_fresh_ix');
            $table->index(['check_id', 'next_check_at', 'id'], 'monitor_result_due_ix');
            $table->index(['correlation_key', 'observed_at'], 'monitor_result_correlation_ix');
        });

        Schema::create('monitoring_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('incident_key', 64);
            $table->unsignedInteger('generation')->default(1);
            $table->string('check_id', 96);
            $table->string('scope', 24);
            $table->string('subject_type', 48);
            $table->string('subject_id', 64);
            $table->string('severity', 16);
            $table->string('reason_code', 96);
            $table->string('status', 24)->default('open');
            $table->string('correlation_key', 128)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('last_observed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_reason', 64)->nullable();
            $table->unsignedInteger('observation_count')->default(1);
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['incident_key', 'generation'], 'monitor_incident_generation_uq');
            $table->index(['status', 'severity', 'opened_at'], 'monitor_incident_active_ix');
            $table->index(['scope', 'subject_type', 'subject_id', 'status'], 'monitor_incident_subject_ix');
            $table->index(['correlation_key', 'status'], 'monitor_incident_correlation_ix');
        });

        Schema::create('monitoring_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->nullable()->constrained('monitoring_incidents')->nullOnDelete();
            $table->foreignId('monitoring_result_id')->nullable()->constrained('monitoring_results')->nullOnDelete();
            $table->string('event_type', 32);
            $table->string('check_id', 96);
            $table->string('scope', 24);
            $table->string('subject_type', 48);
            $table->string('subject_id', 64);
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24)->nullable();
            $table->string('reason_code', 96);
            $table->string('severity', 16)->nullable();
            $table->string('source', 32);
            $table->json('evidence')->nullable();
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id', 'created_at'], 'monitor_transition_subject_ix');
            $table->index(['incident_id', 'id'], 'monitor_transition_incident_ix');
            $table->index(['event_type', 'created_at'], 'monitor_transition_type_ix');
        });

        Schema::create('monitoring_alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('monitoring_incidents')->cascadeOnDelete();
            $table->foreignId('transition_id')->constrained('monitoring_transitions')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16)->default('pending');
            $table->char('idempotency_key', 64)->unique('monitor_delivery_key_uq');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'monitor_delivery_pending_ix');
            $table->index(['incident_id', 'channel', 'status'], 'monitor_delivery_incident_ix');
        });

        Schema::create('monitoring_maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 24);
            $table->string('subject_type', 48)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('check_family', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 255);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['scope', 'subject_type', 'subject_id', 'starts_at', 'ends_at'], 'monitor_maintenance_match_ix');
            $table->index(['ends_at', 'ended_at'], 'monitor_maintenance_expiry_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_maintenance_windows');
        Schema::dropIfExists('monitoring_alert_deliveries');
        Schema::dropIfExists('monitoring_transitions');
        Schema::dropIfExists('monitoring_incidents');
        Schema::dropIfExists('monitoring_results');
    }
};
