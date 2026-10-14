<?php

use App\Privacy\RetentionPolicyDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacy_retention_policies', function (Blueprint $table): void {
            $table->string('setting_type', 16)->default('duration')->after('trigger_code');
            $table->json('technical_value')->nullable()->after('setting_type');
            $table->string('owner_approval_status', 24)->default('provisional')->index();
            $table->string('legal_review_status', 24)->default('not_required');
            $table->string('accounting_review_status', 24)->default('not_required');
            $table->string('security_review_status', 24)->default('not_required');
            $table->string('security_review_reference', 128)->nullable();
            $table->string('owner_decision_reference', 160)->nullable();
            $table->boolean('compliance_lock')->default(false);
            $table->foreignId('previous_version_id')->nullable()->constrained('privacy_retention_policies')->restrictOnDelete();
            $table->foreignId('owner_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('owner_approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->char('active_preview_hash', 64)->nullable();
        });

        Schema::create('privacy_retention_previews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('policy_id')->constrained('privacy_retention_policies')->restrictOnDelete();
            $table->unsignedInteger('policy_version');
            $table->timestamp('evaluated_at');
            $table->string('engine_version', 32);
            $table->string('coverage_state', 16);
            $table->string('source_watermark', 128)->nullable();
            $table->json('summary');
            $table->char('preview_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->index(['policy_id', 'policy_version', 'evaluated_at']);
        });

        $now = '2026-10-14 00:00:00';
        foreach (RetentionPolicyDefaults::all() as $key => $definition) {
            $value = $definition['value'];
            DB::table('privacy_retention_policies')->insertOrIgnore([
                'uuid' => (string) Str::uuid(),
                'policy_key' => $key,
                'version' => 1,
                'category_code' => $definition['category'],
                'trigger_code' => $definition['trigger'],
                'setting_type' => $definition['type'],
                'technical_value' => json_encode($value, JSON_THROW_ON_ERROR),
                'duration_value' => is_array($value) ? ($value['value'] ?? null) : (is_int($value) && $definition['type'] === 'duration' ? $value : null),
                'duration_unit' => is_array($value) ? ($value['unit'] ?? null) : ($definition['unit'] ?? null),
                'action' => 'report_only',
                'approval_status' => 'pending_review',
                'owner_approval_status' => 'owner_approved',
                'legal_review_status' => $definition['legal'] ? 'wait_legal' : 'not_required',
                'accounting_review_status' => $definition['accounting'] ? 'wait_accounting' : 'not_required',
                'security_review_status' => $definition['security'] ? 'wait_security' : 'not_required',
                'owner_decision_reference' => 'reports/m12-privacy/final-decision-register.md#owner-acceptance-2026-10-14',
                'compliance_lock' => $definition['compliance_lock'],
                'effective_at' => $now,
                'owner_approved_at' => $now,
                'activated_at' => $now,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('privacy_retention_previews') && DB::table('privacy_retention_previews')->exists()) {
            throw new RuntimeException('Refusing to roll back M12 retention previews after evidence has been recorded.');
        }

        $seedReference = 'reports/m12-privacy/final-decision-register.md#owner-acceptance-2026-10-14';
        $seedKeys = array_keys(RetentionPolicyDefaults::all());
        $seedRows = DB::table('privacy_retention_policies')->whereIn('policy_key', $seedKeys)
            ->where('version', 1)->where('owner_decision_reference', $seedReference);
        $nonSeedRows = DB::table('privacy_retention_policies')->whereNotIn('policy_key', $seedKeys)
            ->orWhere('version', '<>', 1)->orWhere('owner_decision_reference', '<>', $seedReference)
            ->orWhereNull('owner_decision_reference');
        if ($nonSeedRows->exists()) {
            throw new RuntimeException('Refusing to roll back after retention policy history or non-seed policy data exists.');
        }
        DB::table('privacy_retention_policies')->whereIn('id', $seedRows->pluck('id'))->delete();

        Schema::dropIfExists('privacy_retention_previews');
        Schema::table('privacy_retention_policies', function (Blueprint $table): void {
            $table->dropIndex(['owner_approval_status']);
            $table->dropForeign(['previous_version_id']);
            $table->dropForeign(['owner_approved_by']);
            $table->dropForeign(['last_changed_by']);
            $table->dropColumn([
                'setting_type', 'technical_value', 'owner_approval_status', 'legal_review_status',
                'accounting_review_status', 'security_review_status', 'compliance_lock',
                'security_review_reference', 'owner_decision_reference',
                'previous_version_id', 'owner_approved_by', 'last_changed_by', 'owner_approved_at',
                'activated_at', 'active_preview_hash',
            ]);
        });
    }
};
