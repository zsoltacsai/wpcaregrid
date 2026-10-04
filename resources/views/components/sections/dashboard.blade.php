@props(['content', 'metrics', 'sites', 'updates', 'licenses'])

<x-ui.section tone="surface" labelledby="dashboard-title" :lang="$content->fallbackLang()">
    <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <x-ui.section-heading
            id="dashboard-title"
            :eyebrow="$content['eyebrow']"
            :title="$content['title']"
            :lead="$content['description']"
        />
        <p class="flex flex-wrap items-center gap-3 text-sm text-muted">
            <x-ui.demo-label />
            <span>{{ $content['demo_note'] }}</span>
        </p>
    </div>

    <ul class="mt-10 grid grid-cols-2 gap-4 lg:grid-cols-4 lg:gap-6" aria-label="{{ __('home.dashboard.metrics_label') }}">
        @foreach ($metrics as $metric)
            <li><x-dashboard.metric-card :metric="$metric" class="h-full" /></li>
        @endforeach
    </ul>

    <x-dashboard.dashboard-preview
        variant="full"
        :sites="$sites"
        :updates="$updates"
        :licenses="$licenses"
        class="mt-6 lg:mt-8"
    />
</x-ui.section>
