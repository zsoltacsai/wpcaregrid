<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Platform Update Intelligence, Phase D: one row per Owner notification (history and de-duplication). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_update_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);                          // critical | security | failure | digest
            $table->string('dedupe_key', 80)->nullable()->unique(); // a claimed/sent notification; null on a failed attempt so it can be retried
            $table->string('status', 16);                        // pending | sent | failed
            $table->string('failure_code', 48)->nullable();
            $table->unsignedInteger('items')->default(0);        // number of advisories / counts only, never content
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_update_notifications');
    }
};
