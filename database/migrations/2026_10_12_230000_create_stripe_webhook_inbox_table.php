<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type')->index();
            $table->string('object_id')->nullable()->index();
            $table->boolean('livemode');
            // Encrypted by the model cast. Stripe payloads may contain personal data.
            $table->longText('payload');
            $table->string('status')->default('received')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('stripe_created_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error_category')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_inbox');
    }
};
