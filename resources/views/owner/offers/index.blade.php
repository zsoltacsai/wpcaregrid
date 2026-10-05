<x-layouts.owner :title="__('owner.offers.title')">
    <x-operator.page-header :title="__('owner.offers.title')" :lead="__('owner.offers.lead')">
        <x-slot:actions>
            <x-ui.button :href="route('owner.offers.create')" size="sm">{{ __('owner.offers.new') }}</x-ui.button>
        </x-slot:actions>
    </x-operator.page-header>

    <form method="GET" action="{{ route('owner.offers.index') }}" class="mb-3 flex flex-wrap items-end gap-3">
        <div>
            <label for="filter-status" class="block text-xs font-medium text-muted">{{ __('owner.offers.fields.status') }}</label>
            <select id="filter-status" name="status" class="mt-1 h-9 rounded-sm border border-line-strong bg-surface px-2 text-sm text-ink">
                <option value="">{{ __('owner.all') }}</option>
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>
        <x-ui.button type="submit" size="sm" class="h-9">{{ __('owner.filter') }}</x-ui.button>
        <x-ui.button :href="route('owner.offers.index')" variant="ghost" size="sm" class="h-9">{{ __('owner.reset') }}</x-ui.button>
    </form>

    @if ($offers->isEmpty())
        <x-account.notice tone="neutral">{{ __('owner.offers.empty') }}</x-account.notice>
    @else
        <div class="relative hidden overflow-x-auto rounded-md border border-line bg-surface md:block">
            <table class="w-full min-w-[860px] text-left text-sm">
                <thead class="bg-canvas text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr>
                        @foreach (['title', 'customer', 'website', 'price', 'status', 'valid_until'] as $column)
                            <th scope="col" class="px-3 py-2">{{ __('owner.offers.columns.'.$column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($offers as $offer)
                        <tr class="hover:bg-canvas/60">
                            <td class="max-w-[240px] px-3 py-2"><a href="{{ route('owner.offers.show', $offer) }}" class="block truncate font-medium text-primary hover:underline">{{ $offer->title }}</a></td>
                            <td class="max-w-[180px] truncate px-3 py-2">{{ $offer->customer->fullName() }}</td>
                            <td class="max-w-[180px] truncate px-3 py-2 text-xs">{{ $offer->website->host() }}</td>
                            <td class="px-3 py-2 tabular-nums whitespace-nowrap">{{ $offer->money()->format() }}</td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$offer->status->tone()">{{ $offer->status->label() }}</x-ui.status-badge></td>
                            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-muted">{{ $offer->valid_until->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <ul class="grid grid-cols-1 gap-3 md:hidden">
            @foreach ($offers as $offer)
                <li class="min-w-0 rounded-md border border-line bg-surface p-3">
                    <a href="{{ route('owner.offers.show', $offer) }}" class="block truncate font-medium text-primary hover:underline">{{ $offer->title }}</a>
                    <p class="truncate text-xs text-muted">{{ $offer->customer->fullName() }} · {{ $offer->website->host() }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                        <x-ui.status-badge :tone="$offer->status->tone()">{{ $offer->status->label() }}</x-ui.status-badge>
                        <span class="text-sm tabular-nums">{{ $offer->money()->format() }}</span>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $offers->links() }}</div>
    @endif
</x-layouts.owner>
