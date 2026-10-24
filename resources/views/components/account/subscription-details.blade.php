{{-- Everything Stripe has told us about one subscription. --}}
@use('App\Billing\SubscriptionStatus')
@props(['subscription'])

@php $s = $subscription; @endphp

@if ($s->isInternal())
    {{-- An internal plan (PROBONO): assigned, not bought. No price, no billing, no Stripe. --}}
    <dl class="grid gap-x-8 gap-y-5 text-sm sm:grid-cols-2">
        <x-account.detail :label="__('account.subscription.billing_source')">{{ __('account.subscription.internal.source') }}</x-account.detail>
        <x-account.detail :label="__('account.subscription.product')">{{ $s->product_type->label() }}</x-account.detail>
        <x-account.detail :label="__('account.subscription.plan')">{{ $s->planName() }}</x-account.detail>
        <x-account.detail :label="__('account.subscription.status')">
            <x-ui.status-badge :tone="$s->status->tone()">{{ $s->status->label() }}</x-ui.status-badge>
        </x-account.detail>
        <x-account.detail :label="__('account.subscription.website')">{{ $s->website?->host() ?? __('account.subscription.no_website') }}</x-account.detail>
        <x-account.detail :label="__('account.subscription.internal.since')">
            <span class="font-mono">{{ ($s->internal_starts_at ?? $s->activated_at ?? $s->created_at)->translatedFormat('j M Y') }}</span>
        </x-account.detail>
        <x-account.detail :label="__('account.subscription.internal.until')">
            @if ($s->internal_ends_at)<span class="font-mono">{{ $s->internal_ends_at->translatedFormat('j M Y') }}</span>@else{{ __('account.subscription.internal.no_end') }}@endif
        </x-account.detail>
    </dl>

    <div class="mt-6 border-t border-line pt-6">
        <h3 class="text-sm font-semibold text-primary">{{ __('account.subscription.internal.includes_title') }}</h3>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-ink">
            @foreach (['updates', 'health', 'licenses'] as $item)
                <li>{{ __('account.subscription.internal.includes.'.$item) }}</li>
            @endforeach
        </ul>
        <h3 class="mt-4 text-sm font-semibold text-primary">{{ __('account.subscription.internal.excludes_title') }}</h3>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-ink">
            @foreach (['backups', 'scans', 'admin_access'] as $item)
                <li>{{ __('account.subscription.internal.excludes.'.$item) }}</li>
            @endforeach
        </ul>
    </div>
@else
<dl class="grid gap-x-8 gap-y-5 text-sm sm:grid-cols-2">
    <x-account.detail :label="__('account.subscription.product')">{{ $s->product_type->label() }}</x-account.detail>
    <x-account.detail :label="__('account.subscription.plan')">{{ $s->planName() }}</x-account.detail>
    <x-account.detail :label="__('account.subscription.interval')">{{ $s->interval->label() }}</x-account.detail>
    <x-account.detail :label="__('account.subscription.currency')">{{ $s->currency }}</x-account.detail>
    @if ($s->basePriceMoney())
        <x-account.detail :label="__('account.subscription.base_amount')">
            {{ $s->basePriceMoney()->format() }} <span class="font-normal text-muted">/ {{ $s->interval->unit() }}</span>
        </x-account.detail>
    @endif
    <x-account.detail :label="__('account.subscription.current_recurring')">
        @if ($s->currentRecurringMoney())
            {{ $s->currentRecurringMoney()->format() }} <span class="font-normal text-muted">/ {{ $s->interval->unit() }}</span>
        @else
            {{ __('account.subscription.amount_unknown') }}
        @endif
    </x-account.detail>
    @if ($s->discount_type && $s->billing_snapshot_confirmed_at)
        <x-account.detail :label="__('account.subscription.discount')">
            @if ($s->discount_type === 'percent' && $s->discount_percent !== null)
                {{ $s->discountPercentLabel() }}%
            @elseif ($s->discount_type === 'fixed' && $s->discount_amount !== null && $s->discount_currency)
                {{ (new \App\Pricing\Money($s->discount_amount, \App\Pricing\Currency::fromConfig($s->discount_currency, config("pricing.currencies.{$s->discount_currency}", []), app()->getLocale())))->format() }}
            @else
                {{ __('account.subscription.discount_unknown') }}
            @endif
            @if ($s->discount_duration)<span class="text-muted"> · {{ __('account.subscription.discount_durations.'.$s->discount_duration, ['months' => $s->discount_duration_months]) }}</span>@endif
        </x-account.detail>
    @endif
    @if ($s->initial_discount_snapshot_confirmed_at && $s->initial_discount_duration === 'once')
        <x-account.detail :label="__('account.subscription.initial_invoice_discount')">
            @if ($s->initial_discount_type === 'percent' && $s->initial_discount_percent !== null)
                {{ $s->initialDiscountPercentLabel() }}%
            @elseif ($s->initial_discount_type === 'fixed' && $s->initial_discount_amount !== null && $s->initial_discount_currency)
                {{ (new \App\Pricing\Money($s->initial_discount_amount, \App\Pricing\Currency::fromConfig($s->initial_discount_currency, config("pricing.currencies.{$s->initial_discount_currency}", []), app()->getLocale())))->format() }}
            @else
                {{ __('account.subscription.discount_unknown') }}
            @endif
            <span class="text-muted"> · {{ __('account.subscription.discount_durations.once') }}</span>
        </x-account.detail>
    @endif
    <x-account.detail :label="__('account.subscription.status')">
        <x-ui.status-badge :tone="$s->status->tone()">{{ $s->status->label() }}</x-ui.status-badge>
    </x-account.detail>
    <x-account.detail :label="__('account.subscription.period')">
        @if ($s->current_period_start && $s->current_period_end)
            <span class="font-mono">{{ $s->current_period_start->translatedFormat('j M Y') }} – {{ $s->current_period_end->translatedFormat('j M Y') }}</span>
        @endif
    </x-account.detail>
    <x-account.detail :label="__('account.subscription.next_billing')">
        @if ($s->nextBillingDate())<span class="font-mono">{{ $s->nextBillingDate()->translatedFormat('j M Y') }}</span>@endif
    </x-account.detail>
    <x-account.detail :label="__('account.subscription.renewal')">
        @if ($s->status->hasEnded())
            {{ __('account.subscription.ended') }}
        @elseif ($s->cancel_at_period_end && $s->current_period_end)
            {{ __('account.subscription.ends_on', ['date' => $s->current_period_end->translatedFormat('j M Y')]) }}
        @elseif ($s->status !== SubscriptionStatus::Pending)
            {{ __('account.subscription.renews') }}
        @endif
    </x-account.detail>
    <x-account.detail :label="__('account.subscription.cancel_at_period_end')">
        {{ $s->cancel_at_period_end ? __('account.subscription.yes') : __('account.subscription.no') }}
    </x-account.detail>
    <x-account.detail :label="__('account.subscription.last_payment')">
        @if ($s->last_payment_status)
            {{ __('account.subscription.payment_statuses.'.$s->last_payment_status) }}@if ($s->last_payment_at) · <span class="font-mono">{{ app(\App\Support\DisplayDateTime::class)->date($s->last_payment_at, 'j M Y') }}</span>@endif
        @endif
    </x-account.detail>
    <x-account.detail :label="__('account.subscription.website')">
        {{ $s->website?->host() ?? __('account.subscription.no_website') }}
    </x-account.detail>
</dl>
@php $billingInvoices = $s->billingInvoices()->limit(25)->get(); @endphp
@if ($billingInvoices->isNotEmpty())
    <div class="mt-6 border-t border-line pt-6">
        <h3 class="text-sm font-semibold text-primary">{{ __('account.subscription.invoice_history') }}</h3>
        <ul class="mt-2 divide-y divide-line text-sm">
            @foreach ($billingInvoices as $invoice)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>{{ app(\App\Support\DisplayDateTime::class)->localizedFormat($invoice->stripe_created_at, 'j M Y') ?? '—' }} · {{ __('account.subscription.invoice_statuses.'.$invoice->invoice_status, [], app()->getLocale()) }}</span>
                    <span class="tabular-nums">{{ $invoice->money('amount_paid')?->format() ?? __('account.subscription.amount_unknown') }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
@endif
