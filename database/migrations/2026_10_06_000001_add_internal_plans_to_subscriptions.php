<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Internal, non-public plans (App\Billing\InternalPlan, docs/PROBONO.md).
 *
 * An internal subscription lives in the same table as a Stripe one but has
 * no Stripe subscription, no public tier, no price and no billing interval:
 * those columns become nullable and stay null. A null `plan` means "no
 * public plan", which every plan-driven rule (backups, scheduled scans,
 * WordPress admin access) already treats as "not included".
 */
return new class extends Migration
{
    // SQLite rebuilds the table to change a column; foreign keys must be off for that,
    // and the pragma does nothing inside a transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->change();
            $table->string('plan', 32)->nullable()->change();
            $table->string('interval', 16)->nullable()->change();
            $table->string('market', 32)->nullable()->change();
            $table->char('currency', 3)->nullable()->change();
            $table->unsignedBigInteger('amount')->nullable()->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('internal_plan', 32)->nullable()->index()->comment('probono; null for a Stripe subscription');
            $table->timestamp('internal_starts_at')->nullable();
            $table->timestamp('internal_ends_at')->nullable();
            $table->text('internal_note')->nullable()->comment('Owner only; never shown to the customer');
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        // Internal subscriptions have no Stripe counterpart and cannot exist in the old shape.
        DB::table('subscriptions')->whereNotNull('internal_plan')->delete();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by_user_id');
            $table->dropIndex(['internal_plan']);
            $table->dropColumn(['internal_plan', 'internal_starts_at', 'internal_ends_at', 'internal_note']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable(false)->change();
            $table->string('plan', 32)->nullable(false)->change();
            $table->string('interval', 16)->nullable(false)->change();
            $table->string('market', 32)->nullable(false)->change();
            $table->char('currency', 3)->nullable(false)->change();
            $table->unsignedBigInteger('amount')->nullable(false)->change();
        });

        Schema::enableForeignKeyConstraints();
    }
};
