<x-layouts.owner :title="__('owner.invoice_intents.title')">
    <x-operator.page-header :title="__('owner.invoice_intents.title')" :lead="__('owner.invoice_intents.lead')" />
    <p class="mb-5 rounded-md border border-warning/40 bg-warning-soft p-3 text-sm text-warning-ink">{{ __('owner.invoice_intents.dry_run_notice') }}</p>
    @can('owner.billing.synthetic_tests.manage')
        <p class="mb-5 text-sm"><a class="underline" href="{{ route('owner.billing.synthetic-szamlazz-test.index') }}">{{ __('owner.synthetic_invoice_test.title') }}</a></p>
    @endcan
    <nav class="mb-4 flex gap-4 text-sm" aria-label="{{ __('owner.invoice_intents.queue_navigation') }}">
        <a class="underline" href="{{ route('owner.billing.invoice-intents.index') }}">{{ __('owner.invoice_intents.all_intents') }}</a>
        <a class="underline" href="{{ route('owner.billing.invoice-intents.index', ['tax_review' => 1]) }}">{{ __('owner.invoice_intents.tax_review_queue') }}</a>
    </nav>

    <div class="overflow-x-auto rounded-md border border-line bg-surface">
        <table class="w-full min-w-[900px] text-left text-sm">
            <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                <tr>
                    <th class="p-3">{{ __('owner.invoice_intents.id') }}</th>
                    <th class="p-3">{{ __('owner.invoice_intents.customer') }}</th>
                    <th class="p-3">{{ __('owner.invoice_intents.subscription') }}</th>
                    <th class="p-3">{{ __('owner.invoice_intents.stripe_invoice') }}</th>
                    <th class="p-3">{{ __('owner.invoice_intents.amount') }}</th>
                    <th class="p-3">{{ __('owner.invoice_intents.provider') }}</th>
                    <th class="p-3">{{ __('owner.invoice_intents.status') }}</th>
                    <th class="p-3">{{ __('owner.invoice_intents.blocked_reason') }}</th>
                    <th class="p-3">{{ __('owner.invoice_intents.dry_run_result') }}</th>
                    <th class="p-3">{{ __('owner.invoice_intents.retry_count') }}</th>
                    <th class="p-3">{{ __('owner.invoice_intents.updated') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($intents as $intent)
                    <tr>
                        <td class="p-3 font-mono"><a class="underline" href="{{ route('owner.billing.invoice-intents.show', $intent) }}">#{{ $intent->id }}</a></td>
                        <td class="p-3">{{ $intent->customer?->email ?? '—' }}</td>
                        <td class="p-3">{{ $intent->subscription?->tierLabel() ?? '—' }}</td>
                        <td class="p-3 font-mono">{{ $intent->stripe_invoice_id }}</td>
                        <td class="p-3">{{ $intent->billingInvoice?->money('total')?->format() ?? '—' }}</td>
                        <td class="p-3">{{ $intent->provider }}</td>
                        <td class="p-3"><x-ui.status-badge :tone="in_array($intent->status->value, ['dry_run_validated'], true) ? 'positive' : (str_starts_with($intent->status->value, 'blocked') || in_array($intent->status->value, ['dry_run_failed', 'reconciliation_required'], true) ? 'warning' : 'neutral')">{{ $intent->status->label() }}</x-ui.status-badge></td>
                        <td class="p-3">{{ $intent->blocked_reason ? __('owner.invoice_intents.reasons.'.$intent->blocked_reason) : '—' }}</td>
                        <td class="p-3">{{ $intent->dry_run_result['validation'] ?? __('owner.invoice_intents.not_run') }}</td>
                        <td class="p-3">{{ $intent->retry_count }}</td>
                        <td class="p-3">{{ $intent->updated_at?->translatedFormat('j M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="p-6 text-center text-muted">{{ __('owner.invoice_intents.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $intents->links() }}</div>
</x-layouts.owner>
