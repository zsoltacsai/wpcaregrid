<x-layouts.owner :title="__('owner.coupons.title')">
    <x-operator.page-header :title="__('owner.coupons.title')" :lead="__('owner.coupons.lead')">
        <x-slot:actions>
            <x-ui.button :href="route('owner.coupons.create')" size="sm">{{ __('owner.coupons.new') }}</x-ui.button>
        </x-slot:actions>
    </x-operator.page-header>

    <nav aria-label="{{ __('owner.coupons.filter') }}" class="mb-3 flex flex-wrap gap-2">
        @foreach ([null => __('owner.all'), 'active' => __('owner.coupons.states.active'), 'inactive' => __('owner.coupons.states.inactive')] as $key => $label)
            <a href="{{ route('owner.coupons.index', $key ? ['state' => $key] : []) }}" @if ($key === $filter) aria-current="page" @endif
               @class(['inline-flex h-8 items-center rounded-sm border px-3 text-sm', 'border-primary bg-primary text-white' => $key === $filter, 'border-line-strong text-primary hover:border-primary' => $key !== $filter])>{{ $label }}</a>
        @endforeach
    </nav>

    @if ($coupons->isEmpty())
        <x-account.notice tone="neutral">{{ __('owner.coupons.empty') }}</x-account.notice>
    @else
        <div class="relative hidden overflow-x-auto rounded-md border border-line bg-surface md:block">
            <table class="w-full min-w-[820px] text-left text-sm">
                <thead class="bg-canvas text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr>
                        @foreach (['code', 'discount', 'state', 'valid', 'used', 'updated'] as $column)
                            <th scope="col" class="px-3 py-2">{{ __('owner.coupons.columns.'.$column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($coupons as $coupon)
                        <tr class="hover:bg-canvas/60">
                            <td class="px-3 py-2"><a href="{{ route('owner.coupons.show', $coupon) }}" class="font-mono font-medium text-primary hover:underline">{{ $coupon->code }}</a></td>
                            <td class="px-3 py-2 tabular-nums">{{ $coupon->percentLabel() }}% <span class="text-xs text-muted">{{ __('owner.coupons.durations_short.'.$coupon->duration, ['months' => $coupon->duration_months]) }}</span><span class="block text-xs text-muted">{{ __('owner.coupons.market_groups.'.($coupon->marketLabelKey())) }}</span></td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$coupon->active ? 'positive' : 'neutral'">{{ __('owner.coupons.states.'.($coupon->active ? 'active' : 'inactive')) }}</x-ui.status-badge></td>
                            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-muted">{{ $coupon->starts_at?->format('Y-m-d') ?? '…' }} – {{ $coupon->expires_at?->format('Y-m-d') ?? '…' }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ $coupon->times_redeemed }}@if ($coupon->max_redemptions !== null) / {{ $coupon->max_redemptions }}@endif</td>
                            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-muted">{{ $coupon->updated_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <ul class="grid grid-cols-1 gap-3 md:hidden">
            @foreach ($coupons as $coupon)
                <li class="min-w-0 rounded-md border border-line bg-surface p-3">
                    <a href="{{ route('owner.coupons.show', $coupon) }}" class="block truncate font-mono font-medium text-primary hover:underline">{{ $coupon->code }}</a>
                    <p class="mt-1 text-sm tabular-nums">{{ $coupon->percentLabel() }}% <span class="text-xs text-muted">{{ __('owner.coupons.durations_short.'.$coupon->duration, ['months' => $coupon->duration_months]) }}</span></p>
                    <p class="text-xs text-muted">{{ __('owner.coupons.market_groups.'.$coupon->marketLabelKey()) }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <x-ui.status-badge :tone="$coupon->active ? 'positive' : 'neutral'">{{ __('owner.coupons.states.'.($coupon->active ? 'active' : 'inactive')) }}</x-ui.status-badge>
                        <x-ui.status-badge tone="neutral">{{ __('owner.coupons.used_of', ['used' => $coupon->times_redeemed, 'max' => $coupon->max_redemptions ?? '∞']) }}</x-ui.status-badge>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $coupons->links() }}</div>
    @endif
</x-layouts.owner>
