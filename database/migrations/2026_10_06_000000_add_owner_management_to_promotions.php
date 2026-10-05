<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons managed in the owner area (docs/COUPONS.md). The discount is still
 * applied by Stripe; the owner area creates the Stripe coupon and promotion
 * code and keeps the rules checked on our side before a Checkout Session is
 * created. A coupon's code, percentage and duration are fixed once created
 * (a Stripe coupon cannot be changed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            // Whole and fractional percentages (12.5): Stripe accepts up to two decimals.
            $table->decimal('percent_off', 5, 2)->nullable()->change();
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->string('stripe_coupon_id')->nullable()->after('stripe_promotion_code_id');
            $table->string('duration', 16)->default('once')->after('currency')->comment('once | repeating | forever (Stripe coupon duration)');
            $table->unsignedSmallInteger('duration_months')->nullable()->after('duration')->comment('Only for repeating');
            $table->unsignedInteger('max_redemptions_per_customer')->nullable()->after('max_redemptions');
            $table->text('note')->nullable()->comment('Internal; never shown to customers');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['stripe_coupon_id', 'duration', 'duration_months', 'max_redemptions_per_customer', 'note']);
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->unsignedTinyInteger('percent_off')->nullable()->change();
        });
    }
};
