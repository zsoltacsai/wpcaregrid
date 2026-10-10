<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synthetic_site_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 40)->unique();
            $table->string('environment', 16);
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('backup_restore_entitled')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synthetic_site_fixtures');
    }
};
