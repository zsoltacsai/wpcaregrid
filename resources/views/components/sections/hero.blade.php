@props(['content', 'trustIndicators'])

<section aria-labelledby="hero-title" class="pt-8 pb-16 sm:pt-12 sm:pb-20 lg:pt-16 lg:pb-24" @if ($content->fallbackLang()) lang="{{ $content->fallbackLang() }}" @endif>
    <x-ui.container class="max-w-3xl">
        <div>
            <p class="mb-4 text-xs font-semibold tracking-[0.12em] text-muted uppercase">{{ $content['eyebrow'] }}</p>

            <h1 id="hero-title" class="text-[34px] leading-[1.12] font-semibold tracking-[-0.02em] sm:text-[42px] lg:text-[48px] lg:leading-[1.1]">
                {{ $content['title'] }}
            </h1>

            <p class="mt-6 max-w-xl text-lg leading-relaxed text-muted">{{ $content['description'] }}</p>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <x-ui.button :href="app(\App\Localization\MarketingRoutes::class)->localize($content->url('primary_cta_url'))" size="lg" icon="arrow-right">{{ $content['primary_cta_label'] }}</x-ui.button>
                <x-ui.button :href="app(\App\Localization\MarketingRoutes::class)->localize($content->url('secondary_cta_url'))" variant="secondary" size="lg">{{ $content['secondary_cta_label'] }}</x-ui.button>
            </div>

            <ul class="mt-8 flex flex-col gap-3 text-sm text-ink sm:flex-row sm:flex-wrap sm:gap-x-6">
                @foreach ($trustIndicators as $indicator)
                    <li class="flex items-center gap-2">
                        <x-ui.icon :name="$indicator['icon']" class="size-4 text-accent-ink" />
                        {{ $indicator['label'] }}
                    </li>
                @endforeach
            </ul>
        </div>

    </x-ui.container>
</section>
