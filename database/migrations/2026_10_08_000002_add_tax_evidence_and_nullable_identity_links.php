<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_billing_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32)->default('eu_vies');
            $table->string('status', 32)->index();
            $table->char('country_code', 2)->nullable();
            $table->text('original_vat_identifier')->nullable();
            $table->text('vat_identifier')->nullable();
            $table->string('vat_identifier_hash', 64)->nullable()->index();
            $table->string('provider_reference', 160)->nullable();
            $table->string('provider_request_date', 64)->nullable();
            $table->text('provider_name')->nullable();
            $table->text('provider_address')->nullable();
            $table->string('provider_name_match', 20)->default('not_returned');
            $table->string('provider_address_match', 20)->default('not_returned');
            $table->timestamp('checked_at')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('message_code', 48);
            $table->timestamps();
            $table->index(['country_code', 'vat_identifier_hash', 'checked_at'], 'vat_validation_identifier_checked_idx');
        });

        Schema::create('tax_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_billing_identity_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->index();
            $table->string('reason_code', 64)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['customer_billing_identity_id', 'created_at'], 'tax_verification_identity_created_idx');
        });

        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->foreignId('billing_identity_id')->nullable()->after('customer_id')->constrained('customer_billing_identities')->nullOnDelete();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('billing_identity_id')->nullable()->after('customer_id')->constrained('customer_billing_identities')->nullOnDelete();
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->foreignId('billing_identity_id')->nullable()->after('customer_id')->constrained('customer_billing_identities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_identity_id');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_identity_id');
        });
        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_identity_id');
        });

        Schema::dropIfExists('tax_verifications');
        Schema::dropIfExists('vat_validations');
    }
};
