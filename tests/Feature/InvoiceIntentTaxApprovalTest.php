<?php

namespace Tests\Feature;

use App\Billing\SzamlazzBillingSettings;
use App\Invoicing\InvoiceIntentLocalDryRun;
use App\Invoicing\InvoiceIntentStatus;
use App\Models\AdminAuditEvent;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\CustomerBillingIdentity;
use App\Models\InvoiceIntent;
use App\Models\InvoiceIntentTaxReview;
use App\Models\SellerTaxProfile;
use App\Tax\VerificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class InvoiceIntentTaxApprovalTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_records_an_approved_decision_and_locally_validates_without_provider_or_invoice_submission(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $owner = $this->asOwner();
        $fixtures = $this->fixtures($owner);
        $intent = $fixtures['intent'];

        $this->post(route('owner.billing.invoice-intents.tax-review', $intent), [
            'provider_tax_code' => 'AAM',
            'proposed_treatment' => 'seller_exemption',
            'justification' => 'Accountant-approved M11 mapping for synthetic HU individual.',
            'basis_reference' => 'Owner recorded proposal basis.',
        ])->assertRedirect();
        $proposal = InvoiceIntentTaxReview::query()->where('status', 'proposed_pending_accountant')->sole();

        $this->post(route('owner.billing.invoice-intents.tax-review.approve', [$intent, $proposal]), [
            'approval_authority' => 'accountant',
            'approval_reference' => 'REF2610-ZTA-HU',
            'confirm_approved_decision' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $approvedIntent = $intent->fresh();
        $snapshot = $approvedIntent->commercial_snapshot;
        $this->assertSame(InvoiceIntentStatus::ReadyForDryRun, $approvedIntent->status);
        $this->assertSame('wpcg-tax-intent-'.$intent->id.'-v1', $approvedIntent->tax_decision_reference);
        $this->assertSame(1, $approvedIntent->tax_decision_version);
        $this->assertSame('AAM', $snapshot['approved_tax_decision']['line']['tax_code']);
        $this->assertSame(8990, $snapshot['approved_tax_decision']['line']['net']);
        $this->assertSame(0, $snapshot['approved_tax_decision']['line']['tax']);
        $this->assertSame(8990, $snapshot['approved_tax_decision']['line']['gross']);
        $this->assertSame('REF2610-ZTA-HU', $snapshot['approved_tax_decision']['approval_reference']);
        $this->assertSame('approved', InvoiceIntentTaxReview::query()->where('status', 'approved')->sole()->status);
        $this->assertSame(InvoiceIntentStatus::ReadyForDryRun, $approvedIntent->status);
        $this->assertSame(1, AdminAuditEvent::query()->where('event', 'billing_tax.tax_decision_approved')->count());
        $this->assertDatabaseMissing('invoice_issuance_attempts', ['invoice_intent_id' => $intent->id]);

        $passed = app(InvoiceIntentLocalDryRun::class)->run($intent->id, $owner);
        $validated = $intent->fresh();
        $this->assertTrue($passed, json_encode($validated->dry_run_result, JSON_THROW_ON_ERROR));
        $this->assertSame(InvoiceIntentStatus::DryRunValidated, $validated->status);
        $this->assertSame('LOCAL_VALIDATION_PASSED', $validated->dry_run_result['validation']);
        $this->assertNotNull($validated->dry_run_validated_at);
        $this->assertDatabaseMissing('invoice_issuance_attempts', ['invoice_intent_id' => $intent->id]);
        $this->assertDatabaseHas('invoice_intent_events', ['invoice_intent_id' => $intent->id, 'event_type' => 'tax_decision_approved']);
        $this->assertDatabaseHas('invoice_intent_events', ['invoice_intent_id' => $intent->id, 'event_type' => 'dry_run_passed']);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'billing_tax.local_invoice_validation', 'subject_key' => (string) $intent->id]);
        $this->assertSame(2, InvoiceIntentTaxReview::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_approval_rejects_mismatched_frozen_invoice_amount_and_leaves_intent_blocked(): void
    {
        $owner = $this->asOwner();
        $fixtures = $this->fixtures($owner);
        $intent = $fixtures['intent'];
        $proposal = InvoiceIntentTaxReview::query()->create([
            'invoice_intent_id' => $intent->id,
            'configuration_version_id' => $intent->billing_tax_configuration_version_id,
            'actor_user_id' => $owner->id,
            'status' => 'proposed_pending_accountant',
            'provider_tax_code' => 'AAM',
            'proposed_treatment' => 'seller_exemption',
            'justification' => 'Synthetic test proposal for a mismatch case.',
            'created_at' => now(),
        ]);
        $fixtures['invoice']->forceFill(['total' => 8991])->save();

        $this->post(route('owner.billing.invoice-intents.tax-review.approve', [$intent, $proposal]), [
            'approval_authority' => 'accountant',
            'approval_reference' => 'synthetic-ref-1',
            'confirm_approved_decision' => '1',
        ])->assertSessionHasErrors('tax_review');

        $this->assertSame(InvoiceIntentStatus::BlockedTaxReview, $intent->fresh()->status);
        $this->assertNull($intent->fresh()->tax_decision_reference);
        $this->assertSame(1, InvoiceIntentTaxReview::query()->count());
        $this->assertSame(0, AdminAuditEvent::query()->where('event', 'billing_tax.tax_decision_approved')->count());
    }

    private function fixtures($owner): array
    {
        $customer = Customer::factory()->create();
        $identity = new CustomerBillingIdentity;
        $identity->forceFill([
            'customer_id' => $customer->id, 'version' => 1, 'source' => 'profile', 'customer_type' => 'individual',
            'legal_name' => 'M11 Synthetic Test', 'country_code' => 'HU', 'address_line1' => 'Synthetic Street 2',
            'postal_code' => '1011', 'city' => 'Budapest', 'verification_status' => VerificationStatus::NotRequired,
        ])->save();
        $seller = new SellerTaxProfile;
        $seller->forceFill([
            'version' => 1, 'legal_name' => 'Synthetic Seller Kft.', 'establishment_country' => 'HU',
            'seller_address' => ['address_line1' => 'Seller Street 1', 'postal_code' => '1011', 'city' => 'Budapest', 'country_code' => 'HU'],
            'aam_status' => 'confirmed', 'verification_status' => 'verified', 'effective_from' => now()->toDateString(),
            'evidence_reference' => 'synthetic-profile-evidence', 'aam_evidence_reference' => 'synthetic-aam-evidence', 'created_by' => $owner->id,
        ])->save();
        $seller = SellerTaxProfile::query()->findOrFail($seller->id);
        $configuration = app(SzamlazzBillingSettings::class)->publish([
            'seller_profile_id' => $seller->id, 'invoice_language' => 'hu', 'default_payment_method' => 'bank_card',
            'payment_deadline_policy' => 'same_day', 'fulfillment_date_policy' => 'payment_date', 'invoice_prefix' => 'A',
            'notify_customer' => false, 'currency_policy' => 'block_foreign_without_fx',
            'tax_configuration_mode' => 'manual_review', 'default_tax_code' => 'AAM', 'effective_at' => now()->toDateString(),
        ], $owner);
        $invoice = BillingInvoice::query()->create([
            'customer_id' => $customer->id, 'stripe_invoice_id' => 'in_m11_tax_approval_fixture', 'invoice_status' => 'paid',
            'subtotal' => 8990, 'discount_total' => 0, 'tax_total' => 0, 'total' => 8990, 'amount_paid' => 8990,
            'amount_due' => 0, 'currency' => 'HUF', 'paid_at' => now(), 'billing_identity_id' => $identity->id,
        ]);
        $intent = InvoiceIntent::query()->create([
            'customer_id' => $customer->id, 'billing_invoice_id' => $invoice->id, 'billing_identity_id' => $identity->id,
            'seller_tax_profile_id' => $seller->id, 'billing_tax_configuration_version_id' => $configuration->id,
            'stripe_invoice_id' => $invoice->stripe_invoice_id, 'provider' => 'szamlazz_hu', 'purpose' => 'subscription_invoice',
            'status' => InvoiceIntentStatus::BlockedTaxReview, 'amount' => 8990, 'currency' => 'HUF',
            'idempotency_key' => 'm11_tax_approval_'.bin2hex(random_bytes(8)),
            'commercial_snapshot' => [
                'stripe_invoice' => ['id' => $invoice->stripe_invoice_id, 'status' => 'paid', 'total' => 8990, 'tax_total' => 0, 'currency' => 'HUF', 'paid_at' => now()->toIso8601String()],
                'billing_identity' => ['id' => $identity->id, 'version' => 1, 'customer_type' => 'individual', 'legal_name' => 'M11 Synthetic Test', 'country_code' => 'HU', 'address_line1' => 'Synthetic Street 2', 'postal_code' => '1011', 'city' => 'Budapest'],
                'seller_tax_profile' => ['id' => $seller->id, 'version' => 1, 'legal_name' => 'Synthetic Seller Kft.', 'establishment_country' => 'HU', 'seller_address' => $seller->seller_address, 'aam_status' => 'confirmed', 'verification_status' => 'verified', 'effective_from' => now()->toDateString(), 'evidence_reference' => 'synthetic-profile-evidence', 'aam_evidence_reference' => 'synthetic-aam-evidence'],
                'billing_tax_configuration' => ['version_id' => $configuration->id, 'version' => $configuration->version, 'tax_configuration_mode' => 'manual_review', 'default_tax_code' => 'AAM', 'configuration' => $configuration->configuration],
            ],
        ]);

        return compact('intent', 'invoice', 'seller', 'identity', 'configuration');
    }
}
