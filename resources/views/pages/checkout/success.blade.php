{{--
    Where Stripe sends the customer after checkout. This page never
    activates anything: it only reports what the verified webhook has done.
--}}
@php
    $confirmed = $intent?->status === \App\Checkout\CheckoutStatus::Completed;
    $accountPurchase = $intent && auth()->user()?->customer?->id === $intent->customer_id;
    $website = $accountPurchase ? $intent->subscription?->website : null;
@endphp

<x-layouts.marketing :title="__('checkout.meta.success_title')" :description="__('home.meta.description')">
    <section class="py-16 sm:py-20 lg:py-24" aria-labelledby="success-title">
        <x-ui.container class="max-w-[720px]">
            <div class="rounded-lg border border-line bg-surface p-6 sm:p-10">
                @if ($confirmed)
                    <x-ui.icon-tile name="check-circle" tone="positive" />
                    <h1 id="success-title" class="mt-6 text-[28px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[32px]">{{ __('checkout.success.title') }}</h1>
                    <p class="mt-3 text-lg text-ink">{{ __('checkout.success.lead') }}</p>
                    <p class="mt-4 text-base leading-relaxed text-muted">
                        {{ $accountPurchase ? __('account.success.existing_account_complete') : ($intent->customer?->user?->hasVerifiedEmail() ? __('account.dashboard.setup_text') : __('account.success.account_email')) }}
                    </p>
                @else
                    <x-ui.icon-tile name="clock" />
                    <h1 id="success-title" class="mt-6 text-[28px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[32px]">{{ __('account.success.confirming_title') }}</h1>
                    <p class="mt-4 text-base leading-relaxed text-muted">{{ __('account.success.confirming_body') }}</p>
                @endif

                @if ($intent)
                    <dl class="mt-8 grid gap-4 border-t border-line pt-6 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted">{{ __('checkout.success.plan') }}</dt>
                            <dd class="mt-1 font-medium text-primary">
                                {{ $intent->product_type->label() }} {{ __("pricing.plans.{$intent->product_type->value}.{$intent->plan->value}.name") }}
                                · {{ $intent->interval->label() }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted">{{ __('checkout.success.order') }}</dt>
                            <dd class="mt-1 font-mono text-primary">{{ strtoupper(substr($intent->uuid, 0, 8)) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-sm text-muted">{{ __('checkout.success.email_note', ['email' => $intent->email]) }}</p>
                @endif

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    @if ($confirmed)
                        @if ($accountPurchase)
                            <x-ui.button :href="$website ? route('account.websites.show', $website) : route('account.subscription')">{{ $website ? __('account.websites.connect_website') : __('account.success.open_account') }}</x-ui.button>
                        @else
                            <x-ui.button :href="route('login')">{{ __('account.success.login') }}</x-ui.button>
                        @endif
                    @elseif ($accountPurchase)
                        <x-ui.button :href="route('account.dashboard')" variant="secondary">{{ __('account.success.open_account') }}</x-ui.button>
                    @else
                        <x-ui.button :href="request()->fullUrl()" variant="secondary">{{ __('account.success.refresh') }}</x-ui.button>
                    @endif
                    <x-ui.button :href="app(\App\Localization\MarketingRoutes::class)->home()" variant="ghost">{{ __('checkout.success.home') }}</x-ui.button>
                </div>
            </div>
        </x-ui.container>
    </section>
</x-layouts.marketing>
