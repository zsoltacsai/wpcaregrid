<?php

namespace Tests\Feature\Privacy;

use App\Jobs\GeneratePrivacyExport;
use App\Models\Customer;
use App\Models\PrivacyExportArtifact;
use App\Models\PrivacyRequestEvent;
use App\Privacy\PrivacyExportService;
use App\Privacy\PrivacyExportStatus;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestStatus;
use App\Privacy\PrivacyRequestType;
use App\Privacy\PrivacyTombstoneJournal;
use App\Tax\CustomerBillingIdentityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;
use ZipArchive;

class PrivacyExportTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->app->instance(PrivacyTombstoneJournal::class, new class implements PrivacyTombstoneJournal
        {
            public array $events = [];

            public function readiness(): array
            {
                return ['state' => 'ready', 'reason' => 'test_journal', 'watermark' => $this->watermark(), 'replay_verified' => false, 'verified_events' => count($this->events)];
            }

            public function append(array $event): array
            {
                $event['sequence'] = (string) Str::ulid();
                $this->events[] = $event;

                return $event;
            }

            public function watermark(): ?string
            {
                return $this->events === [] ? null : end($this->events)['sequence'];
            }

            public function eventsAfter(?string $snapshotWatermark): iterable
            {
                return array_values(array_filter($this->events, static fn (array $event): bool => $snapshotWatermark === null || strcmp($event['sequence'], $snapshotWatermark) > 0));
            }
        });
    }

    public function test_customer_export_is_encrypted_partial_reusable_and_audited_per_download(): void
    {
        $customer = Customer::factory()->withUser()->create([
            'tax_identifier_encrypted' => 'HU12345678',
        ]);
        $service = app(PrivacyExportService::class);

        $artifact = $service->request($customer, $customer->user, 'password-123456');
        $duplicate = $service->request($customer, $customer->user, 'password-123456');
        $this->assertSame($artifact->uuid, $duplicate->uuid);
        $this->assertSame(1, PrivacyExportArtifact::query()->count());
        $this->assertSame(PrivacyExportStatus::Queued, $artifact->status);

        $service->generate($artifact->uuid);
        $artifact->refresh();
        $this->assertSame(PrivacyExportStatus::Available, $artifact->status);
        $this->assertSame('partial', $artifact->coverage_state);
        $this->assertSame(0, $artifact->download_count);

        $stored = Storage::disk('local')->get($artifact->expectedStoragePath());
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('HU12345678', $stored);
        $this->assertSame($artifact->sha256, hash('sha256', $stored));
        $archive = Crypt::decryptString($stored);
        $this->assertStringStartsWith("PK\x03\x04", $archive);

        $path = tempnam(sys_get_temp_dir(), 'wpcg-export-test-');
        try {
            file_put_contents($path, $archive);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $this->assertNotFalse($zip->locateName('manifest.json'));
            $this->assertNotFalse($zip->locateName('profile.json'));
            $this->assertFalse($zip->locateName('backup_metadata.json'));
            $this->assertStringNotContainsString('tax_identifier', (string) $zip->getFromName('profile.json'));
            $this->assertStringNotContainsString('HU12345678', (string) $zip->getFromName('profile.json'));
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $profileBytes = (string) $zip->getFromName('profile.json');
            $this->assertSame($manifest['section_sha256']['profile.json'], hash('sha256', $profileBytes));
            $zip->close();
        } finally {
            @unlink($path);
        }

        $first = $service->download($artifact, $customer, $customer->user);
        $second = $service->download($artifact->fresh(), $customer, $customer->user);
        $this->assertSame($first, $second);
        $this->assertSame(2, $artifact->fresh()->download_count);
        $this->assertSame(2, PrivacyRequestEvent::query()->where('event_type', 'export_downloaded')->count());
        $this->assertNotNull($service->downloadUrl($artifact->fresh()));
        try {
            $service->request($customer, $customer->user, 'password-123456');
            $this->fail('A second active temporary export was allowed.');
        } catch (ValidationException) {
            $this->assertSame(1, PrivacyExportArtifact::query()->count());
        }
    }

    public function test_customer_cannot_generate_or_download_another_customers_export(): void
    {
        $owner = Customer::factory()->withUser()->create();
        $other = Customer::factory()->withUser()->create();
        $service = app(PrivacyExportService::class);
        $artifact = $service->request($owner, $owner->user, 'password-123456');
        $service->generate($artifact->uuid);

        $this->expectException(AuthorizationException::class);
        $service->download($artifact->fresh(), $other, $other->user);
    }

    public function test_foreign_signed_export_download_and_link_renewal_have_no_side_effects(): void
    {
        $owner = Customer::factory()->withUser()->create();
        $foreign = Customer::factory()->withUser()->create();
        $service = app(PrivacyExportService::class);
        $artifact = $service->request($owner, $owner->user, 'password-123456');
        $service->generate($artifact->uuid);
        $artifact->refresh();

        $path = $artifact->expectedStoragePath();
        $stored = Storage::disk('local')->get($path);
        $downloadUrl = $service->downloadUrl($artifact);
        $queuedExports = Queue::pushed(GeneratePrivacyExport::class)->count();
        $this->assertIsString($stored);
        $this->assertNotNull($downloadUrl);
        $before = [
            'downloads' => $artifact->download_count,
            'events' => PrivacyRequestEvent::count(),
            'artifacts' => PrivacyExportArtifact::count(),
        ];

        $this->actingAs($foreign->user)->get($downloadUrl)->assertNotFound();
        $this->actingAs($foreign->user)->post(route('account.privacy.exports.link', $artifact), [
            'current_password' => 'password-123456',
        ])->assertNotFound();

        $this->assertSame($before['downloads'], $artifact->fresh()->download_count);
        $this->assertSame($before['events'], PrivacyRequestEvent::count());
        $this->assertSame($before['artifacts'], PrivacyExportArtifact::count());
        $this->assertSame($stored, Storage::disk('local')->get($path));
        $this->assertSame($queuedExports, Queue::pushed(GeneratePrivacyExport::class)->count());
    }

    public function test_export_request_requires_recent_password_confirmation(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user)
            ->post(route('account.privacy.export.request'), ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');

        $this->assertDatabaseCount('privacy_requests', 0);
        $this->assertDatabaseCount('privacy_export_artifacts', 0);
    }

    public function test_integrity_failure_and_expired_link_never_return_package_bytes(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $service = app(PrivacyExportService::class);
        $artifact = $service->request($customer, $customer->user, 'password-123456');
        $service->generate($artifact->uuid);
        $artifact->refresh();

        Storage::disk('local')->put($artifact->expectedStoragePath(), 'corrupt');
        try {
            $service->download($artifact, $customer, $customer->user);
            $this->fail('A checksum mismatch returned an export.');
        } catch (\RuntimeException) {
            $this->assertSame(0, $artifact->fresh()->download_count);
        }

        $artifact->forceFill(['download_expires_at' => now('UTC')->subSecond()])->save();
        $this->assertNull($service->downloadUrl($artifact->fresh()));
    }

    public function test_expiry_cleanup_only_removes_the_encrypted_export_artifact(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $service = app(PrivacyExportService::class);
        $artifact = $service->request($customer, $customer->user, 'password-123456');
        $service->generate($artifact->uuid);
        $artifact->refresh()->forceFill(['expires_at' => now('UTC')->subSecond()])->save();
        Storage::disk('local')->put($artifact->expectedStoragePath(), 'encrypted-placeholder');

        $this->assertSame(1, $service->expireDue());
        $this->assertSame(PrivacyExportStatus::Expired, $artifact->fresh()->status);
        $this->assertFalse(Storage::disk('local')->exists($artifact->expectedStoragePath()));
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'email' => $customer->email]);
        $this->assertSame(1, PrivacyExportArtifact::query()->count());
    }

    public function test_verified_rectification_uses_canonical_profile_and_preserves_historical_identity_snapshot(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $identity = app(CustomerBillingIdentityService::class)->snapshotProfile($customer, [
            'customer_type' => 'individual',
            'legal_name' => $customer->fullName(),
            'country_code' => 'HU',
            'tax_id' => null,
            'billing_address' => $customer->billing_address,
        ]);
        $invoiceId = DB::table('billing_invoices')->insertGetId([
            'stripe_invoice_id' => 'in_privacy_wave2_immutability',
            'customer_id' => $customer->id,
            'billing_identity_id' => $identity->id,
            'amount_paid' => 8990,
            'currency' => 'HUF',
            'invoice_status' => 'paid',
            'stripe_created_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $case = app(PrivacyRequestService::class)->createCustomerRequest(
            $customer,
            PrivacyRequestType::Rectification,
            'privacy-rectification-test-key-001',
            $customer->user,
            'password-123456',
        );

        $response = $this->actingAs($customer->user)->put(route('account.profile.update'), [
            'privacy_request' => $case->uuid,
            'first_name' => 'Corrected',
            'last_name' => $customer->last_name,
            'customer_type' => 'individual',
            'country_code' => 'HU',
            'tax_id' => '',
            'address_line1' => 'Updated Street 5',
            'address_line2' => '',
            'postal_code' => '1011',
            'city' => 'Budapest',
        ]);

        $response->assertRedirect(route('account.profile'));
        $this->assertSame('Corrected', $customer->fresh()->first_name);
        $this->assertSame(PrivacyRequestStatus::Completed, $case->fresh()->status);
        $this->assertSame(2, $customer->billingIdentities()->count());
        $this->assertSame($customer->fullName(), $identity->fresh()->legal_name);
        $this->assertDatabaseHas('billing_invoices', [
            'id' => $invoiceId,
            'billing_identity_id' => $identity->id,
            'amount_paid' => 8990,
            'invoice_status' => 'paid',
        ]);
        $event = $case->events()->where('event_type', 'rectification_completed')->firstOrFail();
        $this->assertSame(['first_name', 'billing_address', 'locale'], $event->metadata['field_codes']);
        $this->assertStringNotContainsString('Corrected', json_encode($event->metadata));
    }

    public function test_owner_export_revocation_is_recorded_in_independent_journal(): void
    {
        $owner = $this->asOwner();
        $customer = Customer::factory()->withUser()->create();
        $service = app(PrivacyExportService::class);
        $artifact = $service->request($customer, $customer->user, 'password-123456');
        $service->generate($artifact->uuid);
        $artifact->refresh();

        $service->revoke($artifact, $owner);

        $this->assertSame(PrivacyExportStatus::Revoked, $artifact->fresh()->status);
        $journal = app(PrivacyTombstoneJournal::class);
        $this->assertSame('privacy_export_revoked', $journal->events[0]['action']);
        $this->assertSame($artifact->uuid, $journal->events[0]['artifact_uuid']);
        $this->assertSame($customer->uuid, $journal->events[0]['subject_uuid']);
    }
}
