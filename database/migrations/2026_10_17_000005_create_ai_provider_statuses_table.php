<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_statuses', function (Blueprint $table): void {
            $table->string('provider_id', 40)->primary();
            $table->uuid('credential_generation');
            $table->uuid('health_generation')->nullable();
            $table->string('health_status', 24)->default('UNKNOWN');
            $table->string('normalized_error_category', 32)->nullable();
            $table->unsignedInteger('latency_bucket_ms')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_statuses');
    }
};
