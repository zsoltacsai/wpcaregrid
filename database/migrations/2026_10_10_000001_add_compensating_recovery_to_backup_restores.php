<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_restores', function (Blueprint $table) {
            $table->timestamp('destructive_started_at')->nullable();
            $table->string('failure_stage', 48)->nullable();
            $table->foreignId('recovery_job_id')->nullable()->constrained('website_jobs')->nullOnDelete();
            $table->unsignedTinyInteger('recovery_attempts')->default(0);
            $table->timestamp('recovery_attempted_at')->nullable();
            $table->timestamp('recovery_started_at')->nullable();
            $table->timestamp('recovery_completed_at')->nullable();
            $table->string('recovery_error_code', 64)->nullable();
            $table->string('recovery_error_message', 500)->nullable();
            $table->json('recovery_result')->nullable();
            $table->boolean('manual_recovery_required')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('backup_restores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recovery_job_id');
            $table->dropIndex(['manual_recovery_required']);
            $table->dropColumn([
                'destructive_started_at',
                'failure_stage',
                'recovery_attempts',
                'recovery_attempted_at',
                'recovery_started_at',
                'recovery_completed_at',
                'recovery_error_code',
                'recovery_error_message',
                'recovery_result',
                'manual_recovery_required',
            ]);
        });
    }
};
