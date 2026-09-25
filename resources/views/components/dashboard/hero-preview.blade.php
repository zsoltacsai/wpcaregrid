{{-- Compact dashboard shown in the hero. Uses demo data only. --}}
@props(['sites'])

@php
    $counts = collect($sites)->countBy(fn ($site) => $site['status']->tone());
@endphp

<x-dashboard.frame title="Sites overview" caption="Example of the WP CareGrid dashboard, showing demo data." {{ $attributes }}>
    <dl class="grid grid-cols-3 divide-x divide-line border-b border-line">
        @foreach (['positive' => 'Healthy', 'warning' => 'Attention', 'danger' => 'Critical'] as $tone => $label)
            <div class="px-4 py-4 sm:px-6">
                <dt class="flex items-center gap-2 text-xs text-muted">
                    <x-ui.status-dot :tone="$tone" />
                    {{ $label }}
                </dt>
                <dd class="mt-1 text-2xl font-semibold text-primary">{{ $counts->get($tone, 0) }}</dd>
            </div>
        @endforeach
    </dl>

    <ul class="divide-y divide-line">
        @foreach ($sites as $site)
            <x-dashboard.site-row :site="$site" />
        @endforeach
    </ul>

    <div class="flex items-center justify-between gap-4 border-t border-line bg-canvas px-4 py-3 font-mono text-xs text-muted sm:px-6">
        <span>Next backup run 03:00</span>
        <span class="hidden sm:inline">Example data</span>
    </div>
</x-dashboard.frame>
