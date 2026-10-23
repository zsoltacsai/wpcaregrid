<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->string('intended_website_url')->nullable()->after('stripe_price_id');
            $table->string('intended_website_type', 32)->nullable()->after('intended_website_url');
            $table->string('request_fingerprint', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->dropUnique(['request_fingerprint']);
        });

        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->dropColumn(['intended_website_url', 'intended_website_type', 'request_fingerprint']);
        });
    }
};
