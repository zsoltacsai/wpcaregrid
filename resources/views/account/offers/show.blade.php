@use('App\Offers\OfferStatus')

@php
    $status = $offer->effectiveStatus();
    $returned = request()->query('payment') === 'returned';
@endphp

<x-layouts.account :title="$offer->title">
    <x-account.page-header :title="$offer->title" :lead="__('offers.for_website', ['host' => $offer->website->host()])">
        <x-slot:actions>
            <x-ui.status-badge :tone="$status->tone()">{{ $status->customerLabel() }}</x-ui.status-badge>
        </x-slot:actions>
    </x-account.page-header>

    @if ($errors->has('offer'))
        <x-account.notice tone="danger" class="mb-6">{{ $errors->first('offer') }}</x-account.notice>
    @endif
    @if (session('offer_error'))
        <x-account.notice tone="danger" class="mb-6">{{ session('offer_error') }}</x-account.notice>
    @endif

    {{-- The browser reaching the success address proves nothing; only Stripe's confirmation marks the offer paid. --}}
    @if ($status === OfferStatus::Accepted && $returned)
        <x-account.notice class="mb-6">{{ __('offers.confirming') }}</x-account.notice>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <x-account.panel :title="__('offers.scope')" heading-id="offer-scope">
            <p class="text-sm leading-relaxed whitespace-pre-line text-ink">{{ $offer->scope }}</p>
            <p class="mt-4 text-xs text-muted">{{ __('offers.scope_note') }}</p>
        </x-account.panel>

        <x-account.panel :title="__('offers.summary')" heading-id="offer-summary">
            <dl class="grid gap-4 text-sm">
                <x-account.detail :label="__('offers.price')"><span class="text-xl tabular-nums">{{ $offer->money()->format() }}</span> <span class="font-normal text-muted">{{ __('offers.one_time') }}</span></x-account.detail>
                <x-account.detail :label="__('offers.website')">{{ $offer->website->host() }}</x-account.detail>
                <x-account.detail :label="__('offers.valid_until')"><span class="font-mono">{{ $offer->valid_until->translatedFormat('j M Y, H:i') }} UTC</span></x-account.detail>
                <x-account.detail :label="__('offers.status')"><x-ui.status-badge :tone="$status->tone()">{{ $status->customerLabel() }}</x-ui.status-badge></x-account.detail>
                @if ($offer->paid_at)
                    <x-account.detail :label="__('offers.paid_at')"><span class="font-mono">{{ $offer->paid_at->translatedFormat('j M Y, H:i') }} UTC</span></x-account.detail>
                @endif
            </dl>

            <div class="mt-6 border-t border-line pt-6">
                @switch($status)
                    @case(OfferStatus::Offered)
                        <p class="mb-4 text-sm text-muted">{{ __('offers.accept_lead') }}</p>
                        <div class="flex flex-wrap gap-3">
                            <form method="POST" action="{{ route('account.offers.accept', $offer) }}">@csrf<x-ui.button type="submit">{{ __('offers.accept') }}</x-ui.button></form>
                            <form method="POST" action="{{ route('account.offers.decline', $offer) }}" onsubmit="return confirm(@js(__('offers.confirm_decline')))">@csrf<x-ui.button type="submit" variant="ghost">{{ __('offers.decline') }}</x-ui.button></form>
                        </div>
                        @break
                    @case(OfferStatus::Accepted)
                        <p class="mb-4 text-sm text-muted">{{ __('offers.pay_lead') }}</p>
                        <form method="POST" action="{{ route('account.offers.pay', $offer) }}">@csrf<x-ui.button type="submit">{{ __('offers.pay') }}</x-ui.button></form>
                        @break
                    @case(OfferStatus::Paid)
                        <x-account.notice tone="positive">{{ __('offers.paid_note') }}</x-account.notice>
                        @break
                    @case(OfferStatus::Completed)
                        <x-account.notice tone="positive">{{ __('offers.completed_note') }}</x-account.notice>
                        @break
                    @case(OfferStatus::Expired)
                        <x-account.notice tone="warning">{{ __('offers.expired_note') }}</x-account.notice>
                        @break
                    @case(OfferStatus::Declined)
                        <x-account.notice>{{ __('offers.declined_note') }}</x-account.notice>
                        @break
                    @case(OfferStatus::Cancelled)
                        <x-account.notice>{{ __('offers.cancelled_note') }}</x-account.notice>
                        @break
                @endswitch
            </div>
        </x-account.panel>
    </div>

    <p class="mt-6"><x-ui.button :href="route('account.offers.index')" variant="ghost" size="sm">{{ __('offers.back') }}</x-ui.button></p>
</x-layouts.account>
