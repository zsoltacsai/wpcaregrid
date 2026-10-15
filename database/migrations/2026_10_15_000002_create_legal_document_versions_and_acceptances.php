<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('document_type', 40);
            $table->unsignedInteger('version');
            $table->string('locale', 8);
            $table->string('title', 180);
            $table->longText('body');
            $table->char('content_sha256', 64)->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->string('acceptance_mode', 40)->default('disabled');
            $table->json('audiences')->nullable();
            $table->timestamp('effective_at')->nullable()->index();
            $table->string('approval_reference', 128)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('retired_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['document_type', 'locale', 'version'], 'legal_document_type_locale_version_unique');
            $table->index(['document_type', 'locale', 'status', 'effective_at'], 'legal_document_resolution_idx');
        });

        Schema::create('legal_document_acceptances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('legal_document_version_id')->constrained('legal_document_versions')->restrictOnDelete();
            $table->string('document_type', 40);
            $table->string('locale', 8);
            $table->char('content_sha256', 64);
            $table->string('source', 32);
            $table->timestamp('accepted_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'legal_document_version_id'], 'legal_acceptance_user_version_unique');
            $table->index(['customer_id', 'accepted_at'], 'legal_acceptance_customer_time_idx');
        });

        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->foreignId('legal_document_version_id')->nullable()->after('promotion_code')
                ->constrained('legal_document_versions')->restrictOnDelete();
            $table->char('legal_document_sha256', 64)->nullable()->after('legal_document_version_id');
            $table->timestamp('legal_accepted_at')->nullable()->after('legal_document_sha256');
        });
    }

    public function down(): void
    {
        foreach (['legal_document_acceptances', 'legal_document_versions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back legal document history while {$table} contains records.");
            }
        }

        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legal_document_version_id');
            $table->dropColumn(['legal_document_sha256', 'legal_accepted_at']);
        });
        Schema::dropIfExists('legal_document_acceptances');
        Schema::dropIfExists('legal_document_versions');
    }
};
