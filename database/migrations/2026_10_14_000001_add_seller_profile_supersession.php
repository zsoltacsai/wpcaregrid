<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_tax_profiles', function (Blueprint $table) {
            $table->foreignId('supersedes_profile_id')->nullable()->after('version')
                ->constrained('seller_tax_profiles')->restrictOnDelete();
            $table->unique('supersedes_profile_id', 'seller_tax_profiles_supersedes_unique');
            $table->string('aam_evidence_reference', 200)->nullable()->after('evidence_reference');
        });
    }

    public function down(): void
    {
        Schema::table('seller_tax_profiles', function (Blueprint $table) {
            $table->dropForeign(['supersedes_profile_id']);
        });

        Schema::table('seller_tax_profiles', function (Blueprint $table) {
            $table->dropUnique('seller_tax_profiles_supersedes_unique');
            $table->dropColumn('supersedes_profile_id');
            $table->dropColumn('aam_evidence_reference');
        });
    }
};
