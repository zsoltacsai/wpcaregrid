<?php

namespace Tests\Feature\Privacy;

use App\Models\Customer;
use App\Models\PrivacyErasureExecutionPlan;
use App\Models\User;
use App\Privacy\ErasureExecutionPlanService;
use App\Privacy\PrivacyErasureCircuitBreakerService;
use App\Privacy\PrivacyExecutionAuthorization;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestSource;
use App\Privacy\PrivacyRequestType;
use App\Privacy\PrivacyTombstoneJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

class ErasureExecutionPlanTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PrivacyTombstoneJournal::class, new class implements PrivacyTombstoneJournal
        {
            public function readiness(): array
            {
                return ['state' => 'ready', 'reason' => 'test', 'watermark' => null, 'replay_verified' => false, 'verified_events' => 0];
            }

            public function append(array $event): array
            {
                return $event + ['sequence' => str_repeat('0', 26)];
            }

            public function watermark(): ?string
            {
                return null;
            }

            public function eventsAfter(?string $snapshotWatermark): iterable
            {
                return [];
            }
        });
    }

    public function test_owner_preview_is_immutable_pii_minimal_and_blocked_by_external_gates(): void
    {
        $this->withoutVite();
        $owner = $this->asOwner();
        [$customer, $case] = $this->customerAndErasureCase();
        $originalEmail = $customer->email;

        $response = $this->post(route('owner.privacy-execution-plans.create', $case));

        $plan = PrivacyErasureExecutionPlan::query()->firstOrFail();
        $response->assertRedirect(route('owner.privacy-execution-plans.show', [$case, $plan]));
        $this->assertSame('blocked', $plan->state->value);
        $this->assertContains('WAIT_LEGAL', $plan->blocker_codes);
        $this->assertContains('WAIT_ACCOUNTING', $plan->blocker_codes);
        $this->assertContains('GLOBAL_DESTRUCTIVE_GATE_OFF', $plan->blocker_codes);
        $this->assertStringNotContainsString($originalEmail, json_encode($plan->preview, JSON_THROW_ON_ERROR));
        $this->assertSame($originalEmail, $customer->fresh()->email);
        $this->assertDatabaseCount('privacy_erasure_execution_actions', 2);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'privacy.erasure.preview_created', 'actor_user_id' => $owner->id]);
    }

    public function test_owner_approval_requires_recent_password_and_exact_preview_and_stays_non_executable(): void
    {
        $this->actingAs($this->asOwner(), 'owner');
        [, $case] = $this->customerAndErasureCase();
        $plan = app(ErasureExecutionPlanService::class)->createPreview($case, auth('owner')->user());
        $phrase = 'APPROVE '.$plan->uuid.' '.$plan->preview_hash;

        $this->post(route('owner.privacy-execution-plans.approve', [$case, $plan]), [
            'current_password' => 'wrong-password', 'typed_confirmation' => $phrase, 'preview_hash' => $plan->preview_hash,
        ])->assertSessionHasErrors('password');

        $this->post(route('owner.privacy-execution-plans.approve', [$case, $plan]), [
            'current_password' => 'password', 'typed_confirmation' => 'APPROVE '.$plan->uuid.' stale', 'preview_hash' => $plan->preview_hash,
        ])->assertSessionHasErrors('confirmation');

        $this->post(route('owner.privacy-execution-plans.approve', [$case, $plan]), [
            'current_password' => 'password', 'typed_confirmation' => $phrase, 'preview_hash' => $plan->preview_hash,
        ])->assertRedirect();

        $fresh = $plan->fresh();
        $this->assertSame('approved', $fresh->owner_approval_status);
        $this->assertSame('approved_blocked', $fresh->state->value);
        $this->assertSame($plan->preview_hash, $fresh->approved_preview_hash);
        $action = $fresh->actions()->firstOrFail();
        $blockers = app(PrivacyExecutionAuthorization::class)->blockers($fresh, $action);
        $this->assertContains('GLOBAL_DESTRUCTIVE_GATE_OFF', $blockers);
        $this->assertContains('WAIT_LEGAL', $blockers);
        $this->assertContains('WAIT_ACCOUNTING', $blockers);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'privacy.erasure.plan.owner_approved']);
    }

    public function test_source_change_invalidates_preview_before_owner_approval(): void
    {
        $owner = $this->asOwner();
        [$customer, $case] = $this->customerAndErasureCase();
        $plan = app(ErasureExecutionPlanService::class)->createPreview($case, $owner);
        Carbon::setTestNow(now()->addMinute());
        $customer->forceFill(['first_name' => 'Changed'])->save();

        $result = app(ErasureExecutionPlanService::class)->approve(
            $plan, $owner, 'password', 'APPROVE '.$plan->uuid.' '.$plan->preview_hash, $plan->preview_hash,
        );

        Carbon::setTestNow();
        $this->assertSame('invalidated', $result->state->value);
        $this->assertNull($result->approved_at);
        $this->assertNotNull($result->invalidated_at);
    }

    public function test_operator_cannot_create_owner_plan(): void
    {
        $operator = User::factory()->operator()->create();
        [$customer, $case] = $this->customerAndErasureCase();

        $this->actingAs($operator, 'owner')
            ->post(route('owner.privacy-execution-plans.create', $case))
            ->assertRedirect(route('owner.login'));
        $this->assertDatabaseCount('privacy_erasure_execution_plans', 0);
    }

    public function test_circuit_breaker_blocks_plan_authorization_and_reset_is_explicit_and_audited(): void
    {
        $owner = $this->owner(['password' => 'circuit-owner-password']);
        [, $case] = $this->customerAndErasureCase();
        $plan = app(ErasureExecutionPlanService::class)->createPreview($case, $owner);
        $action = $plan->actions()->firstOrFail();
        $breaker = app(PrivacyErasureCircuitBreakerService::class);
        $breaker->trip('journal_failure', $owner);

        $this->assertTrue($breaker->status()['is_open']);
        $this->assertContains('CIRCUIT_BREAKER_OPEN', app(PrivacyExecutionAuthorization::class)->blockers($plan, $action));

        $breaker->reset($owner, 'circuit-owner-password', 'RESET PRIVACY EXECUTION CIRCUIT');

        $this->assertFalse($breaker->status()['is_open']);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'privacy.erasure.circuit_open']);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'privacy.erasure.circuit_reset']);
    }

    private function customerAndErasureCase(): array
    {
        $customer = Customer::factory()->withUser()->create();
        $case = app(PrivacyRequestService::class)->create(
            $customer, PrivacyRequestType::Erasure, PrivacyRequestSource::Support, 'wave7-case-'.Str::lower(Str::random(20)),
        );

        return [$customer, $case];
    }
}
