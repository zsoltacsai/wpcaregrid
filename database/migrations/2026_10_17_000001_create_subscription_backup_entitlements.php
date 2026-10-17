<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_backup_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('resolution', 16)->default('unknown');
            $table->string('reason_code', 64)->nullable();
            $table->string('source_type', 24);
            $table->string('catalogue_version', 40);
            $table->string('product_type', 32)->nullable();
            $table->string('plan', 32)->nullable();
            $table->string('cadence', 16)->nullable();
            $table->unsignedSmallInteger('daily_recovery_points')->nullable();
            $table->unsignedSmallInteger('weekly_recovery_points')->nullable();
            $table->unsignedSmallInteger('monthly_recovery_points')->nullable();
            $table->boolean('pre_update_safety_required')->nullable();
            $table->boolean('manual_backup_allowed')->nullable();
            $table->boolean('restore_eligible')->nullable();
            $table->timestamp('effective_at');
            $table->string('source_event_id', 191)->nullable();
            $table->char('source_key', 64)->unique();
            $table->timestamp('created_at');

            $table->index(['subscription_id', 'effective_at'], 'sub_backup_entitlements_effective_idx');
            $table->index(['subscription_id', 'resolution', 'effective_at'], 'sub_backup_entitlements_resolution_effective_idx');
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->foreignId('backup_entitlement_snapshot_id')->nullable()
                ->after('schedule_window')
                ->constrained('subscription_backup_entitlements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backup_entitlement_snapshot_id');
        });
        Schema::dropIfExists('subscription_backup_entitlements');
    }
};
