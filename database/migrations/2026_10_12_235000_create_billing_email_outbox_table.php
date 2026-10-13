<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_email_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 200)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('billing_invoice_id')->nullable()->constrained('billing_invoices')->restrictOnDelete();
            $table->string('notification', 48);
            $table->string('locale', 8)->default('en');
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->string('failure_category', 80)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_email_outbox');
    }
};
