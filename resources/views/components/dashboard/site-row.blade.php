{{--
    One website in a dashboard list.
    variant: compact (hero preview) | full (all states; a table row from lg up, stacked below)
--}}
@props(['site', 'variant' => 'compact'])

@php
    $platform = __("dashboard.platforms.{$site['platform']}");
    $backupDay = __("dashboard.days.{$site['last_backup']['day']}");
    $backupTime = $site['last_backup']['time'];
@endphp

@if ($variant === 'compact')
    <li {{ $attributes->merge(['class' => 'flex items-center justify-between gap-4 px-4 py-3 sm:px-6']) }}>
        <div class="min-w-0">
            <p class="flex items-center gap-2">
                <x-ui.status-dot :tone="$site['status']->tone()" />
                <span class="truncate text-sm font-medium text-primary">{{ $site['domain'] }}</span>
            </p>
            <p class="mt-1 truncate pl-4 text-xs text-muted">
                {{ $platform }} <span aria-hidden="true">·</span>
                <span class="whitespace-nowrap">{{ __('dashboard.backup_at', ['when' => $backupDay]) }} <span class="font-mono">{{ $backupTime }}</span></span>
            </p>
        </div>
        <x-ui.status-badge :status="$site['status']" />
    </li>
@else
    <li {{ $attributes->merge(['class' => 'px-4 py-3 md:px-6']) }}>
        {{-- Below lg: domain and status, then the states as a wrapped list. --}}
        <div class="lg:hidden">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="flex items-center gap-2">
                        <x-ui.status-dot :tone="$site['status']->tone()" />
                        <span class="truncate text-sm font-medium text-primary">{{ $site['domain'] }}</span>
                    </p>
                    <p class="mt-0.5 pl-4 text-xs text-muted">
                        {{ $platform }} <span aria-hidden="true">·</span>
                        <span class="whitespace-nowrap">{{ __('dashboard.backup_at', ['when' => $backupDay]) }} <span class="font-mono">{{ $backupTime }}</span></span>
                    </p>
                </div>
                <x-ui.status-badge :status="$site['status']" />
            </div>
            <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 pl-4 text-xs">
                @foreach (['updates', 'security', 'license'] as $type)
                    <div class="flex items-center gap-1">
                        <dt class="sr-only">{{ __("dashboard.columns.{$type}") }}</dt>
                        <dd><x-dashboard.site-state :type="$type" :site="$site" /></dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- lg and up: one row per site, aligned with the column headers. --}}
        <div class="hidden items-center gap-x-4 text-sm lg:grid lg:grid-cols-(--dashboard-columns)">
            <div class="min-w-0">
                <p class="flex items-center gap-2">
                    <x-ui.status-dot :tone="$site['status']->tone()" />
                    <span class="truncate font-medium text-primary">{{ $site['domain'] }}</span>
                </p>
                <p class="mt-0.5 truncate pl-4 text-xs text-muted">{{ $platform }}</p>
            </div>
            <p class="text-ink">{{ $backupDay }} <span class="font-mono">{{ $backupTime }}</span></p>
            <x-dashboard.site-state type="updates" :site="$site" />
            <x-dashboard.site-state type="security" :site="$site" />
            <x-dashboard.site-state type="license" :site="$site" />
            <div class="justify-self-end">
                <x-ui.status-badge :status="$site['status']" />
            </div>
        </div>
    </li>
@endif
