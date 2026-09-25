{{-- A single plan. Renders every billing interval; Alpine shows the selected one. --}}
@use('App\Pricing\BillingInterval')
@props(['plan', 'productLineLabel', 'defaultInterval', 'freeMonthsLabel' => null])

@php
    $signupUrl = fn (BillingInterval $interval) => config('marketing.links.signup').'?'.http_build_query([
        'plan' => $plan->id(),
        'billing' => $interval->value,
    ]);
    $savings = $plan->annualSavings();
@endphp

<article
    aria-labelledby="plan-{{ $plan->id() }}"
    @class([
        'relative flex h-full flex-col rounded-lg border bg-surface p-6 md:p-4 lg:p-8',
        'border-primary shadow-raised ring-1 ring-primary' => $plan->highlighted,
        'border-line' => ! $plan->highlighted,
    ])
>
    <header class="flex items-start justify-between gap-4">
        <h3 id="plan-{{ $plan->id() }}" class="text-xl font-semibold">{{ $plan->name }}</h3>
        @if ($plan->badge)
            <span class="inline-flex h-6 items-center rounded-full bg-primary px-2.5 text-xs font-medium whitespace-nowrap text-white">
                {{ $plan->badge }}
            </span>
        @endif
    </header>
    <p class="mt-2 text-sm text-muted">{{ $plan->description }}</p>

    <div class="mt-6 min-h-[96px] border-t border-line pt-6">
        @foreach (BillingInterval::cases() as $interval)
            @php $price = $plan->price($interval); @endphp
            <div
                x-show="interval === '{{ $interval->value }}'"
                @if ($interval !== $defaultInterval) x-cloak @endif
                data-stripe-price="{{ $price->stripePriceId }}"
            >
                <p class="flex flex-wrap items-baseline gap-x-2">
                    <span class="text-[36px] leading-none font-semibold tracking-[-0.02em] text-primary lg:text-[40px]">{{ $price->money->formattedAmount() }}</span>
                    <span class="text-sm text-muted">{{ $price->money->currency->symbol }} / {{ $interval->unit() }}</span>
                </p>

                @if ($interval === BillingInterval::Monthly)
                    <p class="mt-3 text-sm text-muted">Billed monthly. No long-term contract.</p>
                @else
                    <p class="mt-3 flex flex-wrap items-center gap-2 text-sm text-ink">
                        @if ($freeMonthsLabel && $savings->isPositive())
                            <x-ui.status-badge tone="positive">{{ $freeMonthsLabel }}</x-ui.status-badge>
                            <span>Save {{ $savings->format() }}</span>
                        @else
                            <span class="text-muted">Billed annually.</span>
                        @endif
                    </p>
                @endif
            </div>
        @endforeach
    </div>

    <x-ui.button
        :href="$signupUrl($defaultInterval)"
        x-bind:href="interval === 'annual' ? '{{ $signupUrl(BillingInterval::Annual) }}' : '{{ $signupUrl(BillingInterval::Monthly) }}'"
        :variant="$plan->highlighted ? 'primary' : 'secondary'"
        size="lg"
        class="mt-6 w-full"
    >
        Choose {{ $plan->name }}<span class="sr-only"> {{ $productLineLabel }}</span>
    </x-ui.button>

    <ul class="mt-6 space-y-3 text-sm">
        @foreach ($plan->features as $feature)
            <li class="flex gap-3">
                <x-ui.icon name="check" class="mt-0.5 size-4 text-accent-ink" />
                <span>{{ $feature }}</span>
            </li>
        @endforeach
    </ul>
</article>
