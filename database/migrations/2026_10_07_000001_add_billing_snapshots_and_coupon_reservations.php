<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_source', 32)->nullable()->after('internal_plan');
            $table->unsignedBigInteger('base_price_amount')->nullable();
            $table->char('base_price_currency', 3)->nullable();
            $table->string('pricing_market', 32)->nullable();
            $table->string('discount_type', 16)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('discount_amount')->nullable();
            $table->char('discount_currency', 3)->nullable();
            $table->string('discount_duration', 16)->nullable();
            $table->unsignedSmallInteger('discount_duration_months')->nullable();
            $table->string('stripe_coupon_id')->nullable()->index();
            $table->string('stripe_promotion_code_id')->nullable()->index();
            $table->string('stripe_promotion_code')->nullable();
            $table->unsignedBigInteger('current_recurring_amount')->nullable();
            $table->char('current_recurring_currency', 3)->nullable();
            $table->timestamp('billing_snapshot_confirmed_at')->nullable();
            $table->string('stripe_synced_event_id')->nullable();
        });

        DB::table('subscriptions')->whereNotNull('internal_plan')->update(['billing_source' => 'internal_probono']);
        DB::table('subscriptions')->whereNull('internal_plan')->whereNotNull('stripe_subscription_id')->update(['billing_source' => 'stripe']);

        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_invoice_id')->unique();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->unsignedBigInteger('subtotal')->nullable();
            $table->unsignedBigInteger('discount_total')->nullable();
            $table->unsignedBigInteger('tax_total')->nullable();
            $table->unsignedBigInteger('total')->nullable();
            $table->unsignedBigInteger('amount_paid')->nullable();
            $table->unsignedBigInteger('amount_due')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('invoice_status', 32)->nullable()->index();
            $table->string('billing_reason', 64)->nullable();
            $table->timestamp('stripe_created_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_stripe_event_at')->nullable();
            $table->string('last_stripe_event_id')->nullable();
            $table->timestamp('payment_confirmation_notified_at')->nullable();
            $table->timestamp('payment_failure_notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checkout_intent_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->char('email_hash', 64)->index();
            $table->string('status', 16)->index()->comment('reserved | consumed | released');
            $table->timestamp('reserved_until')->nullable()->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['promotion_id', 'status', 'reserved_until']);
            $table->index(['promotion_id', 'email_hash', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_reservations');
        Schema::dropIfExists('billing_invoices');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'billing_source', 'base_price_amount', 'base_price_currency', 'pricing_market',
                'discount_type', 'discount_percent', 'discount_amount', 'discount_currency',
                'discount_duration', 'discount_duration_months', 'stripe_coupon_id',
                'stripe_promotion_code_id', 'stripe_promotion_code', 'current_recurring_amount',
                'current_recurring_currency', 'billing_snapshot_confirmed_at', 'stripe_synced_event_id',
            ]);
        });
    }
};
