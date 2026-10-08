<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // The initial invoice promotion is historical. Existing discount_*
            // columns continue to describe the Stripe subscription's current
            // recurring discount.
            $table->string('initial_discount_type', 16)->nullable();
            $table->decimal('initial_discount_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('initial_discount_amount')->nullable();
            $table->char('initial_discount_currency', 3)->nullable();
            $table->string('initial_discount_duration', 16)->nullable();
            $table->unsignedSmallInteger('initial_discount_duration_months')->nullable();
            $table->unsignedBigInteger('initial_invoice_discount_total')->nullable();
            $table->string('initial_stripe_coupon_id')->nullable()->index();
            $table->string('initial_stripe_promotion_code_id')->nullable()->index();
            $table->string('initial_stripe_promotion_code')->nullable();
            $table->timestamp('initial_discount_snapshot_confirmed_at')->nullable();
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            // Coupon terms explain this invoice; discount_total remains the
            // actual Stripe-calculated amount deducted from this invoice.
            $table->string('applied_discount_type', 16)->nullable();
            $table->decimal('applied_discount_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('applied_discount_amount')->nullable();
            $table->char('applied_discount_currency', 3)->nullable();
            $table->string('applied_discount_duration', 16)->nullable();
            $table->unsignedSmallInteger('applied_discount_duration_months')->nullable();
            $table->string('stripe_coupon_id')->nullable()->index();
            $table->string('stripe_promotion_code_id')->nullable()->index();
            $table->string('stripe_promotion_code')->nullable();
            $table->timestamp('discount_snapshot_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropIndex(['stripe_coupon_id']);
            $table->dropIndex(['stripe_promotion_code_id']);
            $table->dropColumn([
                'applied_discount_type', 'applied_discount_percent', 'applied_discount_amount',
                'applied_discount_currency', 'applied_discount_duration', 'applied_discount_duration_months',
                'stripe_coupon_id', 'stripe_promotion_code_id', 'stripe_promotion_code',
                'discount_snapshot_confirmed_at',
            ]);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['initial_stripe_coupon_id']);
            $table->dropIndex(['initial_stripe_promotion_code_id']);
            $table->dropColumn([
                'initial_discount_type', 'initial_discount_percent', 'initial_discount_amount', 'initial_invoice_discount_total',
                'initial_discount_currency', 'initial_discount_duration', 'initial_discount_duration_months',
                'initial_stripe_coupon_id', 'initial_stripe_promotion_code_id',
                'initial_stripe_promotion_code', 'initial_discount_snapshot_confirmed_at',
            ]);
        });
    }
};
