{{-- Compact dashboard shown in the hero. Uses demo data only. --}}
@props(['sites', 'updateRun'])

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

    <div class="border-t border-line bg-canvas px-4 py-4 sm:px-6">
        <p class="text-xs text-muted">
            Latest update run <span aria-hidden="true">·</span> <span class="font-medium text-primary">{{ $updateRun['site'] }}</span>
        </p>
        <ol class="mt-3 grid grid-cols-2 gap-2">
            @foreach ($updateRun['steps'] as $step)
                <li class="flex items-center justify-between gap-2 rounded-sm border border-line bg-surface px-3 py-2">
                    <span class="flex min-w-0 items-center gap-1.5 text-xs leading-tight font-medium text-primary">
                        <x-ui.icon name="check" class="size-3.5 text-accent-ink" />
                        <span>{{ $step['label'] }}</span>
                    </span>
                    <span class="font-mono text-[11px] text-muted">{{ $step['time'] }}</span>
                </li>
            @endforeach
        </ol>
    </div>
</x-dashboard.frame>
