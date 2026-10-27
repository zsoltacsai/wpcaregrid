{{--
    Pricing table: product-line tabs, billing toggle and plan cards.
    All figures come from App\Pricing\PricingCatalog (config/pricing.php), the same
    source the checkout uses. Shows the display market's prices.
--}}
@use('App\Pricing\BillingInterval')
@props(['catalog'])

@php
    $productTypes = $catalog->productTypes();
    $defaultLine = $catalog->defaultProductType->value;
    $defaultInterval = $catalog->defaultInterval;
    $displayCurrency = app(\App\Pricing\DisplayCurrency::class);
    // The Hungarian marketing route is the stronger display hint. On English
    // routes keep the established browser-region suggestion (for example,
    // en-GB → GBP and en-US → USD). An explicit session selection still wins.
    $currencyLocale = app()->getLocale() === 'hu'
        ? app()->getLocale()
        : (request()->getLanguages()[0] ?? app()->getLocale());
    $selectedCurrency = $displayCurrency->selected($currencyLocale);
    $market = $catalog->marketForCurrency($selectedCurrency)
        ?? $catalog->market(config('pricing.display_market'));
    $freeMonths = $catalog->annualFreeMonths($market);
    $freeMonthsLabel = $freeMonths > 0 ? trans_choice('pricing.free_months', $freeMonths) : null;
    $taxNote = __('pricing.tax_notes.'.$market->taxTreatment->value);
@endphp

<div
    x-data="pricingTable(@js([
        'productLines' => array_map(fn ($type) => $type->value, $productTypes),
        'productLine' => $defaultLine,
        'interval' => $defaultInterval->value,
        'currency' => $selectedCurrency,
    ]))"
    {{ $attributes }}
>
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div role="tablist" aria-label="{{ __('pricing.tabs_label') }}" class="grid grid-cols-2 rounded-md border border-line bg-surface p-1 sm:inline-grid sm:w-auto">
            @foreach ($productTypes as $type)
                @php $isDefault = $type->value === $defaultLine; @endphp
                <button
                    type="button"
                    role="tab"
                    id="pricing-tab-{{ $type->value }}"
                    data-tab="{{ $type->value }}"
                    aria-controls="pricing-panel-{{ $type->value }}"
                    aria-selected="{{ $isDefault ? 'true' : 'false' }}"
                    tabindex="{{ $isDefault ? '0' : '-1' }}"
                    :aria-selected="(productLine === '{{ $type->value }}').toString()"
                    :tabindex="productLine === '{{ $type->value }}' ? 0 : -1"
                    @click="selectProductLine('{{ $type->value }}')"
                    @keydown="onTabKeydown($event)"
                    class="h-10 rounded-sm px-6 text-sm font-medium text-ink transition-colors not-aria-selected:hover:bg-subtle aria-selected:bg-primary aria-selected:text-white"
                >
                    {{ $type->label() }}
                </button>
            @endforeach
        </div>

        <div class="flex flex-col items-start gap-3 md:flex-row md:flex-wrap md:items-center">
            <x-pricing.currency-selector :catalog="$catalog" :locale="$currencyLocale" />
            <div role="group" aria-label="{{ __('pricing.billing_label') }}" class="inline-grid grid-cols-2 rounded-md border border-line bg-surface p-1">
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
                    <span>{{ __('pricing.annual_offer', ['offer' => $freeMonthsLabel]) }}</span>
                </p>
            @endif
        </div>
    </div>

    @foreach ($productTypes as $type)
        <div
            role="tabpanel"
            id="pricing-panel-{{ $type->value }}"
            aria-labelledby="pricing-tab-{{ $type->value }}"
            x-show="productLine === '{{ $type->value }}'"
            @if ($type->value !== $defaultLine) x-cloak @endif
            class="mt-8"
        >
            <p class="mb-6 text-sm text-muted">{{ $type->summary() }}</p>

            <ul class="grid gap-4 md:grid-cols-3 lg:gap-6">
                @foreach ($catalog->offers($type, $market) as $offer)
                    <li>
                        <x-pricing.pricing-card :offer="$offer" :default-interval="$defaultInterval" :currency="$selectedCurrency" :free-months-label="$freeMonthsLabel" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

    <p class="mt-6 text-sm text-muted">
        {{ $taxNote }} {{ __('pricing.licenses_note') }}
    </p>
</div>
