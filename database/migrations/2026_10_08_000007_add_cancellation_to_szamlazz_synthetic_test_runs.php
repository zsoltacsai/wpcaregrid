<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('szamlazz_synthetic_test_runs', function (Blueprint $table): void {
            $table->foreignId('cancelled_by')->nullable()->after('submitted_by')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 64)->nullable()->after('cancelled_by');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('szamlazz_synthetic_test_runs', function (Blueprint $table): void {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_by', 'cancellation_reason', 'cancelled_at']);
        });
    }
};
