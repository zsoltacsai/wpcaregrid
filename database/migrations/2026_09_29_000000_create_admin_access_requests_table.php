<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_access_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete()->comment('Connector that claimed it');
            $table->foreignId('job_id')->nullable()->constrained('website_jobs')->nullOnDelete()->comment('CREATE_ADMIN_SESSION');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 16)->comment('customer | operator');
            $table->string('access_level', 16)->comment('maintenance | full_admin');
            $table->string('status', 16)->index()->comment('pending | authorized | active | expired | revoked | failed');
            $table->char('token_hash', 64)->unique()->comment('SHA-256 of the one-time browser token; the token itself is never stored');
            $table->timestamp('token_expires_at');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('confirmed_at')->nullable()->comment('Explicit confirmation (full admin)');
            $table->timestamp('authorized_at')->nullable()->comment('Token consumed by the site');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_code', 64)->nullable();
            $table->timestamps();

            $table->index(['website_id', 'status']);
        });

        Schema::table('connectors', function (Blueprint $table) {
            $table->string('admin_access_url', 255)->nullable()->after('site_url')->comment('Reported by the site: its wp-login.php access action');
        });

        Schema::table('website_events', function (Blueprint $table) {
            $table->foreignId('admin_access_request_id')->nullable()->after('restore_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('website_events', fn (Blueprint $table) => $table->dropConstrainedForeignId('admin_access_request_id'));
        Schema::table('connectors', fn (Blueprint $table) => $table->dropColumn('admin_access_url'));
        Schema::dropIfExists('admin_access_requests');
    }
};
