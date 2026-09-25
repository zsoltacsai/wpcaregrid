<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commercial customer (who pays), separate from the login (users).
 * One user per customer for now; multi-user and agency accounts can later
 * move `user_id` to a pivot without changing subscriptions or websites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('email')->unique()->comment('Lower-case, trimmed');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('company_name')->nullable();
            $table->char('country_code', 2);
            $table->string('customer_type', 16)->nullable()->comment('individual | company');
            $table->string('tax_id')->nullable();
            $table->json('billing_address')->nullable();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('uuid')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customers');
    }
};
