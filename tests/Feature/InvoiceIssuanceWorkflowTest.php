<?php

namespace Tests\Feature;

use App\Billing\SzamlazzBillingSettings;
use App\Invoicing\ApprovedTaxDecision;
use App\Invoicing\InvoiceIntentStatus;
use App\Invoicing\InvoiceIssuanceWorkflow;
use App\Invoicing\NormalizedInvoiceRequest;
use App\Invoicing\Providers\InvoiceSubmissionProvider;
use App\Invoicing\Providers\ProviderLookupResult;
use App\Invoicing\Providers\ProviderSubmissionResult;
use App\Models\Customer;
use App\Models\CustomerBillingIdentity;
use App\Models\InvoiceIntent;
use App\Models\InvoiceIssuanceAttempt;
use App\Tax\SellerTaxProfileRegistry;
use App\Tax\SellerTaxProfileStatus;
use App\Tax\VerificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InvoiceIssuanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_gates_default_closed_without_creating_an_attempt_or_calling_provider(): void
    {
        $intent = $this->readyIntent();
        config(['szamlazz.provider_enabled' => false, 'szamlazz.dry_run' => true, 'szamlazz.issuance_enabled' => false]);
        $provider = $this->mock(InvoiceSubmissionProvider::class);
        $provider->shouldNotReceive('submit');

        $result = app(InvoiceIssuanceWorkflow::class)->submit($intent->id, $this->request());

        $this->assertSame('issuance_gates_closed', $result);
        $this->assertSame(0, InvoiceIssuanceAttempt::query()->count());
    }

    public function test_ambiguous_provider_result_is_never_resubmitted_and_read_only_lookup_reconciles(): void
    {
        $intent = $this->readyIntent();
        config([
            'szamlazz.provider_enabled' => true,
            'szamlazz.dry_run' => false,
            'szamlazz.issuance_enabled' => true,
            'szamlazz.permitted_environments' => ['testing'],
            'szamlazz.agent_api_key' => 'synthetic-test-key',
        ]);
        $provider = $this->mock(InvoiceSubmissionProvider::class);
        $provider->shouldReceive('submit')->once()->andReturn(new ProviderSubmissionResult('ambiguous', errorCategory: 'provider_transport_ambiguous'));
        $provider->shouldReceive('lookupByExternalReference')->once()->with('wpcg-invoice-'.$intent->id)
            ->andReturn(new ProviderLookupResult('found', 'WPCG-2026-1', ['provider_invoice_id' => 'WPCG-2026-1']));

        $workflow = app(InvoiceIssuanceWorkflow::class);
        $first = $workflow->submit($intent->id, $this->request());
        $second = $workflow->submit($intent->id, $this->request());
        $this->assertInstanceOf(InvoiceIssuanceAttempt::class, $first);
        $this->assertInstanceOf(InvoiceIssuanceAttempt::class, $second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('reconciliation_required', $first->fresh()->status);
        $this->assertSame(1, InvoiceIssuanceAttempt::query()->count());
        $this->assertSame(InvoiceIntentStatus::ReconciliationRequired, $intent->fresh()->status);

        $reconciled = $workflow->reconcile($first->id);
        $this->assertSame('issued', $reconciled->fresh()->status);
        $this->assertSame('WPCG-2026-1', $intent->fresh()->provider_invoice_id);
        $this->assertSame(InvoiceIntentStatus::Issued, $intent->fresh()->status);
    }

    public function test_unapproved_tax_proposal_cannot_pass_the_issuance_gate_even_if_other_switches_are_enabled(): void
    {
        $intent = $this->readyIntent();
        $snapshot = $intent->commercial_snapshot;
        unset($snapshot['approved_tax_decision']);
        $intent->forceFill(['commercial_snapshot' => $snapshot])->save();
        config([
            'szamlazz.provider_enabled' => true, 'szamlazz.dry_run' => false, 'szamlazz.issuance_enabled' => true,
            'szamlazz.permitted_environments' => ['testing'], 'szamlazz.agent_api_key' => 'synthetic-test-key',
        ]);
        $provider = $this->mock(InvoiceSubmissionProvider::class);
        $provider->shouldNotReceive('submit');

        $result = app(InvoiceIssuanceWorkflow::class)->submit($intent->id, $this->request());

        $this->assertSame('approved_tax_decision_missing', $result);
        $this->assertSame(0, InvoiceIssuanceAttempt::query()->count());
    }

    private function readyIntent(): InvoiceIntent
    {
        $customer = Customer::factory()->create();
        $identity = new CustomerBillingIdentity;
        $identity->forceFill([
            'customer_id' => $customer->id, 'version' => 1, 'source' => 'profile', 'customer_type' => 'company',
            'legal_name' => 'Synthetic Buyer Ltd', 'company_name' => 'Synthetic Buyer Ltd', 'country_code' => 'DE',
            'verification_status' => VerificationStatus::Verified,
        ])->save();
        $profile = app(SellerTaxProfileRegistry::class)->publish([
            'effective_from' => now()->toDateString(), 'effective_to' => null,
            'legal_name' => 'Synthetic Seller', 'establishment_country' => 'HU',
            'verification_status' => SellerTaxProfileStatus::Verified, 'evidence_reference' => 'test-fixture-only',
        ]);
        $settings = app(SzamlazzBillingSettings::class);
        $version = $settings->publish([
            'seller_profile_id' => $profile->id, 'invoice_language' => 'en', 'default_payment_method' => 'bank_transfer',
            'payment_deadline_policy' => 'same_day', 'fulfillment_date_policy' => 'payment_date', 'invoice_prefix' => 'WPCG',
            'notify_customer' => false, 'currency_policy' => 'block_foreign_without_fx', 'tax_configuration_mode' => 'manual_review',
            'default_tax_code' => null, 'effective_at' => now()->toDateString(),
        ], \App\Models\User::factory()->owner()->create());
        $intent = InvoiceIntent::query()->create([
            'customer_id' => $customer->id,
            'billing_identity_id' => $identity->id,
            'seller_tax_profile_id' => $profile->id,
            'billing_tax_configuration_version_id' => $version->id,
            'stripe_invoice_id' => 'in_issuance_fixture_'.bin2hex(random_bytes(5)),
            'provider' => 'szamlazz_hu', 'purpose' => 'subscription_invoice',
            'status' => InvoiceIntentStatus::DryRunValidated, 'amount' => 80100, 'currency' => 'EUR',
            'idempotency_key' => 'issuance_'.bin2hex(random_bytes(10)), 'dry_run_validated_at' => now(),
            'tax_decision_reference' => 'approved-test-decision', 'tax_decision_version' => 1,
            'commercial_snapshot' => [
                'billing_identity' => ['id' => $identity->id, 'country_code' => 'DE'],
                'approved_tax_decision' => [
                    'reference' => 'approved-test-decision', 'version' => 1,
                    'approval_authority' => 'accountant', 'approval_reference' => 'synthetic-fixture-approval',
                    'tax_code' => 'AAM',
                ],
            ],
        ]);

        return $intent;
    }

    private function request(): NormalizedInvoiceRequest
    {
        return new NormalizedInvoiceRequest(
            'ignored-request-reference', 'EUR', 80100, now()->toDateString(), now()->toDateString(), now()->toDateString(), now()->toDateString(), 'ignored',
            ['name' => 'Synthetic Seller', 'country' => 'HU', 'postal_code' => '1000', 'city' => 'Budapest', 'address' => 'Test street 1'],
            ['name' => 'Synthetic Buyer', 'country' => 'DE', 'postal_code' => '10115', 'city' => 'Berlin', 'address' => 'Example street 1'],
            'Maintenance subscription',
            new ApprovedTaxDecision('approved-test-decision', 1, ['net' => 80100, 'tax' => 0, 'gross' => 80100, 'tax_code' => 'AAM'], 'hu', '1.00000000', 'Test FX source'),
        );
    }
}
