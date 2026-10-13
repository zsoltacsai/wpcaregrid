<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_definition_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['price_definition_id', 'version']);
        });

        Schema::table('price_definitions', function (Blueprint $table) {
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
        });

        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->string('pricing_version', 80)->nullable()->index();
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('pricing_version', 80)->nullable()->index();
        });

        DB::table('price_definitions')->whereNotNull('published_at')->orderBy('id')->chunkById(100, function ($definitions): void {
            foreach ($definitions as $definition) {
                $snapshot = [
                    'product_type' => $definition->product_type,
                    'tier' => $definition->tier,
                    'interval' => $definition->interval,
                    'market' => $definition->market,
                    'currency' => $definition->currency,
                    'amount' => (int) $definition->amount,
                    'stripe_price_id' => $definition->stripe_price_id,
                    'active' => (bool) $definition->active,
                    'retired_stripe_price_ids' => $definition->retired_stripe_price_ids ? json_decode($definition->retired_stripe_price_ids, true) : [],
                ];
                $now = now();
                $versionId = DB::table('price_definition_versions')->insertGetId([
                    'price_definition_id' => $definition->id,
                    'version' => 1,
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'published_by' => $definition->published_by,
                    'published_at' => $definition->published_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('price_definitions')->where('id', $definition->id)->update(['current_version_id' => $versionId]);
            }
        });
    }

    public function down(): void
    {
        // SQLite (and MySQL) require indexes to be removed before their
        // backing columns. Keep rollback deterministic across both engines.
        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->dropIndex('checkout_intents_pricing_version_index');
            $table->dropColumn('pricing_version');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_pricing_version_index');
            $table->dropColumn('pricing_version');
        });
        Schema::table('price_definitions', function (Blueprint $table) {
            $table->dropIndex('price_definitions_current_version_id_index');
            $table->dropColumn('current_version_id');
        });
        Schema::dropIfExists('price_definition_versions');
    }
};
