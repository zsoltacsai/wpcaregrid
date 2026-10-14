<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_type', 24);
            $table->string('status', 40)->index();
            $table->string('active_request_key', 16)->nullable();
            $table->char('idempotency_hash', 64)->unique();
            $table->string('source', 24);
            $table->string('policy_version', 64)->nullable();
            $table->timestamp('requested_at')->index();
            $table->timestamp('identity_verified_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['customer_id', 'request_type', 'active_request_key'], 'privacy_requests_one_active_case');
            $table->index(['customer_id', 'status', 'created_at']);
        });

        Schema::create('privacy_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privacy_request_id')->constrained('privacy_requests')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['privacy_request_id', 'created_at']);
        });

        Schema::create('privacy_provider_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('privacy_request_id')->constrained('privacy_requests')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('scope_code', 40);
            $table->string('scope_reference', 128)->nullable()->comment('Internal non-secret reference only');
            $table->string('status', 24)->default('pending')->index();
            $table->char('idempotency_hash', 64)->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_category', 40)->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->index(['privacy_request_id', 'status']);
        });

        Schema::create('privacy_retention_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('policy_key', 64);
            $table->unsignedInteger('version');
            $table->string('category_code', 40);
            $table->string('trigger_code', 64);
            $table->unsignedInteger('duration_value')->nullable();
            $table->string('duration_unit', 16)->nullable();
            $table->string('action', 24)->default('report_only');
            $table->string('approval_status', 24)->default('draft')->index();
            $table->string('legal_review_reference', 128)->nullable();
            $table->string('accounting_review_reference', 128)->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['policy_key', 'version']);
            $table->index(['category_code', 'approval_status', 'effective_at'], 'privacy_policy_category_approval_idx');
        });
    }

    public function down(): void
    {
        foreach (['privacy_requests', 'privacy_request_events', 'privacy_provider_tasks', 'privacy_retention_policies'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back M12 privacy foundation while {$table} contains evidence or case data.");
            }
        }

        Schema::dropIfExists('privacy_retention_policies');
        Schema::dropIfExists('privacy_provider_tasks');
        Schema::dropIfExists('privacy_request_events');
        Schema::dropIfExists('privacy_requests');
    }
};
