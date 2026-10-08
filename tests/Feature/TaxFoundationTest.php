<?php

namespace Tests\Feature;

use App\Checkout\CheckoutStatus;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\VatValidation;
use App\Tax\AamStatus;
use App\Tax\CustomerBillingIdentityService;
use App\Tax\SellerTaxProfileRegistry;
use App\Tax\SellerTaxProfileStatus;
use App\Tax\SellerTaxRegistrationType;
use App\Tax\TaxVerificationWorkflow;
use App\Tax\Vat\VatNumberNormalization;
use App\Tax\Vat\VatNumberProvider;
use App\Tax\Vat\VatNumberValidator;
use App\Tax\Vat\VatProviderResult;
use App\Tax\Vat\VatProviderUnavailable;
use App\Tax\Vat\VatValidationStatus;
use App\Tax\VerificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class TaxFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_identity_versions_are_monotonic_and_immutable(): void
    {
        $customer = Customer::factory()->create();
        $service = app(CustomerBillingIdentityService::class);

        $first = $service->snapshotProfile($customer, $this->identity());
        $second = $service->snapshotProfile($customer, $this->identity(['country_code' => 'DE']));

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame('HU', $first->country_code);
        $this->assertSame('DE', $second->country_code);
        $this->assertSame(2, $customer->fresh()->billingIdentities()->count());
        $this->expectException(\LogicException::class);
        $first->legal_name = 'mutated';
        $first->save();
    }

    public function test_billing_identity_snapshot_rolls_back_with_its_caller_transaction(): void
    {
        $customer = Customer::factory()->create();

        try {
            DB::transaction(function () use ($customer) {
                app(CustomerBillingIdentityService::class)->snapshotProfile($customer, $this->identity());
                throw new \RuntimeException('rollback identity snapshot');
            });
            $this->fail('The outer transaction should abort the billing identity snapshot.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback identity snapshot', $exception->getMessage());
        }

        $this->assertSame(0, $customer->billingIdentities()->count());
    }

    public function test_new_identity_and_profile_writes_encrypt_tax_identifiers_without_serializing_them(): void
    {
        $customer = Customer::factory()->create();
        $identity = app(CustomerBillingIdentityService::class)->snapshotProfile($customer, $this->identity([
            'customer_type' => 'company',
            'company_name' => 'Example GmbH',
            'legal_name' => 'Example GmbH',
            'tax_id' => 'HU12345678',
        ]));

        $stored = DB::table('customer_billing_identities')->where('id', $identity->id)->value('tax_identifier');
        $this->assertNotSame('HU12345678', $stored);
        $this->assertSame('HU12345678', $identity->tax_identifier);
        $this->assertStringNotContainsString('HU12345678', json_encode($identity, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Example GmbH', json_encode($identity, JSON_THROW_ON_ERROR));
        $this->assertNotNull($identity->tax_identifier_hash);
        $this->assertSame(VerificationStatus::PendingVerification, $identity->verification_status);
    }

    public function test_existing_customer_without_a_billing_identity_remains_compatible_and_unlinked(): void
    {
        $customer = Customer::factory()->create(['tax_id' => 'legacy-test-id']);

        $this->assertSame('legacy-test-id', $customer->taxIdentifier());
        $this->assertSame(0, $customer->billingIdentities()->count());
        $this->assertTrue(Schema::hasColumn('checkout_intents', 'billing_identity_id'));
        $this->assertTrue(Schema::hasColumn('subscriptions', 'billing_identity_id'));
        $this->assertTrue(Schema::hasColumn('billing_invoices', 'billing_identity_id'));
    }

    public function test_checkout_intent_snapshot_uses_its_own_customer_type_for_review_status(): void
    {
        $customer = Customer::factory()->create(['customer_type' => 'company']);
        $intent = new CheckoutIntent;
        $intent->forceFill([
            'customer_id' => $customer->id,
            'status' => CheckoutStatus::CheckoutCreated,
            'email' => $customer->email,
            'first_name' => 'Test',
            'last_name' => 'Company',
            'country' => 'HU',
            'customer_type' => 'company',
            'company_name' => 'Test Company Kft',
            'billing_address' => ['line1' => 'Example utca 1', 'postal_code' => '1051', 'city' => 'Budapest'],
            'product_type' => 'wordpress',
            'plan' => 'business',
            'interval' => 'annual',
            'market' => 'HU',
            'currency' => 'HUF',
            'amount' => 890000,
            'tax_treatment' => 'unknown',
            'stripe_price_id' => 'price_test_foundation',
        ])->save();

        $identity = app(CustomerBillingIdentityService::class)->snapshotFromCheckoutIntent($customer, $intent);

        $this->assertSame(VerificationStatus::PendingVerification, $identity->verification_status);
        $this->assertSame($identity->id, $intent->fresh()->billing_identity_id);
    }

    public function test_customer_billing_profile_changes_create_a_new_identity_snapshot(): void
    {
        $customer = Customer::factory()->withUser()->create(['tax_id' => 'legacy-customer-tax-id']);
        $customer->user->markEmailAsVerified();

        $this->actingAs($customer->user)->put(route('account.profile.update'), [
            'first_name' => 'Updated',
            'last_name' => 'Customer',
            'customer_type' => 'company',
            'company_name' => 'Updated Company Kft',
            'country_code' => 'HU',
            'tax_id' => 'HU12345678',
            'address_line1' => 'Main Street 2',
            'postal_code' => '1051',
            'city' => 'Budapest',
        ])->assertRedirect(route('account.profile'));

        $identity = $customer->fresh()->billingIdentities()->sole();
        $this->assertSame('Updated Company Kft', $identity->legal_name);
        $this->assertSame('HU12345678', $identity->tax_identifier);
        $this->assertSame('HU12345678', $customer->fresh()->taxIdentifier());
        $this->assertNull($customer->fresh()->tax_id);
    }

    public function test_seller_tax_profile_is_unverified_unknown_by_default_and_effective_periods_do_not_overlap(): void
    {
        $registry = app(SellerTaxProfileRegistry::class);
        $first = $registry->publish(['effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);

        $this->assertSame(1, $first->version);
        $this->assertSame(AamStatus::Unknown, $first->aam_status);
        $this->assertSame(SellerTaxProfileStatus::Unverified, $first->verification_status);
        $this->assertNull($first->seller_address);
        $this->assertNull($first->legal_name);
        $this->assertSame(0, $first->registrations()->count());

        try {
            $registry->publish(['effective_from' => '2026-06-01']);
            $this->fail('An overlapping seller profile period should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_from', $exception->errors());
        }

        $second = $registry->publish(['effective_from' => '2027-01-01']);
        $this->assertSame(2, $second->version);
    }

    public function test_seller_profiles_require_evidence_before_claiming_verified_aam_facts(): void
    {
        try {
            app(SellerTaxProfileRegistry::class)->publish([
                'effective_from' => '2026-01-01',
                'aam_status' => AamStatus::Confirmed,
            ]);
            $this->fail('A confirmed seller tax fact needs an evidence reference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_reference', $exception->errors());
        }

        $profile = app(SellerTaxProfileRegistry::class)->publish([
            'effective_from' => '2026-01-01',
            'aam_status' => AamStatus::Confirmed,
            'verification_status' => SellerTaxProfileStatus::Verified,
            'evidence_reference' => 'accountant-letter-test-001',
            'seller_address' => ['country' => 'HU'],
        ]);

        $this->assertSame(AamStatus::Confirmed, $profile->aam_status);
        $this->assertSame(['country' => 'HU'], $profile->seller_address);
    }

    public function test_seller_tax_registration_rejects_invalid_ranges_and_unverified_values_stay_unverified(): void
    {
        $registry = app(SellerTaxProfileRegistry::class);
        $profile = $registry->publish(['effective_from' => '2026-01-01']);

        try {
            $registry->addRegistration($profile, [
                'registration_type' => SellerTaxRegistrationType::EuVat,
                'jurisdiction' => 'HU',
                'effective_from' => '2026-05-01',
                'effective_to' => '2026-04-30',
            ]);
            $this->fail('A registration end date cannot precede its start date.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_to', $exception->errors());
        }

        $registration = $registry->addRegistration($profile, [
            'registration_type' => SellerTaxRegistrationType::EuVat,
            'jurisdiction' => 'HU',
            'effective_from' => '2026-05-01',
        ]);
        $this->assertSame(SellerTaxProfileStatus::Unverified, $registration->verification_status);
    }

    public function test_seller_registration_identifiers_are_encrypted_and_unverified(): void
    {
        $profile = app(SellerTaxProfileRegistry::class)->publish(['effective_from' => '2026-01-01']);
        $registration = app(SellerTaxProfileRegistry::class)->addRegistration($profile, [
            'registration_type' => SellerTaxRegistrationType::EuVat,
            'jurisdiction' => 'HU',
            'tax_identifier' => 'HU12345678',
        ]);

        $stored = DB::table('seller_tax_registrations')->where('id', $registration->id)->value('tax_identifier');
        $this->assertNotSame('HU12345678', $stored);
        $this->assertSame('HU12345678', $registration->tax_identifier);
        $this->assertSame(SellerTaxProfileStatus::Unverified, $registration->verification_status);
    }

    public function test_vies_country_normalization_is_input_validation_not_validity(): void
    {
        $normalizer = app(VatNumberNormalization::class);
        $hu = $normalizer->normalize('hu', 'HU 1234-5678');
        $greek = $normalizer->normalize('GR', 'EL123456789');

        $this->assertSame('HU', $hu->countryCode);
        $this->assertSame('12345678', $hu->number);
        $this->assertSame('EL', $greek->countryCode);
        $this->assertSame('123456789', $greek->number);
        $this->assertNotSame('HU12345678', $hu->fingerprint);
    }

    public function test_malformed_vat_identifier_is_recorded_without_calling_vies(): void
    {
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldNotReceive('validate');
        $this->app->instance(VatNumberProvider::class, $provider);

        $result = app(VatNumberValidator::class)->validate('HU', 'HU!!');

        $this->assertSame(VatValidationStatus::Malformed, $result->status);
        $evidence = VatValidation::query()->sole();
        $this->assertSame('local_validation', $evidence->provider);
        $this->assertNull($evidence->vat_identifier);
        $this->assertNull($evidence->expires_at);
    }

    public function test_valid_and_invalid_vies_results_are_separate_auditable_evidence(): void
    {
        Cache::flush();
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldReceive('validate')->once()->with('HU', '12345678')->andReturn(new VatProviderResult(
            valid: true,
            requestDate: '2026-10-08+02:00',
            referenceId: 'vies-test-reference',
            name: 'Example Kft',
            address: 'Example utca 1, Budapest',
        ));
        $this->app->instance(VatNumberProvider::class, $provider);

        $result = app(VatNumberValidator::class)->validate('HU', 'HU12345678');

        $this->assertSame(VatValidationStatus::Valid, $result->status);
        $this->assertSame('vies-test-reference', $result->providerReference);
        $this->assertSame(VatValidationStatus::Valid, VatValidation::query()->sole()->status);
        $this->assertNotNull(VatValidation::query()->sole()->provider_name);
        $fingerprint = app(VatNumberNormalization::class)->normalize('HU', 'HU12345678')->fingerprint;
        $cacheValues = Cache::get('vies:result:'.$fingerprint);
        $this->assertArrayNotHasKey('name', $cacheValues);
        $this->assertArrayNotHasKey('address', $cacheValues);
    }

    public function test_provider_outage_never_becomes_invalid_or_logs_identifier(): void
    {
        Cache::flush();
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldReceive('validate')->once()->andThrow(new VatProviderUnavailable('secret-test-value'));
        $this->app->instance(VatNumberProvider::class, $provider);
        $logger = Log::spy();

        $result = app(VatNumberValidator::class)->validate('HU', 'HU12345678');

        $this->assertSame(VatValidationStatus::Unavailable, $result->status);
        $this->assertSame(VatValidationStatus::Unavailable, VatValidation::query()->sole()->status);
        $this->assertSame(1, VatValidation::query()->count());
        $logger->shouldNotHaveReceived('warning');
        $logger->shouldNotHaveReceived('error');
    }

    public function test_vies_result_cache_expires_and_rate_limit_is_a_pending_state(): void
    {
        Cache::flush();
        config(['tax.vies.cache_seconds' => 60, 'tax.vies.rate_limit_per_minute' => 1]);
        $provider = Mockery::mock(VatNumberProvider::class);
        $provider->shouldReceive('validate')->twice()->andReturn(new VatProviderResult(true), new VatProviderResult(false));
        $this->app->instance(VatNumberProvider::class, $provider);

        $validator = app(VatNumberValidator::class);
        $first = $validator->validate('HU', '12345678');
        $second = $validator->validate('HU', '12345678');

        $this->assertSame(VatValidationStatus::Valid, $first->status);
        $this->assertSame(VatValidationStatus::PendingVerification, $second->status);
        $this->travel(61)->seconds();
        config(['tax.vies.rate_limit_per_minute' => 5]);
        $third = $validator->validate('HU', '12345678');
        $this->assertSame(VatValidationStatus::Invalid, $third->status);
    }

    public function test_pending_review_status_is_not_a_tax_treatment(): void
    {
        $identity = app(CustomerBillingIdentityService::class)->snapshotProfile(Customer::factory()->create(), $this->identity());
        $verification = app(TaxVerificationWorkflow::class)->open($identity, VerificationStatus::PendingVerification, 'vies_unavailable');

        $this->assertSame(VerificationStatus::PendingVerification, $verification->status);
        $this->assertFalse(Schema::hasTable('tax_decisions'));
    }

    public function test_tax_review_cases_cannot_start_as_resolved(): void
    {
        $identity = app(CustomerBillingIdentityService::class)->snapshotProfile(Customer::factory()->create(), $this->identity());

        $this->expectException(ValidationException::class);
        app(TaxVerificationWorkflow::class)->open($identity, VerificationStatus::Verified);
    }

    public function test_tax_review_resolution_is_auditable_and_terminal(): void
    {
        $identity = app(CustomerBillingIdentityService::class)->snapshotProfile(Customer::factory()->create(), $this->identity());
        $workflow = app(TaxVerificationWorkflow::class);
        $case = $workflow->open($identity, VerificationStatus::ManualReview, 'vies_unavailable');
        $resolved = $workflow->resolve($case, VerificationStatus::Verified, null, 'reviewed_evidence');

        $this->assertSame(VerificationStatus::Verified, $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->expectException(ValidationException::class);
        $workflow->resolve($resolved, VerificationStatus::Rejected, null, 'later_change');
    }

    /** @return array<string, mixed> */
    private function identity(array $overrides = []): array
    {
        return array_replace([
            'customer_type' => 'individual',
            'legal_name' => 'Test Customer',
            'company_name' => null,
            'country_code' => 'HU',
            'tax_id' => null,
            'billing_address' => ['line1' => 'Example utca 1', 'postal_code' => '1051', 'city' => 'Budapest'],
        ], $overrides);
    }
}
