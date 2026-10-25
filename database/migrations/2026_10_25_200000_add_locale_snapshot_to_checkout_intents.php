<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_intents', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable()->after('customer_id')
                ->comment('Resolved customer-facing language snapshot: hu | en');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_intents', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
