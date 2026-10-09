<x-layouts.marketing :title="__('checkout.meta.cancel_title')" :description="__('home.meta.description')">
    <section class="py-16 sm:py-20 lg:py-24" aria-labelledby="cancel-title">
        <x-ui.container class="max-w-[720px]">
            <div class="rounded-lg border border-line bg-surface p-6 sm:p-10">
                <x-ui.icon-tile name="info" />
                <h1 id="cancel-title" class="mt-6 text-[28px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[32px]">
                    {{ __('checkout.cancel.title') }}
                </h1>
                <p class="mt-3 text-lg text-ink">{{ __('checkout.cancel.lead') }}</p>
                <p class="mt-4 text-base leading-relaxed text-muted">{{ __('checkout.cancel.body') }}</p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <x-ui.button :href="$retryUrl" icon="arrow-right">{{ __('checkout.cancel.retry') }}</x-ui.button>
                    <x-ui.button :href="app(\App\Localization\MarketingRoutes::class)->pricing()" variant="secondary">{{ __('checkout.cancel.pricing') }}</x-ui.button>
                </div>
            </div>
        </x-ui.container>
    </section>
</x-layouts.marketing>
