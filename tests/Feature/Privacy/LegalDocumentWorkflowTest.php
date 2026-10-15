<?php

namespace Tests\Feature\Privacy;

use App\Legal\LegalDocumentCatalog;
use App\Legal\LegalDocumentWorkflow;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\LegalDocumentAcceptance;
use App\Privacy\CustomerPrivacyExportData;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestSource;
use App\Privacy\PrivacyRequestType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class LegalDocumentWorkflowTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_can_prepare_approve_and_publish_an_immutable_localized_version(): void
    {
        $owner = $this->asOwner();
        $workflow = app(LegalDocumentWorkflow::class);
        $draft = $workflow->createDraft([
            'document_type' => 'terms_of_service',
            'locale' => 'en',
            'title' => 'Terms draft',
            'body' => '<p>Reviewed <script>alert(1)</script><a href="javascript:alert(1)">terms</a></p>',
            'acceptance_mode' => 'disabled',
            'effective_at' => '2026-10-15T12:00',
        ], $owner);

        $this->assertSame('draft', $draft->status);
        $this->assertStringNotContainsString('<script', $draft->body);
        $this->assertStringNotContainsString('javascript:', $draft->body);
        $this->assertNull(app(LegalDocumentCatalog::class)->current('terms_of_service', 'en'));

        $approved = $workflow->approve($draft, 'LEGAL-REVIEW-REF', $owner);
        $this->assertSame('approved', $approved->status);
        $this->assertSame(hash('sha256', $approved->body), $approved->content_sha256);
        $this->assertSame($owner->id, $approved->approved_by_user_id);
        $published = $workflow->publish($approved, $owner);
        $this->assertSame('published', $published->status);
        $this->assertSame($published->id, app(LegalDocumentCatalog::class)->current('terms_of_service', 'en', Carbon::parse('2026-10-15T12:01:00Z'))?->id);
        $this->assertSame(3, AdminAuditEvent::where('subject_type', 'legal_document_version')->count());

        $this->expectException(LogicException::class);
        $published->update(['body' => '<p>Changed after publication.</p>']);
    }

    public function test_future_effective_publication_and_unapproved_draft_are_not_public(): void
    {
        $owner = $this->owner();
        $workflow = app(LegalDocumentWorkflow::class);
        $draft = $workflow->createDraft([
            'document_type' => 'privacy_notice', 'locale' => 'hu', 'title' => 'Privacy',
            'body' => '<p>Notice</p>', 'acceptance_mode' => 'disabled', 'effective_at' => '2026-10-16T00:00',
        ], $owner);
        $this->get('/privacy')->assertNotFound();
        $this->assertNull(app(LegalDocumentCatalog::class)->current('privacy_notice', 'hu'));
        $workflow->approve($draft, 'REVIEW-1', $owner);
        $workflow->publish($draft->refresh(), $owner);
        $this->assertNull(app(LegalDocumentCatalog::class)->current('privacy_notice', 'hu', Carbon::parse('2026-10-15T23:59:59Z')));
        $this->assertNotNull(app(LegalDocumentCatalog::class)->current('privacy_notice', 'hu', Carbon::parse('2026-10-16T00:00:00Z')));
    }

    public function test_dpa_cannot_be_approved_or_resolved_publicly_without_explicit_public_audience(): void
    {
        $owner = $this->owner();
        $workflow = app(LegalDocumentWorkflow::class);
        $draft = $workflow->createDraft([
            'document_type' => 'dpa', 'locale' => 'en', 'title' => 'DPA',
            'body' => '<p>Reviewed DPA</p>', 'acceptance_mode' => 'disabled',
            'effective_at' => now('UTC')->subMinute()->format('Y-m-d\\TH:i'),
        ], $owner);

        $this->assertSame([], $draft->audiences);
        try {
            $workflow->approve($draft, 'DPA-REVIEW-1', $owner);
            $this->fail('A DPA with an unspecified audience must not be approved.');
        } catch (ValidationException) {
            $this->assertNull(app(LegalDocumentCatalog::class)->current('dpa', 'en'));
        }

        $draft = $workflow->updateDraft($draft, [
            'title' => 'DPA', 'body' => '<p>Reviewed DPA</p>', 'acceptance_mode' => 'disabled',
            'audiences' => ['processor'], 'effective_at' => now('UTC')->subMinute()->format('Y-m-d\\TH:i'),
        ], $owner);
        try {
            $workflow->approve($draft, 'DPA-REVIEW-2', $owner);
            $this->fail('A processor-only DPA cannot be published through the public DPA route.');
        } catch (ValidationException) {
            $this->assertNull(app(LegalDocumentCatalog::class)->current('dpa', 'en'));
        }

        $draft = $workflow->updateDraft($draft, [
            'title' => 'DPA', 'body' => '<p>Reviewed DPA</p>', 'acceptance_mode' => 'disabled',
            'audiences' => ['all'], 'effective_at' => now('UTC')->subMinute()->format('Y-m-d\\TH:i'),
        ], $owner);
        $workflow->approve($draft, 'DPA-REVIEW-3', $owner);
        $published = $workflow->publish($draft->refresh(), $owner);

        $this->assertSame($published->id, app(LegalDocumentCatalog::class)->current('dpa', 'en')?->id);
        $this->get('/dpa')->assertOk();
    }

    public function test_owner_console_lists_documents_and_creates_an_empty_draft(): void
    {
        $owner = $this->asOwner();
        $this->actingAs($owner, 'owner')
            ->get(route('owner.legal-documents.index'))
            ->assertOk()
            ->assertSee('Legal documents');

        $this->actingAs($owner, 'owner')
            ->post(route('owner.legal-documents.store'), [
                'document_type' => 'terms_of_service',
                'locale' => 'en',
                'title' => 'Unpublished terms draft',
                'acceptance_mode' => 'disabled',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('legal_document_versions', [
            'document_type' => 'terms_of_service',
            'locale' => 'en',
            'title' => 'Unpublished terms draft',
            'body' => '',
            'acceptance_mode' => 'disabled',
            'status' => 'draft',
        ]);
    }

    public function test_acceptance_is_version_bound_idempotent_append_only_and_excludes_ip_metadata(): void
    {
        $owner = $this->owner();
        $customer = Customer::factory()->withUser()->create();
        $workflow = app(LegalDocumentWorkflow::class);
        $draft = $workflow->createDraft([
            'document_type' => 'terms_of_service', 'locale' => 'en', 'title' => 'Terms',
            'body' => '<p>Terms</p>', 'acceptance_mode' => 'acceptance_required_before_account_use',
            'effective_at' => now('UTC')->subMinute()->format('Y-m-d\TH:i'),
        ], $owner);
        $workflow->approve($draft, 'REVIEW-2', $owner);
        $document = $workflow->publish($draft->refresh(), $owner);

        $first = $workflow->accept($document, $customer->user, $customer, 'customer_legal_area');
        $again = $workflow->accept($document, $customer->user, $customer, 'customer_legal_area');
        $this->assertSame($first->id, $again->id);
        $this->assertSame($document->content_sha256, $first->content_sha256);
        $this->assertSame(1, LegalDocumentAcceptance::query()->count());
        $this->assertArrayNotHasKey('ip_address', $first->getAttributes());
        $this->assertArrayNotHasKey('user_agent', $first->getAttributes());

        $this->expectException(LogicException::class);
        $first->delete();
    }

    public function test_customer_export_contains_only_their_minimized_acceptance_evidence(): void
    {
        $owner = $this->owner();
        $customer = Customer::factory()->withUser()->create();
        $workflow = app(LegalDocumentWorkflow::class);
        $draft = $workflow->createDraft([
            'document_type' => 'privacy_notice', 'locale' => 'en', 'title' => 'Privacy',
            'body' => '<p>Notice</p>', 'acceptance_mode' => 'acceptance_required_before_account_use',
            'effective_at' => now('UTC')->subMinute()->format('Y-m-d\\TH:i'),
        ], $owner);
        $workflow->approve($draft, 'REVIEW-EXPORT', $owner);
        $document = $workflow->publish($draft->refresh(), $owner);
        $workflow->accept($document, $customer->user, $customer, 'customer_legal_area');
        $privacyRequest = app(PrivacyRequestService::class)->create(
            $customer,
            PrivacyRequestType::Access,
            PrivacyRequestSource::CustomerPortal,
            'legal-export-request-0001',
            $customer->user,
        );

        $data = app(CustomerPrivacyExportData::class)->build($customer, $privacyRequest, now('UTC'));
        $acceptance = $data['sections']['legal_acceptances'][0];

        $this->assertSame('privacy_notice', $acceptance['document_type']);
        $this->assertSame($document->uuid, $acceptance['document_reference']);
        $this->assertSame($document->content_sha256, $acceptance['content_sha256']);
        $this->assertSame('customer_legal_area', $acceptance['source']);
        $this->assertArrayNotHasKey('approval_reference', $acceptance);
        $this->assertArrayNotHasKey('ip_address', $acceptance);
    }

    public function test_checkout_only_acceptance_cannot_be_falsely_recorded_from_account_area(): void
    {
        $owner = $this->owner();
        $customer = Customer::factory()->withUser()->create();
        $workflow = app(LegalDocumentWorkflow::class);
        $draft = $workflow->createDraft([
            'document_type' => 'terms_of_service', 'locale' => 'en', 'title' => 'Terms',
            'body' => '<p>Terms</p>', 'acceptance_mode' => 'acceptance_required_before_checkout',
            'effective_at' => now('UTC')->subMinute()->format('Y-m-d\TH:i'),
        ], $owner);
        $workflow->approve($draft, 'REVIEW-3', $owner);
        $document = $workflow->publish($draft->refresh(), $owner);

        $this->actingAs($customer->user);
        $this->post(route('account.legal-documents.accept', $document), [
            'confirm' => '1', 'content_sha256' => $document->content_sha256,
        ])->assertSessionHasErrors();
        $this->assertDatabaseCount('legal_document_acceptances', 0);
    }

    public function test_customer_gate_is_inactive_until_an_explicit_current_version_requires_acceptance(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user)->get(route('account.dashboard'))->assertOk();

        $owner = $this->owner();
        $workflow = app(LegalDocumentWorkflow::class);
        $draft = $workflow->createDraft([
            'document_type' => 'terms_of_service', 'locale' => 'en', 'title' => 'Terms',
            'body' => '<p>Terms</p>', 'acceptance_mode' => 'acceptance_required_before_account_use',
            'effective_at' => now('UTC')->subMinute()->format('Y-m-d\TH:i'),
        ], $owner);
        $workflow->approve($draft, 'REVIEW-4', $owner);
        $workflow->publish($draft->refresh(), $owner);

        $this->get(route('account.dashboard'))->assertRedirect(route('account.legal-documents.index'));
        $this->get(route('account.legal-documents.index'))->assertOk();
    }

    public function test_owner_management_is_forbidden_for_customer_and_draft_never_appears_on_public_route(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user, 'web')->get(route('owner.legal-documents.index'))->assertRedirect();

        $owner = $this->asOwner();
        $draft = app(LegalDocumentWorkflow::class)->createDraft([
            'document_type' => 'cookie_notice', 'locale' => 'en', 'title' => 'Cookie',
            'body' => '<p>Draft only</p>', 'acceptance_mode' => 'disabled',
        ], $owner);
        $this->get(route('legal.cookies'))->assertNotFound();
        $this->assertDatabaseHas('legal_document_versions', ['id' => $draft->id, 'status' => 'draft']);
    }
}
