<?php

namespace App\Invoicing;

enum InvoiceIntentStatus: string
{
    case Pending = 'pending';
    case BlockedIdentity = 'blocked_identity';
    case BlockedSellerProfile = 'blocked_seller_profile';
    case BlockedTaxReview = 'blocked_tax_review';
    case ReadyForDryRun = 'ready_for_dry_run';
    case DryRunValidating = 'dry_run_validating';
    case DryRunValidated = 'dry_run_validated';
    case DryRunFailed = 'dry_run_failed';
    case IssuanceDisabled = 'issuance_disabled';
    case ReconciliationRequired = 'reconciliation_required';
    case Issued = 'issued';
    case Failed = 'failed';

    public function label(): string
    {
        return __('owner.invoice_intents.statuses.'.$this->value);
    }
}
