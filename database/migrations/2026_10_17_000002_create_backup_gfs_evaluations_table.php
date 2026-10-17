<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_gfs_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_backup_entitlement_id')->nullable();
            $table->string('catalogue_version', 40)->nullable();
            $table->string('result_status', 24)->default('UNKNOWN');
            $table->char('source_fingerprint', 64);
            $table->unsignedInteger('missing_slot_count')->default(0);
            $table->json('result');
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(['result_status', 'evaluated_at'], 'backup_gfs_result_checked_idx');
            $table->foreign('subscription_backup_entitlement_id', 'backup_gfs_entitlement_fk')
                ->references('id')->on('subscription_backup_entitlements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_gfs_evaluations');
    }
};
