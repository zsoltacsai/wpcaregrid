<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reason codes such as "connector_disconnected_by_customer" (34 characters)
 * did not fit the 32-character columns. SQLite ignores the length; MySQL
 * refused the update, so a customer disconnect failed after the connector
 * was revoked and left admin access requests open. Found by running the
 * suite on MySQL in CI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_access_requests', fn (Blueprint $table) => $table->string('end_reason', 64)->nullable()->change());
        Schema::table('connectors', fn (Blueprint $table) => $table->string('revoked_reason', 64)->nullable()->change());
    }

    public function down(): void
    {
        // Not narrowed again: existing longer values would no longer fit.
    }
};
