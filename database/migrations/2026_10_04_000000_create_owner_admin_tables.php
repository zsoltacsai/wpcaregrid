<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner administration (docs/OWNER_ADMIN.md):
 *
 * - users: role "owner" needs no column; operators can be disabled and
 *   re-enabled from the owner area without losing their permissions.
 * - staff_invitation_tokens: activation links for operators and owners,
 *   kept apart from customer activation and password reset tokens.
 * - admin_audit_events: what owners changed (content, pricing, customers,
 *   operators), outside the per-website audit log.
 * - content_entries: one structured section per locale, with a draft and a
 *   published version (docs/CONTENT_MANAGEMENT.md).
 * - price_definitions: owner-published technical prices that override or
 *   extend config/pricing.php (docs/PRICING_ADMIN.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('operator_disabled_at')->nullable()->after('operator_permissions')->comment('Operator sign-in blocked while set');
        });

        Schema::create('staff_invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 16)->comment('owner | system');
            $table->string('event', 64)->index();
            $table->string('subject_type', 32)->comment('content | price | customer | operator | website | owner');
            $table->string('subject_key', 128)->comment('Content key and locale, price key, or UUID / ID');
            $table->json('data')->nullable()->comment('Field names and codes; never secrets or passwords');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_key']);
        });

        Schema::create('content_entries', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->comment('Section, e.g. home.hero');
            $table->string('locale', 10);
            $table->json('draft')->nullable();
            $table->json('published')->nullable();
            $table->timestamp('draft_updated_at')->nullable();
            $table->foreignId('draft_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'locale']);
        });

        Schema::create('price_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('product_type', 32);
            $table->string('tier', 32);
            $table->string('interval', 16);
            $table->string('market', 32);
            // Published values: what the catalogue and checkout use. Null until first published.
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('amount')->nullable()->comment('Minor units');
            $table->string('stripe_price_id', 100)->nullable();
            $table->boolean('active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('retired_stripe_price_ids')->nullable()->comment('Earlier Stripe Price IDs, still recognised for existing subscriptions');
            // Draft values: never used by checkout.
            $table->json('draft')->nullable();
            $table->timestamp('draft_updated_at')->nullable();
            $table->foreignId('draft_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['product_type', 'tier', 'interval', 'market']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_definitions');
        Schema::dropIfExists('content_entries');
        Schema::dropIfExists('admin_audit_events');
        Schema::dropIfExists('staff_invitation_tokens');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('operator_disabled_at'));
    }
};
