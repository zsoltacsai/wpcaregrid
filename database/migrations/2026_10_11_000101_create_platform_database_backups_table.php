<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_database_backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_environment', 32);
            $table->char('database_identity_sha256', 64);
            $table->string('database_driver', 24);
            $table->string('database_version', 64)->nullable();
            $table->string('release_sha', 64)->nullable();
            $table->string('retention_class', 16);
            $table->string('status', 24)->index();
            $table->string('verification_status', 32)->default('pending');
            $table->string('verification_method', 64)->nullable();
            $table->string('storage_disk', 64);
            $table->string('object_key', 512)->unique();
            $table->string('manifest_object_key', 640)->unique();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('created_for_backup_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_for_backup_at']);
            $table->index(['retention_class', 'status', 'created_for_backup_at'], 'platform_backups_retention_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_database_backups');
    }
};
