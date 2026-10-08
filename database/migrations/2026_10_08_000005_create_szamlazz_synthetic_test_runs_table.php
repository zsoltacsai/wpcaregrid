<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('szamlazz_synthetic_test_runs', function (Blueprint $table) {
            $table->id();
            // A permanent unique identity is allocated per authorized run. Its
            // state machine permits at most one provider POST for that run.
            $table->string('execution_slot', 64)->unique();
            $table->string('designation', 32)->default('synthetic_test');
            $table->string('status', 32)->index();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tax_code', 16);
            $table->string('currency', 3)->default('HUF');
            $table->unsignedBigInteger('amount');
            $table->boolean('test_account_attested')->default(false);
            $table->boolean('test_seller_attested')->default(false);
            $table->string('credential_reference', 64);
            $table->string('external_reference', 80)->unique();
            $table->char('snapshot_sha256', 64);
            $table->longText('commercial_snapshot');
            $table->string('provider_invoice_number', 128)->nullable()->unique();
            $table->json('provider_metadata')->nullable();
            $table->string('error_category', 64)->nullable();
            $table->string('pdf_disk', 32)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->unsignedInteger('pdf_bytes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('szamlazz_synthetic_test_runs');
    }
};
