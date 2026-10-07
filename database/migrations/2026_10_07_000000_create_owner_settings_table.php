<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_settings', function (Blueprint $table) {
            $table->string('id', 96)->primary();
            $table->longText('value'); // JSON scalar/array whose type is defined by App\Owner\OwnerSettings.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_settings');
    }
};
