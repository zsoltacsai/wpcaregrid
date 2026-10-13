<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('pending_plan', 32)->nullable();
            $table->string('pending_interval', 16)->nullable();
            $table->string('pending_stripe_price_id')->nullable();
            $table->string('pending_pricing_version', 80)->nullable();
            $table->timestamp('pending_change_effective_at')->nullable()->index();
            $table->string('pending_change_status', 32)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_pending_change_effective_at_index');
            $table->dropIndex('subscriptions_pending_change_status_index');
            $table->dropColumn([
                'pending_plan', 'pending_interval', 'pending_stripe_price_id',
                'pending_pricing_version', 'pending_change_effective_at', 'pending_change_status',
            ]);
        });
    }
};
