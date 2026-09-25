<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal mirror of Stripe promotion codes. Stripe applies the discount;
 * these rules decide on our side whether a code may be used for a selection,
 * so the browser cannot apply a code to a plan it was not meant for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Stored upper-case; matched case-insensitively');
            $table->string('stripe_promotion_code_id')->nullable()->comment('promo_…; required before the code can be used');
            $table->boolean('active')->default(true);

            // Discount, for display. Stripe's coupon is what is charged.
            $table->string('discount_type', 16)->comment('percent | fixed');
            $table->unsignedTinyInteger('percent_off')->nullable();
            $table->unsignedBigInteger('amount_off')->nullable()->comment('Smallest currency unit');
            $table->char('currency', 3)->nullable()->comment('Required for fixed discounts');

            // Eligibility. Null means "no restriction".
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->json('product_types')->nullable();
            $table->json('plans')->nullable();
            $table->json('intervals')->nullable();
            $table->json('markets')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
