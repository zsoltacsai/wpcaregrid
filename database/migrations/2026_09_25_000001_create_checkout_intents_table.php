<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of what a customer intended to buy, created before they are sent
 * to Stripe. It is not a subscription: only a verified Stripe webhook moves
 * it to `completed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status', 32)->index();

            // Customer. Company/billing fields are collected in a later milestone.
            $table->string('email');
            $table->string('first_name');
            $table->string('last_name');
            $table->char('country', 2);
            $table->string('customer_type', 16)->default('individual');
            $table->string('company_name')->nullable();
            $table->string('tax_id')->nullable();
            $table->json('billing_address')->nullable();

            // What was selected and what the server resolved for it.
            $table->string('product_type', 32);
            $table->string('plan', 32);
            $table->string('interval', 16);
            $table->string('market', 32);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount')->comment('Smallest currency unit');
            $table->string('tax_treatment', 64);
            $table->string('stripe_price_id');

            // Promotion, validated against internal rules before checkout.
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promotion_code')->nullable();

            // Stripe references.
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->text('stripe_checkout_url')->nullable();
            $table->timestamp('stripe_checkout_expires_at')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_payment_status', 32)->nullable();

            // Links an intent to the browser session that created it (hashed),
            // so a repeated submit reuses it and only that session can cancel it.
            $table->string('browser_session_hash', 64)->nullable()->index();

            $table->string('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_intents');
    }
};
