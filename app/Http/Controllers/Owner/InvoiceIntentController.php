<?php

namespace App\Http\Controllers\Owner;

use App\Billing\SzamlazzBillingSettings;
use App\Http\Controllers\Controller;
use App\Invoicing\InvoiceIntentStatus;
use App\Invoicing\InvoiceIssuanceWorkflow;
use App\Invoicing\SzamlazzTaxCodeRegistry;
use App\Models\InvoiceIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class InvoiceIntentController extends Controller
{
    public function index(Request $request): View
    {
        $reviewQueue = $request->boolean('tax_review');
        $query = InvoiceIntent::query()->with(['customer', 'subscription', 'billingInvoice']);
        if ($reviewQueue) {
            $query->where('status', InvoiceIntentStatus::BlockedTaxReview->value);
        }

        return view('owner.billing.invoice-intents', [
            'intents' => $query->latest('id')->paginate(25)->withQueryString(),
            'reviewQueue' => $reviewQueue,
        ]);
    }

    public function show(InvoiceIntent $invoiceIntent, SzamlazzBillingSettings $billingSettings): View
    {
        $invoiceIntent->load(['customer', 'subscription', 'billingInvoice', 'events', 'taxReviews.actor', 'taxReviews.configurationVersion', 'issuanceAttempt']);
        $snapshot = $invoiceIntent->commercial_snapshot ?? [];
        $identity = $snapshot['billing_identity'] ?? [];
        $taxId = $identity['tax_identifier'] ?? null;
        $maskedTaxId = is_string($taxId) && $taxId !== ''
            ? mb_substr($taxId, 0, 2).'••••'.mb_substr($taxId, -2)
            : null;

        return view('owner.billing.invoice-intent', [
            'intent' => $invoiceIntent,
            'billingIdentitySnapshot' => $identity,
            'maskedTaxIdentifier' => $maskedTaxId,
            'sellerProfileSnapshot' => $snapshot['seller_tax_profile'] ?? [],
            'taxConfigurationSnapshot' => $snapshot['billing_tax_configuration'] ?? [],
            'providerReadiness' => [
                'provider_enabled' => __('owner.invoice_intents.gates.provider_enabled').': '.((bool) config('szamlazz.provider_enabled') ? __('owner.settings.billing_tax.enabled') : __('owner.settings.billing_tax.disabled')),
                'dry_run' => __('owner.invoice_intents.gates.dry_run').': '.((bool) config('szamlazz.dry_run') ? __('owner.settings.billing_tax.enabled') : __('owner.settings.billing_tax.disabled')),
                'issuance_enabled' => __('owner.invoice_intents.gates.issuance_enabled').': '.((bool) config('szamlazz.issuance_enabled') ? __('owner.settings.billing_tax.enabled') : __('owner.settings.billing_tax.disabled')),
            ],
            'taxMode' => $snapshot['billing_tax_configuration']['tax_configuration_mode'] ?? $billingSettings->values()['tax_configuration_mode'],
            'taxCodeOptions' => SzamlazzTaxCodeRegistry::all(),
            'canProposeTaxReview' => $invoiceIntent->status === InvoiceIntentStatus::BlockedTaxReview
                && $invoiceIntent->billing_tax_configuration_version_id !== null
                && ($invoiceIntent->commercial_snapshot['billing_tax_configuration']['tax_configuration_mode'] ?? null) === 'manual_review',
        ]);
    }

    public function reconcile(InvoiceIntent $invoiceIntent, InvoiceIssuanceWorkflow $workflow, Request $request): RedirectResponse
    {
        $attempt = $invoiceIntent->issuanceAttempt;
        abort_unless($attempt !== null, 404);
        $workflow->reconcile($attempt->id, $request->user('owner'));

        return back()->with('status', __('owner.invoice_intents.reconciliation_checked'));
    }
}
