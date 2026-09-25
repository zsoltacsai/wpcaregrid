<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's website. Connection details are filled in by the future
 * WP CareGrid Connector; nothing here claims a connection before that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->unique()->constrained()->nullOnDelete()->comment('Each plan covers one website');
            $table->string('url');
            $table->string('type', 32)->comment('wordpress | woocommerce');
            $table->string('status', 32)->default('pending')->index();
            $table->string('connector_status', 32)->default('not_installed');
            $table->string('wordpress_version', 32)->nullable();
            $table->string('php_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
