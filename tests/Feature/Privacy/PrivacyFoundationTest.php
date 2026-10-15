<?php

namespace Tests\Feature\Privacy;

use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\PrivacyProviderTask;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\PrivacyRetentionPolicy;
use App\Owner\AdminAudit;
use App\Privacy\DestructiveRetentionGate;
use App\Privacy\PrivacyDataCategoryRegistry;
use App\Privacy\PrivacyProvider;
use App\Privacy\PrivacyProviderFactRegistry;
use App\Privacy\PrivacyProviderTaskService;
use App\Privacy\PrivacyProviderTaskStatus;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestSource;
use App\Privacy\PrivacyRequestStatus;
use App\Privacy\PrivacyRequestType;
use App\Privacy\RetentionAction;
use App\Privacy\RetentionApprovalStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

class PrivacyFoundationTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    private function newCase(Customer $customer, string $key = 'privacy-request-key-0001'): PrivacyRequest
    {
        return app(PrivacyRequestService::class)->create($customer, PrivacyRequestType::Access, PrivacyRequestSource::Support, $key);
    }

    public function test_request_creation_is_minimal_idempotent_and_has_append_only_event_history(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $service = app(PrivacyRequestService::class);
        $request = $service->create($customer, PrivacyRequestType::Access, PrivacyRequestSource::Support, 'privacy-request-key-0001');
        $duplicateByKey = $service->create($customer, PrivacyRequestType::Access, PrivacyRequestSource::Support, 'privacy-request-key-0001');
        $duplicateActive = $service->create($customer, PrivacyRequestType::Access, PrivacyRequestSource::Support, 'privacy-request-key-0002');

        $this->assertSame($request->id, $duplicateByKey->id);
        $this->assertSame($request->id, $duplicateActive->id);
        $this->assertSame(1, PrivacyRequest::count());
        $this->assertSame(1, PrivacyRequestEvent::count());
        $this->assertSame(hash('sha256', 'privacy-request-key-0001'), $request->idempotency_hash);
        $this->assertNull($request->getAttribute('narrative'));

        $service->transition($request, PrivacyRequestStatus::IdentityVerificationRequired);
        $service->transition($request->fresh(), PrivacyRequestStatus::ManualReview, metadata: ['reason_code' => 'identity_review', 'email' => 'must_not_be_stored']);
        $this->assertSame(3, PrivacyRequestEvent::count());
        $this->assertSame(['reason_code' => 'identity_review'], PrivacyRequestEvent::query()->where('privacy_request_id', $request->id)->orderByDesc('id')->first()->metadata);

        $this->expectException(LogicException::class);
        $service->transition($request->fresh(), PrivacyRequestStatus::Verified);
    }

    public function test_invalid_idempotency_input_and_cross_request_key_reuse_are_rejected(): void
    {
        $one = Customer::factory()->create();
        $two = Customer::factory()->create();
        $service = app(PrivacyRequestService::class);
        try {
            $service->create($one, PrivacyRequestType::Access, PrivacyRequestSource::Support, 'short');
            $this->fail('Short idempotency key was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, PrivacyRequest::count());
        }

        $service->create($one, PrivacyRequestType::Access, PrivacyRequestSource::Support, 'privacy-request-key-0003');
        $this->expectException(InvalidArgumentException::class);
        $service->create($two, PrivacyRequestType::Access, PrivacyRequestSource::Support, 'privacy-request-key-0003');
    }

    public function test_customer_actor_can_create_only_own_case_and_cannot_change_workflow_state(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $otherCustomer = Customer::factory()->create();
        $service = app(PrivacyRequestService::class);
        $request = $service->create($customer, PrivacyRequestType::Access, PrivacyRequestSource::CustomerPortal, 'privacy-request-key-0009', $customer->user);

        try {
            $service->create($otherCustomer, PrivacyRequestType::Access, PrivacyRequestSource::CustomerPortal, 'privacy-request-key-0010', $customer->user);
            $this->fail('Customer created a request for another tenant.');
        } catch (AuthorizationException) {
            $this->assertSame(1, PrivacyRequest::count());
        }

        $this->expectException(AuthorizationException::class);
        $service->transition($request, PrivacyRequestStatus::Cancelled, $customer->user);
    }

    public function test_owner_actions_are_audited_with_codes_only(): void
    {
        $owner = $this->owner();
        $customer = Customer::factory()->create();
        $request = app(PrivacyRequestService::class)->create($customer, PrivacyRequestType::Erasure, PrivacyRequestSource::OwnerConsole, 'privacy-request-key-0004', $owner);

        app(PrivacyRequestService::class)->transition($request, PrivacyRequestStatus::ManualReview, $owner, ['reason_code' => 'legal_review']);

        $events = AdminAuditEvent::query()->where('actor_user_id', $owner->id)->orderBy('id')->get();
        $this->assertSame(['privacy.request.created', 'privacy.request.status_changed'], $events->pluck('event')->all());
        $this->assertStringNotContainsString($customer->email, json_encode($events->toArray()));
    }

    public function test_provider_tasks_are_idempotent_and_transition_without_provider_calls(): void
    {
        $request = $this->newCase(Customer::factory()->create());
        $service = app(PrivacyProviderTaskService::class);
        $task = $service->create($request, PrivacyProvider::ObjectStorage, 'BACKUP', '72', 'provider-task-key-0001');
        $duplicate = $service->create($request, PrivacyProvider::ObjectStorage, 'BACKUP', '72', 'provider-task-key-0001');
        $this->assertSame($task->id, $duplicate->id);
        $this->assertSame(1, PrivacyProviderTask::count());
        $this->assertSame(PrivacyProviderTaskStatus::Pending, $task->status);

        $failed = $service->transition($task, PrivacyProviderTaskStatus::Failed, errorCategory: 'provider_unavailable');
        $this->assertSame(1, $failed->attempts);
        $this->assertSame('provider_unavailable', $failed->error_category);
        $this->assertDatabaseHas('privacy_request_events', ['event_type' => 'provider_task_status_changed', 'to_status' => 'failed']);

        $service->transition($failed, PrivacyProviderTaskStatus::ManualReview);
        $this->assertSame(PrivacyProviderTaskStatus::ManualReview, $task->fresh()->status);
        $this->assertSame(4, PrivacyRequestEvent::count());

        $this->expectException(InvalidArgumentException::class);
        $service->transition($task->fresh(), PrivacyProviderTaskStatus::Confirmed);
    }

    public function test_provider_scope_and_error_codes_reject_arbitrary_payloads(): void
    {
        $request = $this->newCase(Customer::factory()->create(), 'privacy-request-key-0005');
        $service = app(PrivacyProviderTaskService::class);
        try {
            $service->create($request, PrivacyProvider::Stripe, 'BILLING', 'sk_test_sensitive', 'provider-task-key-0002');
            $this->fail('Sensitive provider scope value was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, PrivacyProviderTask::count());
        }

        $task = $service->create($request, PrivacyProvider::Stripe, 'BILLING', null, 'provider-task-key-0003');
        $this->expectException(InvalidArgumentException::class);
        $service->transition($task, PrivacyProviderTaskStatus::Failed, errorCategory: 'provider response: token=secret');
    }

    public function test_provider_task_owner_audit_is_allowlisted(): void
    {
        $owner = $this->owner();
        $request = $this->newCase(Customer::factory()->create(), 'privacy-request-key-0006');
        $task = app(PrivacyProviderTaskService::class)->create($request, PrivacyProvider::Mail, 'EMAIL', null, 'provider-task-key-0004', $owner);
        app(PrivacyProviderTaskService::class)->transition($task, PrivacyProviderTaskStatus::Requested, $owner);

        $this->assertSame(['privacy.provider_task.created', 'privacy.provider_task.status_changed'], AdminAuditEvent::query()->where('actor_user_id', $owner->id)->orderBy('id')->pluck('event')->all());
        $this->assertContains('privacy.provider_task.status_changed', AdminAudit::EVENTS);
    }

    public function test_customer_policy_is_tenant_scoped_and_owner_console_is_read_only(): void
    {
        $customerOne = Customer::factory()->withUser()->create();
        $customerTwo = Customer::factory()->withUser()->create();
        $request = $this->newCase($customerOne, 'privacy-request-key-0007');
        $owner = $this->asOwner();

        $this->assertTrue(Gate::forUser($customerOne->user)->allows('view', $request));
        $this->assertFalse(Gate::forUser($customerTwo->user)->allows('view', $request));

        $this->get(route('owner.privacy-requests.index'))->assertOk()->assertSee($request->uuid)->assertDontSee($customerOne->email);
        $this->get(route('owner.privacy-requests.show', $request))->assertOk()->assertSee($request->uuid)->assertSee('SAFE / DISABLED BY POLICY');
        $this->assertDatabaseCount('privacy_requests', 1);
        $this->assertDatabaseCount('privacy_request_events', 1);

        $this->actingAs($customerTwo->user, 'owner')
            ->get(route('owner.privacy-requests.show', $request))
            ->assertRedirect(route('owner.login'))
            ->assertSessionHasErrors('email');
        $this->actingAs($owner, 'owner');
        $this->get(route('owner.privacy-requests.show', '00000000-0000-0000-0000-000000000000'))->assertNotFound();
    }

    public function test_owner_privacy_navigation_and_routes_have_explicit_owner_ability(): void
    {
        $this->asOwner();
        $this->get(route('owner.privacy-requests.index'))->assertOk()->assertSee('Privacy requests');

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array($route->getName(), ['owner.privacy-requests.index', 'owner.privacy-requests.show'], true));
        $this->assertCount(2, $routes);
        foreach ($routes as $route) {
            $this->assertContains('can:owner.privacy.requests.view', $route->gatherMiddleware());
            $this->assertContains('auth:owner', $route->gatherMiddleware());
            $this->assertContains('owner', $route->gatherMiddleware());
            $this->assertSame('GET', $route->methods()[0]);
        }
    }

    public function test_retention_gate_fails_closed_and_policy_requires_approvals_and_explicit_gate(): void
    {
        config()->set('privacy.destructive_retention_enabled', null);
        $gate = app(DestructiveRetentionGate::class);
        $this->assertFalse($gate->enabled());
        $this->assertSame('SAFE_DISABLED_BY_POLICY', $gate->safeStatus());

        $policy = new PrivacyRetentionPolicy;
        $policy->forceFill([
            'category_code' => 'ACCOUNTING_EVIDENCE', 'duration_value' => 90, 'duration_unit' => 'days',
            'action' => RetentionAction::Delete, 'approval_status' => RetentionApprovalStatus::Approved,
            'effective_at' => now()->subDay(), 'enabled' => true, 'legal_review_reference' => 'LEGAL-REF-1',
        ]);
        config(['privacy.destructive_retention_enabled' => true]);
        $this->assertFalse($policy->isExecutable($gate), 'Accounting evidence cannot be eligible without an accounting review reference.');
        $policy->accounting_review_reference = 'ACCOUNTING-REF-1';
        $this->assertTrue($policy->isExecutable($gate));
        config(['privacy.destructive_retention_enabled' => 'true']);
        $this->assertFalse($gate->enabled(), 'A string value must not enable a destructive gate.');
        $this->assertFalse($policy->isExecutable($gate));
    }

    public function test_classification_registry_has_all_planned_categories_without_legal_basis_or_retention_durations(): void
    {
        $categories = PrivacyDataCategoryRegistry::all();
        foreach (['ACCOUNT', 'CUSTOMER_PROFILE', 'BILLING', 'ACCOUNTING_EVIDENCE', 'WEBSITE', 'BACKUP', 'SECURITY', 'MONITORING', 'AUDIT', 'WEBHOOK', 'EMAIL', 'SESSION', 'PLATFORM_BACKUP'] as $category) {
            $this->assertArrayHasKey($category, $categories);
            $this->assertArrayHasKey('sensitivity', $categories[$category]);
            $this->assertNotEmpty($categories[$category]['purpose_codes']);
            $this->assertArrayHasKey('operational_owner', $categories[$category]);
            $this->assertArrayHasKey('source_systems', $categories[$category]);
            $this->assertArrayHasKey('retention_class', $categories[$category]);
            $this->assertArrayHasKey('deletion_behavior', $categories[$category]);
            $this->assertArrayNotHasKey('lawful_basis', $categories[$category]);
            $this->assertArrayNotHasKey('retention_days', $categories[$category]);
        }

        foreach (PrivacyProviderFactRegistry::all() as $provider => $facts) {
            $this->assertSame('unverified', $facts['region_status']);
            $this->assertSame('unverified', $facts['contract_status']);
            $this->assertSame('unreviewed', $facts['transfer_review_status']);
            $this->assertArrayHasKey('deletion_verification_status', $facts);
            $this->assertArrayHasKey('version_retention_status', $facts);
            $this->assertArrayHasKey('test_mode_status', $facts);
            if ($provider === 'object_storage') {
                $this->assertSame('logical_delete_api_implemented', $facts['deletion_capability_status']);
                $this->assertSame('logical_key_readback_implemented; physical_version_state_unverified', $facts['deletion_verification_status']);
                $this->assertSame('unverified', $facts['version_retention_status']);
            } else {
                $this->assertSame('unverified', $facts['deletion_capability_status']);
            }
        }
    }

    public function test_request_event_records_cannot_be_updated_or_deleted(): void
    {
        $request = $this->newCase(Customer::factory()->create(), 'privacy-request-key-0008');
        $event = $request->events()->firstOrFail();

        try {
            $event->update(['event_type' => 'rewritten']);
            $this->fail('Append-only privacy event was updated.');
        } catch (LogicException) {
            $this->assertSame('created', $event->fresh()->event_type);
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }
}
