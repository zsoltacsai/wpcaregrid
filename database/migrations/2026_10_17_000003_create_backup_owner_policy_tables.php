<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_backup_owner_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('daily_recovery_points')->nullable();
            $table->unsignedSmallInteger('weekly_recovery_points')->nullable();
            $table->unsignedSmallInteger('monthly_recovery_points')->nullable();
            $table->unsignedSmallInteger('desired_post_service_days')->default(30);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('backup_retention_policy_previews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('owner_policy_version')->default(0);
            $table->char('source_fingerprint', 64);
            $table->char('preview_hash', 64);
            $table->json('proposed_values');
            $table->json('impact');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'state', 'expires_at'], 'backup_retention_previews_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_retention_policy_previews');
        Schema::dropIfExists('subscription_backup_owner_policies');
    }
};
