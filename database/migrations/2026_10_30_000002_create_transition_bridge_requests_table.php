<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transition_bridge_requests', function (Blueprint $table) {
            $table->id();
            $table->char('request_id', 36)->unique('transition_bridge_request_id_unique');
            $table->char('request_digest', 64);
            $table->string('action', 32);
            $table->longText('receipt_payload');
            $table->char('receipt_digest', 64);
            $table->char('release_nonce_digest', 64)->nullable()->unique('transition_bridge_release_nonce_unique');
            $table->timestamp('committed_at', 6);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('transition_bridge_requests')
            && DB::table('transition_bridge_requests')->exists()) {
            throw new RuntimeException('Cannot roll back committed transition bridge receipts.');
        }

        Schema::dropIfExists('transition_bridge_requests');
    }
};
