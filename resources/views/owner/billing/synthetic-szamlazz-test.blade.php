<x-layouts.owner :title="__('owner.synthetic_invoice_test.title')">
    <x-operator.page-header :title="__('owner.synthetic_invoice_test.title')" :lead="__('owner.synthetic_invoice_test.lead')" />
    <div class="mb-5 rounded-md border border-warning/40 bg-warning-soft p-4 text-sm text-warning-ink">
        <p>{{ __('owner.synthetic_invoice_test.warning') }}</p>
        <p class="mt-2">{{ __('owner.synthetic_invoice_test.normal_gates', $normalIssuanceGates) }}</p>
        <p class="mt-2">{{ __('owner.synthetic_invoice_test.attestation_only') }}</p>
    </div>
    <dl class="mb-6 grid gap-3 rounded-md border border-line bg-surface p-4 text-sm sm:grid-cols-2">
        <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.stage_guard') }}</dt><dd>{{ $stageGuardPassed ? __('owner.settings.billing_tax.enabled') : __('owner.settings.billing_tax.disabled') }}</dd></div>
        <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.owner_credential') }}</dt><dd>{{ $ownerManagedCredential ? __('owner.integrations.owner_managed') : __('owner.integrations.not_configured') }}</dd></div>
    </dl>
    @if (session('status'))<p class="mb-4 rounded-md border border-positive/30 bg-positive-soft p-3 text-sm">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="mb-4 rounded-md border border-danger/30 bg-danger-soft p-3 text-sm" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="mb-6 space-y-4">
        <h2 class="text-lg font-semibold">{{ __('owner.synthetic_invoice_test.history_title') }}</h2>
        @forelse ($runs as $run)
            <article class="rounded-md border border-line bg-surface p-5">
                <h3 class="text-base font-semibold">{{ __('owner.synthetic_invoice_test.run_title', ['id' => $run->id]) }}</h3>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.prepared_at') }}</dt><dd>{{ $run->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') ?? '—' }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.status') }}</dt><dd>{{ $run->status }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.amount') }}</dt><dd>{{ $run->formattedAmount() }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.tax_code') }}</dt><dd>{{ $run->tax_code }} · {{ __('owner.synthetic_invoice_test.mechanical_only') }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.external_reference') }}</dt><dd class="font-mono">{{ $run->external_reference }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.provider_invoice') }}</dt><dd>{{ $run->provider_invoice_number ?? '—' }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.execution_slot_consumed') }}</dt><dd>{{ in_array($run->status, ['submission_started', 'reconciliation_required', 'issued', 'rejected'], true) ? __('owner.synthetic_invoice_test.yes') : __('owner.synthetic_invoice_test.no') }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.pdf') }}</dt><dd>{{ $run->pdf_sha256 ? __('owner.synthetic_invoice_test.pdf_stored', ['bytes' => $run->pdf_bytes, 'sha256' => $run->pdf_sha256]) : __('owner.synthetic_invoice_test.pdf_unavailable') }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.synthetic_invoice_test.error') }}</dt><dd>{{ $run->error_category ?? '—' }}</dd></div>
                </dl>
                @if ($run->status === 'prepared' && $stageGuardPassed && ($runErrors[$run->id] ?? []) === ['incorrect_amount_snapshot'])
                    <div class="mt-6 rounded-md border border-danger/30 bg-danger-soft p-3 text-sm" role="alert">{{ __('owner.synthetic_invoice_test.invalid_snapshot') }}</div>
                    <form method="POST" action="{{ route('owner.billing.synthetic-szamlazz-test.cancel', $run) }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="reason_code" value="invalid_amount_snapshot">
                        <label class="block text-sm">{{ __('owner.synthetic_invoice_test.cancel_phrase') }}<input name="confirmation_phrase" required autocomplete="off" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 sm:max-w-md"></label>
                        <button class="h-10 rounded-sm border border-danger px-4 text-sm font-semibold text-danger">{{ __('owner.synthetic_invoice_test.cancel_invalid_run') }}</button>
                    </form>
                @elseif ($run->status === 'prepared' && $stageGuardPassed)
                    <form method="POST" action="{{ route('owner.billing.synthetic-szamlazz-test.submit', $run) }}" class="mt-6 space-y-3">
                        @csrf
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm_submission" value="1" required class="mt-1">{{ __('owner.synthetic_invoice_test.confirm_test_submission') }}</label>
                        <label class="block text-sm">{{ __('owner.synthetic_invoice_test.phrase') }}<input name="confirmation_phrase" required autocomplete="off" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 sm:max-w-md"></label>
                        <button class="h-10 rounded-sm bg-danger px-4 text-sm font-semibold text-white">{{ __('owner.synthetic_invoice_test.submit_button') }}</button>
                    </form>
                @elseif (in_array($run->status, ['submission_started', 'reconciliation_required'], true) && $stageGuardPassed)
                    <form method="POST" action="{{ route('owner.billing.synthetic-szamlazz-test.reconcile', $run) }}" class="mt-6">@csrf<button class="h-10 rounded-sm border border-line-strong px-4 text-sm font-semibold">{{ __('owner.synthetic_invoice_test.reconcile') }}</button></form>
                @endif
                @if ($run->pdf_path)<a class="mt-4 inline-block text-sm underline" href="{{ route('owner.billing.synthetic-szamlazz-test.pdf', $run) }}">{{ __('owner.synthetic_invoice_test.download_pdf') }}</a>@endif
            </article>
        @empty
            <p class="rounded-md border border-line bg-surface p-4 text-sm">{{ __('owner.synthetic_invoice_test.no_runs') }}</p>
        @endforelse
        {{ $runs->links() }}
    </section>
    @if ($activeRunExists)
        <p class="rounded-md border border-warning/40 bg-warning-soft p-4 text-sm text-warning-ink">{{ __('owner.synthetic_invoice_test.unresolved_run_exists') }}</p>
    @elseif ($stageGuardPassed && $ownerManagedCredential)
        <form method="POST" action="{{ route('owner.billing.synthetic-szamlazz-test.store') }}" class="space-y-4 rounded-md border border-line bg-surface p-5">
            @csrf
            <p class="text-sm">{{ __('owner.synthetic_invoice_test.synthetic_data') }}</p>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="account_test_mode_confirmed" value="1" required class="mt-1">{{ __('owner.synthetic_invoice_test.confirm_account_test') }}</label>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="test_seller_confirmed" value="1" required class="mt-1">{{ __('owner.synthetic_invoice_test.confirm_synthetic_seller') }}</label>
            <label class="block text-sm">{{ __('owner.synthetic_invoice_test.tax_code') }}
                <select name="tax_code" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 sm:max-w-md"><option value="AAM">AAM — {{ __('owner.synthetic_invoice_test.mechanical_only') }}</option></select>
            </label>
            <label class="block text-sm">{{ __('owner.synthetic_invoice_test.phrase') }}<input name="confirmation_phrase" required autocomplete="off" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 sm:max-w-md"></label>
            <button class="h-10 rounded-sm border border-line-strong px-4 text-sm font-semibold">{{ __('owner.synthetic_invoice_test.prepare_button') }}</button>
        </form>
    @else
        <p class="rounded-md border border-line bg-surface p-4 text-sm">{{ __('owner.synthetic_invoice_test.disabled') }}</p>
    @endif
</x-layouts.owner>
