<?php

namespace Tests\Feature\Privacy;

use App\Billing\SubscriptionStatus;
use App\Models\Customer;
use App\Models\PrivacyRequest;
use App\Models\Subscription;
use App\Models\Website;
use App\Privacy\AccountClosureImpactPlanner;
use App\Privacy\NonDeliverableIdentity;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestSource;
use App\Privacy\PrivacyRequestStatus;
use App\Privacy\PrivacyRequestType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

class AccountClosureOrchestrationTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    public function test_owner_preview_is_aggregate_only_and_does_not_mutate_customer_or_related_records(): void
    {
        $this->withoutVite();
        $owner = $this->asOwner();
        $customer = Customer::factory()->withUser()->create();
        $case = app(PrivacyRequestService::class)->create($customer, PrivacyRequestType::Erasure, PrivacyRequestSource::Support, 'wave5-erasure-request-0001');
        $before = [
            [$customer->email, $customer->first_name, $customer->last_name, $customer->active, $customer->billing_address],
            [$customer->user->email, $customer->user->name],
            $customer->subscriptions()->count(),
            $customer->websites()->count(),
        ];

        $response = $this->post(route('owner.privacy-requests.closure.preview'), ['customer_uuid' => $customer->uuid]);

        $response->assertOk()->assertSee('NOT_ELIGIBLE')->assertSee('WAIT_ACCOUNTING')->assertSee('Destructive retention disabled');
        $response->assertDontSee($customer->email)->assertDontSee($customer->user->name);
        $preview = app(AccountClosureImpactPlanner::class)->preview($customer);
        $this->assertSame($case->uuid, $preview['erasure_request_reference']);
        $this->assertSame('PARTIAL', $preview['coverage']);
        $this->assertSame(0, $preview['customer_backups']['deleted']);
        $fresh = $customer->fresh();
        $after = [
            [$fresh->email, $fresh->first_name, $fresh->last_name, $fresh->active, $fresh->billing_address],
            [$fresh->user->email, $fresh->user->name],
            $fresh->subscriptions()->count(),
            $fresh->websites()->count(),
        ];
        $this->assertSame($this->canonicalize($before), $this->canonicalize($after));
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'privacy.closure.impact_previewed']);
        $this->assertTrue($owner->isOwner());
    }

    public function test_account_closure_is_distinct_and_does_not_enter_erasure_cooling_off(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $service = app(PrivacyRequestService::class);
        $closure = $service->create($customer, PrivacyRequestType::AccountClosure, PrivacyRequestSource::Support, 'wave5-closure-request-0001');
        $erasure = $service->create($customer, PrivacyRequestType::Erasure, PrivacyRequestSource::Support, 'wave5-erasure-request-0002');

        $this->assertNotSame($closure->id, $erasure->id);
        $this->assertSame('account_closure', $closure->request_type->value);
        $this->assertSame('erasure', $erasure->request_type->value);
        $this->expectException(LogicException::class);
        $service->transition($closure, PrivacyRequestStatus::CoolingOff);
    }

    public function test_erasure_cooling_off_is_cancellable_but_has_no_execution_transition(): void
    {
        $customer = Customer::factory()->create();
        $service = app(PrivacyRequestService::class);
        $case = $service->create($customer, PrivacyRequestType::Erasure, PrivacyRequestSource::Support, 'wave5-erasure-request-0003');
        $case = $service->transition($case, PrivacyRequestStatus::IdentityVerificationRequired);
        $case = $service->transition($case, PrivacyRequestStatus::Verified);
        $case = $service->transition($case, PrivacyRequestStatus::CoolingOff);
        $this->assertSame(PrivacyRequestStatus::CoolingOff, $case->status);
        $this->assertTrue($case->status->canTransitionTo(PrivacyRequestStatus::Cancelled));
        $this->assertFalse($case->status->canTransitionTo(PrivacyRequestStatus::Completed));
        $this->assertFalse($case->status->canTransitionTo(PrivacyRequestStatus::Failed));
    }

    public function test_preview_requires_separate_erasure_request_and_keeps_accounting_and_site_data_out_of_anonymization(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $preview = app(AccountClosureImpactPlanner::class)->preview($customer);

        $this->assertSame('NOT_ELIGIBLE', $preview['profile_result']);
        $this->assertContains('SEPARATE_ERASURE_REQUEST_REQUIRED', $preview['profile_blockers']);
        $this->assertSame('RETENTION_EXCEPTION', $preview['accounting_evidence']['state']);
        $this->assertSame('NOT_MODIFIED_OR_PREVIEWED_AS_ERASABLE', $preview['customer_site_data']);
        $this->assertContains('WAIT_ACCOUNTING', array_column($preview['anonymization_plan'], 'approval_state'));
        $this->assertContains('no_site_mutation', array_column($preview['anonymization_plan'], 'action'));
    }

    public function test_non_deliverable_identity_is_unique_and_does_not_encode_original_email(): void
    {
        $one = NonDeliverableIdentity::email();
        $two = NonDeliverableIdentity::email();

        $this->assertNotSame($one, $two);
        $this->assertStringEndsWith('@privacy.invalid', $one);
        $this->assertStringNotContainsString('example', $one);
        $this->assertStringNotContainsString('@', substr($one, 0, -strlen('@privacy.invalid')));
    }

    public function test_simulated_anonymization_releases_email_without_reassociating_historical_customer(): void
    {
        $old = Customer::factory()->withUser()->create();
        $originalEmail = $old->email;
        $replacement = NonDeliverableIdentity::email();
        $old->forceFill(['email' => $replacement])->save();
        $old->user->forceFill(['email' => $replacement])->save();

        $new = Customer::factory()->withUser()->create(['email' => $originalEmail]);

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($old->id, $old->fresh()->user->customer->id);
        $this->assertSame($new->id, $new->fresh()->user->customer->id);
        $this->assertSame($replacement, $old->fresh()->email);
        $this->assertSame($originalEmail, $new->fresh()->email);
    }

    public function test_owner_can_record_case_but_operator_and_customer_cannot_access_owner_case_tools(): void
    {
        $this->withoutVite();
        $owner = $this->asOwner();
        $customer = Customer::factory()->withUser()->create();
        $this->post(route('owner.privacy-requests.closure.store'), [
            'customer_uuid' => $customer->uuid,
            'request_type' => 'account_closure',
        ])->assertRedirect();
        $case = PrivacyRequest::query()->where('customer_id', $customer->id)->where('request_type', 'account_closure')->firstOrFail();
        $this->assertDatabaseHas('privacy_requests', ['customer_id' => $customer->id, 'request_type' => 'account_closure']);
        $this->assertTrue($customer->fresh()->active);
        $this->assertSame($customer->email, $customer->fresh()->email);
        $this->get(route('owner.privacy-requests.show', $case))->assertOk()->assertDontSee('value="verified"');
        $this->post(route('owner.privacy-requests.transition', $case), ['status' => 'verified'])->assertSessionHasErrors('status');
        $this->assertSame(PrivacyRequestStatus::Requested, $case->fresh()->status);

        auth('owner')->logout();
        $this->actingAs($customer->user, 'owner');
        $this->get(route('owner.privacy-requests.closure'))->assertRedirect(route('owner.login'));
        $this->assertTrue($owner->isOwner());
    }

    public function test_backup_age_is_only_a_candidate_and_missing_service_end_never_uses_account_closure_time(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00 UTC');
        $customer = Customer::factory()->withUser()->create();
        $preview = app(AccountClosureImpactPlanner::class)->preview($customer, Carbon::now('UTC'));

        $this->assertTrue($preview['customer_backups']['active_plan_retention_separate']);
        $this->assertSame(30, $preview['customer_backups']['post_service_retention_days']);
        $this->assertSame(0, $preview['customer_backups']['technical_candidates']);
        $this->assertSame(0, DB::table('backups')->count());
    }

    public function test_backup_preview_reports_first_eligibility_time_and_respects_exact_boundary(): void
    {
        Carbon::setTestNow('2026-10-31 12:00:00 UTC');
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->for($customer)->create(['ended_at' => Carbon::parse('2026-10-01 12:00:00 UTC')]);
        $website = Website::factory()->for($customer)->create(['subscription_id' => $subscription->id]);
        DB::table('backups')->insert([
            'uuid' => (string) Str::uuid(),
            'website_id' => $website->id,
            'type' => 'full',
            'reason' => 'manual',
            'status' => 'verified',
            'created_at' => '2026-10-01 12:00:00',
            'updated_at' => '2026-10-01 12:00:00',
        ]);

        $before = app(AccountClosureImpactPlanner::class)->preview($customer, Carbon::parse('2026-10-31 11:59:59 UTC'));
        $boundary = app(AccountClosureImpactPlanner::class)->preview($customer, Carbon::parse('2026-10-31 12:00:00 UTC'));

        $this->assertSame(0, $before['customer_backups']['technical_candidates']);
        $this->assertSame('2026-10-31T12:00:00+00:00', $before['customer_backups']['next_technical_eligibility_at']);
        $this->assertSame(1, $boundary['customer_backups']['technical_candidates']);
        $this->assertSame('2026-10-31T12:00:00+00:00', $boundary['customer_backups']['next_technical_eligibility_at']);
        $this->assertSame(0, $boundary['customer_backups']['deleted']);
    }

    public function test_backup_preview_excludes_incomplete_objects_and_uses_later_completion_as_retention_origin(): void
    {
        Carbon::setTestNow('2026-10-31 12:00:00 UTC');
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->for($customer)->create(['ended_at' => Carbon::parse('2026-10-01 12:00:00 UTC')]);
        $website = Website::factory()->for($customer)->create(['subscription_id' => $subscription->id]);
        DB::table('backups')->insert([
            [
                'uuid' => (string) Str::uuid(), 'website_id' => $website->id, 'type' => 'full', 'reason' => 'manual',
                'status' => 'queued', 'created_at' => '2026-08-01 12:00:00', 'verified_at' => null,
                'completed_at' => null, 'updated_at' => '2026-08-01 12:00:00',
            ],
            [
                'uuid' => (string) Str::uuid(), 'website_id' => $website->id, 'type' => 'full', 'reason' => 'manual',
                'status' => 'verified', 'created_at' => '2026-10-10 12:00:00', 'verified_at' => '2026-10-10 12:00:00',
                'completed_at' => '2026-10-10 12:00:00', 'updated_at' => '2026-10-10 12:00:00',
            ],
        ]);

        $preview = app(AccountClosureImpactPlanner::class)->preview($customer, Carbon::now('UTC'));

        $this->assertSame(2, $preview['customer_backups']['scanned']);
        $this->assertSame(0, $preview['customer_backups']['technical_candidates']);
        $this->assertSame(1, $preview['customer_backups']['manual_review_backup_states']);
        $this->assertContains('MANUAL_REVIEW', $preview['customer_backups']['blockers']);
        $this->assertSame('2026-11-09T12:00:00+00:00', $preview['customer_backups']['next_technical_eligibility_at']);
        $this->assertSame(0, $preview['customer_backups']['deleted']);
    }

    public function test_closure_restricts_and_restores_access_but_never_cancels_an_active_subscription(): void
    {
        $owner = $this->asOwner();
        $customer = Customer::factory()->withUser()->create();
        $activeSubscription = Subscription::factory()->for($customer)->create();
        $service = app(PrivacyRequestService::class);
        $case = $service->create($customer, PrivacyRequestType::AccountClosure, PrivacyRequestSource::Support, 'wave5-close-access-0001');

        try {
            $service->restrictAccountAccess($case, $owner);
            $this->fail('Active subscription must block access restriction until the separate billing workflow ends it.');
        } catch (ValidationException) {
            $this->assertTrue($customer->fresh()->active);
            $this->assertSame(SubscriptionStatus::Active, $activeSubscription->fresh()->status);
        }

        $activeSubscription->forceFill(['status' => SubscriptionStatus::Canceled, 'ended_at' => now()])->save();
        $case = $service->restrictAccountAccess($case, $owner);
        $this->assertFalse($customer->fresh()->active);
        $this->assertSame(PrivacyRequestStatus::AccessRestricted, $case->status);
        $this->assertDatabaseHas('privacy_request_events', ['privacy_request_id' => $case->id, 'event_type' => 'account_access_restricted']);
        $this->actingAs($customer->user, 'web')
            ->get(route('account.dashboard'))
            ->assertRedirect(route('login'));

        $case = $service->restoreAccountAccess($case, $owner);
        $this->assertTrue($customer->fresh()->active);
        $this->assertSame(PrivacyRequestStatus::Cancelled, $case->status);
        $this->assertDatabaseHas('privacy_request_events', ['privacy_request_id' => $case->id, 'event_type' => 'account_access_restored']);
        $this->assertSame(SubscriptionStatus::Canceled, $activeSubscription->fresh()->status);
        $this->assertSame(0, $customer->privacyRequests()->where('request_type', PrivacyRequestType::Erasure->value)->count());
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
