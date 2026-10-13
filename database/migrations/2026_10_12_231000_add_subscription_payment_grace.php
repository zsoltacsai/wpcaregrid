<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('grace_started_at')->nullable()->index();
            $table->timestamp('grace_ends_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_grace_started_at_index');
            $table->dropIndex('subscriptions_grace_ends_at_index');
            $table->dropColumn(['grace_started_at', 'grace_ends_at']);
        });
    }
};
