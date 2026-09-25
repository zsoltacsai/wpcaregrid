{{--
    One website in a dashboard list.
    variant: compact (hero preview) | full (dashboard preview, adds plan and update columns on wide screens)
--}}
@props(['site', 'variant' => 'compact'])

@php
    $updates = $site['updates'] === 0 ? 'Up to date' : $site['updates'].' '.Str::plural('update', $site['updates']).' pending';
@endphp

@if ($variant === 'compact')
    <li {{ $attributes->merge(['class' => 'flex items-center justify-between gap-4 px-4 py-3 sm:px-6']) }}>
        <div class="min-w-0">
            <p class="flex items-center gap-2">
                <x-ui.status-dot :tone="$site['status']->tone()" />
                <span class="truncate font-mono text-sm font-medium text-primary">{{ $site['domain'] }}</span>
            </p>
            <p class="mt-1 truncate pl-4 text-xs text-muted">
                {{ $site['platform'] }} <span aria-hidden="true">·</span> Backup {{ $site['last_backup'] }}
            </p>
        </div>
        <x-ui.status-badge :status="$site['status']" />
    </li>
@else
    <li {{ $attributes->merge(['class' => 'grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 px-4 py-3 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.3fr)_104px] md:px-6']) }}>
        <div class="min-w-0">
            <p class="flex items-center gap-2">
                <x-ui.status-dot :tone="$site['status']->tone()" />
                <span class="truncate font-mono text-sm font-medium text-primary">{{ $site['domain'] }}</span>
            </p>
            <p class="mt-0.5 truncate pl-4 text-xs text-muted">{{ $site['platform'] }} <span class="md:hidden">· {{ $site['plan'] }}</span></p>
        </div>
        <p class="hidden text-sm text-ink md:block">{{ $site['plan'] }}</p>
        <p class="col-start-1 row-start-2 pl-4 text-xs text-muted md:col-start-auto md:row-start-auto md:pl-0 md:text-sm md:text-ink">
            <span class="md:sr-only">Last backup:</span> <span class="font-mono">{{ $site['last_backup'] }}</span>
        </p>
        <p class="hidden text-sm text-ink md:block">{{ $site['note'] }}</p>
        <div class="col-start-2 row-span-2 row-start-1 justify-self-end md:col-start-auto md:row-span-1 md:row-start-auto">
            <x-ui.status-badge :status="$site['status']" />
        </div>
    </li>
@endif
