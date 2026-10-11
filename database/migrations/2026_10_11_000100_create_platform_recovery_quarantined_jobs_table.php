<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_recovery_quarantined_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_job_id')->index();
            $table->string('queue', 191)->index();
            $table->longText('payload');
            $table->char('payload_sha256', 64);
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('original_created_at');
            $table->timestamp('quarantined_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_recovery_quarantined_jobs');
    }
};
