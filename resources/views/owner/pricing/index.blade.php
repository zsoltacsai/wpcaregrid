<x-layouts.owner :title="__('owner.pricing.title')">
    <x-operator.page-header :title="__('owner.pricing.title')" :lead="__('owner.pricing.lead')">
        <x-slot:actions>
            <form method="POST" action="{{ route('owner.pricing.verify') }}">
                @csrf
                <x-ui.button type="submit" size="sm" variant="secondary" :disabled="! $stripeConfigured">{{ __('owner.pricing.verify') }}</x-ui.button>
            </form>
        </x-slot:actions>
    </x-operator.page-header>

    <x-account.notice tone="neutral" class="mb-4">{{ __('owner.pricing.future_only') }}</x-account.notice>

    @if (is_array($verification))
        <x-account.notice :tone="$verification['ok'] ? 'positive' : 'warning'" class="mb-4">
            {{ __('owner.pricing.last_verification', ['at' => $verification['at']]) }}
            {{ $verification['ok'] ? __('owner.system.values.ok') : __('owner.system.values.problems', ['count' => $verification['problems']]) }}
        </x-account.notice>
        @if (! $verification['ok'] && ! empty($verification['details']))
            <ul class="mb-4 rounded-md border border-line bg-surface p-3 font-mono text-xs break-all text-muted">
                @foreach ($verification['details'] as $detail)
                    <li>{{ $detail }}</li>
                @endforeach
            </ul>
        @endif
    @elseif (! $stripeConfigured)
        <x-account.notice tone="warning" class="mb-4">{{ __('owner.pricing.errors.stripe_not_configured') }}</x-account.notice>
    @endif

    <x-operator.section :title="__('owner.pricing.technical')" id="prices">
        <nav aria-label="{{ __('owner.pricing.markets') }}" class="mb-3 flex flex-wrap gap-2">
            @foreach ($markets as $key)
                <a href="{{ route('owner.pricing.index', ['market' => $key]) }}" @if ($key === $market) aria-current="page" @endif
                   @class(['inline-flex h-8 items-center rounded-sm border px-3 text-sm', 'border-primary bg-primary text-white' => $key === $market, 'border-line-strong text-primary hover:border-primary' => $key !== $market])>
                    {{ __('owner.pricing.market_names.'.$key) }} <span class="ml-1 font-mono text-[11px] opacity-75">{{ config("pricing.markets.{$key}.currency") }}</span>
                </a>
            @endforeach
        </nav>

        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr class="border-b border-line">
                        @foreach (['plan', 'interval', 'amount', 'stripe', 'state', 'source'] as $column)
                            <th scope="col" class="px-2 py-2">{{ __('owner.pricing.columns.'.$column) }}</th>
                        @endforeach
                        <th scope="col" class="px-2 py-2"><span class="sr-only">{{ __('owner.pricing.edit') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($rows as $row)
                        @php
                            $price = $row['price'];
                            $definition = $row['definition'];
                        @endphp
                        <tr>
                            <td class="px-2 py-2 whitespace-nowrap">{{ $row['key']->productType->label() }} {{ __('owner.tiers.'.$row['key']->tier->value) }}</td>
                            <td class="px-2 py-2">{{ $row['key']->interval->label() }}</td>
                            <td class="px-2 py-2 tabular-nums whitespace-nowrap">{{ $price ? $price->money->format() : '—' }}</td>
                            <td class="max-w-[220px] px-2 py-2 font-mono text-xs break-all">{{ $price?->stripePriceId ?: '—' }}</td>
                            <td class="px-2 py-2">
                                @if (! $price)
                                    <x-ui.status-badge tone="neutral">{{ __('owner.pricing.states.none') }}</x-ui.status-badge>
                                @elseif (! $price->active)
                                    <x-ui.status-badge tone="neutral">{{ __('owner.pricing.states.inactive') }}</x-ui.status-badge>
                                @elseif (! $price->hasStripePrice())
                                    <x-ui.status-badge tone="warning">{{ __('owner.pricing.states.no_stripe_id') }}</x-ui.status-badge>
                                @else
                                    <x-ui.status-badge tone="positive">{{ __('owner.pricing.states.on_sale') }}</x-ui.status-badge>
                                @endif
                                @if ($definition?->draft)
                                    <x-ui.status-badge tone="warning" class="mt-1">{{ __('owner.pricing.states.draft') }}</x-ui.status-badge>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-xs text-muted">{{ $definition?->isPublished() ? __('owner.pricing.sources.owner') : ($price ? __('owner.pricing.sources.config') : '—') }}</td>
                            <td class="px-2 py-2 text-right">
                                <x-ui.button :href="route('owner.pricing.prices.edit', $row['key']->value())" size="sm" variant="ghost">{{ __('owner.pricing.edit') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-operator.section>

    <x-operator.section :title="__('owner.pricing.marketing')" id="plan-wording" class="mt-4">
        <p class="mb-3 text-sm text-muted">{{ __('owner.pricing.marketing_lead') }}</p>
        <ul class="divide-y divide-line">
            @foreach ($plans as $key => $states)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span class="font-medium text-primary">{{ \App\Content\Schema\ContentSchema::label($key) }}</span>
                    <span class="flex flex-wrap gap-2">
                        @foreach ($states as $locale => $state)
                            <a href="{{ route('owner.content.edit', [$key, $locale]) }}" class="inline-flex items-center gap-1.5 rounded-sm border border-line px-2 py-1 text-xs hover:border-primary">
                                <span class="font-mono uppercase">{{ $locale }}</span>
                                <x-ui.status-badge :tone="$state->tone()">{{ $state->label() }}</x-ui.status-badge>
                            </a>
                        @endforeach
                    </span>
                </li>
            @endforeach
        </ul>
    </x-operator.section>
</x-layouts.owner>
