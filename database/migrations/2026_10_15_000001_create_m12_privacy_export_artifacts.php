<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_export_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('privacy_request_id')->unique()->constrained('privacy_requests')->restrictOnDelete();
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path', 255)->nullable();
            $table->string('status', 24)->index();
            $table->json('included_sections')->nullable();
            $table->json('excluded_sections')->nullable();
            $table->string('coverage_state', 16)->default('partial');
            $table->unsignedBigInteger('package_size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 48)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('download_expires_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('privacy_export_artifacts') && DB::table('privacy_export_artifacts')->exists()) {
            throw new RuntimeException('Refusing to roll back M12 export artifacts while privacy export evidence exists.');
        }

        Schema::dropIfExists('privacy_export_artifacts');
    }
};
