<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local mirror of Stripe subscriptions, kept in sync by verified webhooks.
 * Stripe is the source of truth for status and billing periods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Null until payment is confirmed and the customer is resolved.
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('checkout_intent_id')->nullable()->constrained()->nullOnDelete();

            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->unique();
            $table->string('stripe_price_id')->nullable();

            $table->string('product_type', 32);
            $table->string('plan', 32);
            $table->string('interval', 16);
            $table->string('market', 32);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount')->comment('Smallest currency unit');

            $table->string('status', 32)->index();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->string('last_payment_status', 16)->nullable()->comment('paid | failed, from invoice events');
            $table->timestamp('last_payment_at')->nullable();

            // First time the subscription granted access, and whether that was announced.
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('activation_notified_at')->nullable();

            // Creation time of the last subscription event applied, so an older
            // event delivered late cannot overwrite newer state.
            $table->timestamp('stripe_synced_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
