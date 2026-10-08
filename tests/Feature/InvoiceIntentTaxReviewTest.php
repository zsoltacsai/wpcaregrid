<?php

namespace Tests\Feature;

use App\Billing\SzamlazzBillingSettings;
use App\Invoicing\InvoiceIntentStatus;
use App\Models\AdminAuditEvent;
use App\Models\InvoiceIntent;
use App\Models\InvoiceIntentTaxReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class InvoiceIntentTaxReviewTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    public function test_owner_can_record_encrypted_tax_proposal_without_approving_or_unblocking_intent(): void
    {
        $owner = $this->asOwner();
        $version = app(SzamlazzBillingSettings::class)->publish($this->settings(), $owner);
        $intent = $this->intent($version->id);
        $justification = 'Synthetic fixture basis for a proposed treatment only.';

        $this->post(route('owner.billing.invoice-intents.tax-review', $intent), [
            'provider_tax_code' => 'EUE',
            'proposed_treatment' => 'reverse_charge',
            'justification' => $justification,
            'basis_reference' => 'fixture-reference-only',
        ])->assertRedirect()->assertSessionHas('status');

        $review = InvoiceIntentTaxReview::query()->firstOrFail();
        $this->assertSame($owner->id, $review->actor_user_id);
        $this->assertSame('proposed_pending_accountant', $review->status);
        $this->assertSame($justification, $review->justification);
        $this->assertStringNotContainsString($justification, DB::table('invoice_intent_tax_reviews')->value('justification'));
        $this->assertSame(InvoiceIntentStatus::BlockedTaxReview, $intent->fresh()->status);
        $this->assertNull($intent->fresh()->tax_decision_reference);
        $this->assertDatabaseHas('invoice_intent_events', ['invoice_intent_id' => $intent->id, 'event_type' => 'tax_review_proposed']);
        $this->assertSame(1, AdminAuditEvent::query()->where('event', 'billing_tax.review_proposed')->count());
    }

    public function test_vies_or_default_tax_code_does_not_create_an_approved_decision(): void
    {
        $owner = $this->asOwner();
        $version = app(SzamlazzBillingSettings::class)->publish($this->settings('AAM'), $owner);
        $intent = $this->intent($version->id);
        $this->get(route('owner.billing.invoice-intents.show', $intent))
            ->assertOk()
            ->assertSee(__('owner.invoice_intents.proposal_not_approval'))
            ->assertDontSee('approved_tax_decision');
        $this->assertSame(InvoiceIntentStatus::BlockedTaxReview, $intent->fresh()->status);
    }

    public function test_unsupported_tax_code_and_non_manual_mode_cannot_be_recorded(): void
    {
        $owner = $this->asOwner();
        $settings = app(SzamlazzBillingSettings::class);
        $manualReviewVersion = $settings->publish($this->settings(), $owner);
        $intent = $this->intent($manualReviewVersion->id);
        $input = ['provider_tax_code' => '27%', 'proposed_treatment' => 'standard_rate', 'justification' => 'Synthetic reason for test.'];
        $this->post(route('owner.billing.invoice-intents.tax-review', $intent), $input)->assertSessionHasErrors();
        $this->assertSame(0, InvoiceIntentTaxReview::query()->count());

        $approvedRulesVersion = $settings->publish($this->settings(null, 'approved_rules'), $owner);
        $newIntent = $this->intent($approvedRulesVersion->id);
        $this->post(route('owner.billing.invoice-intents.tax-review', $newIntent), [
            'provider_tax_code' => '27', 'proposed_treatment' => 'standard_rate', 'justification' => 'Synthetic reason for test.',
        ])->assertSessionHasErrors('tax_review');
        $this->assertSame(0, InvoiceIntentTaxReview::query()->count());
    }

    private function intent(int $configurationVersionId): InvoiceIntent
    {
        return InvoiceIntent::query()->create([
            'billing_tax_configuration_version_id' => $configurationVersionId,
            'stripe_invoice_id' => 'in_review_fixture_'.uniqid(),
            'provider' => 'szamlazz_hu',
            'purpose' => 'subscription_invoice',
            'status' => InvoiceIntentStatus::BlockedTaxReview,
            'amount' => 80100,
            'currency' => 'EUR',
            'idempotency_key' => 'review_'.bin2hex(random_bytes(12)),
        ]);
    }

    private function settings(?string $taxCode = null, string $mode = 'manual_review'): array
    {
        return [
            'seller_profile_id' => null, 'invoice_language' => 'hu', 'default_payment_method' => 'bank_transfer',
            'payment_deadline_policy' => 'same_day', 'fulfillment_date_policy' => 'payment_date', 'invoice_prefix' => null,
            'notify_customer' => false, 'currency_policy' => 'block_foreign_without_fx', 'tax_configuration_mode' => $mode,
            'default_tax_code' => $taxCode, 'effective_at' => now()->toDateString(),
        ];
    }
}
