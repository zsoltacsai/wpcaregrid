{{--
    A single plan (App\Pricing\PlanOffer). Renders every billing interval;
    Alpine shows the selected one. The button carries only the logical
    selection; the checkout resolves the price again on the server.
--}}
@use('App\Pricing\BillingInterval')
@props(['offer', 'defaultInterval', 'freeMonthsLabel' => null])

@php
    $plan = $offer->plan;
    $checkoutUrl = fn (BillingInterval $interval) => route('checkout.show', [
        'product' => $plan->productType->value,
        'plan' => $plan->tier->value,
        'interval' => $interval->value,
    ]);
    $savings = $offer->annualSavings();
@endphp

<article
    aria-labelledby="plan-{{ $plan->id() }}"
    @class([
        'relative flex h-full flex-col rounded-lg border bg-surface p-6 md:p-4 lg:p-8',
        'border-accent shadow-raised ring-1 ring-accent' => $plan->highlighted,
        'border-line' => ! $plan->highlighted,
    ])
>
    <header class="flex items-start justify-between gap-4">
        <h3 id="plan-{{ $plan->id() }}" class="text-xl font-semibold">{{ $plan->name }}</h3>
        @if ($plan->badge)
            <span class="inline-flex h-6 items-center rounded-full bg-action px-2.5 text-xs font-medium whitespace-nowrap text-white">
                {{ $plan->badge }}
            </span>
        @endif
    </header>
    <p class="mt-2 text-sm text-muted">{{ $plan->description }}</p>

    <div class="mt-6 min-h-[96px] border-t border-line pt-6">
        @foreach (BillingInterval::cases() as $interval)
            @php $price = $offer->price($interval); @endphp
            <div
                x-show="interval === '{{ $interval->value }}'"
                @if ($interval !== $defaultInterval) x-cloak @endif
                data-price="{{ $interval->value }}"
            >
                <p class="flex flex-wrap items-baseline gap-x-2">
                    <span class="text-[36px] leading-none font-semibold tracking-[-0.02em] text-primary lg:text-[40px]">{{ $price->money->formattedAmount() }}</span>
                    <span class="text-sm text-muted">{{ __('pricing.per_unit', ['currency' => $price->money->currency->symbol, 'unit' => $interval->unit()]) }}</span>
                </p>

                @if ($interval === BillingInterval::Monthly)
                    <p class="mt-3 text-sm text-muted">{{ __('pricing.billed_monthly') }}</p>
                @else
                    <p class="mt-3 flex flex-wrap items-center gap-2 text-sm text-ink">
                        @if ($freeMonthsLabel && $savings->isPositive())
                            <x-ui.status-badge tone="positive">{{ $freeMonthsLabel }}</x-ui.status-badge>
                            <span>{{ __('pricing.save', ['amount' => $savings->format()]) }}</span>
                        @else
                            <span class="text-muted">{{ __('pricing.billed_annually') }}</span>
                        @endif
                    </p>
                @endif
            </div>
        @endforeach
    </div>

    <x-ui.button
        :href="$checkoutUrl($defaultInterval)"
        x-bind:href="interval === 'annual' ? '{{ $checkoutUrl(BillingInterval::Annual) }}' : '{{ $checkoutUrl(BillingInterval::Monthly) }}'"
        :variant="$plan->highlighted ? 'primary' : 'secondary'"
        size="lg"
        class="mt-6 w-full"
    >
        {{ __('pricing.choose', ['plan' => $plan->name]) }}<span class="sr-only"> ({{ $plan->productType->label() }})</span>
    </x-ui.button>

    <ul class="mt-6 space-y-3 text-sm">
        @if ($offer->previousPlan)
            <li class="flex gap-3">
                <x-ui.icon name="check" class="mt-0.5 size-4 text-accent-ink" />
                <span>{{ __('pricing.everything_in', ['plan' => $offer->previousPlan->name]) }}</span>
            </li>
        @endif
        @foreach ($offer->highlightedFeatures() as $feature)
            <li class="flex gap-3">
                <x-ui.icon name="check" class="mt-0.5 size-4 text-accent-ink" />
                <span>{{ $feature }}</span>
            </li>
        @endforeach
    </ul>
</article>
