<x-layouts.account :title="__('account.websites.options_title')">
    <x-account.page-header :title="__('account.websites.options_title')" :lead="__('account.websites.options_lead')" />
    @if ($errors->any())<x-account.notice tone="warning" class="mb-5">{{ $errors->first() }}</x-account.notice>@endif

    <x-account.panel :title="__('account.websites.selected_site')" class="mb-6">
        <p class="font-medium text-primary">{{ $target->url }}</p>
        <p class="mt-1 text-sm text-muted">{{ $target->type->label() }}</p>
    </x-account.panel>

    @if ($subscriptions->isNotEmpty())
        <x-account.panel :title="__('account.websites.use_existing_subscription')" class="mb-8">
            <ul class="divide-y divide-line">
                @foreach ($subscriptions as $subscription)
                    <li class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-medium text-primary">{{ $subscription->planName() }} — {{ $subscription->product_type->label() }}</p>
                            <p class="text-sm text-muted">{{ $subscription->status->label() }} · {{ $subscription->interval?->label() }}</p>
                        </div>
                        <form method="POST" action="{{ route('account.websites.assign') }}">
                            @csrf
                            <input type="hidden" name="subscription" value="{{ $subscription->uuid }}">
                            <x-ui.button type="submit" variant="secondary">{{ __('account.websites.use_subscription') }}</x-ui.button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </x-account.panel>
    @endif

    <h2 class="mb-4 text-xl font-semibold text-primary">{{ __('account.websites.buy_subscription') }}</h2>
    <p class="mb-5 text-sm text-muted">{{ __('account.websites.billing_currency', ['country' => $billingCountry, 'currency' => $currency ?? __('account.subscription.not_available')]) }}</p>
    <div class="grid gap-5 lg:grid-cols-3">
        @forelse ($offers as $offer)
            <x-account.panel :title="$offer->plan->name" :heading-id="'purchase-'.$offer->plan->tier->value">
                @if ($offer->plan->badge)<p class="text-xs font-semibold uppercase tracking-wide text-accent-ink">{{ $offer->plan->badge }}</p>@endif
                <p class="mt-2 text-sm text-muted">{{ $offer->plan->description }}</p>
                <ul class="mt-4 grid gap-2 text-sm text-ink">
                    @foreach ($offer->plan->featureLabels() as $feature)<li>✓ {{ $feature }}</li>@endforeach
                </ul>
                <div class="mt-5 border-t border-line pt-4 text-sm">
                    <p><strong>{{ __('account.websites.monthly') }}:</strong> {{ $offer->monthly->money->format() }} / {{ __('pricing.intervals.monthly') }}</p>
                    <p class="mt-2"><strong>{{ __('account.websites.annual') }}:</strong> {{ $offer->annual->money->format() }} / {{ __('pricing.intervals.annual') }}</p>
                    <p class="mt-2 text-muted">{{ __('account.websites.renewal', ['interval' => __('pricing.intervals.monthly')]) }} {{ __('account.websites.renewal', ['interval' => __('pricing.intervals.annual')]) }}</p>
                    @if ($offer->freeMonthsOnAnnual() > 0)
                        <p class="mt-2 text-positive-ink">{{ __('account.websites.annual_savings', ['amount' => $offer->annualSavings()->format(), 'months' => $offer->freeMonthsOnAnnual()]) }}</p>
                    @endif
                </div>
                <div class="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-1">
                    @foreach ([\App\Pricing\BillingInterval::Monthly, \App\Pricing\BillingInterval::Annual] as $interval)
                        <form method="POST" action="{{ route('account.websites.purchase') }}">
                            @csrf
                            <input type="hidden" name="plan" value="{{ $offer->plan->tier->value }}">
                            <input type="hidden" name="interval" value="{{ $interval->value }}">
                            <x-ui.button type="submit" variant="secondary" class="w-full">{{ __('account.websites.choose_interval', ['interval' => $interval->label()]) }}</x-ui.button>
                        </form>
                    @endforeach
                </div>
            </x-account.panel>
        @empty
            <x-account.notice>{{ __('account.websites.no_available_prices') }}</x-account.notice>
        @endforelse
    </div>
</x-layouts.account>
