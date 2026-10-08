<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_identity_id')->nullable()->constrained('customer_billing_identities')->restrictOnDelete();
            $table->foreignId('seller_tax_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('tax_decision_reference', 128)->nullable()->index();
            $table->unsignedInteger('tax_decision_version')->nullable();
            $table->string('stripe_invoice_id')->index();
            $table->string('stripe_event_id')->nullable()->index();
            $table->string('provider', 32)->default('szamlazz_hu');
            $table->string('purpose', 32)->default('subscription_invoice');
            $table->string('status', 40)->default('pending')->index();
            $table->string('blocked_reason', 64)->nullable()->index();
            $table->unsignedBigInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->longText('commercial_snapshot')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->string('last_error_category', 64)->nullable();
            $table->string('provider_invoice_id', 128)->nullable()->index();
            $table->json('provider_response_metadata')->nullable();
            $table->json('dry_run_result')->nullable();
            $table->timestamp('dry_run_validated_at')->nullable();
            $table->timestamp('refund_review_required_at')->nullable();
            $table->timestamps();

            $table->unique(['stripe_invoice_id', 'purpose'], 'invoice_intents_stripe_invoice_purpose_unique');
            $table->index(['status', 'created_at'], 'invoice_intents_status_created_idx');
            $table->index(['customer_id', 'created_at'], 'invoice_intents_customer_created_idx');
        });

        Schema::create('invoice_intent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_intent_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 48);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->string('stripe_event_id')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['invoice_intent_id', 'stripe_event_id', 'event_type'], 'invoice_intent_events_stripe_event_unique');
            $table->index(['invoice_intent_id', 'created_at'], 'invoice_intent_events_intent_created_idx');
        });

        Schema::create('invoice_intent_follow_up_markers', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_payment_intent_id')->index();
            $table->string('stripe_event_id')->unique();
            $table->string('reason', 48);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['stripe_payment_intent_id', 'reason'], 'invoice_followup_payment_reason_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_intent_follow_up_markers');
        Schema::dropIfExists('invoice_intent_events');
        Schema::dropIfExists('invoice_intents');
    }
};
