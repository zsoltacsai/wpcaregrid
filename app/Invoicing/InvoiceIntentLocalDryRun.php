<?php

namespace App\Invoicing;

use App\Invoicing\Providers\SzamlazzDryRunProvider;
use App\Models\BillingInvoice;
use App\Models\BillingTaxConfigurationVersion;
use App\Models\InvoiceIntent;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Builds and validates the frozen invoice snapshot locally. It never contacts a provider. */
final class InvoiceIntentLocalDryRun
{
    public function __construct(private readonly InvoiceIntentWorkflow $workflow) {}

    public function run(int $intentId, User $actor): bool
    {
        DB::transaction(function () use ($intentId): void {
            $intent = InvoiceIntent::query()->lockForUpdate()->findOrFail($intentId);
            if ($intent->status !== InvoiceIntentStatus::ReadyForDryRun || $intent->issuanceAttempt()->exists()) {
                throw new \DomainException('Invoice intent is not ready for local validation.');
            }
            $this->workflow->startDryRun($intent->id);
        });
        try {
            $passed = $this->validateFrozenSnapshot($intentId);
        } catch (\Throwable) {
            $this->workflow->recordDryRunResult($intentId, ['errors' => ['local_validation_runtime_error']], false);
            $passed = false;
        }

        $intent = InvoiceIntent::query()->findOrFail($intentId);
        AdminAudit::record($actor, 'billing_tax.local_invoice_validation', 'invoice_intent', (string) $intentId, [
            'result' => $passed ? 'passed' : 'failed',
            'error_categories' => array_values($intent->dry_run_result['errors'] ?? []),
            'provider_called' => false,
        ]);

        return $passed;
    }

    private function validateFrozenSnapshot(int $intentId): bool
    {
        $intent = InvoiceIntent::query()->with('billingInvoice')->findOrFail($intentId);
        $invoice = $intent->billingInvoice;
        $snapshot = $intent->commercial_snapshot ?? [];
        $billing = $snapshot['billing_identity'] ?? null;
        $sellerProfile = $snapshot['seller_tax_profile'] ?? null;
        $configurationSnapshot = $snapshot['billing_tax_configuration'] ?? null;
        $configurationVersionId = is_array($configurationSnapshot) ? ($configurationSnapshot['version_id'] ?? null) : null;
        $configurationVersion = is_int($configurationVersionId)
            ? BillingTaxConfigurationVersion::query()->find($configurationVersionId)
            : null;
        $decision = $snapshot['approved_tax_decision'] ?? null;
        if (! $invoice instanceof BillingInvoice || ! is_array($billing) || ! is_array($sellerProfile)
            || ! is_array($configurationSnapshot) || ! $configurationVersion || ! is_array($decision) || ! is_array($sellerProfile['seller_address'] ?? null)) {
            $this->workflow->recordDryRunResult($intent->id, ['errors' => ['frozen_snapshot_incomplete']], false);

            return false;
        }

        $sellerAddress = $sellerProfile['seller_address'];
        $buyerAddress = trim(implode(', ', array_filter([
            $billing['address_line1'] ?? null,
            $billing['address_line2'] ?? null,
        ], fn ($value) => is_string($value) && trim($value) !== '')));
        $paidAt = $invoice->paid_at ?? $invoice->finalized_at ?? $invoice->stripe_created_at;
        $paymentDate = $paidAt?->toDateString();
        $issueDate = now()->toDateString();
        $settings = $configurationVersion->configuration;
        $dueDays = match ($settings['payment_deadline_policy'] ?? null) {
            'days_8' => 8, 'days_15' => 15, 'days_30' => 30, default => 0,
        };
        $paymentMethod = SzamlazzPaymentMethodRegistry::label(
            is_string($settings['default_payment_method'] ?? null) ? $settings['default_payment_method'] : 'bank_transfer',
            is_string($settings['invoice_language'] ?? null) ? $settings['invoice_language'] : 'hu',
        );
        $line = $decision['line'] ?? null;
        if (! is_array($line) || ! is_int($line['net'] ?? null) || ! is_int($line['tax'] ?? null)
            || ! is_int($line['gross'] ?? null) || ! is_string($decision['reference'] ?? null)
            || ! is_int($decision['version'] ?? null) || ! $paymentMethod) {
            $this->workflow->recordDryRunResult($intent->id, ['errors' => ['approved_decision_incomplete']], false);

            return false;
        }
        $language = is_string($settings['invoice_language'] ?? null) ? $settings['invoice_language'] : 'hu';
        $approved = new ApprovedTaxDecision(
            $decision['reference'],
            $decision['version'],
            $line,
            $language,
            null,
            null,
        );
        $request = new NormalizedInvoiceRequest(
            'wpcg-invoice-'.$intent->id,
            strtoupper((string) $intent->currency),
            (int) $intent->amount,
            $issueDate,
            ($settings['fulfillment_date_policy'] ?? null) === 'invoice_date' ? $issueDate : ($paymentDate ?? $issueDate),
            Carbon::parse($issueDate)->addDays($dueDays)->toDateString(),
            $paymentDate,
            $paymentMethod,
            [
                'name' => (string) ($sellerProfile['legal_name'] ?? ''),
                'country' => (string) ($sellerProfile['establishment_country'] ?? ''),
                'postal_code' => (string) ($sellerAddress['postal_code'] ?? ''),
                'city' => (string) ($sellerAddress['city'] ?? ''),
                'address' => trim(implode(', ', array_filter([
                    $sellerAddress['address_line1'] ?? null,
                    $sellerAddress['address_line2'] ?? null,
                ], fn ($value) => is_string($value) && trim($value) !== ''))),
            ],
            [
                'name' => (string) (($billing['company_name'] ?? null) ?: ($billing['legal_name'] ?? '')),
                'country' => (string) ($billing['country_code'] ?? ''),
                'postal_code' => (string) ($billing['postal_code'] ?? ''),
                'city' => (string) ($billing['city'] ?? ''),
                'address' => $buyerAddress,
            ],
            'WP CareGrid subscription',
            $approved,
            $settings,
        );

        $result = app(SzamlazzDryRunProvider::class)->validate($request);
        $this->workflow->recordDryRunResult($intent->id, ['errors' => $result->errors], $result->valid);

        return $result->valid;
    }
}
