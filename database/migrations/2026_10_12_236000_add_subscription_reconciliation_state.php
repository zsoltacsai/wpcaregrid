<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('reconciliation_status', 40)->default('pending')->index();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->string('reconciliation_error_category', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['reconciliation_status']);
            $table->dropColumn(['reconciliation_status', 'last_reconciled_at', 'reconciliation_error_category']);
        });
    }
};
