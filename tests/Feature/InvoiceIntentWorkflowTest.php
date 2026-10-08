<?php

namespace Tests\Feature;

use App\Invoicing\InvoiceIntentStatus;
use App\Invoicing\InvoiceIntentWorkflow;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\CustomerBillingIdentity;
use App\Models\InvoiceIntent;
use App\Models\InvoiceIntentEvent;
use App\Models\InvoiceIntentFollowUpMarker;
use App\Models\SellerTaxProfile;
use App\Models\User;
use App\Models\VatValidation;
use App\Tax\SellerTaxProfileRegistry;
use App\Tax\SellerTaxProfileStatus;
use App\Tax\Vat\VatValidationStatus;
use App\Tax\VerificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvoiceIntentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_stripe_invoice_creates_one_deterministic_intent_and_duplicate_delivery_is_idempotent(): void
    {
        Queue::fake();
        $invoice = $this->invoice('in_intent_once', 'paid');
        $workflow = app(InvoiceIntentWorkflow::class);

        $first = $workflow->recordPaidInvoice($invoice, 'evt_paid_one');
        $second = $workflow->recordPaidInvoice($invoice, 'evt_paid_retry');

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, InvoiceIntent::query()->count());
        $this->assertSame(1, InvoiceIntentEvent::query()->where('event_type', 'intent_created')->count());
        $this->assertSame(InvoiceIntentStatus::Pending, $first->status);
        $this->assertSame('EUR', $first->currency);
        $this->assertSame(80100, $first->amount);
        $this->assertStringStartsWith('szamlazz:stripe-invoice:', $first->idempotency_key);
    }

    public function test_failed_invoice_does_not_create_an_invoice_intent(): void
    {
        $invoice = $this->invoice('in_failed_no_intent', 'open');

        $this->assertNull(app(InvoiceIntentWorkflow::class)->recordPaidInvoice($invoice, 'evt_failed'));
        $this->assertSame(0, InvoiceIntent::query()->count());
    }

    public function test_zero_value_paid_invoice_is_preserved_without_inventing_tax_treatment(): void
    {
        Queue::fake();
        $invoice = $this->invoice('in_zero_invoice', 'paid', ['total' => 0]);
        $intent = app(InvoiceIntentWorkflow::class)->recordPaidInvoice($invoice, 'evt_zero');

        $this->assertNotNull($intent);
        app(InvoiceIntentWorkflow::class)->prepare($intent->id);
        $this->assertSame(InvoiceIntentStatus::BlockedIdentity, $intent->fresh()->status);
        $this->assertSame(0, $intent->fresh()->amount);
    }

    public function test_refund_arriving_before_paid_invoice_creates_review_marker_not_correction_intent(): void
    {
        Queue::fake();
        $workflow = app(InvoiceIntentWorkflow::class);
        $workflow->markRefundReviewRequired('pi_refund_first', 'evt_refund_first');

        $intent = $workflow->recordPaidInvoice(
            $this->invoice('in_refund_first', 'paid', ['stripe_payment_intent_id' => 'pi_refund_first']),
            'evt_invoice_after_refund',
        );

        $this->assertNotNull($intent);
        $this->assertSame(1, InvoiceIntentFollowUpMarker::query()->count());
        $this->assertSame(InvoiceIntentStatus::ReconciliationRequired, $intent->fresh()->status);
        $this->assertSame('refund_review_required', $intent->fresh()->blocked_reason);
        $this->assertFalse(InvoiceIntent::query()->whereNotNull('provider_invoice_id')->exists());
    }

    public function test_preparation_snapshot_is_encrypted_and_missing_identity_stays_blocked(): void
    {
        Queue::fake();
        $intent = app(InvoiceIntentWorkflow::class)->recordPaidInvoice($this->invoice('in_snapshot', 'paid'), 'evt_snapshot');
        $this->assertNotNull($intent);

        app(InvoiceIntentWorkflow::class)->prepare($intent->id);

        $stored = DB::table('invoice_intents')->where('id', $intent->id)->value('commercial_snapshot');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('in_snapshot', $stored);
        $this->assertSame(InvoiceIntentStatus::BlockedIdentity, $intent->fresh()->status);
        $this->assertSame('billing_identity_missing', $intent->fresh()->blocked_reason);
    }

    public function test_identity_owned_by_another_customer_is_blocked_and_not_copied(): void
    {
        Queue::fake();
        $invoiceCustomer = Customer::factory()->create();
        $identityOwner = Customer::factory()->create();
        $identity = $this->identity($identityOwner);
        $intent = app(InvoiceIntentWorkflow::class)->recordPaidInvoice(
            $this->invoice('in_identity_mismatch', 'paid', [
                'customer_id' => $invoiceCustomer->id,
                'billing_identity_id' => $identity->id,
            ]),
            'evt_identity_mismatch',
        );

        $this->assertNotNull($intent);
        app(InvoiceIntentWorkflow::class)->prepare($intent->id);

        $fresh = $intent->fresh();
        $this->assertSame(InvoiceIntentStatus::BlockedIdentity, $fresh->status);
        $this->assertSame('billing_identity_customer_mismatch', $fresh->blocked_reason);
        $this->assertArrayNotHasKey('billing_identity', $fresh->commercial_snapshot);
        $this->assertNull($fresh->billing_identity_id);
    }

    public function test_matching_identity_without_seller_profile_stays_blocked_without_assuming_aam(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create();
        $identity = $this->identity($customer);
        $intent = app(InvoiceIntentWorkflow::class)->recordPaidInvoice(
            $this->invoice('in_missing_seller_profile', 'paid', [
                'customer_id' => $customer->id,
                'billing_identity_id' => $identity->id,
            ]),
            'evt_missing_seller_profile',
        );

        $this->assertNotNull($intent);
        app(InvoiceIntentWorkflow::class)->prepare($intent->id);

        $fresh = $intent->fresh();
        $this->assertSame(InvoiceIntentStatus::BlockedSellerProfile, $fresh->status);
        $this->assertSame('seller_profile_missing', $fresh->blocked_reason);
        $this->assertSame($identity->id, $fresh->billing_identity_id);
        $this->assertSame($identity->id, $fresh->commercial_snapshot['billing_identity']['id']);
        $this->assertArrayNotHasKey('seller_tax_profile', $fresh->commercial_snapshot);
        $this->assertNull(SellerTaxProfile::query()->first());
    }

    public function test_valid_vies_evidence_does_not_approve_tax_treatment(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create();
        $identity = $this->identity($customer, [
            'customer_type' => 'company',
            'company_name' => 'Test Company Kft',
            'declared_business_status' => true,
            'tax_identifier' => 'DE123456789',
            'verification_status' => VerificationStatus::Verified,
        ]);
        $validation = new VatValidation;
        $validation->forceFill([
            'customer_billing_identity_id' => $identity->id,
            'provider' => 'eu_vies',
            'status' => VatValidationStatus::Valid,
            'country_code' => 'DE',
            'vat_identifier' => 'DE123456789',
            'vat_identifier_hash' => hash('sha256', 'test-only-vat'),
            'checked_at' => now(),
            'message_code' => 'valid',
        ])->save();
        app(SellerTaxProfileRegistry::class)->publish([
            'effective_from' => '2026-01-01',
            'verification_status' => SellerTaxProfileStatus::Verified,
            'evidence_reference' => 'test-only-seller-evidence',
        ]);
        $intent = app(InvoiceIntentWorkflow::class)->recordPaidInvoice(
            $this->invoice('in_vies_not_tax_decision', 'paid', [
                'customer_id' => $customer->id,
                'billing_identity_id' => $identity->id,
            ]),
            'evt_vies_not_tax_decision',
        );

        $this->assertNotNull($intent);
        app(InvoiceIntentWorkflow::class)->prepare($intent->id);

        $fresh = $intent->fresh();
        $this->assertSame(InvoiceIntentStatus::BlockedTaxReview, $fresh->status);
        $this->assertSame('approved_tax_decision_missing', $fresh->blocked_reason);
        $this->assertArrayNotHasKey('tax_decision', $fresh->commercial_snapshot);
        $this->assertSame('DE123456789', $fresh->commercial_snapshot['billing_identity']['tax_identifier']);
        $storedSnapshot = DB::table('invoice_intents')->where('id', $fresh->id)->value('commercial_snapshot');
        $this->assertStringNotContainsString('DE123456789', $storedSnapshot);
        $this->assertStringNotContainsString('Test Company Kft', $storedSnapshot);
    }

    public function test_owner_monitor_is_owner_only_and_never_serializes_encrypted_snapshot(): void
    {
        $intent = InvoiceIntent::create([
            'stripe_invoice_id' => 'in_owner_safe',
            'provider' => 'szamlazz_hu',
            'purpose' => 'subscription_invoice',
            'status' => InvoiceIntentStatus::BlockedTaxReview,
            'amount' => 80100,
            'currency' => 'EUR',
            'idempotency_key' => 'owner-safe-test-key',
            'commercial_snapshot' => ['billing_identity' => ['tax_identifier' => 'TEST-SECRET-ID']],
        ]);
        $owner = User::factory()->owner()->create();
        $customerUser = User::factory()->create();

        $this->get(route('owner.billing.invoice-intents.show', $intent))->assertRedirect(route('owner.login'));
        $this->actingAs($customerUser, 'owner')->get(route('owner.billing.invoice-intents.show', $intent))->assertRedirect(route('owner.login'));
        $response = $this->actingAs($owner, 'owner')->get(route('owner.billing.invoice-intents.show', $intent));
        $response->assertOk()->assertDontSee('TEST-SECRET-ID');
        $this->assertArrayNotHasKey('commercial_snapshot', $intent->toArray());
    }

    private function invoice(string $stripeId, string $status, array $overrides = []): BillingInvoice
    {
        $billingIdentityId = $overrides['billing_identity_id'] ?? null;
        unset($overrides['billing_identity_id']);
        $invoice = BillingInvoice::query()->create($overrides + [
            'stripe_invoice_id' => $stripeId,
            'invoice_status' => $status,
            'subtotal' => 89000,
            'discount_total' => 8900,
            'tax_total' => 0,
            'total' => 80100,
            'amount_paid' => $status === 'paid' ? 80100 : 0,
            'amount_due' => $status === 'paid' ? 0 : 80100,
            'currency' => 'EUR',
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
        if ($billingIdentityId !== null) {
            $invoice->forceFill(['billing_identity_id' => $billingIdentityId])->save();
        }

        return $invoice;
    }

    /** @param array<string, mixed> $overrides */
    private function identity(Customer $customer, array $overrides = []): CustomerBillingIdentity
    {
        $identity = new CustomerBillingIdentity;
        $identity->forceFill($overrides + [
            'customer_id' => $customer->id,
            'version' => 1,
            'source' => 'profile',
            'customer_type' => 'individual',
            'legal_name' => 'Test Customer',
            'country_code' => 'HU',
            'verification_status' => VerificationStatus::NotRequired,
        ]);
        $identity->save();

        return $identity;
    }
}
