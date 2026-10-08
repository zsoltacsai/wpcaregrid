@props(['content', 'services'])

<x-ui.section id="custom-development" labelledby="development-title" :lang="$content->fallbackLang()">
    <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <x-ui.section-heading
            id="development-title"
            :eyebrow="$content['eyebrow']"
            :title="$content['title']"
            :lead="$content['description']"
        />

        <x-ui.button :href="app(\App\Localization\MarketingRoutes::class)->localize($content->url('cta_url'))" size="lg" icon="arrow-right" class="self-start lg:self-auto">
            {{ $content['cta_label'] }}
        </x-ui.button>
    </div>

    <ul class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
        @foreach ($services as $service)
            <li>
                <x-ui.service-card :icon="$service['icon']" :title="$service['title']">
                    {{ $service['text'] }}
                </x-ui.service-card>
            </li>
        @endforeach
    </ul>

    @if (filled($content['note']))
        <p class="mt-6 text-sm text-muted">{{ $content['note'] }}</p>
    @endif
</x-ui.section>
