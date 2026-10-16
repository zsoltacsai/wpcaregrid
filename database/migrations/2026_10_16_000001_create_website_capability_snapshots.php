<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_capability_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('connector_version', 32)->nullable();
            $table->string('wordpress_version', 32)->nullable();
            $table->string('php_version', 32)->nullable();
            $table->string('policy_version', 64);
            $table->string('evaluator_version', 32);
            $table->unsignedSmallInteger('schema_version')->default(0);
            $table->json('facts')->nullable();
            $table->json('capabilities');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('website_capability_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('policy_version', 64);
            $table->json('capabilities');
            $table->timestamp('changed_at');
            $table->index(['website_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_capability_transitions');
        Schema::dropIfExists('website_capability_snapshots');
    }
};
