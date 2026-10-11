<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_backup_schedule_slots', function (Blueprint $table): void {
            $table->id();
            $table->string('slot_key', 96)->unique('platform_backup_slot_key_uq');
            $table->string('frequency', 16);
            $table->char('settings_fingerprint', 64);
            $table->string('status', 20)->index();
            $table->string('backup_uuid', 36)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at'], 'platform_backup_slot_running_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_backup_schedule_slots');
    }
};
