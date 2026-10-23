<x-layouts.account :title="__('account.subscription.title')">
    <x-account.page-header :title="__('account.subscription.title')" :lead="__('account.subscription.lead')" />
    <div class="mb-6"><x-ui.button :href="route('account.websites.create')" icon="plus">{{ __('account.websites.add_another') }}</x-ui.button></div>

    @forelse ($subscriptions as $subscription)
        <x-account.panel :title="$subscription->planName().' — '.$subscription->product_type->label()" :heading-id="'subscription-'.$subscription->uuid" class="mb-6">
            <x-account.subscription-details :subscription="$subscription" />

            @if ($subscription->pending_change_status)
                <p class="mt-4 rounded-sm border border-line bg-canvas p-3 text-sm text-muted">
                    {{ __('account.subscription.pending_change', ['plan' => app(\App\Pricing\PricingCatalog::class)->plan($subscription->product_type, \App\Pricing\PlanTier::from($subscription->pending_plan))->name, 'interval' => \App\Pricing\BillingInterval::from($subscription->pending_interval)->label(), 'date' => $subscription->pending_change_effective_at?->timezone(config('app.timezone'))->translatedFormat('j M Y') ?? __('account.subscription.not_available')]) }}
                </p>
            @endif

            <div class="mt-6 flex flex-col gap-2 border-t border-line pt-6 sm:flex-row sm:items-center sm:gap-4">
                @if ($subscription->billing_source === 'stripe' && ! $subscription->isInternal() && in_array($subscription->status, [\App\Billing\SubscriptionStatus::Active, \App\Billing\SubscriptionStatus::Trialing, \App\Billing\SubscriptionStatus::PastDue], true))
                    @if ($subscription->cancel_at_period_end)
                        <form method="POST" action="{{ route('account.subscriptions.reactivate', $subscription) }}">@csrf
                            <x-ui.button type="submit" variant="secondary">{{ __('account.subscription.reactivate') }}</x-ui.button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('account.subscriptions.cancel', $subscription) }}">@csrf
                            <x-ui.button type="submit" variant="secondary">{{ __('account.subscription.cancel') }}</x-ui.button>
                        </form>
                    @endif
                    @if (! $subscription->pending_change_status)
                        <form method="POST" action="{{ route('account.subscriptions.change', $subscription) }}" class="mt-4 grid gap-3 border-t border-line pt-4 sm:grid-cols-[1fr_1fr_auto]">
                            @csrf
                            <label class="text-sm">{{ __('account.subscription.plan') }}
                                <select name="plan" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3">
                                    @foreach (\App\Pricing\PlanTier::cases() as $tier)
                                        <option value="{{ $tier->value }}" @selected($subscription->plan === $tier)>{{ app(\App\Pricing\PricingCatalog::class)->plan($subscription->product_type, $tier)->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-sm">{{ __('account.subscription.interval') }}
                                <select name="interval" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3">
                                    @foreach (\App\Pricing\BillingInterval::cases() as $interval)
                                        <option value="{{ $interval->value }}" @selected($subscription->interval === $interval)>{{ $interval->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <div class="self-end"><x-ui.button type="submit" variant="secondary">{{ __('account.subscription.change_plan') }}</x-ui.button></div>
                        </form>
                    @endif
                    @if ($subscription->stripe_customer_id)
                        <form method="POST" action="{{ route('account.billing.payment-method') }}" class="mt-3">
                            @csrf
                            <x-ui.button type="submit" variant="secondary">{{ __('account.subscription.manage_payment_method') }}</x-ui.button>
                        </form>
                    @endif
                @else
                    <p class="text-sm text-muted">{{ __('account.subscription.manage_note') }}</p>
                @endif
            </div>
        </x-account.panel>
    @empty
        <x-account.panel>
            <p class="text-sm text-muted">{{ __('account.subscription.none') }}</p>
            <x-ui.button :href="route('account.websites.create')" icon="plus" variant="secondary" class="mt-4">{{ __('account.websites.add_another') }}</x-ui.button>
        </x-account.panel>
    @endforelse
</x-layouts.account>
