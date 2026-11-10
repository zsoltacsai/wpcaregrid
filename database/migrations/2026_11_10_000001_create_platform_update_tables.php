<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Platform Update Intelligence (docs/PLATFORM_UPDATE_INTELLIGENCE.md): inventory, check history, advisories. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_update_checks', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('trigger', 16);                 // scheduled | manual
            $table->string('status', 16);                  // running | succeeded | partial | failed | refused
            $table->string('reason_code', 64)->nullable(); // fixed code when not succeeded
            $table->json('source_results')->nullable();    // per source: fixed codes only
            $table->unsignedInteger('components_total')->default(0);
            $table->unsignedInteger('components_unknown')->default(0);
            $table->unsignedInteger('advisories_open')->default(0);
            $table->unsignedInteger('advisories_critical')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'finished_at']);
        });

        Schema::create('platform_components', function (Blueprint $table): void {
            $table->id();
            $table->string('component_key', 191)->unique(); // e.g. composer:laravel/framework
            $table->string('kind', 16);                     // composer | npm | runtime | connector
            $table->string('name', 160);
            $table->string('ecosystem', 32)->nullable();    // advisory ecosystem; null = no advisory source (UNVERIFIED)
            $table->string('installed_version', 64)->nullable();
            $table->string('version_state', 16);            // VERIFIED | UNKNOWN
            $table->string('advisory_state', 16);           // CHECKED | UNVERIFIED | UNKNOWN
            $table->string('evidence_source', 64);          // where the version was read from
            $table->boolean('development_only')->default(false);
            $table->boolean('present')->default(true);      // false once it disappears from the inventory
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('advisories_checked_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'present']);
        });

        Schema::create('platform_advisories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_component_id')->constrained('platform_components')->cascadeOnDelete();
            $table->string('advisory_id', 64);              // OSV id (GHSA-..., CVE-..., ...)
            $table->json('aliases')->nullable();            // CVE ids etc.
            $table->string('severity', 16);                 // critical | high | medium | low | unknown
            $table->string('summary', 500)->nullable();
            $table->string('affected_version', 64);         // installed version when detected
            $table->string('fixed_version', 64)->nullable(); // only when the source proves one
            $table->timestamp('published_at')->nullable();
            $table->string('source', 16);                   // osv
            $table->string('source_url', 255);
            $table->string('state', 16);                    // open | resolved
            $table->foreignId('first_seen_check_id')->nullable()->constrained('platform_update_checks')->nullOnDelete();
            $table->foreignId('last_seen_check_id')->nullable()->constrained('platform_update_checks')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('details_checked_at')->nullable(); // when the advisory text was last read from the source (daily re-validation)
            $table->timestamp('notified_at')->nullable();   // notification de-duplication (Phase D)
            $table->timestamps();
            $table->unique(['platform_component_id', 'advisory_id']);
            $table->index(['state', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_advisories');
        Schema::dropIfExists('platform_components');
        Schema::dropIfExists('platform_update_checks');
    }
};
