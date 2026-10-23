<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m14_security_scan_windows', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('active')->default(false);
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('opened_by', 64)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('closed_by', 64)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        DB::table('m14_security_scan_windows')->insert([
            'id' => 1,
            'active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('m14_security_scan_windows');
    }
};
