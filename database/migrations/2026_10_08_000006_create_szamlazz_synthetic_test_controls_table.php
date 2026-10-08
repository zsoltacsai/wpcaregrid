<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('szamlazz_synthetic_test_controls', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamps();
        });

        DB::table('szamlazz_synthetic_test_controls')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('szamlazz_synthetic_test_controls');
    }
};
