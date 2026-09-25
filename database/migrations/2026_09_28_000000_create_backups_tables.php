<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16)->comment('files | database | full');
            $table->string('reason', 16)->comment('scheduled | manual | pre_update | pre_restore | recovery');
            $table->string('status', 16)->index()->comment('queued | running | uploading | uploaded | verifying | verified | failed | corrupt | expired | deleted');
            $table->string('schedule_window', 32)->nullable()->comment('e.g. daily:2026-09-25; one scheduled backup per window');
            $table->string('storage_provider', 16)->nullable();
            $table->string('storage_path', 191)->nullable()->comment('{website_uuid}/{backup_uuid}/');
            $table->unsignedBigInteger('files_size')->nullable();
            $table->unsignedBigInteger('database_size')->nullable();
            $table->unsignedBigInteger('total_size')->nullable();
            $table->json('checksums')->nullable()->comment('object filename => sha256');
            $table->json('manifest')->nullable();
            $table->string('connector_version', 32)->nullable();
            $table->string('wordpress_version', 32)->nullable();
            $table->string('php_version', 32)->nullable();
            $table->unsignedBigInteger('update_job_id')->nullable()->comment('Update this backup protects (pre_update)');
            $table->json('update_target')->nullable()->comment('kind, identifier, previous_version');
            $table->foreignId('retry_of_id')->nullable()->constrained('backups')->nullOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('requested_by', 64)->default('system');
            $table->unsignedInteger('transfer_attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('objects_deleted_at')->nullable()->comment('Stored objects removed; metadata is kept');
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->unique(['website_id', 'schedule_window']);
        });

        Schema::create('backup_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            $table->string('name', 16)->comment('files | database | manifest');
            $table->string('filename', 32);
            $table->string('status', 16)->comment('pending | receiving | received | stored | verified | failed');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->unsignedBigInteger('size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('remote_size')->nullable();
            $table->string('verification_method', 16)->nullable()->comment('native | readback');
            $table->timestamp('stored_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['backup_id', 'name']);
        });

        Schema::create('backup_restores', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('safety_backup_id')->nullable()->constrained('backups')->nullOnDelete();
            $table->string('scope', 16)->comment('files | database | full');
            $table->string('status', 24)->index()->comment('awaiting_safety_backup | queued | restoring | restored | needs_attention | failed | cancelled');
            $table->string('requested_by', 64);
            $table->timestamp('confirmed_at');
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('website_jobs', function (Blueprint $table) {
            $table->foreignId('backup_id')->nullable()->after('connector_id')->constrained()->nullOnDelete()
                ->comment('BACKUP_*: the backup being made; UPDATE_*: the backup protecting the update');
            $table->foreignId('restore_id')->nullable()->after('backup_id')->constrained('backup_restores')->nullOnDelete();
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->foreign('update_job_id')->references('id')->on('website_jobs')->nullOnDelete();
        });

        // Audit log per website. Never holds secrets.
        Schema::create('website_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('event', 48)->index();
            $table->foreignId('backup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('restore_id')->nullable()->constrained('backup_restores')->nullOnDelete();
            $table->string('actor', 64)->default('system');
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['website_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_events');
        Schema::table('backups', fn (Blueprint $table) => $table->dropForeign(['update_job_id']));
        Schema::table('website_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restore_id');
            $table->dropConstrainedForeignId('backup_id');
        });
        Schema::dropIfExists('backup_restores');
        Schema::dropIfExists('backup_objects');
        Schema::dropIfExists('backups');
    }
};
