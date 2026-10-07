{{--
    Checkout: confirm plan and billing period, enter details, continue to
    Stripe. Only the logical selection is posted; the server resolves the
    price again from the pricing catalogue.
--}}
@use('App\Pricing\BillingInterval')

@php
    $plan = $offer->plan;
    $interval = old('interval', $selection->interval->value);
    $country = old('country', $account?->country_code ?? 'HU');
@endphp

<x-layouts.marketing locale-form="checkout-form" :title="__('checkout.meta.checkout_title')" :description="__('home.meta.description')">
    <section class="py-10 sm:py-12 lg:py-16" aria-labelledby="checkout-title">
        <x-ui.container>
            <nav aria-label="{{ __('checkout.breadcrumb') }}">
                <a href="{{ route('pricing') }}" class="inline-flex items-center gap-1.5 rounded-sm text-sm font-medium text-muted hover:text-primary">
                    <x-ui.icon name="arrow-right" class="size-4 rotate-180" />
                    {{ __('checkout.back_to_pricing') }}
                </a>
            </nav>

            <h1 id="checkout-title" class="mt-6 text-[28px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[32px] lg:text-[40px]">
                {{ __('checkout.title') }}
            </h1>
            <p class="mt-3 text-base text-muted sm:text-lg">{{ __('checkout.lead') }}</p>

            <form id="checkout-form"
                method="POST"
                action="{{ route('checkout.store') }}"
                novalidate
                class="mt-10 grid items-start gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:gap-8"
                x-data="{
                    interval: @js($interval),
                    country: @js($country),
                    currency: @js($currency),
                    prices: @js($priceMatrix),
                    purchasable: @js($purchasableCountries),
                    submitting: false,
                    get available() { return this.purchasable.includes(this.country) },
                    syncCurrency() {
                        if (this.country === 'HU') this.currency = 'HUF';
                        else if (this.currency === 'HUF') this.currency = @js($defaultInternationalCurrency);
                    },
                    priceFor(interval) { return this.prices[this.currency]?.[interval] ?? null; },
                }"
                x-init="syncCurrency()"
                x-on:submit="submitting = true"
            >
                @csrf
                <input type="hidden" name="product" value="{{ $plan->productType->value }}">
                <input type="hidden" name="plan" value="{{ $plan->tier->value }}">

                <div class="overflow-hidden rounded-lg border border-line bg-surface">
                    @error('checkout')
                        <p role="alert" class="m-6 mb-0 flex items-start gap-3 rounded-sm border border-danger/35 bg-danger-soft px-4 py-3 text-sm text-danger-ink">
                            <x-ui.icon name="alert-circle" class="mt-px size-4" />
                            {{ $message }}
                        </p>
                    @enderror

                    {{-- Plan --}}
                    <fieldset class="border-b border-line p-6 sm:p-8">
                        <legend class="sr-only">{{ __('checkout.plan_heading') }}</legend>
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold tracking-[0.12em] text-muted uppercase">{{ __('checkout.plan_heading') }}</p>
                                <p class="mt-2 text-xl font-semibold text-primary">{{ $plan->productType->label() }} {{ $plan->name }}</p>
                                <p class="mt-1 text-sm text-muted">{{ $plan->description }}</p>
                            </div>
                            <a href="{{ route('home') }}#pricing" class="rounded-sm text-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary">
                                {{ __('checkout.change_plan') }}
                            </a>
                        </div>
                    </fieldset>

                    {{-- Billing period --}}
                    <fieldset class="border-b border-line p-6 sm:p-8">
                        <legend class="sr-only">{{ __('checkout.interval_heading') }}</legend>
                        <p class="text-xs font-semibold tracking-[0.12em] text-muted uppercase" aria-hidden="true">{{ __('checkout.interval_heading') }}</p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach (BillingInterval::cases() as $option)
                                @php $price = $offer->price($option); @endphp
                                <label class="relative flex cursor-pointer flex-col gap-1 rounded-md border border-line-strong bg-surface p-4 hover:border-muted has-checked:border-accent has-checked:bg-accent-soft has-checked:ring-1 has-checked:ring-accent has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent-ink">
                                    <input type="radio" name="interval" value="{{ $option->value }}" x-model="interval" @checked($interval === $option->value) class="sr-only">
                                    <span class="flex items-center justify-between gap-3">
                                        <span class="text-sm font-semibold text-primary">{{ $option->label() }}</span>
                                        @if ($option === BillingInterval::Annual && $freeMonths > 0)
                                            <x-ui.status-badge tone="positive">{{ trans_choice('pricing.free_months', $freeMonths) }}</x-ui.status-badge>
                                        @endif
                                    </span>
                                    <span class="text-sm text-ink">
                                        <span class="font-semibold" x-text="priceFor('{{ $option->value }}')?.formatted">{{ $price->money->format() }}</span>
                                        <span class="text-muted">/ {{ $option->unit() }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    {{-- Customer details --}}
                    <fieldset class="p-6 sm:p-8">
                        <legend class="sr-only">{{ __('checkout.details_heading') }}</legend>
                        <p class="text-xs font-semibold tracking-[0.12em] text-muted uppercase" aria-hidden="true">{{ __('checkout.details_heading') }}</p>

                        <div class="mt-4 grid gap-5">
                            <x-form.field name="email" type="email" :label="__('checkout.fields.email')" :hint="__('checkout.fields.email_hint')" :value="$account?->email" :readonly="$account !== null" autocomplete="email" required maxlength="254" />

                            <div class="grid gap-5 sm:grid-cols-2">
                                <x-form.field name="first_name" :label="__('checkout.fields.first_name')" :value="$account?->first_name" autocomplete="given-name" required maxlength="100" />
                                <x-form.field name="last_name" :label="__('checkout.fields.last_name')" :value="$account?->last_name" autocomplete="family-name" required maxlength="100" />
                            </div>

                            <x-form.select
                                name="country"
                                :label="__('checkout.fields.country')"
                                :hint="__('checkout.fields.country_hint')"
                                :options="$countries"
                                :selected="$country"
                                x-model="country"
                                x-on:change="syncCurrency()"
                                autocomplete="country"
                            />

                            <div>
                                <label for="checkout-currency" class="mb-2 block text-sm font-medium">{{ __('checkout.fields.currency') }}</label>
                                <select id="checkout-currency" name="currency" x-model="currency" class="h-11 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm text-primary" required>
                                    @foreach ($currencies as $code)
                                        <option value="{{ $code }}" @selected($currency === $code) :disabled="country === 'HU' ? '{{ $code }}' !== 'HUF' : '{{ $code }}' === 'HUF'">{{ $code }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-muted">{{ __('checkout.fields.currency_hint') }}</p>
                                @error('currency')<p role="alert" class="mt-1 text-sm text-danger-ink">{{ $message }}</p>@enderror
                            </div>

                            <p
                                x-show="!available"
                                x-cloak
                                role="status"
                                class="flex items-start gap-3 rounded-sm border border-warning/40 bg-warning-soft px-4 py-3 text-sm text-warning-ink"
                            >
                                <x-ui.icon name="alert-triangle" class="mt-px size-4" />
                                {{ __('checkout.international_unavailable') }}
                            </p>

                            <x-form.field name="promotion_code" :label="__('checkout.fields.promotion_code')" optional autocomplete="off" maxlength="64" class="sm:max-w-xs" />
                        </div>
                    </fieldset>

                    <div class="flex flex-col gap-3 border-t border-line bg-canvas p-6 sm:p-8">
                        <x-ui.button
                            type="submit"
                            size="lg"
                            icon="arrow-right"
                            class="w-full disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto sm:self-start"
                            x-bind:disabled="!available || submitting"
                        >
                            {{ __('checkout.submit') }}
                        </x-ui.button>
                        <p class="text-sm text-muted">{{ __('checkout.redirect_note') }}</p>
                        <p class="flex items-center gap-2 text-xs text-muted">
                            <x-ui.icon name="lock" class="size-4 text-accent-ink" />
                            {{ __('checkout.secure_note') }}
                        </p>
                    </div>
                </div>

                {{-- Summary --}}
                <aside aria-labelledby="summary-title" class="rounded-lg border border-line bg-surface lg:sticky lg:top-24">
                    <div class="border-b border-line p-6">
                        <h2 id="summary-title" class="text-base font-semibold">{{ __('checkout.summary.heading') }}</h2>
                        <p class="mt-4 text-sm text-muted">{{ $plan->productType->label() }}</p>
                        <p class="text-lg font-semibold text-primary">{{ $plan->name }}</p>

                        @foreach (BillingInterval::cases() as $option)
                            @php $price = $offer->price($option); @endphp
                            <div x-show="interval === '{{ $option->value }}'" @if ($interval !== $option->value) x-cloak @endif class="mt-4">
                                <p class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="text-[32px] leading-none font-semibold tracking-[-0.02em] text-primary" x-text="priceFor('{{ $option->value }}')?.formatted">{{ $price->money->format() }}</span>
                                    <span class="text-sm text-muted">/ {{ $option->unit() }}</span>
                                </p>
                                <p class="mt-2 text-sm text-muted">{{ __('checkout.summary.billed.'.$option->value) }}</p>
                            </div>
                        @endforeach

                        @if ($taxNote = __('pricing.tax_notes.'.$market->taxTreatment->value))
                            <p class="mt-4 text-xs text-muted">{{ $taxNote }}</p>
                        @endif
                    </div>

                    <div class="border-b border-line p-6">
                        <h3 class="text-sm font-semibold">{{ __('checkout.summary.includes') }}</h3>
                        <ul class="mt-3 space-y-2 text-sm">
                            @foreach ($plan->featureLabels() as $feature)
                                <li class="flex gap-3">
                                    <x-ui.icon name="check" class="mt-0.5 size-4 text-accent-ink" />
                                    <span>{{ $feature }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="p-6">
                        <h3 class="text-sm font-semibold">{{ __('checkout.summary.next_heading') }}</h3>
                        <ol class="mt-3 space-y-2 text-sm text-muted">
                            @foreach (__('checkout.summary.next') as $step)
                                <li class="flex gap-3">
                                    <span class="text-xs font-semibold text-accent-ink tabular-nums">{{ sprintf('%02d', $loop->iteration) }}</span>
                                    <span>{{ $step }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </aside>
            </form>
        </x-ui.container>
    </section>
</x-layouts.marketing>
