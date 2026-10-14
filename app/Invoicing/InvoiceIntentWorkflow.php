<?php

namespace App\Invoicing;

use App\Billing\SzamlazzBillingSettings;
use App\Jobs\Invoicing\PrepareInvoiceIntentJob;
use App\Models\BillingInvoice;
use App\Models\CustomerBillingIdentity;
use App\Models\InvoiceIntent;
use App\Models\InvoiceIntentEvent;
use App\Models\InvoiceIntentFollowUpMarker;
use App\Models\SellerTaxProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Owner\AdminAudit;
use App\Tax\SellerTaxProfileCompleteness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class InvoiceIntentWorkflow
{
    /**
     * Refresh only the seller/tax configuration portion of a blocked paid-invoice
     * snapshot after the Owner has corrected those records. Buyer and Stripe
     * invoice facts remain the original immutable snapshot.
     */
    public function refreshSellerAndTaxSnapshot(int $intentId, User $actor): InvoiceIntent
    {
        return DB::transaction(function () use ($intentId, $actor): InvoiceIntent {
            $intent = InvoiceIntent::query()->lockForUpdate()->findOrFail($intentId);
            if (! in_array($intent->status, [InvoiceIntentStatus::BlockedSellerProfile, InvoiceIntentStatus::BlockedTaxReview], true)
                || $intent->issuanceAttempt()->exists()
                || $intent->taxReviews()->exists()
                || $intent->tax_decision_reference !== null
                || $intent->tax_decision_version !== null
                || $intent->dry_run_validated_at !== null
                || $intent->refund_review_required_at !== null) {
                throw new \DomainException('This invoice intent is not eligible for a seller/tax snapshot refresh.');
            }

            $invoice = BillingInvoice::query()->find($intent->billing_invoice_id);
            if (! $invoice || $invoice->invoice_status !== 'paid' || $invoice->stripe_invoice_id !== $intent->stripe_invoice_id) {
                throw new \DomainException('Only the matching paid Stripe invoice can be refreshed.');
            }

            $effectiveAt = $invoice->paid_at ?? $invoice->finalized_at ?? $invoice->stripe_created_at;
            $configuration = app(SzamlazzBillingSettings::class)->current($effectiveAt);
            $configuredSellerId = $configuration?->configuration['seller_profile_id'] ?? null;
            $sellerProfile = $configuredSellerId
                ? SellerTaxProfile::query()->find($configuredSellerId)
                : $this->sellerProfileAt($effectiveAt);

            $snapshot = $intent->commercial_snapshot ?? [];
            $previousConfigurationVersion = $snapshot['billing_tax_configuration']['version'] ?? null;
            $previousSellerVersion = $snapshot['seller_tax_profile']['version'] ?? null;

            unset($snapshot['seller_tax_profile'], $snapshot['billing_tax_configuration']);
            if ($configuration) {
                $intent->billing_tax_configuration_version_id = $configuration->id;
                $snapshot['billing_tax_configuration'] = [
                    'version_id' => $configuration->id,
                    'version' => $configuration->version,
                    'effective_at' => $configuration->effective_at?->toIso8601String(),
                    'tax_configuration_mode' => $configuration->configuration['tax_configuration_mode'] ?? null,
                    'default_tax_code' => $configuration->configuration['default_tax_code'] ?? null,
                    'selected_seller_profile_id' => $configuredSellerId,
                ];
            } else {
                $intent->billing_tax_configuration_version_id = null;
            }

            if ($sellerProfile) {
                $snapshot['seller_tax_profile'] = [
                    'id' => $sellerProfile->id,
                    'version' => $sellerProfile->version,
                    'legal_name' => $sellerProfile->legal_name,
                    'establishment_country' => $sellerProfile->establishment_country,
                    'seller_address' => $sellerProfile->seller_address,
                    'aam_status' => $sellerProfile->aam_status?->value,
                    'verification_status' => $sellerProfile->verification_status?->value,
                    'effective_from' => $sellerProfile->effective_from?->toDateString(),
                    'effective_to' => $sellerProfile->effective_to?->toDateString(),
                    'evidence_reference' => $sellerProfile->evidence_reference,
                    'aam_evidence_reference' => $sellerProfile->aam_evidence_reference,
                ];
                $intent->seller_tax_profile_id = $sellerProfile->id;
            } else {
                $intent->seller_tax_profile_id = null;
            }

            $from = $intent->status;
            $identity = $snapshot['billing_identity'] ?? null;
            $identityRecord = $intent->billing_identity_id ? CustomerBillingIdentity::query()->find($intent->billing_identity_id) : null;
            $identityValid = is_array($identity)
                && (int) ($identity['id'] ?? 0) === (int) $intent->billing_identity_id
                && $identityRecord !== null
                && (int) $identityRecord->customer_id === (int) $intent->customer_id;
            if (! $identityValid) {
                $intent->status = InvoiceIntentStatus::BlockedIdentity;
                $intent->blocked_reason = 'billing_identity_missing';
            } elseif (! $sellerProfile) {
                $intent->status = InvoiceIntentStatus::BlockedSellerProfile;
                $intent->blocked_reason = 'seller_profile_missing';
            } elseif (! app(SellerTaxProfileCompleteness::class)->isComplete($sellerProfile)) {
                $intent->status = InvoiceIntentStatus::BlockedSellerProfile;
                $intent->blocked_reason = 'seller_profile_incomplete';
            } elseif ($sellerProfile->verification_status?->value !== 'verified') {
                $intent->status = InvoiceIntentStatus::BlockedSellerProfile;
                $intent->blocked_reason = 'seller_profile_unverified';
            } else {
                $intent->status = InvoiceIntentStatus::BlockedTaxReview;
                $intent->blocked_reason = 'approved_tax_decision_missing';
            }

            $intent->commercial_snapshot = $snapshot;
            $intent->save();

            InvoiceIntentEvent::query()->create([
                'invoice_intent_id' => $intent->id,
                'event_type' => 'intent_seller_tax_snapshot_refreshed',
                'from_status' => $from->value,
                'to_status' => $intent->status->value,
                'actor_user_id' => $actor->id,
                'metadata' => [
                    'configuration_version' => $configuration?->version,
                    'seller_profile_version' => $sellerProfile?->version,
                    'previous_configuration_version' => $previousConfigurationVersion,
                    'previous_seller_profile_version' => $previousSellerVersion,
                    'result_reason' => $intent->blocked_reason,
                    'provider_called' => false,
                ],
                'created_at' => now(),
            ]);
            AdminAudit::record($actor, 'billing_tax.intent_snapshot_refreshed', 'invoice_intent', (string) $intent->id, [
                'configuration_version' => $configuration?->version,
                'seller_profile_version' => $sellerProfile?->version,
                'result_status' => $intent->status->value,
                'provider_called' => false,
            ]);

            return $intent;
        });
    }

    public function recordPaidInvoice(BillingInvoice $invoice, ?string $stripeEventId): ?InvoiceIntent
    {
        if ($invoice->invoice_status !== 'paid' || ! is_string($invoice->stripe_invoice_id) || $invoice->stripe_invoice_id === '') {
            return null;
        }

        return DB::transaction(fn () => $this->createForPaidInvoice($invoice, $stripeEventId));
    }

    private function createForPaidInvoice(BillingInvoice $invoice, ?string $stripeEventId): ?InvoiceIntent
    {
        // Serialize on the persisted billing row. Locking an absent intent key
        // alone can acquire competing InnoDB gap locks before concurrent inserts.
        $invoice = BillingInvoice::query()->lockForUpdate()->find($invoice->id);
        if (! $invoice || $invoice->invoice_status !== 'paid' || ! $invoice->stripe_invoice_id) {
            return null;
        }

        $existing = InvoiceIntent::query()
            ->where('stripe_invoice_id', $invoice->stripe_invoice_id)
            ->where('purpose', 'subscription_invoice')
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        $key = 'szamlazz:stripe-invoice:'.hash('sha256', $invoice->stripe_invoice_id).':subscription_invoice';
        try {
            $intent = InvoiceIntent::create([
                'customer_id' => $invoice->customer_id,
                'subscription_id' => $invoice->subscription_id,
                'billing_invoice_id' => $invoice->id,
                'stripe_invoice_id' => $invoice->stripe_invoice_id,
                'stripe_event_id' => $stripeEventId,
                'provider' => 'szamlazz_hu',
                'purpose' => 'subscription_invoice',
                'status' => InvoiceIntentStatus::Pending,
                'amount' => $invoice->total,
                'currency' => $invoice->currency,
                'idempotency_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'invoice_intents_stripe_invoice_purpose_unique')
                && ! str_contains($exception->getMessage(), 'invoice_intents.idempotency_key')) {
                throw $exception;
            }

            // Current locking read sees a row committed by a competing webhook transaction.
            return InvoiceIntent::query()
                ->where('stripe_invoice_id', $invoice->stripe_invoice_id)
                ->where('purpose', 'subscription_invoice')
                ->lockForUpdate()
                ->first() ?? throw $exception;
        }

        $this->event($intent, 'intent_created', null, InvoiceIntentStatus::Pending, $stripeEventId);
        if ($invoice->stripe_payment_intent_id && InvoiceIntentFollowUpMarker::query()
            ->where('stripe_payment_intent_id', $invoice->stripe_payment_intent_id)
            ->where('reason', 'refund_review_required')->exists()) {
            $intent->forceFill([
                'status' => InvoiceIntentStatus::ReconciliationRequired,
                'blocked_reason' => 'refund_review_required',
                'refund_review_required_at' => now(),
            ])->save();
            $this->event($intent, 'refund_review_required', InvoiceIntentStatus::Pending, InvoiceIntentStatus::ReconciliationRequired, $stripeEventId, ['automatic_correction' => false]);
        }
        PrepareInvoiceIntentJob::dispatch($intent->id)->afterCommit();

        return $intent;
    }

    public function prepare(int $intentId): void
    {
        DB::transaction(function () use ($intentId) {
            $intent = InvoiceIntent::query()->lockForUpdate()->findOrFail($intentId);
            if ($intent->status !== InvoiceIntentStatus::Pending) {
                return;
            }

            $invoice = BillingInvoice::query()->find($intent->billing_invoice_id);
            $subscription = $intent->subscription_id ? Subscription::query()->find($intent->subscription_id) : null;
            $identityId = $intent->billing_identity_id ?? $invoice?->billing_identity_id ?? $subscription?->billing_identity_id;
            $identity = $identityId ? CustomerBillingIdentity::query()->find($identityId) : null;
            $identityMatchesCustomer = $identity !== null
                && $intent->customer_id !== null
                && (int) $identity->customer_id === (int) $intent->customer_id;
            $taxConfiguration = app(SzamlazzBillingSettings::class)->current($invoice?->paid_at ?? $invoice?->finalized_at ?? $invoice?->stripe_created_at);
            $configuredSellerId = $taxConfiguration?->configuration['seller_profile_id'] ?? null;
            $sellerProfile = $taxConfiguration
                ? ($configuredSellerId ? SellerTaxProfile::query()->find($configuredSellerId) : null)
                : $this->sellerProfileAt($invoice?->paid_at ?? $invoice?->finalized_at ?? $invoice?->stripe_created_at);

            $snapshot = $intent->commercial_snapshot ?? [];
            if ($taxConfiguration) {
                $intent->billing_tax_configuration_version_id = $taxConfiguration->id;
                $snapshot['billing_tax_configuration'] = [
                    'version_id' => $taxConfiguration->id,
                    'version' => $taxConfiguration->version,
                    'effective_at' => $taxConfiguration->effective_at?->toIso8601String(),
                    'tax_configuration_mode' => $taxConfiguration->configuration['tax_configuration_mode'] ?? null,
                    'default_tax_code' => $taxConfiguration->configuration['default_tax_code'] ?? null,
                    'selected_seller_profile_id' => $configuredSellerId,
                ];
            }
            $snapshot['stripe_invoice'] = [
                'id' => $intent->stripe_invoice_id,
                'status' => $invoice?->invoice_status,
                'subtotal' => $invoice?->subtotal,
                'discount_total' => $invoice?->discount_total,
                'tax_total' => $invoice?->tax_total,
                'total' => $invoice?->total,
                'amount_paid' => $invoice?->amount_paid,
                'currency' => $invoice?->currency,
                'billing_reason' => $invoice?->billing_reason,
                'paid_at' => $invoice?->paid_at?->toIso8601String(),
            ];
            if ($identityMatchesCustomer) {
                $snapshot['billing_identity'] = [
                    'id' => $identity->id,
                    'version' => $identity->version,
                    'customer_type' => $identity->customer_type,
                    'legal_name' => $identity->legal_name,
                    'company_name' => $identity->company_name,
                    'country_code' => $identity->country_code,
                    'address_line1' => $identity->address_line1,
                    'address_line2' => $identity->address_line2,
                    'postal_code' => $identity->postal_code,
                    'city' => $identity->city,
                    'region' => $identity->region,
                    'tax_identifier' => $identity->tax_identifier,
                    'declared_business_status' => $identity->declared_business_status,
                ];
                $intent->billing_identity_id = $identity->id;
            }
            if ($sellerProfile) {
                $snapshot['seller_tax_profile'] = [
                    'id' => $sellerProfile->id,
                    'version' => $sellerProfile->version,
                    'legal_name' => $sellerProfile->legal_name,
                    'establishment_country' => $sellerProfile->establishment_country,
                    'seller_address' => $sellerProfile->seller_address,
                    'aam_status' => $sellerProfile->aam_status?->value,
                    'verification_status' => $sellerProfile->verification_status?->value,
                    'effective_from' => $sellerProfile->effective_from?->toDateString(),
                    'effective_to' => $sellerProfile->effective_to?->toDateString(),
                    'evidence_reference' => $sellerProfile->evidence_reference,
                    'aam_evidence_reference' => $sellerProfile->aam_evidence_reference,
                ];
                $intent->seller_tax_profile_id = $sellerProfile->id;
            }
            if ($subscription) {
                $snapshot['subscription'] = [
                    'id' => $subscription->id,
                    'product_type' => $subscription->product_type?->value,
                    'plan' => $subscription->plan?->value,
                    'interval' => $subscription->interval?->value,
                    'currency' => $subscription->currency,
                ];
            }

            $intent->commercial_snapshot = $snapshot;
            $from = $intent->status;
            if (! $identity) {
                $intent->status = InvoiceIntentStatus::BlockedIdentity;
                $intent->blocked_reason = 'billing_identity_missing';
            } elseif (! $identityMatchesCustomer) {
                $intent->billing_identity_id = null;
                $intent->status = InvoiceIntentStatus::BlockedIdentity;
                $intent->blocked_reason = 'billing_identity_customer_mismatch';
            } elseif (! $sellerProfile) {
                $intent->status = InvoiceIntentStatus::BlockedSellerProfile;
                $intent->blocked_reason = 'seller_profile_missing';
            } elseif (! app(SellerTaxProfileCompleteness::class)->isComplete($sellerProfile)) {
                $intent->status = InvoiceIntentStatus::BlockedSellerProfile;
                $intent->blocked_reason = 'seller_profile_incomplete';
            } elseif ($sellerProfile->verification_status?->value !== 'verified') {
                $intent->status = InvoiceIntentStatus::BlockedSellerProfile;
                $intent->blocked_reason = 'seller_profile_unverified';
            } else {
                // Phase 1 has no approved executable TaxDecision model. VIES and country are evidence only.
                $intent->status = InvoiceIntentStatus::BlockedTaxReview;
                $intent->blocked_reason = 'approved_tax_decision_missing';
            }
            $intent->save();
            $this->event($intent, 'intent_prepared', $from, $intent->status, $intent->stripe_event_id, ['reason' => $intent->blocked_reason]);
        });
    }

    public function markRefundReviewRequired(string $paymentIntentId, ?string $stripeEventId): void
    {
        DB::transaction(function () use ($paymentIntentId, $stripeEventId) {
            if ($stripeEventId) {
                InvoiceIntentFollowUpMarker::query()->firstOrCreate([
                    'stripe_event_id' => $stripeEventId,
                ], [
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'reason' => 'refund_review_required',
                    'created_at' => now(),
                ]);
            }
            $invoice = BillingInvoice::query()->where('stripe_payment_intent_id', $paymentIntentId)->lockForUpdate()->first();
            if (! $invoice) {
                return;
            }
            $intent = InvoiceIntent::query()->where('billing_invoice_id', $invoice->id)->lockForUpdate()->first();
            if (! $intent || $intent->refund_review_required_at) {
                return;
            }
            $from = $intent->status;
            $intent->refund_review_required_at = now();
            $intent->status = InvoiceIntentStatus::ReconciliationRequired;
            $intent->blocked_reason = 'refund_review_required';
            $intent->save();
            $this->event($intent, 'refund_review_required', $from, $intent->status, $stripeEventId, ['automatic_correction' => false]);
        });
    }

    public function recordDryRunResult(int $intentId, array $result, bool $passed): void
    {
        DB::transaction(function () use ($intentId, $result, $passed) {
            $intent = InvoiceIntent::query()->lockForUpdate()->findOrFail($intentId);
            if ($intent->status !== InvoiceIntentStatus::DryRunValidating) {
                return;
            }
            $from = $intent->status;
            $intent->status = $passed ? InvoiceIntentStatus::DryRunValidated : InvoiceIntentStatus::DryRunFailed;
            $intent->blocked_reason = $passed ? null : 'local_validation_failed';
            $intent->dry_run_result = ['validation' => $passed ? 'LOCAL_VALIDATION_PASSED' : 'LOCAL_VALIDATION_FAILED', 'errors' => array_values($result['errors'] ?? [])];
            $intent->dry_run_validated_at = $passed ? now() : null;
            $intent->last_error_category = $passed ? null : 'local_validation_failed';
            $intent->save();
            $this->event($intent, $passed ? 'dry_run_passed' : 'dry_run_failed', $from, $intent->status);
        });
    }

    public function startDryRun(int $intentId): bool
    {
        return DB::transaction(function () use ($intentId): bool {
            $intent = InvoiceIntent::query()->lockForUpdate()->findOrFail($intentId);
            if ($intent->status === InvoiceIntentStatus::DryRunValidating) {
                return true;
            }
            if ($intent->status !== InvoiceIntentStatus::ReadyForDryRun) {
                return false;
            }

            $intent->status = InvoiceIntentStatus::DryRunValidating;
            $intent->save();
            $this->event($intent, 'dry_run_started', InvoiceIntentStatus::ReadyForDryRun, InvoiceIntentStatus::DryRunValidating);

            return true;
        });
    }

    public function markPreparationFailed(int $intentId): void
    {
        DB::transaction(function () use ($intentId): void {
            $intent = InvoiceIntent::query()->lockForUpdate()->find($intentId);
            if (! $intent || $intent->status !== InvoiceIntentStatus::Pending) {
                return;
            }

            $intent->status = InvoiceIntentStatus::Failed;
            $intent->blocked_reason = 'preparation_failed';
            $intent->last_error_category = 'preparation_failed';
            $intent->retry_count++;
            $intent->save();
            $this->event($intent, 'preparation_failed', InvoiceIntentStatus::Pending, InvoiceIntentStatus::Failed, metadata: ['error_category' => 'preparation_failed']);
        });
    }

    public function markDryRunFailed(int $intentId): void
    {
        DB::transaction(function () use ($intentId): void {
            $intent = InvoiceIntent::query()->lockForUpdate()->find($intentId);
            if (! $intent || $intent->status !== InvoiceIntentStatus::DryRunValidating) {
                return;
            }

            $intent->status = InvoiceIntentStatus::DryRunFailed;
            $intent->blocked_reason = 'local_validation_failed';
            $intent->last_error_category = 'local_dry_run_failed';
            $intent->retry_count++;
            $intent->save();
            $this->event($intent, 'dry_run_failed', InvoiceIntentStatus::DryRunValidating, InvoiceIntentStatus::DryRunFailed, metadata: ['error_category' => 'local_dry_run_failed']);
        });
    }

    public function preventProcessing(int $intentId, string $reason = 'issuance_disabled'): void
    {
        DB::transaction(function () use ($intentId, $reason) {
            $intent = InvoiceIntent::query()->lockForUpdate()->findOrFail($intentId);
            $from = $intent->status;
            if ($from === InvoiceIntentStatus::Issued) {
                return;
            }
            $intent->status = InvoiceIntentStatus::IssuanceDisabled;
            $intent->blocked_reason = $reason;
            $intent->save();
            $this->event($intent, 'configuration_prevented_processing', $from, $intent->status, metadata: ['reason' => $reason]);
        });
    }

    private function sellerProfileAt(mixed $at): ?SellerTaxProfile
    {
        $date = $at ? Carbon::parse($at)->toDateString() : now()->toDateString();

        return SellerTaxProfile::query()
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('version')
            ->first();
    }

    private function event(InvoiceIntent $intent, string $type, ?InvoiceIntentStatus $from, InvoiceIntentStatus $to, ?string $stripeEventId = null, array $metadata = []): void
    {
        $query = InvoiceIntentEvent::query()
            ->where('invoice_intent_id', $intent->id)
            ->where('event_type', $type);
        if ($stripeEventId) {
            $query->where('stripe_event_id', $stripeEventId);
        }
        if ($query->exists()) {
            return;
        }
        InvoiceIntentEvent::create([
            'invoice_intent_id' => $intent->id,
            'event_type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'stripe_event_id' => $stripeEventId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
