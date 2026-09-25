{{--
    Pricing table: product-line tabs, billing toggle and plan cards.
    All figures come from App\Pricing\PricingCatalog (config/pricing.php).
--}}
@use('App\Pricing\BillingInterval')
@props(['catalog'])

@php
    $productLines = $catalog->productLines();
    $defaultLine = $catalog->defaultProductLine;
    $defaultInterval = $catalog->defaultInterval;
    $freeMonths = $catalog->annualFreeMonths();
    $freeMonthsLabel = $freeMonths > 0 ? $freeMonths.' '.Str::plural('month', $freeMonths).' free' : null;
@endphp

<div
    x-data="pricingTable(@js([
        'productLines' => array_keys($productLines),
        'productLine' => $defaultLine,
        'interval' => $defaultInterval->value,
    ]))"
    {{ $attributes }}
>
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div role="tablist" aria-label="Plan type" class="grid grid-cols-2 rounded-md border border-line bg-surface p-1 sm:inline-grid sm:w-auto">
            @foreach ($productLines as $line)
                @php $isDefault = $line->key === $defaultLine; @endphp
                <button
                    type="button"
                    role="tab"
                    id="pricing-tab-{{ $line->key }}"
                    data-tab="{{ $line->key }}"
                    aria-controls="pricing-panel-{{ $line->key }}"
                    aria-selected="{{ $isDefault ? 'true' : 'false' }}"
                    tabindex="{{ $isDefault ? '0' : '-1' }}"
                    :aria-selected="(productLine === '{{ $line->key }}').toString()"
                    :tabindex="productLine === '{{ $line->key }}' ? 0 : -1"
                    @click="selectProductLine('{{ $line->key }}')"
                    @keydown="onTabKeydown($event)"
                    class="h-10 rounded-sm px-6 text-sm font-medium text-ink transition-colors not-aria-selected:hover:bg-subtle aria-selected:bg-primary aria-selected:text-white"
                >
                    {{ $line->label }}
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <div role="group" aria-label="Billing period" class="inline-grid grid-cols-2 rounded-md border border-line bg-surface p-1">
                @foreach (BillingInterval::cases() as $interval)
                    @php $isDefault = $interval === $defaultInterval; @endphp
                    <button
                        type="button"
                        aria-pressed="{{ $isDefault ? 'true' : 'false' }}"
                        :aria-pressed="(interval === '{{ $interval->value }}').toString()"
                        @click="setInterval('{{ $interval->value }}')"
                        class="h-10 rounded-sm border border-transparent px-4 text-sm font-medium text-muted transition-colors hover:text-primary aria-pressed:border-line-strong aria-pressed:bg-subtle aria-pressed:text-primary"
                    >
                        {{ $interval->label() }}
                    </button>
                @endforeach
            </div>

            @if ($freeMonthsLabel)
                {{-- Filled while annual billing is selected. --}}
                <p
                    @if ($defaultInterval === BillingInterval::Annual) data-active @endif
                    x-bind:data-active="interval === 'annual'"
                    class="inline-flex h-8 items-center gap-1.5 rounded-full border border-accent/35 bg-accent-soft px-3 text-sm font-medium text-accent-ink transition-colors data-active:border-accent-ink data-active:bg-accent-ink data-active:text-white"
                >
                    <x-ui.icon name="check" class="size-4" />
                    <span>Annual: {{ $freeMonthsLabel }}</span>
                </p>
            @endif
        </div>
    </div>

    @foreach ($productLines as $line)
        <div
            role="tabpanel"
            id="pricing-panel-{{ $line->key }}"
            aria-labelledby="pricing-tab-{{ $line->key }}"
            x-show="productLine === '{{ $line->key }}'"
            @if ($line->key !== $defaultLine) x-cloak @endif
            class="mt-8"
        >
            <p class="mb-6 text-sm text-muted">{{ $line->summary }}</p>

            <ul class="grid gap-4 md:grid-cols-3 lg:gap-6">
                @foreach ($line->plans as $plan)
                    <li>
                        <x-pricing.plan-card :plan="$plan" :product-line-label="$line->label" :default-interval="$defaultInterval" :free-months-label="$freeMonthsLabel" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

    <p class="mt-6 text-sm text-muted">
        Prices in {{ $catalog->currency->code }}. Third-party plugin and theme licenses are not included.
    </p>
</div>
