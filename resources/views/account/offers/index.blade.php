<x-layouts.account :title="__('offers.title')">
    <x-account.page-header :title="__('offers.title')" :lead="__('offers.lead')" />

    @forelse ($offers as $offer)
        @php $status = $offer->effectiveStatus(); @endphp
        <x-account.panel class="mb-4" :heading-id="'offer-'.$offer->uuid">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="offer-{{ $offer->uuid }}" class="text-base font-semibold break-words text-primary">
                        <a href="{{ route('account.offers.show', $offer) }}" class="underline decoration-line-strong underline-offset-4 hover:decoration-primary">{{ $offer->title }}</a>
                    </h2>
                    <p class="mt-1 text-sm text-muted">{{ __('offers.for_website', ['host' => $offer->website->host()]) }}</p>
                </div>
                <x-ui.status-badge :tone="$status->tone()">{{ $status->customerLabel() }}</x-ui.status-badge>
            </div>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                <x-account.detail :label="__('offers.price')"><span class="tabular-nums">{{ $offer->money()->format() }}</span></x-account.detail>
                <x-account.detail :label="__('offers.valid_until')"><span class="font-mono">{{ $offer->valid_until->translatedFormat('j M Y, H:i') }} UTC</span></x-account.detail>
            </dl>
        </x-account.panel>
    @empty
        <x-account.panel>
            <p class="text-sm text-muted">{{ __('offers.none') }}</p>
        </x-account.panel>
    @endforelse
</x-layouts.account>
