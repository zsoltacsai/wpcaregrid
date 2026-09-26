<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security & Malware Engine (docs/SECURITY_ENGINE.md). Scans, findings
 * (deduplicated by a stable fingerprint), incidents with their timeline,
 * quarantined files and remediations. Nothing here holds file contents,
 * credentials or database values: codes, relative paths, hashes and counts only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->index()->comment('open | investigating | containment | remediation | verification | resolved | closed');
            $table->string('severity', 16)->nullable();
            $table->string('title', 191);
            $table->string('summary', 1000)->nullable();
            $table->string('opened_by', 64);
            $table->string('actor_type', 16)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'status']);
        });

        Schema::create('security_scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('security_incidents')->nullOnDelete();
            $table->string('type', 16)->comment('vulnerability | integrity | malware | full');
            $table->string('status', 16)->index()->comment('queued | running | completed | partial | failed | cancelled');
            $table->string('requested_by', 64);
            $table->string('actor_type', 16)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('website_jobs')->nullOnDelete()->comment('Current SECURITY_SCAN part');
            $table->unsignedSmallInteger('parts')->default(0)->comment('Connector scan parts run so far');
            $table->string('scanner_version', 32)->comment('Server engine version');
            $table->string('rules_version', 32)->nullable()->comment('Connector rule set version');
            $table->string('connector_version', 32)->nullable();
            $table->string('vulnerability_source', 32)->nullable();
            $table->json('coverage')->nullable()->comment('Per scanner: completed | partial | unavailable, with counts');
            $table->json('summary')->nullable()->comment('Finding counts by severity and type');
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'status']);
        });

        Schema::create('security_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('first_scan_id')->nullable()->constrained('security_scans')->nullOnDelete();
            $table->foreignId('last_scan_id')->nullable()->constrained('security_scans')->nullOnDelete();
            $table->string('type', 24)->comment('vulnerability | integrity | suspicious_file | suspicious_database | suspicious_user | suspicious_cron | configuration (confirmed malware = suspicious_file with status confirmed)');
            $table->string('code', 64);
            $table->string('severity', 16)->nullable()->comment('null when the source gives none: never invented');
            $table->string('confidence', 16);
            $table->string('status', 16)->index()->comment('open | confirmed | quarantined | remediated | false_positive | ignored | resolved');
            $table->string('title', 191);
            $table->string('description', 1000)->nullable();
            $table->string('component', 191)->nullable()->comment('core | plugin/theme slug | option name…');
            $table->string('path', 512)->nullable()->comment('Relative to the WordPress root');
            $table->char('fingerprint', 40)->comment('Stable identity: type, code, component, path');
            $table->json('evidence')->nullable()->comment('Metadata only: rule codes, hashes, sizes, versions');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'fingerprint']);
            $table->index(['website_id', 'status']);
        });

        Schema::create('security_incident_finding', function (Blueprint $table) {
            $table->foreignId('incident_id')->constrained('security_incidents')->cascadeOnDelete();
            $table->foreignId('finding_id')->constrained('security_findings')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['incident_id', 'finding_id']);
        });

        Schema::create('security_incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('security_incidents')->cascadeOnDelete();
            $table->string('type', 32)->comment('opened | status | note | finding | scan | backup | admin_access | remediation');
            $table->string('actor', 64)->default('system');
            $table->string('actor_type', 16)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('data')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['incident_id', 'id']);
        });

        Schema::create('security_remediations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finding_id')->constrained('security_findings')->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('security_incidents')->nullOnDelete();
            $table->string('type', 32)->comment('quarantine_file | restore_quarantined_file | repair_core_file | repair_plugin_file | disable_plugin');
            $table->string('status', 24)->index()->comment('awaiting_backup | queued | running | completed | failed | blocked | cancelled');
            $table->string('requested_by', 64);
            $table->string('actor_type', 16)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('backup_id')->nullable()->constrained()->nullOnDelete()->comment('Verified full backup taken before the change');
            $table->foreignId('job_id')->nullable()->constrained('website_jobs')->nullOnDelete();
            $table->unsignedBigInteger('quarantine_id')->nullable();
            $table->json('before')->nullable()->comment('State before: path, sha256, size, version');
            $table->json('after')->nullable()->comment('State after, as reported by the connector');
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'status']);
        });

        Schema::create('security_quarantines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finding_id')->nullable()->constrained('security_findings')->nullOnDelete();
            $table->foreignId('remediation_id')->nullable()->constrained('security_remediations')->nullOnDelete();
            $table->string('original_path', 512);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamp('original_modified_at')->nullable();
            $table->string('original_permissions', 8)->nullable();
            $table->string('reason', 191);
            $table->string('status', 16)->index()->comment('quarantined | restored');
            $table->timestamp('quarantined_at');
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('security_remediations', function (Blueprint $table) {
            $table->foreign('quarantine_id')->references('id')->on('security_quarantines')->nullOnDelete();
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->string('security_status', 16)->nullable()->after('health_checked_at')->index()->comment('healthy | attention | critical | unknown (null = never assessed)');
            $table->unsignedInteger('security_open_findings')->default(0)->after('security_status');
            $table->timestamp('security_scanned_at')->nullable()->after('security_open_findings')->comment('Last completed or partial scan');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex(['security_status']);
            $table->dropColumn(['security_status', 'security_open_findings', 'security_scanned_at']);
        });
        Schema::table('security_remediations', fn (Blueprint $table) => $table->dropForeign(['quarantine_id']));
        Schema::dropIfExists('security_quarantines');
        Schema::dropIfExists('security_remediations');
        Schema::dropIfExists('security_incident_events');
        Schema::dropIfExists('security_incident_finding');
        Schema::dropIfExists('security_findings');
        Schema::dropIfExists('security_scans');
        Schema::dropIfExists('security_incidents');
    }
};
