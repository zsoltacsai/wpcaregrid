<x-layouts.owner :title="__('owner.invoice_intents.detail_title', ['id' => $intent->id])">
    <x-operator.page-header :title="__('owner.invoice_intents.detail_title', ['id' => $intent->id])" :lead="__('owner.invoice_intents.dry_run_notice')" />
    @if ($intent->status === \App\Invoicing\InvoiceIntentStatus::BlockedTaxReview)
        <p class="mb-5 text-sm text-warning-ink">{{ __('owner.invoice_intents.proposal_not_approval') }}</p>
    @endif
    @if ($canRefreshTaxSnapshot)
        <section class="mb-5 rounded-md border border-line bg-surface p-4">
            <p class="text-sm text-muted">{{ __('owner.invoice_intents.refresh_explainer') }}</p>
            <form method="POST" action="{{ route('owner.billing.invoice-intents.refresh-tax-snapshot', $intent) }}" class="mt-3">
                @csrf
                <button class="h-10 rounded-sm border border-line-strong px-4 text-sm font-semibold text-primary">{{ __('owner.invoice_intents.refresh_snapshot') }}</button>
            </form>
        </section>
    @endif
    @if ($canRunLocalDryRun)
        <section class="mb-5 rounded-md border border-line bg-surface p-4">
            <p class="text-sm text-muted">{{ __('owner.invoice_intents.local_validation_explainer') }}</p>
            <form method="POST" action="{{ route('owner.billing.invoice-intents.local-dry-run', $intent) }}" class="mt-3">@csrf<button class="h-10 rounded-sm border border-line-strong px-4 text-sm font-semibold text-primary">{{ __('owner.invoice_intents.run_local_validation') }}</button></form>
        </section>
    @endif
    <div class="mb-5"><a class="text-sm underline" href="{{ route('owner.billing.invoice-intents.index') }}">{{ __('owner.invoice_intents.back') }}</a></div>
    <dl class="grid gap-3 rounded-md border border-line bg-surface p-5 sm:grid-cols-2">
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.customer') }}</dt><dd>{{ $intent->customer?->email ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.subscription') }}</dt><dd>{{ $intent->subscription?->tierLabel() ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.stripe_invoice') }}</dt><dd class="font-mono">{{ $intent->stripe_invoice_id }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.amount') }}</dt><dd>{{ $intent->billingInvoice?->money('total')?->format() ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.provider') }}</dt><dd>{{ $intent->provider }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.status') }}</dt><dd>{{ $intent->status->label() }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.blocked_reason') }}</dt><dd>{{ $intent->blocked_reason ? __('owner.invoice_intents.reasons.'.$intent->blocked_reason) : '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.identity_snapshot') }}</dt><dd>{{ isset($billingIdentitySnapshot['id']) ? __('owner.invoice_intents.snapshot_present') : __('owner.invoice_intents.snapshot_missing') }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.dry_run_result') }}</dt><dd>{{ $intent->dry_run_result['validation'] ?? __('owner.invoice_intents.not_run') }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.retry_count') }}</dt><dd>{{ $intent->retry_count }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.error_category') }}</dt><dd>{{ $intent->last_error_category ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.created') }}</dt><dd>@displayDateTime($intent->created_at)</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.updated') }}</dt><dd>@displayDateTime($intent->updated_at)</dd></div>
    </dl>
    <h2 class="mb-3 mt-7 text-lg font-semibold">{{ __('owner.invoice_intents.billing_snapshot') }}</h2>
    <dl class="grid gap-3 rounded-md border border-line bg-surface p-5 sm:grid-cols-2">
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.billing_name') }}</dt><dd>{{ $billingIdentitySnapshot['legal_name'] ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.company') }}</dt><dd>{{ $billingIdentitySnapshot['company_name'] ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.billing_country') }}</dt><dd>{{ $billingIdentitySnapshot['country_code'] ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.customer_type') }}</dt><dd>{{ isset($billingIdentitySnapshot['customer_type']) ? __('owner.customers.types.'.$billingIdentitySnapshot['customer_type']) : '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.tax_identifier') }}</dt><dd>{{ $maskedTaxIdentifier ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.seller_profile') }}</dt><dd>{{ $sellerProfileSnapshot['legal_name'] ?? '—' }} @if(isset($sellerProfileSnapshot['version'])) · v{{ $sellerProfileSnapshot['version'] }} @endif</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.billing_tax_configuration') }}</dt><dd>{{ isset($taxConfigurationSnapshot['version']) ? 'v'.$taxConfigurationSnapshot['version'] : '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.proposed_provider_tax_code') }}</dt><dd>{{ $intent->taxReviews->last()?->provider_tax_code ?? '—' }}</dd></div>
    </dl>
    <p class="mt-2 text-xs text-muted">{{ __('owner.invoice_intents.provider_readiness', $providerReadiness) }}</p>
    @if ($intent->issuanceAttempt)
        <section class="mt-5 rounded-md border border-line bg-surface p-5">
            <h2 class="text-base font-semibold">{{ __('owner.invoice_intents.provider_attempt') }}</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.attempt_status') }}</dt><dd>{{ __('owner.invoice_intents.attempt_statuses.'.$intent->issuanceAttempt->status) }}</dd></div>
                <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.external_reference') }}</dt><dd class="font-mono">{{ $intent->issuanceAttempt->external_reference }}</dd></div>
                <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.provider_invoice_number') }}</dt><dd>{{ $intent->issuanceAttempt->provider_invoice_id ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.provider_error_category') }}</dt><dd>{{ $intent->issuanceAttempt->error_category ?? '—' }}</dd></div>
            </dl>
            @if (in_array($intent->issuanceAttempt->status, ['submission_started', 'reconciliation_required'], true))
                <form method="POST" action="{{ route('owner.billing.invoice-intents.reconcile', $intent) }}" class="mt-4">@csrf<button class="h-10 rounded-sm border border-line-strong px-4 text-sm font-semibold text-primary">{{ __('owner.invoice_intents.reconcile') }}</button></form>
            @endif
        </section>
    @endif

    @if ($canProposeTaxReview)
        <section class="mt-6 rounded-md border border-line bg-surface p-5">
            <h2 class="text-base font-semibold">{{ __('owner.invoice_intents.propose_tax_review') }}</h2>
            <p class="mt-1 text-sm text-warning-ink">{{ __('owner.invoice_intents.proposal_not_approval') }}</p>
            <form method="POST" action="{{ route('owner.billing.invoice-intents.tax-review', $intent) }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm font-medium">{{ __('owner.invoice_intents.provider_tax_code') }}
                        <select name="provider_tax_code" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                            <option value="">—</option>
                            @foreach ($taxCodeOptions as $code => $description)<option value="{{ $code }}">{{ $code }} — {{ $description }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-medium">{{ __('owner.invoice_intents.proposed_treatment') }}
                        <select name="proposed_treatment" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                            @foreach (\App\Invoicing\InvoiceIntentTaxReviewService::TREATMENTS as $treatment)<option value="{{ $treatment }}">{{ __('owner.invoice_intents.treatments.'.$treatment) }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <label class="block text-sm font-medium">{{ __('owner.invoice_intents.justification') }}
                    <textarea name="justification" required minlength="10" maxlength="2000" rows="4" class="mt-1 block w-full rounded-sm border border-line-strong bg-surface p-3 text-sm">{{ old('justification') }}</textarea>
                </label>
                <label class="block text-sm font-medium">{{ __('owner.invoice_intents.basis_reference') }}
                    <input name="basis_reference" maxlength="160" value="{{ old('basis_reference') }}" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                </label>
                <button class="h-10 rounded-sm border border-line-strong px-4 text-sm font-semibold text-primary">{{ __('owner.invoice_intents.save_proposal') }}</button>
            </form>
        </section>
    @endif
    <h2 class="mb-3 mt-7 text-lg font-semibold">{{ __('owner.invoice_intents.tax_review_history') }}</h2>
    <ol class="space-y-3">
        @forelse ($intent->taxReviews as $review)
            <li class="rounded-md border border-line bg-surface p-4">
                <p class="font-medium">{{ $review->provider_tax_code }} · {{ __('owner.invoice_intents.treatments.'.$review->proposed_treatment) }} · {{ __('owner.invoice_intents.review_statuses.'.$review->status) }}</p>
                <p class="mt-1 text-xs text-muted">@displayDateTime($review->created_at, 'Y-m-d H:i:s T') · {{ $review->actor?->email ?? '—' }} · {{ __('owner.invoice_intents.configuration_version') }} {{ $review->configurationVersion?->version ?? '—' }}</p>
                <p class="mt-2 text-sm">{{ $review->justification }}</p>
                @if($review->basis_reference)<p class="mt-1 text-xs text-muted">{{ __('owner.invoice_intents.basis_reference') }}: {{ $review->basis_reference }}</p>@endif
                @if ($canApproveTaxReview && $review->status === 'proposed_pending_accountant')
                    <form method="POST" action="{{ route('owner.billing.invoice-intents.tax-review.approve', [$intent, $review]) }}" class="mt-4 space-y-3 border-t border-line pt-4">
                        @csrf
                        <p class="text-sm text-warning-ink">{{ __('owner.invoice_intents.approval_explainer') }}</p>
                        <label class="block text-sm font-medium">{{ __('owner.invoice_intents.approval_authority') }}
                            <select name="approval_authority" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm"><option value="accountant">{{ __('owner.invoice_intents.accountant') }}</option><option value="tax_counsel">{{ __('owner.invoice_intents.tax_counsel') }}</option></select>
                        </label>
                        <label class="block text-sm font-medium">{{ __('owner.invoice_intents.approval_reference') }}
                            <input name="approval_reference" maxlength="200" required autocomplete="off" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                        </label>
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm_approved_decision" value="1" required class="mt-1">{{ __('owner.invoice_intents.confirm_approved_decision') }}</label>
                        <button class="h-10 rounded-sm border border-line-strong px-4 text-sm font-semibold text-primary">{{ __('owner.invoice_intents.record_approval') }}</button>
                    </form>
                @endif
            </li>
        @empty
            <li class="rounded-md border border-line bg-surface p-4 text-sm text-muted">{{ __('owner.invoice_intents.no_tax_reviews') }}</li>
        @endforelse
    </ol>
    <h2 class="mb-3 mt-7 text-lg font-semibold">{{ __('owner.invoice_intents.history') }}</h2>
    <ol class="space-y-3">
        @foreach ($intent->events as $event)
            <li class="rounded-md border border-line bg-surface p-4">
                <p class="font-medium">{{ __('owner.invoice_intents.events.'.$event->event_type) }}</p>
                <p class="mt-1 text-xs text-muted">@displayDateTime($event->created_at, 'Y-m-d H:i:s T') · {{ $event->from_status ?? '—' }} → {{ $event->to_status ?? '—' }}</p>
            </li>
        @endforeach
    </ol>
</x-layouts.owner>
