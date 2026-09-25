{{-- Everything Stripe has told us about one subscription. --}}
@use('App\Billing\SubscriptionStatus')
@props(['subscription'])

@php $s = $subscription; @endphp

<dl class="grid gap-x-8 gap-y-5 text-sm sm:grid-cols-2">
    <x-account.detail :label="__('account.subscription.product')">{{ $s->product_type->label() }}</x-account.detail>
    <x-account.detail :label="__('account.subscription.plan')">{{ $s->planName() }}</x-account.detail>
    <x-account.detail :label="__('account.subscription.interval')">{{ $s->interval->label() }}</x-account.detail>
    <x-account.detail :label="__('account.subscription.currency')">{{ $s->currency }}</x-account.detail>
    <x-account.detail :label="__('account.subscription.amount')">
        {{ $s->money()->format() }} <span class="font-normal text-muted">/ {{ $s->interval->unit() }}</span>
    </x-account.detail>
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
            {{ __('account.subscription.payment_statuses.'.$s->last_payment_status) }}@if ($s->last_payment_at) · <span class="font-mono">{{ $s->last_payment_at->translatedFormat('j M Y') }}</span>@endif
        @endif
    </x-account.detail>
    <x-account.detail :label="__('account.subscription.website')">
        {{ $s->website?->host() ?? __('account.subscription.no_website') }}
    </x-account.detail>
</dl>
