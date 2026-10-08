<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\TaxVerification;
use App\Models\VatValidation;
use App\Tax\CustomerBillingIdentityService;
use App\Tax\TaxVerificationWorkflow;
use App\Tax\Vat\VatNumberProvider;
use App\Tax\Vat\VatNumberValidator;
use App\Tax\Vat\VatProviderResult;
use App\Tax\Vat\VatValidationStatus;
use App\Tax\VerificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

class ViesWorkflowTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_company_customer_can_verify_and_sees_status_without_a_tax_or_billing_change(): void
    {
        $customer = $this->companyCustomer();
        $other = $this->companyCustomer();
        $otherIdentity = app(CustomerBillingIdentityService::class)->snapshotProfile($other, $this->identity($other, 'ATU12345'));
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldReceive('validate')->once()->with('HU', '12345678')->andReturn(new VatProviderResult(true, '2026-10-08', 'ref-safe-1'));
        $this->app->instance(VatNumberProvider::class, $provider);

        $response = $this->actingAs($customer->user)->post(route('account.profile.vat-verification'), [
            'vat_country' => 'HU',
            'vat_number' => 'HU 12345678',
            'customer_id' => $other->id,
            'billing_identity_id' => $otherIdentity->id,
            'tax_status' => 'verified',
        ]);

        $response->assertRedirect(route('account.profile'))->assertSessionHas('vat_status', 'valid');
        $identity = $customer->fresh()->latestBillingIdentity();
        $evidence = VatValidation::query()->sole();
        $this->assertSame($customer->id, $identity->customer_id);
        $this->assertSame($identity->id, $evidence->customer_billing_identity_id);
        $this->assertSame(VatValidationStatus::Valid, $evidence->status);
        $this->assertSame(VerificationStatus::PendingVerification, $identity->verification_status);
        $this->assertSame(0, $customer->subscriptions()->count());
        $this->assertSame(0, $customer->billingInvoices()->count());
        $this->assertSame(0, $customer->checkoutIntents()->count());
        $this->assertSame('HU 12345678', $customer->fresh()->taxIdentifier());
        $this->assertNotSame('HU 12345678', DB::table('customers')->where('id', $customer->id)->value('tax_identifier_encrypted'));
        $this->assertSame(0, $otherIdentity->vatValidations()->count());

        $this->actingAs($customer->user)->get(route('account.profile'))->assertOk()
            ->assertSee(__('account.profile.vies.status.valid'))
            ->assertSee(__('account.profile.vies.last_successful_check'))
            ->assertDontSee('SOAP');
    }

    public function test_vies_invalid_result_is_displayed_as_invalid_and_never_as_manual_verification(): void
    {
        $customer = $this->companyCustomer();
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldReceive('validate')->once()->andReturn(new VatProviderResult(false));
        $this->app->instance(VatNumberProvider::class, $provider);

        $this->actingAs($customer->user)->post(route('account.profile.vat-verification'), ['vat_country' => 'HU', 'vat_number' => '12345678'])
            ->assertRedirect(route('account.profile'))->assertSessionHas('vat_status', 'invalid');

        $evidence = VatValidation::query()->sole();
        $this->assertSame(VatValidationStatus::Invalid, $evidence->status);
        $this->assertSame(0, TaxVerification::query()->count());
        $this->assertSame(VerificationStatus::PendingVerification, $customer->fresh()->latestBillingIdentity()->verification_status);
    }

    public function test_vies_outage_preserves_encrypted_identifier_opens_review_and_retry_can_succeed(): void
    {
        $customer = $this->companyCustomer();
        $provider = Mockery::mock(VatNumberProvider::class);
        $attempt = 0;
        $provider->shouldReceive('validate')->twice()->andReturnUsing(function () use (&$attempt) {
            if ($attempt++ === 0) {
                throw new \RuntimeException('raw SOAP response secret');
            }

            return new VatProviderResult(true, '2026-10-08', 'ref-safe-2');
        });
        $this->app->instance(VatNumberProvider::class, $provider);

        $payload = ['vat_country' => 'HU', 'vat_number' => 'HU87654321'];
        $this->actingAs($customer->user)->post(route('account.profile.vat-verification'), $payload)
            ->assertRedirect(route('account.profile'))->assertSessionHas('vat_status', 'unavailable');
        $firstIdentity = $customer->fresh()->latestBillingIdentity();
        $this->assertSame('HU87654321', $customer->fresh()->taxIdentifier());
        $this->assertNotSame('HU87654321', DB::table('customer_billing_identities')->where('id', $firstIdentity->id)->value('tax_identifier'));
        $this->assertSame(VatValidationStatus::Unavailable, VatValidation::query()->sole()->status);
        $this->assertSame(1, TaxVerification::query()->whereNull('resolved_at')->count());
        $this->actingAs($customer->user)->get(route('account.profile'))->assertOk()
            ->assertSee(__('account.profile.vies.status.unavailable'))
            ->assertDontSee('raw SOAP response secret');

        $this->actingAs($customer->user)->post(route('account.profile.vat-verification'), $payload)
            ->assertRedirect(route('account.profile'))->assertSessionHas('vat_status', 'valid');
        $this->assertSame(2, VatValidation::query()->count());
        $this->assertSame(1, TaxVerification::query()->whereNull('resolved_at')->count());
    }

    public function test_legacy_plaintext_identifier_is_readable_without_automatic_backfill(): void
    {
        $customer = $this->companyCustomer(['tax_id' => 'HU12345678']);
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldReceive('validate')->once()->andReturn(new VatProviderResult(true));
        $this->app->instance(VatNumberProvider::class, $provider);

        $this->actingAs($customer->user)->post(route('account.profile.vat-verification'), ['vat_country' => 'HU', 'vat_number' => 'HU12345678'])
            ->assertRedirect(route('account.profile'))->assertSessionHas('vat_status', 'valid');

        $this->assertSame('HU12345678', DB::table('customers')->where('id', $customer->id)->value('tax_id'));
        $this->assertSame('HU12345678', $customer->fresh()->taxIdentifier());
    }

    public function test_non_company_customer_cannot_start_vies_verification(): void
    {
        $customer = Customer::factory()->withUser()->create(['customer_type' => 'individual']);
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldNotReceive('validate');
        $this->app->instance(VatNumberProvider::class, $provider);

        $this->actingAs($customer->user)->post(route('account.profile.vat-verification'), ['vat_country' => 'HU', 'vat_number' => '12345678'])
            ->assertRedirect()->assertSessionHasErrors('vat_number');
        $this->assertSame(0, VatValidation::query()->count());
    }

    public function test_customer_cannot_access_owner_queue_and_guest_is_redirected(): void
    {
        $this->get(route('owner.vat-verifications.index'))->assertRedirect(route('owner.login'));
        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user)->get(route('owner.vat-verifications.index'))->assertRedirect(route('owner.login'));
    }

    public function test_owner_sees_masked_vies_history_and_can_recheck_without_exposing_identifier(): void
    {
        $customer = $this->companyCustomer(['tax_id' => 'HU12345678']);
        $identity = app(CustomerBillingIdentityService::class)->snapshotProfile($customer, $this->identity($customer, 'HU12345678'));
        $first = Mockery::mock(VatNumberProvider::class);
        $first->shouldReceive('validate')->once()->andReturn(new VatProviderResult(false));
        $this->app->instance(VatNumberProvider::class, $first);
        app(VatNumberValidator::class)->validate('HU', 'HU12345678', $identity);

        $this->asOwner();
        $this->get(route('owner.customers.show', $customer))->assertOk()
            ->assertSee('HU••••5678')
            ->assertDontSee('HU12345678')
            ->assertSee(__('owner.customers.vat.vies'))
            ->assertSee(__('owner.customers.vat.statuses.invalid'));

        $second = Mockery::mock(VatNumberProvider::class);
        $second->shouldReceive('validate')->once()->with('HU', '12345678')->andReturn(new VatProviderResult(true));
        $this->app->instance(VatNumberProvider::class, $second);
        $this->post(route('owner.customers.vat-verification.recheck', $customer))->assertRedirect();
        $this->assertSame(2, $identity->vatValidations()->count());
        $this->assertSame(VatValidationStatus::Valid, $identity->latestVatValidation()->status);
        $event = AdminAuditEvent::where('event', 'customer.vat_rechecked')->sole();
        $this->assertStringNotContainsString('HU12345678', json_encode($event->data));
    }

    public function test_manual_owner_review_is_separate_and_cannot_change_vies_evidence(): void
    {
        $customer = $this->companyCustomer(['tax_id' => 'HU12345678']);
        $identity = app(CustomerBillingIdentityService::class)->snapshotProfile($customer, $this->identity($customer, 'HU12345678'));
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldReceive('validate')->once()->andReturn(new VatProviderResult(false));
        $this->app->instance(VatNumberProvider::class, $provider);
        app(VatNumberValidator::class)->validate('HU', 'HU12345678', $identity);
        $case = app(TaxVerificationWorkflow::class)->open($identity, VerificationStatus::PendingVerification, 'vies_unavailable');

        $this->asOwner();
        $this->get(route('owner.vat-verifications.index'))->assertOk()
            ->assertSee(__('owner.customers.vat.queue'))
            ->assertSee('HU••••5678')
            ->assertDontSee('HU12345678');
        $this->post(route('owner.vat-verifications.resolve', $case), [
            'decision' => 'verified',
            'reason_code' => 'documents_reviewed',
            'vies_status' => 'valid',
        ])->assertRedirect(route('owner.vat-verifications.index'));

        $this->assertSame(VerificationStatus::Verified, $case->fresh()->status);
        $this->assertSame(VatValidationStatus::Invalid, $identity->latestVatValidation()->status);
        $this->assertSame(1, VatValidation::query()->count());
        $this->assertSame(1, AdminAuditEvent::where('event', 'customer.vat_review_resolved')->count());
    }

    public function test_vies_route_is_throttled_and_does_not_call_provider_after_limit(): void
    {
        $customer = $this->companyCustomer();
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldReceive('validate')->once()->andReturn(new VatProviderResult(true));
        $this->app->instance(VatNumberProvider::class, $provider);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($customer->user)->post(route('account.profile.vat-verification'), ['vat_country' => 'HU', 'vat_number' => '12345678'])->assertRedirect();
        }
        $this->actingAs($customer->user)->post(route('account.profile.vat-verification'), ['vat_country' => 'HU', 'vat_number' => '12345678'])->assertTooManyRequests();
        $this->assertSame(5, VatValidation::query()->where('status', 'valid')->count());
    }

    public function test_validator_rate_limit_is_recorded_as_local_and_never_calls_vies_after_limit(): void
    {
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldReceive('validate')->once()->andReturn(new VatProviderResult(true));
        $this->app->instance(VatNumberProvider::class, $provider);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            app(VatNumberValidator::class)->validate('HU', '12345678');
        }
        $result = app(VatNumberValidator::class)->validate('HU', '12345678');

        $this->assertSame(VatValidationStatus::PendingVerification, $result->status);
        $rateLimited = VatValidation::query()->where('message_code', 'rate_limited')->sole();
        $this->assertSame('local_validation', $rateLimited->provider);
    }

    private function companyCustomer(array $overrides = []): Customer
    {
        return Customer::factory()->withUser()->create(array_replace([
            'customer_type' => 'company',
            'company_name' => 'Example Kft',
            'country_code' => 'HU',
        ], $overrides));
    }

    private function identity(Customer $customer, ?string $taxId = null): array
    {
        return [
            'customer_type' => 'company',
            'company_name' => $customer->company_name,
            'legal_name' => $customer->company_name,
            'country_code' => $customer->country_code,
            'tax_id' => $taxId,
            'billing_address' => $customer->billing_address,
        ];
    }
}
