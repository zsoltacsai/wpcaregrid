<x-layouts.owner :title="__('owner.invoice_intents.detail_title', ['id' => $intent->id])">
    <x-operator.page-header :title="__('owner.invoice_intents.detail_title', ['id' => $intent->id])" :lead="__('owner.invoice_intents.dry_run_notice')" />
    <div class="mb-5"><a class="text-sm underline" href="{{ route('owner.billing.invoice-intents.index') }}">{{ __('owner.invoice_intents.back') }}</a></div>
    <dl class="grid gap-3 rounded-md border border-line bg-surface p-5 sm:grid-cols-2">
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.customer') }}</dt><dd>{{ $intent->customer?->email ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.subscription') }}</dt><dd>{{ $intent->subscription?->tierLabel() ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.stripe_invoice') }}</dt><dd class="font-mono">{{ $intent->stripe_invoice_id }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.amount') }}</dt><dd>{{ $intent->billingInvoice?->money('total')?->format() ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.provider') }}</dt><dd>{{ $intent->provider }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.status') }}</dt><dd>{{ $intent->status->label() }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.blocked_reason') }}</dt><dd>{{ $intent->blocked_reason ? __('owner.invoice_intents.reasons.'.$intent->blocked_reason) : '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.dry_run_result') }}</dt><dd>{{ $intent->dry_run_result['validation'] ?? __('owner.invoice_intents.not_run') }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.retry_count') }}</dt><dd>{{ $intent->retry_count }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.error_category') }}</dt><dd>{{ $intent->last_error_category ?? '—' }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.created') }}</dt><dd>{{ $intent->created_at?->translatedFormat('j M Y H:i') }}</dd></div>
        <div><dt class="text-xs text-muted">{{ __('owner.invoice_intents.updated') }}</dt><dd>{{ $intent->updated_at?->translatedFormat('j M Y H:i') }}</dd></div>
    </dl>
    <h2 class="mb-3 mt-7 text-lg font-semibold">{{ __('owner.invoice_intents.history') }}</h2>
    <ol class="space-y-3">
        @foreach ($intent->events as $event)
            <li class="rounded-md border border-line bg-surface p-4">
                <p class="font-medium">{{ __('owner.invoice_intents.events.'.$event->event_type) }}</p>
                <p class="mt-1 text-xs text-muted">{{ $event->created_at?->translatedFormat('j M Y H:i:s') }} · {{ $event->from_status ?? '—' }} → {{ $event->to_status ?? '—' }}</p>
            </li>
        @endforeach
    </ol>
</x-layouts.owner>
