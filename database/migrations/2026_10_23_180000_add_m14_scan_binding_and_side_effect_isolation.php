<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m14_security_scan_windows', function (Blueprint $table) {
            $table->uuid('scan_uuid')->nullable()->unique();
            $table->boolean('side_effect_isolation')->default(false);
        });

        Schema::create('m14_security_scan_isolations', function (Blueprint $table) {
            $table->id();
            $table->uuid('scan_uuid')->unique();
            $table->unsignedTinyInteger('window_id');
            $table->foreign('window_id')->references('id')->on('m14_security_scan_windows')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->uuid('website_uuid');
            $table->uuid('customer_uuid');
            $table->foreignId('connector_id')->constrained()->restrictOnDelete();
            $table->uuid('connector_uuid');
            $table->timestamp('window_opened_at');
            $table->timestamp('window_expires_at');
            $table->timestamp('authorized_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m14_security_scan_isolations');
        Schema::table('m14_security_scan_windows', function (Blueprint $table) {
            $table->dropUnique(['scan_uuid']);
            $table->dropColumn(['scan_uuid', 'side_effect_isolation']);
        });
    }
};
