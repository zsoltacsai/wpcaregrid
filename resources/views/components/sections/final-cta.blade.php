@props(['content'])

<x-ui.section tone="dark" labelledby="cta-title" class="border-t border-primary" :lang="$content->fallbackLang()">
    <div class="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
        <div class="max-w-2xl">
            <h2 id="cta-title" class="text-[28px] leading-[1.15] font-semibold tracking-[-0.015em] text-on-dark sm:text-[32px] lg:text-[40px]">
                {{ $content['title'] }}
            </h2>
            <p class="mt-4 text-base text-on-dark-muted sm:text-lg">{{ $content['description'] }}</p>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row">
            <x-ui.button :href="app(\App\Localization\MarketingRoutes::class)->localize($content->url('primary_url'))" size="lg" icon="arrow-right">{{ $content['primary_label'] }}</x-ui.button>
            <x-ui.button :href="app(\App\Localization\MarketingRoutes::class)->localize($content->url('secondary_url'))" variant="inverse-outline" size="lg">{{ $content['secondary_label'] }}</x-ui.button>
        </div>
    </div>
</x-ui.section>
