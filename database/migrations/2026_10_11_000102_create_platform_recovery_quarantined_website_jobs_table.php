<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_recovery_quarantined_website_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_job_id')->unique('prq_website_job_id_uq');
            $table->uuid('job_uuid')->index();
            $table->uuid('website_uuid')->index();
            $table->string('type', 32);
            $table->string('original_status', 24);
            $table->json('payload');
            $table->char('payload_sha256', 64);
            $table->unsignedSmallInteger('attempts');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('quarantined_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_recovery_quarantined_website_jobs');
    }
};
