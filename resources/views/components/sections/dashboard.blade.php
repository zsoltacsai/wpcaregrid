@props(['metrics', 'sites', 'updates', 'licenses'])

<x-ui.section tone="surface" labelledby="dashboard-title">
    <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <x-ui.section-heading
            id="dashboard-title"
            eyebrow="Dashboard"
            title="All your websites in one place."
            lead="Backups, pending updates, security findings and license status for every site you have with us."
        />
        <p class="flex items-center gap-3 text-sm text-muted">
            <x-ui.demo-label text="Demonstration" />
            <span>Illustrative figures, not real customer data.</span>
        </p>
    </div>

    <ul class="mt-10 grid grid-cols-2 gap-4 lg:grid-cols-4 lg:gap-6" aria-label="Example dashboard metrics (demo data)">
        @foreach ($metrics as $metric)
            <li><x-dashboard.metric-card :metric="$metric" class="h-full" /></li>
        @endforeach
    </ul>

    <x-dashboard.overview-preview :sites="$sites" :updates="$updates" :licenses="$licenses" class="mt-6 lg:mt-8" />
</x-ui.section>
