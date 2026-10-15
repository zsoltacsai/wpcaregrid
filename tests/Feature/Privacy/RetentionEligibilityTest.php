<?php

namespace Tests\Feature\Privacy;

use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\PrivacyRetentionPolicy;
use App\Models\PrivacyRetentionPreview;
use App\Models\Subscription;
use App\Models\Website;
use App\Privacy\CanonicalJson;
use App\Privacy\DestructiveRetentionGate;
use App\Privacy\RetentionEligibilityEvaluator;
use App\Privacy\RetentionPolicyDefaults;
use App\Privacy\RetentionPolicyWorkflow;
use App\Privacy\RetentionSourceScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

class RetentionEligibilityTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    public function test_all_owner_defaults_are_seeded_separately_from_compliance_approval(): void
    {
        $this->assertCount(33, RetentionPolicyDefaults::all());
        $this->assertDatabaseCount('privacy_retention_policies', 33);
        $this->assertSame(33, PrivacyRetentionPolicy::query()->where('owner_approval_status', 'owner_approved')->count());
        $this->assertSame(0, PrivacyRetentionPolicy::query()->where('approval_status', 'approved')->count());
        $this->assertSame(33, PrivacyRetentionPolicy::query()->where('action', 'report_only')->where('enabled', true)->count());
        foreach (RetentionPolicyDefaults::all() as $key => $definition) {
            $policy = PrivacyRetentionPolicy::query()->where('policy_key', $key)->firstOrFail();
            $this->assertSame($definition['category'], $policy->category_code, $key.' category');
            $this->assertSame($definition['trigger'], $policy->trigger_code, $key.' trigger');
            $this->assertSame($definition['type'], $policy->setting_type, $key.' type');
            if (is_array($definition['value'])) {
                foreach ($definition['value'] as $valueKey => $expectedValue) {
                    $this->assertSame($expectedValue, $policy->technical_value[$valueKey] ?? null, $key.' value '.$valueKey);
                }
            } else {
                $this->assertSame($definition['value'], $policy->technical_value, $key.' value');
            }
            $this->assertSame($definition['compliance_lock'], $policy->compliance_lock, $key.' lock');
            $this->assertSame($definition['legal'] ? 'wait_legal' : 'not_required', $policy->legal_review_status, $key.' legal state');
            $this->assertSame($definition['accounting'] ? 'wait_accounting' : 'not_required', $policy->accounting_review_status, $key.' accounting state');
            $this->assertSame($definition['security'] ? 'wait_security' : 'not_required', $policy->security_review_status, $key.' security state');
            $this->assertSame('report_only', $policy->action->value, $key.' action');
            $this->assertSame('pending_review', $policy->approval_status->value, $key.' compliance approval');
        }
        $this->assertFalse(app(DestructiveRetentionGate::class)->enabled());
    }

    public function test_evaluator_uses_utc_exact_boundary_and_zero_means_eligibility_only(): void
    {
        $evaluator = app(RetentionEligibilityEvaluator::class);
        $base = ['category' => 'BACKUP', 'trigger' => 'service_ended_at', 'trigger_at' => '2026-10-01T00:00:00-04:00', 'duration' => 14, 'unit' => 'days'];

        $before = $evaluator->evaluate($base + ['evaluated_at' => '2026-10-15T03:59:59Z']);
        $boundary = $evaluator->evaluate($base + ['evaluated_at' => '2026-10-15T04:00:00Z']);
        $this->assertSame('NOT_ELIGIBLE', $before['result_code']);
        $this->assertSame('ELIGIBLE_DRY_RUN', $boundary['result_code']);
        $this->assertSame('2026-10-15T04:00:00+00:00', $boundary['eligible_at']);

        $zero = $evaluator->evaluate(['trigger_at' => '2026-10-14T00:00:00Z', 'duration' => 0, 'unit' => 'days', 'evaluated_at' => '2026-10-14T00:00:00Z']);
        $this->assertSame('ELIGIBLE_DRY_RUN', $zero['result_code']);
        $this->assertSame('REPORT_ONLY', $zero['action']);
        $this->assertArrayNotHasKey('delete', $zero);
    }

    public function test_preview_hash_serialization_is_independent_of_json_object_key_order(): void
    {
        $this->assertSame(
            CanonicalJson::encode(['value' => 7, 'unit' => 'days']),
            CanonicalJson::encode(['unit' => 'days', 'value' => 7]),
        );
    }

    public function test_missing_source_and_multiple_compliance_safety_blockers_are_preserved(): void
    {
        $result = app(RetentionEligibilityEvaluator::class)->evaluate([
            'trigger_at' => now('UTC')->subDays(40), 'duration' => 30, 'unit' => 'days',
            'evaluated_at' => now('UTC'), 'source_known' => false, 'legal_approved' => false,
            'accounting_approved' => false, 'security_approved' => false, 'blockers' => ['BLOCKED_ACTIVE_RESTORE'],
            'coverage_state' => 'PARTIAL',
        ]);

        $this->assertSame('UNKNOWN', $result['result_code']);
        $this->assertSame('PARTIAL', $result['coverage_state']);
        $this->assertContains('BLOCKED_LEGAL_APPROVAL', $result['blocker_codes']);
        $this->assertContains('BLOCKED_ACCOUNTING_APPROVAL', $result['blocker_codes']);
        $this->assertContains('BLOCKED_ACTIVE_RESTORE', $result['blocker_codes']);
        $this->assertSame('REPORT_ONLY', $result['action']);
    }

    public function test_accounting_evidence_is_blocked_even_when_technical_age_would_have_elapsed(): void
    {
        $result = app(RetentionEligibilityEvaluator::class)->evaluate([
            'category' => 'ACCOUNTING_EVIDENCE', 'trigger_at' => '2020-01-01T00:00:00Z',
            'duration' => 0, 'unit' => 'days', 'evaluated_at' => '2026-10-14T00:00:00Z',
            'accounting_approved' => false,
        ]);
        $this->assertSame('BLOCKED_ACCOUNTING_APPROVAL', $result['result_code']);
        $this->assertContains('BLOCKED_ACCOUNTING_APPROVAL', $result['blocker_codes']);
        $this->assertTrue($result['technical_candidate']);
        $this->assertSame('REPORT_ONLY', $result['action']);
    }

    public function test_owner_can_create_preview_and_reporting_version_without_mutating_sources(): void
    {
        $owner = $this->asOwner();
        $workflow = app(RetentionPolicyWorkflow::class);
        $beforePolicies = PrivacyRetentionPolicy::count();
        $draft = $workflow->createDraft('RET-04', ['value' => 7, 'unit' => 'days'], $owner);
        $preview = $workflow->preview($draft, $owner);
        $activated = $workflow->activateForReporting($draft, $preview->uuid, $owner);

        $this->assertSame('report_only', $activated->action->value);
        $this->assertTrue($activated->enabled);
        $this->assertSame('owner_approved', $activated->owner_approval_status);
        $this->assertSame('wait_legal', $activated->legal_review_status);
        $this->assertSame(PrivacyRetentionPreview::count(), 1);
        $this->assertSame($beforePolicies + 1, PrivacyRetentionPolicy::count());
        $this->assertSame(0, $preview->fresh()->summary['actually_deleted']);
        $this->assertSame(0, $preview->fresh()->summary['provider_calls']);

        config(['privacy.destructive_retention_enabled' => true]);
        $this->assertFalse($activated->isExecutable(app(DestructiveRetentionGate::class)), 'The reporting-only policy never becomes executable even if the general gate is misconfigured.');
        $this->assertSame(1, AdminAuditEvent::where('event', 'privacy.retention.reporting_activated')->count());

        try {
            $activated->forceFill(['technical_value' => ['value' => 1, 'unit' => 'days']])->save();
            $this->fail('An activated reporting policy was mutable.');
        } catch (LogicException) {
            $persistedValue = $activated->fresh()->technical_value;
            $this->assertSame(7, $persistedValue['value']);
            $this->assertSame('days', $persistedValue['unit']);
        }
    }

    public function test_preview_is_invalidated_when_another_active_policy_version_changes(): void
    {
        $owner = $this->asOwner();
        $workflow = app(RetentionPolicyWorkflow::class);
        $backupDraft = $workflow->createDraft('RET-04', ['value' => 7, 'unit' => 'days'], $owner);
        $backupPreview = $workflow->preview($backupDraft, $owner);

        $webhookDraft = $workflow->createDraft('RET-07', ['value' => 14, 'unit' => 'days'], $owner);
        $webhookPreview = $workflow->preview($webhookDraft, $owner);
        $workflow->activateForReporting($webhookDraft, $webhookPreview->uuid, $owner);

        $this->expectException(ValidationException::class);
        $workflow->activateForReporting($backupDraft, $backupPreview->uuid, $owner);
    }

    public function test_policy_versions_have_only_one_current_reporting_version(): void
    {
        $owner = $this->asOwner();
        $workflow = app(RetentionPolicyWorkflow::class);
        $firstDraft = $workflow->createDraft('RET-04', ['value' => 9, 'unit' => 'days'], $owner);
        $secondDraft = $workflow->createDraft('RET-04', ['value' => 8, 'unit' => 'days'], $owner);
        $firstPreview = $workflow->preview($firstDraft, $owner);
        $workflow->activateForReporting($firstDraft, $firstPreview->uuid, $owner);

        $secondPreview = $workflow->preview($secondDraft, $owner);
        $workflow->activateForReporting($secondDraft, $secondPreview->uuid, $owner);

        $this->assertSame(1, PrivacyRetentionPolicy::query()->where('policy_key', 'RET-04')->where('enabled', true)->count());
        $this->assertSame('superseded', $firstDraft->fresh()->approval_status->value);
        $this->assertSame('pending_review', $secondDraft->fresh()->approval_status->value);
    }

    public function test_draft_duration_is_applied_to_technical_candidate_preview(): void
    {
        $owner = $this->asOwner();
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create(['ended_at' => now('UTC')->subDays(10)]);
        $website = Website::factory()->for($customer)->create(['subscription_id' => $subscription->id]);
        $backup = $website->backups()->create([
            'type' => 'database', 'reason' => 'scheduled', 'status' => 'verified', 'verified_at' => now('UTC')->subDays(9),
        ]);
        $restoreId = DB::table('backup_restores')->insertGetId([
            'uuid' => (string) Str::uuid(), 'website_id' => $website->id, 'backup_id' => $backup->id,
            'scope' => 'database', 'status' => 'queued', 'requested_by' => 'synthetic-test', 'confirmed_at' => now('UTC'),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('website_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'website_id' => $website->id, 'type' => 'UPDATE_PLUGIN', 'status' => 'queued',
            'restore_id' => $restoreId, 'requested_by' => 'synthetic-test', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        $workflow = app(RetentionPolicyWorkflow::class);
        $draft = $workflow->createDraft('RET-04', ['value' => 7, 'unit' => 'days'], $owner);
        $preview = $workflow->preview($draft, $owner);
        $this->assertSame(1, $preview->summary['categories']['customer_backups']['technical_candidates_before_blockers']);
        $this->assertSame(1, $preview->summary['categories']['customer_backups']['blocked_active_restore']);
        $this->assertSame(1, $preview->summary['categories']['customer_backups']['blocked_active_operation']);
        $this->assertSame(1, $preview->summary['categories']['customer_backups']['blocked_last_recovery_point']);
        $this->assertSame(0, $preview->summary['actually_deleted']);
    }

    public function test_locked_safety_policy_cannot_be_changed_and_owner_page_is_read_only_for_nonowners(): void
    {
        $this->withoutVite();
        $owner = $this->asOwner();
        try {
            app(RetentionPolicyWorkflow::class)->createDraft('RET-18', false, $owner);
            $this->fail('Locked global destructive safety value accepted a draft.');
        } catch (ValidationException) {
            $this->assertSame(1, AdminAuditEvent::where('event', 'privacy.retention.locked_change_denied')->count());
        }

        $this->get(route('owner.privacy-retention.index'))->assertOk()
            ->assertSee('REPORT ONLY')->assertSee('DISABLED BY POLICY')->assertSee('WAIT_LEGAL')->assertSee('RET-33');
        $this->get(route('owner.privacy-retention.index'))->assertSee('Privacy restore safety: BLOCKED')
            ->assertSee('independent_journal_not_configured')->assertSee('physical_version_state_unverified');
        $this->get(route('owner.privacy-retention.index'))->assertDontSee('READY_TO_DELETE');
    }

    public function test_stale_or_tampered_preview_cannot_activate_a_policy_version(): void
    {
        $owner = $this->asOwner();
        $workflow = app(RetentionPolicyWorkflow::class);
        $draft = $workflow->createDraft('RET-04', ['value' => 7, 'unit' => 'days'], $owner);
        $preview = $workflow->preview($draft, $owner);
        $preview->forceFill(['preview_hash' => str_repeat('a', 64)])->save();

        $this->expectException(ValidationException::class);
        $workflow->activateForReporting($draft, $preview->uuid, $owner);
    }

    public function test_scanner_exposes_partial_coverage_without_provider_or_source_mutation(): void
    {
        $before = PrivacyRetentionPolicy::query()->count();
        $scan = app(RetentionSourceScanner::class)->scan();
        $this->assertSame('PARTIAL', $scan['coverage']);
        $this->assertContains('application_logs', $scan['unsupported']);
        $this->assertSame($before, PrivacyRetentionPolicy::query()->count());
        $this->assertArrayHasKey('customer_backups', $scan['counts']);
    }

    public function test_missing_active_policy_never_falls_back_to_a_candidate_default(): void
    {
        PrivacyRetentionPolicy::query()->where('policy_key', 'RET-04')->update(['enabled' => false]);
        $scan = app(RetentionSourceScanner::class)->scan();

        $this->assertSame(0, $scan['counts']['customer_backups']['technical_candidates_before_blockers']);
        $this->assertSame('BLOCKED_POLICY_MISSING', $scan['counts']['customer_backups']['result_codes']);
    }

    public function test_owner_privacy_abilities_remain_available_alongside_retention_center(): void
    {
        $this->withoutVite();
        $owner = $this->asOwner();
        $this->assertTrue(Gate::forUser($owner)->allows('owner.privacy.requests.view'));
        $this->get(route('owner.privacy-requests.index'))->assertOk();
        $this->get(route('owner.privacy-retention.index'))->assertOk();

        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user, 'owner')->get(route('owner.privacy-retention.index'))
            ->assertRedirect(route('owner.login'));
        $this->actingAs($owner, 'owner');
    }
}
