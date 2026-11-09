<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which Stripe Product stands for a product type, per Stripe mode (test and live never mix).
        Schema::create('stripe_catalogue_products', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 8);
            $table->string('product_type', 32);
            $table->string('stripe_product_id', 100);
            $table->string('source', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mode', 'product_type']);
            $table->unique(['mode', 'stripe_product_id']);
        });

        // One reviewed preview (dry run) and, when approved, its execution. The plan is stored server-side;
        // the browser only ever names an operation and the items it approves out of that stored plan.
        Schema::create('stripe_catalogue_operations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('mode', 8);
            $table->string('status', 16);
            $table->json('plan');
            $table->char('plan_hash', 64);
            $table->json('approved_items')->nullable();
            $table->json('result')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_catalogue_operations');
        Schema::dropIfExists('stripe_catalogue_products');
    }
};
