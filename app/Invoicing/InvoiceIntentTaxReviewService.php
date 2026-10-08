<?php

namespace App\Invoicing;

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
}
