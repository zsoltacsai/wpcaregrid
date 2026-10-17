<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_protection_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            $table->string('reason_code', 48);
            $table->string('source_code', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('held_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'backup_id', 'released_at'], 'backup_holds_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_protection_holds');
    }
};
