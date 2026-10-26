<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_download_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_object_id')->constrained('backup_objects')->cascadeOnDelete();
            $table->string('object_name', 16);
            $table->string('storage_provider', 16);
            $table->string('object_key', 191);
            $table->char('object_sha256', 64);
            $table->unsignedBigInteger('object_size');
            $table->unsignedInteger('fingerprint_block_size');
            $table->longText('block_hashes')->nullable()->comment('Encrypted transient SHA-256 block fingerprints.');
            $table->string('status', 16)->index()->comment('preparing | ready | failed | revoked');
            $table->string('failure_code', 48)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_range_at')->nullable();
            $table->timestamp('last_response_completed_at')->nullable();
            $table->timestamp('last_response_interrupted_at')->nullable();
            $table->unsignedBigInteger('bytes_served')->default(0);
            $table->unsignedInteger('range_responses')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'backup_object_id', 'status', 'expires_at'], 'backup_dl_user_object_active');
            $table->index(['website_id', 'backup_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_download_grants');
    }
};
