<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom one-off offers for security recovery work (docs/RECOVERY_OFFERS.md).
 * Created by the owner for exactly one customer and one of that customer's
 * websites. The price is stored here and is the only amount ever charged;
 * payment is confirmed by a signed Stripe webhook, never by the browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();

            $table->string('title', 150);
            $table->text('scope')->comment('What the offer includes, as written by the owner');
            $table->text('internal_notes')->nullable()->comment('Owner only; never shown to the customer');

            $table->unsignedBigInteger('amount')->comment('Smallest currency unit');
            $table->char('currency', 3);
            $table->timestamp('valid_until');

            $table->string('status', 16)->index()->comment('draft | offered | accepted | declined | expired | paid | cancelled | completed');
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Stripe Checkout (payment mode). The session is created from the stored price.
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->text('stripe_checkout_url')->nullable();
            $table->timestamp('stripe_checkout_expires_at')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->unsignedBigInteger('paid_amount')->nullable()->comment('What Stripe reported, for reconciliation');

            // Each email is sent once.
            $table->timestamp('offer_notified_at')->nullable();
            $table->timestamp('payment_notified_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_offers');
    }
};
