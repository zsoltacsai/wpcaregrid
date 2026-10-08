<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->text('tax_identifier_encrypted')->nullable();
        });
        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->text('tax_identifier_encrypted')->nullable();
        });

        Schema::create('customer_billing_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('source', 24)->comment('profile | checkout_webhook');
            $table->string('customer_type', 16)->comment('individual | company');
            $table->string('legal_name', 200);
            $table->string('company_name', 200)->nullable();
            $table->char('country_code', 2);
            $table->string('address_line1', 150)->nullable();
            $table->string('address_line2', 150)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('tax_identifier_hash', 64)->nullable()->index();
            $table->text('tax_identifier')->nullable();
            $table->boolean('declared_business_status')->default(false);
            $table->string('verification_status', 32)->default('not_required')->index();
            $table->string('evidence_reference', 200)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'version']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('seller_tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('legal_name', 200)->nullable();
            $table->char('establishment_country', 2)->nullable();
            $table->json('seller_address')->nullable();
            $table->string('aam_status', 24)->default('unknown');
            $table->string('verification_status', 32)->default('unverified')->index();
            $table->date('effective_from')->index();
            $table->date('effective_to')->nullable()->index();
            $table->string('evidence_reference', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Serializes version allocation and effective-period checks, including
        // the first insert when seller_tax_profiles itself is still empty.
        Schema::create('seller_tax_registry_locks', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('seller_tax_registry_locks')->insert(['id' => 1, 'revision' => 0]);

        Schema::create('seller_tax_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_tax_profile_id')->constrained()->restrictOnDelete();
            $table->string('registration_type', 32);
            $table->char('jurisdiction', 2)->nullable();
            $table->text('tax_identifier')->nullable();
            $table->string('tax_identifier_hash', 64)->nullable()->index();
            $table->string('verification_status', 32)->default('unverified')->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('evidence_reference', 200)->nullable();
            $table->timestamps();
            $table->index(['registration_type', 'jurisdiction', 'effective_from'], 'seller_tax_reg_type_jurisdiction_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_tax_registrations');
        Schema::dropIfExists('seller_tax_registry_locks');
        Schema::dropIfExists('seller_tax_profiles');
        Schema::dropIfExists('customer_billing_identities');
        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->dropColumn('tax_identifier_encrypted');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('tax_identifier_encrypted');
        });
    }
};
