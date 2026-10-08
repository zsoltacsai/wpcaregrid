<?php

namespace App\Invoicing;

use App\Billing\SzamlazzBillingSettings;
use App\Invoicing\Providers\InvoiceSubmissionProvider;
use App\Models\BillingTaxConfigurationVersion;
use App\Models\InvoiceIntent;
use App\Models\InvoiceIntentEvent;
use App\Models\InvoiceIssuanceAttempt;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Support\Facades\DB;

/** Single-submit orchestration. Ambiguous outcomes are reconciled by lookup, never by resubmission. */
final class InvoiceIssuanceWorkflow
{
    public function __construct(private readonly InvoiceSubmissionProvider $provider, private readonly SzamlazzBillingSettings $settings) {}

    public function submit(int $intentId, NormalizedInvoiceRequest $request): InvoiceIssuanceAttempt|string
    {
        $decision = DB::transaction(function () use ($intentId, $request): array {
            $intent = InvoiceIntent::query()->lockForUpdate()->findOrFail($intentId);
            $existing = InvoiceIssuanceAttempt::query()->where('invoice_intent_id', $intentId)->lockForUpdate()->first();
            if ($existing) {
                return ['attempt' => $existing];
            }
            $version = $intent->billing_tax_configuration_version_id
                ? BillingTaxConfigurationVersion::query()->find($intent->billing_tax_configuration_version_id)
                : null;
            if (! $version) {
                return ['block' => 'billing_configuration_snapshot_missing'];
            }
            if (! $request->taxDecision instanceof ApprovedTaxDecision) {
                return ['block' => 'approved_tax_decision_missing'];
            }
            $effectiveRequest = $request->withBillingSettings($version->configuration, 'wpcg-invoice-'.$intent->id);
            $block = $this->blockReason($intent, $effectiveRequest, $version);
            if ($block !== null) {
                return ['block' => $block];
            }

            $reference = 'wpcg-invoice-'.$intent->id;
            $attempt = InvoiceIssuanceAttempt::query()->create([
                'invoice_intent_id' => $intent->id,
                'external_reference' => $reference,
                'request_sha256' => hash('sha256', json_encode($this->requestFingerprint($effectiveRequest), JSON_THROW_ON_ERROR)),
                'status' => 'submission_started',
                'submitted_at' => now(),
            ]);
            $this->event($intent, 'provider_submission_started', InvoiceIntentStatus::DryRunValidated, InvoiceIntentStatus::DryRunValidated, ['external_reference' => $reference]);

            return ['attempt_id' => $attempt->id, 'request' => $effectiveRequest];
        });

        if (isset($decision['block'])) {
            return $decision['block'];
        }
        if (isset($decision['attempt'])) {
            /** @var InvoiceIssuanceAttempt $attempt */
            $attempt = $decision['attempt'];

            return $attempt;
        }

        $result = $this->provider->submit($decision['request']);

        return DB::transaction(function () use ($decision, $result, $intentId): InvoiceIssuanceAttempt {
            $intent = InvoiceIntent::query()->lockForUpdate()->findOrFail($intentId);
            $attempt = InvoiceIssuanceAttempt::query()->lockForUpdate()->findOrFail($decision['attempt_id']);
            if ($attempt->status !== 'submission_started') {
                return $attempt;
            }
            $attempt->provider_response_metadata = $result->metadata;
            $attempt->error_category = $result->errorCategory;
            $attempt->status = match ($result->outcome) {
                'issued' => 'issued',
                'rejected' => 'rejected',
                default => 'reconciliation_required',
            };
            $attempt->provider_invoice_id = $result->outcome === 'issued' ? $result->invoiceNumber : null;
            $attempt->resolved_at = $result->outcome === 'issued' ? now() : null;
            $attempt->save();
            if ($result->outcome === 'issued') {
                $intent->status = InvoiceIntentStatus::Issued;
                $intent->blocked_reason = null;
                $intent->provider_invoice_id = $result->invoiceNumber;
                $intent->provider_response_metadata = $result->metadata;
                $intent->save();
                $this->event($intent, 'provider_invoice_issued', InvoiceIntentStatus::DryRunValidated, InvoiceIntentStatus::Issued, ['provider_invoice_id' => $result->invoiceNumber]);
            } else {
                $intent->status = $result->outcome === 'rejected' ? InvoiceIntentStatus::Failed : InvoiceIntentStatus::ReconciliationRequired;
                $intent->blocked_reason = $result->errorCategory ?? 'provider_response_ambiguous';
                $intent->last_error_category = $result->errorCategory ?? 'provider_response_ambiguous';
                $intent->save();
                $this->event($intent, $result->outcome === 'rejected' ? 'provider_submission_rejected' : 'provider_submission_ambiguous', InvoiceIntentStatus::DryRunValidated, $intent->status, ['error_category' => $intent->last_error_category]);
            }

            return $attempt;
        });
    }

    public function reconcile(int $attemptId, ?User $actor = null): InvoiceIssuanceAttempt
    {
        $attempt = InvoiceIssuanceAttempt::query()->findOrFail($attemptId);
        if ($attempt->status === 'issued') {
            return $attempt;
        }
        if (! in_array($attempt->status, ['submission_started', 'reconciliation_required'], true)) {
            return $attempt;
        }
        $result = $this->provider->lookupByExternalReference($attempt->external_reference);
        if ($result->outcome !== 'found' || ! $result->invoiceNumber) {
            AdminAudit::record($actor, 'billing_tax.issuance_reconciliation', 'invoice_intent', (string) $attempt->invoice_intent_id, ['result' => 'unresolved']);

            return $attempt;
        }

        return DB::transaction(function () use ($attemptId, $result, $actor): InvoiceIssuanceAttempt {
            $attempt = InvoiceIssuanceAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->status === 'issued') {
                return $attempt;
            }
            $intent = InvoiceIntent::query()->lockForUpdate()->findOrFail($attempt->invoice_intent_id);
            $from = $intent->status;
            $attempt->status = 'issued';
            $attempt->provider_invoice_id = $result->invoiceNumber;
            $attempt->error_category = null;
            $attempt->provider_response_metadata = $result->metadata;
            $attempt->resolved_at = now();
            $attempt->save();
            $intent->status = InvoiceIntentStatus::Issued;
            $intent->blocked_reason = null;
            $intent->last_error_category = null;
            $intent->provider_invoice_id = $result->invoiceNumber;
            $intent->provider_response_metadata = $result->metadata;
            $intent->save();
            $this->event($intent, 'provider_invoice_reconciled', $from, InvoiceIntentStatus::Issued, ['provider_invoice_id' => $result->invoiceNumber]);
            AdminAudit::record($actor, 'billing_tax.issuance_reconciliation', 'invoice_intent', (string) $intent->id, ['result' => 'found']);

            return $attempt;
        });
    }

    private function blockReason(InvoiceIntent $intent, NormalizedInvoiceRequest $request, BillingTaxConfigurationVersion $configuration): ?string
    {
        if (! config('szamlazz.provider_enabled') || config('szamlazz.dry_run') || ! config('szamlazz.issuance_enabled')) {
            return 'issuance_gates_closed';
        }
        $allowed = config('szamlazz.permitted_environments', []);
        if (! is_array($allowed) || ! in_array(app()->environment(), $allowed, true)) {
            return 'execution_environment_not_permitted';
        }
        if (! is_string(config('szamlazz.agent_api_key')) || config('szamlazz.agent_api_key') === '') {
            return 'credential_unavailable';
        }
        if ($intent->status !== InvoiceIntentStatus::DryRunValidated || ! $intent->dry_run_validated_at) {
            return 'local_validation_required';
        }
        if (! $request->taxDecision instanceof ApprovedTaxDecision) {
            return 'approved_tax_decision_missing';
        }
        if (! $intent->tax_decision_reference || ! $intent->tax_decision_version || ! $intent->billing_tax_configuration_version_id) {
            return 'approved_tax_decision_missing';
        }
        $snapshot = $intent->commercial_snapshot ?? [];
        $approval = $snapshot['approved_tax_decision'] ?? null;
        if (! is_array($approval)
            || ! in_array($approval['approval_authority'] ?? null, ['accountant', 'tax_counsel'], true)
            || blank($approval['approval_reference'] ?? null)
            || ($approval['version'] ?? null) !== $intent->tax_decision_version
            || ($approval['reference'] ?? null) !== $intent->tax_decision_reference) {
            return 'approved_tax_decision_missing';
        }
        $profile = $intent->sellerTaxProfile;
        $selectedProfile = $configuration?->configuration['seller_profile_id'] ?? null;
        if (! $profile || $profile->verification_status?->value !== 'verified' || (int) $selectedProfile !== (int) $profile->id) {
            return 'seller_configuration_unapproved';
        }
        if ($intent->billing_identity_id === null || (int) ($snapshot['billing_identity']['id'] ?? 0) !== (int) $intent->billing_identity_id) {
            return 'billing_identity_missing';
        }
        if ($intent->refund_review_required_at || $intent->status === InvoiceIntentStatus::ReconciliationRequired) {
            return 'refund_or_reconciliation_review_required';
        }
        if (! SzamlazzTaxCodeRegistry::supports($approval['tax_code'] ?? null)
            || ($approval['tax_code'] ?? null) !== ($request->taxDecision->line['tax_code'] ?? null)
            || ! hash_equals('wpcg-invoice-'.$intent->id, $request->externalReference)
            || $request->amountMinor !== (int) $intent->amount
            || strtoupper($request->currency) !== strtoupper((string) $intent->currency)
            || (int) ($request->taxDecision->line['gross'] ?? -1) !== (int) $intent->amount
            || $request->taxDecision->reference !== $intent->tax_decision_reference
            || $request->taxDecision->version !== $intent->tax_decision_version) {
            return 'immutable_invoice_snapshot_mismatch';
        }
        if (($configuration->configuration['fulfillment_date_policy'] ?? null) === 'payment_date' && $request->paymentDate === null) {
            return 'payment_date_required';
        }
        if (strtoupper($request->currency) !== 'HUF'
            && (! $request->taxDecision->exchangeRate || ! $request->taxDecision->exchangeRateBank)) {
            return 'approved_fx_data_required';
        }

        return null;
    }

    private function requestFingerprint(NormalizedInvoiceRequest $request): array
    {
        return [
            'external_reference' => $request->externalReference,
            'currency' => $request->currency,
            'amount_minor' => $request->amountMinor,
            'dates' => [$request->issueDate, $request->performanceDate, $request->paymentDueDate, $request->paymentDate],
            'payment_method' => $request->paymentMethod,
            'seller' => $request->seller,
            'buyer' => $request->buyer,
            'description' => $request->description,
            'tax_decision_reference' => $request->taxDecision->reference,
            'tax_decision_version' => $request->taxDecision->version,
            'tax_line' => $request->taxDecision->line,
        ];
    }

    private function event(InvoiceIntent $intent, string $type, InvoiceIntentStatus $from, InvoiceIntentStatus $to, array $metadata): void
    {
        if (InvoiceIntentEvent::query()->where('invoice_intent_id', $intent->id)->where('event_type', $type)->exists()) {
            return;
        }
        InvoiceIntentEvent::query()->create([
            'invoice_intent_id' => $intent->id,
            'event_type' => $type,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
