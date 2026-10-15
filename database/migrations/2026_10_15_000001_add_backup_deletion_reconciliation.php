<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->string('deletion_state', 32)->default('not_requested')->index();
        });

        Schema::create('backup_object_deletions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            $table->string('object_name', 16);
            $table->string('provider', 16);
            $table->string('object_key', 512);
            $table->string('state', 32)->default('pending')->index();
            $table->string('provider_response_state', 24)->nullable();
            $table->string('verification_state', 32)->default('pending');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('verified_absent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['backup_id', 'object_name'], 'backup_object_deletion_uq');
            $table->index(['provider', 'state']);
        });

        // Historical aggregate flags are deliberately not upgraded to verified
        // deletion evidence. The original code did not read objects back.
        DB::table('backups')->whereNotNull('objects_deleted_at')->update(['deletion_state' => 'legacy_unverified']);
        DB::table('backups')->whereNotNull('objects_deleted_at')->whereNotNull('storage_provider')->orderBy('id')->chunkById(100, function ($backups): void {
            foreach ($backups as $backup) {
                $websiteUuid = DB::table('websites')->where('id', $backup->website_id)->value('uuid');
                if (! is_string($websiteUuid)) {
                    continue;
                }
                foreach (['files' => 'files.tar.gz', 'database' => 'database.sql.gz', 'manifest' => 'manifest.json'] as $name => $filename) {
                    DB::table('backup_object_deletions')->insertOrIgnore([
                        'backup_id' => $backup->id,
                        'object_name' => $name,
                        'provider' => $backup->storage_provider,
                        'object_key' => $websiteUuid.'/'.$backup->uuid.'/'.$filename,
                        'state' => 'legacy_unverified',
                        'provider_response_state' => 'unknown',
                        'verification_state' => 'unknown',
                        'requested_at' => $backup->objects_deleted_at,
                        'attempts' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('backup_object_deletions') && DB::table('backup_object_deletions')->exists()) {
            throw new RuntimeException('Refusing to roll back backup deletion reconciliation while object deletion evidence exists.');
        }

        Schema::dropIfExists('backup_object_deletions');
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropIndex('backups_deletion_state_index');
            $table->dropColumn('deletion_state');
        });
    }
};
