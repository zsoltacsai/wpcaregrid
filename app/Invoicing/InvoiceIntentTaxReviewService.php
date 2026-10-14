<?php

namespace App\Invoicing;

use App\Models\BillingInvoice;
use App\Models\BillingTaxConfigurationVersion;
use App\Models\InvoiceIntent;
use App\Models\InvoiceIntentEvent;
use App\Models\InvoiceIntentTaxReview;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InvoiceIntentTaxReviewService
{
    public const TREATMENTS = ['standard_rate', 'seller_exemption', 'reverse_charge', 'outside_scope', 'other'];

    public function propose(InvoiceIntent $intent, array $input, User $actor): InvoiceIntentTaxReview
    {
        if ($intent->status !== InvoiceIntentStatus::BlockedTaxReview) {
            throw ValidationException::withMessages(['tax_review' => __('owner.invoice_intents.review_not_ready')]);
        }
        $configuration = $intent->billing_tax_configuration_version_id
            ? BillingTaxConfigurationVersion::query()->find($intent->billing_tax_configuration_version_id)
            : null;
        if (! $configuration || ($configuration->configuration['tax_configuration_mode'] ?? null) !== 'manual_review') {
            throw ValidationException::withMessages(['tax_review' => __('owner.invoice_intents.review_mode_unavailable')]);
        }
        $validated = Validator::make($input, [
            'provider_tax_code' => ['required', 'string', Rule::in(array_keys(SzamlazzTaxCodeRegistry::all()))],
            'proposed_treatment' => ['required', Rule::in(self::TREATMENTS)],
            'justification' => ['required', 'string', 'min:10', 'max:2000'],
            'basis_reference' => ['nullable', 'string', 'max:160', 'not_regex:/[\x00-\x1F\x7F]/'],
        ])->validate();

        return DB::transaction(function () use ($intent, $validated, $actor, $configuration): InvoiceIntentTaxReview {
            $locked = InvoiceIntent::query()->lockForUpdate()->findOrFail($intent->id);
            if ($locked->status !== InvoiceIntentStatus::BlockedTaxReview) {
                throw ValidationException::withMessages(['tax_review' => __('owner.invoice_intents.review_not_ready')]);
            }
            $review = InvoiceIntentTaxReview::query()->create([
                'invoice_intent_id' => $locked->id,
                'configuration_version_id' => $configuration->id,
                'actor_user_id' => $actor->id,
                'status' => 'proposed_pending_accountant',
                'provider_tax_code' => $validated['provider_tax_code'],
                'proposed_treatment' => $validated['proposed_treatment'],
                'justification' => $validated['justification'],
                'basis_reference' => $validated['basis_reference'] ?? null,
                'created_at' => now(),
            ]);
            InvoiceIntentEvent::query()->create([
                'invoice_intent_id' => $locked->id,
                'event_type' => 'tax_review_proposed',
                'from_status' => $locked->status->value,
                'to_status' => $locked->status->value,
                'actor_user_id' => $actor->id,
                'metadata' => ['tax_code' => $review->provider_tax_code, 'treatment' => $review->proposed_treatment, 'review_status' => $review->status],
                'created_at' => now(),
            ]);
            AdminAudit::record($actor, 'billing_tax.review_proposed', 'invoice_intent', (string) $locked->id, [
                'tax_code' => $review->provider_tax_code,
                'treatment' => $review->proposed_treatment,
                'configuration_version' => $configuration->version,
                'status' => $review->status,
            ]);

            // A proposal is not an approval and must never unblock invoice processing.
            return $review;
        });
    }

    public function approve(InvoiceIntent $intent, InvoiceIntentTaxReview $proposal, array $input, User $actor): InvoiceIntentTaxReview
    {
        $validated = Validator::make($input, [
            'approval_authority' => ['required', Rule::in(['accountant', 'tax_counsel'])],
            'approval_reference' => ['required', 'string', 'min:3', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/'],
            'confirm_approved_decision' => ['accepted'],
        ])->validate();

        return DB::transaction(function () use ($intent, $proposal, $validated, $actor): InvoiceIntentTaxReview {
            $locked = InvoiceIntent::query()->lockForUpdate()->findOrFail($intent->id);
            $source = InvoiceIntentTaxReview::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($source->invoice_intent_id !== $locked->id || $source->status !== 'proposed_pending_accountant'
                || (int) $source->configuration_version_id !== (int) $locked->billing_tax_configuration_version_id
                || $locked->status !== InvoiceIntentStatus::BlockedTaxReview
                || $locked->issuanceAttempt()->exists() || $locked->refund_review_required_at
                || $locked->tax_decision_reference !== null || $locked->tax_decision_version !== null) {
                throw ValidationException::withMessages(['tax_review' => __('owner.invoice_intents.approval_not_ready')]);
            }

            $configuration = $locked->billing_tax_configuration_version_id
                ? BillingTaxConfigurationVersion::query()->find($locked->billing_tax_configuration_version_id)
                : null;
            $snapshot = $locked->commercial_snapshot ?? [];
            $seller = $snapshot['seller_tax_profile'] ?? null;
            $buyer = $snapshot['billing_identity'] ?? null;
            $invoice = $locked->billing_invoice_id ? BillingInvoice::query()->find($locked->billing_invoice_id) : null;
            if (! $configuration || ($configuration->configuration['tax_configuration_mode'] ?? null) !== 'manual_review'
                || ($configuration->configuration['default_tax_code'] ?? null) !== $source->provider_tax_code
                || ! is_array($seller) || ($seller['verification_status'] ?? null) !== 'verified'
                || (int) ($seller['id'] ?? 0) !== (int) $locked->seller_tax_profile_id
                || (int) ($configuration->configuration['seller_profile_id'] ?? 0) !== (int) $locked->seller_tax_profile_id
                || ! is_array($buyer) || ! $invoice
                || (int) ($buyer['id'] ?? 0) !== (int) $locked->billing_identity_id
                || (int) ($locked->billingIdentity?->customer_id ?? 0) !== (int) $locked->customer_id
                || $invoice->invoice_status !== 'paid' || $invoice->stripe_invoice_id !== $locked->stripe_invoice_id
                || ! is_int($invoice->total) || ! is_int($invoice->tax_total)
                || $invoice->total !== (int) $locked->amount || $invoice->amount_paid !== (int) $locked->amount
                || $invoice->tax_total < 0 || $invoice->tax_total > $invoice->total) {
                throw ValidationException::withMessages(['tax_review' => __('owner.invoice_intents.approval_snapshot_mismatch')]);
            }

            $reference = 'wpcg-tax-intent-'.$locked->id.'-v1';
            $version = 1;
            $line = [
                'net' => $invoice->total - $invoice->tax_total,
                'tax' => $invoice->tax_total,
                'gross' => $invoice->total,
                'tax_code' => $source->provider_tax_code,
            ];
            $approved = InvoiceIntentTaxReview::query()->create([
                'invoice_intent_id' => $locked->id,
                'configuration_version_id' => $configuration->id,
                'actor_user_id' => $actor->id,
                'status' => 'approved',
                'provider_tax_code' => $source->provider_tax_code,
                'proposed_treatment' => $source->proposed_treatment,
                'justification' => $source->justification,
                'basis_reference' => $validated['approval_reference'],
                'created_at' => now(),
            ]);

            $snapshot['approved_tax_decision'] = [
                'approval_authority' => $validated['approval_authority'],
                'approval_reference' => $validated['approval_reference'],
                'reference' => $reference,
                'version' => $version,
                'tax_code' => $source->provider_tax_code,
                'treatment' => $source->proposed_treatment,
                'line' => $line,
                'approved_at' => now()->toIso8601String(),
            ];
            $locked->commercial_snapshot = $snapshot;
            $locked->tax_decision_reference = $reference;
            $locked->tax_decision_version = $version;
            $locked->status = InvoiceIntentStatus::ReadyForDryRun;
            $locked->blocked_reason = null;
            $locked->save();

            InvoiceIntentEvent::query()->create([
                'invoice_intent_id' => $locked->id,
                'event_type' => 'tax_decision_approved',
                'from_status' => InvoiceIntentStatus::BlockedTaxReview->value,
                'to_status' => InvoiceIntentStatus::ReadyForDryRun->value,
                'actor_user_id' => $actor->id,
                'metadata' => ['tax_code' => $source->provider_tax_code, 'treatment' => $source->proposed_treatment, 'version' => $version],
                'created_at' => now(),
            ]);
            AdminAudit::record($actor, 'billing_tax.tax_decision_approved', 'invoice_intent', (string) $locked->id, [
                'tax_code' => $source->provider_tax_code,
                'treatment' => $source->proposed_treatment,
                'authority' => $validated['approval_authority'],
                'configuration_version' => $configuration->version,
                'decision_version' => $version,
                'provider_called' => false,
            ]);

            return $approved;
        });
    }
}
