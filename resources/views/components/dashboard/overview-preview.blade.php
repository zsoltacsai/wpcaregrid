{{-- Full dashboard preview for the "central dashboard" section. Uses demo data only. --}}
@props(['sites', 'updates', 'licenses'])

@php
    $navigation = [
        ['label' => 'Overview', 'icon' => 'grid', 'active' => true],
        ['label' => 'Sites', 'icon' => 'globe'],
        ['label' => 'Backups', 'icon' => 'archive'],
        ['label' => 'Updates', 'icon' => 'refresh'],
        ['label' => 'Security', 'icon' => 'shield'],
        ['label' => 'Licenses', 'icon' => 'key'],
        ['label' => 'Activity log', 'icon' => 'list'],
    ];
@endphp

<x-dashboard.frame title="CareGrid dashboard" caption="Example of the WP CareGrid dashboard overview, showing demo data." {{ $attributes }}>
    <div class="lg:grid lg:grid-cols-[208px_minmax(0,1fr)]">
        <div class="hidden border-r border-line bg-canvas p-4 lg:block" aria-hidden="true">
            <ul class="space-y-1">
                @foreach ($navigation as $item)
                    <li @class([
                        'flex h-9 items-center gap-3 rounded-sm px-3 text-sm',
                        'bg-surface font-medium text-primary shadow-card ring-1 ring-line' => $item['active'] ?? false,
                        'text-muted' => ! ($item['active'] ?? false),
                    ])>
                        <x-ui.icon :name="$item['icon']" class="size-4" />
                        {{ $item['label'] }}
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="min-w-0">
            <div class="flex items-center justify-between gap-4 px-4 pt-6 pb-4 md:px-6">
                <p class="text-base font-semibold text-primary">Sites</p>
                <p class="font-mono text-xs text-muted">{{ count($sites) }} sites</p>
            </div>

            <div class="hidden grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.3fr)_104px] gap-x-4 border-y border-line bg-canvas px-6 py-2 font-mono text-[11px] tracking-[0.06em] text-muted uppercase md:grid" aria-hidden="true">
                <span>Site</span>
                <span>Plan</span>
                <span>Last backup</span>
                <span>Note</span>
                <span class="text-right">Status</span>
            </div>

            <ul class="divide-y divide-line border-t border-line md:border-t-0">
                @foreach ($sites as $site)
                    <x-dashboard.site-row :site="$site" variant="full" />
                @endforeach
            </ul>

            <div class="grid gap-4 border-t border-line bg-canvas p-4 md:grid-cols-2 md:p-6">
                <section aria-label="Pending updates (demo)" class="rounded-md border border-line bg-surface">
                    <p class="flex items-center justify-between border-b border-line px-4 py-3 text-sm font-semibold text-primary">
                        Pending updates
                        <span class="font-mono text-xs font-normal text-muted">{{ count($updates) }} shown</span>
                    </p>
                    <ul class="divide-y divide-line">
                        @foreach ($updates as $update)
                            <li class="flex items-center justify-between gap-4 px-4 py-2.5 text-sm">
                                <span class="min-w-0 truncate">
                                    <span class="text-primary">{{ $update['name'] }}</span>
                                    <span class="font-mono text-xs text-muted"> · {{ $update['site'] }}</span>
                                </span>
                                <span class="shrink-0 text-xs text-muted">{{ $update['type'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section aria-label="Licenses (demo)" class="rounded-md border border-line bg-surface">
                    <p class="flex items-center justify-between border-b border-line px-4 py-3 text-sm font-semibold text-primary">
                        Licenses
                        <span class="font-mono text-xs font-normal text-muted">{{ count($licenses) }} expired</span>
                    </p>
                    <ul class="divide-y divide-line">
                        @foreach ($licenses as $license)
                            <li class="flex items-center justify-between gap-4 px-4 py-2.5 text-sm">
                                <span class="min-w-0 truncate">
                                    <span class="text-primary">{{ $license['name'] }}</span>
                                    <span class="font-mono text-xs text-muted"> · {{ $license['site'] }}</span>
                                </span>
                                <x-ui.status-badge :status="$license['status']" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        </div>
    </div>
</x-dashboard.frame>
