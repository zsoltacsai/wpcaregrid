<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_backup_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_database_backup_id', 'pb_delivery_backup_fk')->constrained('platform_database_backups');
            $table->string('destination_key', 48);
            $table->string('destination_type', 24);
            $table->string('storage_disk', 64);
            $table->string('object_key', 512);
            $table->string('manifest_object_key', 640);
            $table->string('upload_status', 24)->default('pending');
            $table->string('verification_status', 32)->default('pending');
            $table->string('reconciliation_status', 32)->default('pending');
            $table->string('retention_status', 24)->default('retained');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['platform_database_backup_id', 'destination_key'], 'platform_backup_delivery_identity_uq');
            $table->index(['destination_key', 'verification_status', 'uploaded_at'], 'platform_backup_delivery_health_ix');
        });

        foreach (DB::table('platform_database_backups')->orderBy('id')->get() as $backup) {
            DB::table('platform_backup_deliveries')->insert([
                'platform_database_backup_id' => $backup->id,
                'destination_key' => 'primary',
                'destination_type' => (string) (config("filesystems.disks.{$backup->storage_disk}.driver") ?: 'legacy'),
                'storage_disk' => $backup->storage_disk,
                'object_key' => $backup->object_key,
                'manifest_object_key' => $backup->manifest_object_key,
                'upload_status' => in_array($backup->status, ['stored', 'integrity_verified', 'verified', 'verification_failed'], true) ? 'uploaded' : ($backup->status === 'failed' ? 'failed' : 'pending'),
                'verification_status' => $backup->verification_status,
                'reconciliation_status' => 'pending',
                'retention_status' => 'retained',
                'size_bytes' => $backup->size_bytes,
                'sha256' => $backup->sha256,
                'failure_code' => $backup->failure_code,
                'uploaded_at' => in_array($backup->status, ['stored', 'integrity_verified', 'verified', 'verification_failed'], true) ? $backup->updated_at : null,
                'verified_at' => $backup->verified_at,
                'created_at' => $backup->created_at,
                'updated_at' => $backup->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_backup_deliveries');
    }
};
