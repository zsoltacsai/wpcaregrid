<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_tax_configuration_counters', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('version')->default(0);
        });
        DB::table('billing_tax_configuration_counters')->insert(['id' => 1, 'version' => 0]);

        Schema::create('billing_tax_configuration_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->timestamp('effective_at')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('configuration');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('invoice_intents', function (Blueprint $table) {
            $table->foreignId('billing_tax_configuration_version_id')->nullable()
                ->after('seller_tax_profile_id')->constrained('billing_tax_configuration_versions')->nullOnDelete();
        });

        Schema::create('invoice_intent_tax_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('configuration_version_id')->nullable()
                ->constrained('billing_tax_configuration_versions')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('proposed_pending_accountant');
            $table->string('provider_tax_code', 16);
            $table->string('proposed_treatment', 40);
            $table->text('justification');
            $table->string('basis_reference', 160)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['invoice_intent_id', 'created_at'], 'invoice_tax_review_intent_created_idx');
        });

        Schema::create('invoice_issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_intent_id')->constrained()->restrictOnDelete()->unique();
            $table->string('external_reference', 80)->unique();
            $table->char('request_sha256', 64);
            $table->string('status', 32)->index();
            $table->string('provider_invoice_id', 128)->nullable()->unique();
            $table->string('error_category', 64)->nullable();
            $table->json('provider_response_metadata')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_issuance_attempts');
        Schema::dropIfExists('invoice_intent_tax_reviews');
        Schema::table('invoice_intents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_tax_configuration_version_id');
        });
        Schema::dropIfExists('billing_tax_configuration_versions');
        Schema::dropIfExists('billing_tax_configuration_counters');
    }
};
