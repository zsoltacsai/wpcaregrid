<?php

namespace Tests\Feature;

use App\Billing\SzamlazzBillingSettings;
use App\Invoicing\InvoiceIntentStatus;
use App\Invoicing\InvoiceIntentWorkflow;
use App\Models\AdminAuditEvent;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\CustomerBillingIdentity;
use App\Models\InvoiceIntentEvent;
use App\Tax\SellerTaxProfileRegistry;
use App\Tax\SellerTaxProfileStatus;
use App\Tax\VerificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class InvoiceIntentSnapshotRefreshTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_can_refresh_only_seller_and_tax_facts_for_a_paid_blocked_intent(): void
    {
        Queue::fake();
        $owner = $this->asOwner();
        $customer = Customer::factory()->create();
        $identity = new CustomerBillingIdentity;
        $identity->forceFill([
            'customer_id' => $customer->id,
            'version' => 1,
            'source' => 'profile',
            'customer_type' => 'individual',
            'legal_name' => 'Synthetic Buyer',
            'country_code' => 'HU',
            'verification_status' => VerificationStatus::NotRequired,
        ])->save();
        $invoice = BillingInvoice::query()->create([
            'customer_id' => $customer->id,
            'stripe_invoice_id' => 'in_snapshot_refresh_fixture',
            'invoice_status' => 'paid',
            'subtotal' => 8990,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 8990,
            'amount_paid' => 8990,
            'amount_due' => 0,
            'currency' => 'HUF',
            'paid_at' => now(),
        ]);
        $invoice->forceFill(['billing_identity_id' => $identity->id])->save();

        $workflow = app(InvoiceIntentWorkflow::class);
        $intent = $workflow->recordPaidInvoice($invoice, 'evt_snapshot_refresh_fixture');
        $this->assertNotNull($intent);
        $workflow->prepare($intent->id);
        $before = $intent->fresh()->commercial_snapshot;
        $this->assertSame(InvoiceIntentStatus::BlockedSellerProfile, $intent->fresh()->status);

        $seller = app(SellerTaxProfileRegistry::class)->publish([
            'legal_name' => 'Synthetic Verified Seller',
            'establishment_country' => 'HU',
            'seller_address' => ['address_line1' => 'Synthetic Street 1', 'postal_code' => '1011', 'city' => 'Budapest', 'country_code' => 'HU'],
            'effective_from' => now()->toDateString(),
            'verification_status' => SellerTaxProfileStatus::Verified,
            'evidence_reference' => 'synthetic-seller-profile-fixture',
        ]);
        $settings = app(SzamlazzBillingSettings::class)->publish([
            'seller_profile_id' => $seller->id,
            'invoice_language' => 'hu',
            'default_payment_method' => 'bank_card',
            'payment_deadline_policy' => 'same_day',
            'fulfillment_date_policy' => 'payment_date',
            'invoice_prefix' => null,
            'notify_customer' => false,
            'currency_policy' => 'block_foreign_without_fx',
            'tax_configuration_mode' => 'approved_rules',
            'default_tax_code' => 'EUFAD37',
            'effective_at' => now()->toDateString(),
        ], $owner);

        $this->actingAs($owner, 'owner')
            ->get(route('owner.billing.invoice-intents.show', $intent))
            ->assertOk()
            ->assertSee(__('owner.invoice_intents.refresh_snapshot'))
            ->assertSee(__('owner.invoice_intents.refresh_explainer'));

        $this->post(route('owner.billing.invoice-intents.refresh-tax-snapshot', $intent))
            ->assertRedirect()
            ->assertSessionHas('status', __('owner.invoice_intents.snapshot_refreshed'));

        $fresh = $intent->fresh();
        $snapshot = $fresh->commercial_snapshot;
        $this->assertSame(InvoiceIntentStatus::BlockedTaxReview, $fresh->status);
        $this->assertSame('approved_tax_decision_missing', $fresh->blocked_reason);
        $this->assertSame($before['stripe_invoice'], $snapshot['stripe_invoice']);
        $this->assertSame($before['billing_identity'], $snapshot['billing_identity']);
        $this->assertSame($settings->id, $fresh->billing_tax_configuration_version_id);
        $this->assertSame($seller->id, $fresh->seller_tax_profile_id);
        $this->assertSame($settings->version, $snapshot['billing_tax_configuration']['version']);
        $this->assertSame($seller->version, $snapshot['seller_tax_profile']['version']);
        $this->assertNull($fresh->tax_decision_reference);
        $this->assertNull($fresh->dry_run_validated_at);
        $this->assertFalse(InvoiceIntentEvent::query()->where('event_type', 'intent_seller_tax_snapshot_refreshed')->firstOrFail()->metadata['provider_called']);
        $this->assertSame(1, AdminAuditEvent::query()->where('event', 'billing_tax.intent_snapshot_refreshed')->count());

        // Once a review exists, the frozen tax snapshot can no longer be refreshed.
        $fresh->taxReviews()->create([
            'configuration_version_id' => $settings->id,
            'actor_user_id' => $owner->id,
            'status' => 'proposed_pending_accountant',
            'provider_tax_code' => 'EUFAD37',
            'proposed_treatment' => 'reverse_charge',
            'justification' => 'Synthetic review must prevent silent rebasing.',
            'created_at' => now(),
        ]);
        $this->expectException(\DomainException::class);
        $workflow->refreshSellerAndTaxSnapshot($fresh->id, $owner);
    }
}
