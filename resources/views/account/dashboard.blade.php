<x-layouts.account :title="__('account.dashboard.title')">
    <x-account.page-header :title="__('account.dashboard.welcome', ['name' => $customer->first_name])" />

    @if ($openOffers > 0)
        <x-account.notice tone="warning" class="mb-6">
            {{ trans_choice('offers.dashboard_open', $openOffers, ['count' => $openOffers]) }}
            <a href="{{ route('account.offers.index') }}" class="font-medium underline underline-offset-4">{{ __('offers.dashboard_view') }}</a>
        </x-account.notice>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <x-account.panel :title="__('account.dashboard.subscription')" heading-id="dashboard-subscription">
            @if ($subscription)
                <x-slot:action>
                    <a href="{{ route('account.subscription') }}" class="rounded-sm text-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary">{{ __('account.dashboard.view_subscription') }}</a>
                </x-slot:action>

                <p class="text-lg font-semibold text-primary">{{ $subscription->planName() }} — {{ $subscription->product_type->label() }}</p>

                <dl class="mt-5 grid gap-5 text-sm sm:grid-cols-2">
                    <x-account.detail :label="__('account.dashboard.status')">
                        <x-ui.status-badge :tone="$subscription->status->tone()">{{ $subscription->status->label() }}</x-ui.status-badge>
                    </x-account.detail>
                    <x-account.detail :label="__('account.dashboard.next_billing')">
                        @if ($subscription->nextBillingDate())
                            <span class="font-mono">{{ $subscription->nextBillingDate()->translatedFormat('j M Y') }}</span>
                        @elseif ($subscription->status->grantsAccess() || $subscription->status->hasEnded())
                            {{ __('account.dashboard.no_renewal') }}
                        @endif
                    </x-account.detail>
                </dl>

                @if ($subscription->status === \App\Billing\SubscriptionStatus::Pending)
                    <x-account.notice class="mt-6">{{ __('account.dashboard.confirming') }}</x-account.notice>
                @elseif (in_array($subscription->status, [\App\Billing\SubscriptionStatus::PastDue, \App\Billing\SubscriptionStatus::Unpaid, \App\Billing\SubscriptionStatus::Incomplete], true))
                    <x-account.notice tone="warning" class="mt-6">{{ __('account.dashboard.payment_problem') }}</x-account.notice>
                @elseif ($subscription->status->hasEnded())
                    <x-account.notice class="mt-6">{{ __('account.dashboard.ended') }}</x-account.notice>
                @endif
            @else
                <p class="text-sm text-muted">{{ __('account.dashboard.no_subscription') }}</p>
                <x-ui.button :href="app(\App\Localization\MarketingRoutes::class)->pricing()" variant="secondary" class="mt-4">{{ __('account.dashboard.view_plans') }}</x-ui.button>
            @endif
        </x-account.panel>

        <x-account.panel :title="__('account.dashboard.websites')" heading-id="dashboard-websites">
            <x-slot:action>
                <a href="{{ route('account.websites.index') }}" class="rounded-sm text-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary">{{ __('account.dashboard.view_websites') }}</a>
            </x-slot:action>

            <p class="text-2xl font-semibold tracking-[-0.01em] text-primary tabular-nums">{{ __('account.dashboard.connected_count', ['count' => $connectedCount]) }}</p>
            <p class="mt-2 text-sm text-muted">{{ trans_choice('account.dashboard.added_count', $websiteCount) }}</p>
        </x-account.panel>
    </div>

        <section class="mt-6 flex flex-col gap-4 rounded-lg border border-accent bg-accent-soft p-6 sm:flex-row sm:items-center sm:justify-between" aria-labelledby="setup-title">
            <div class="flex items-start gap-4">
                <x-ui.icon-tile name="globe" tone="positive" class="bg-surface" />
                <div>
                    <h2 id="setup-title" class="text-base font-semibold">{{ __('account.dashboard.setup_title') }}</h2>
                    <p class="mt-1 text-sm text-ink">{{ __('account.dashboard.setup_text') }}</p>
                </div>
            </div>
            <x-ui.button :href="route('account.websites.create')" icon="arrow-right" class="self-start sm:self-auto">{{ __('account.websites.add_another') }}</x-ui.button>
        </section>
</x-layouts.account>
