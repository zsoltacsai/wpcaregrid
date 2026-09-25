{{-- One entry in an activity log. `entry` matches App\Content\DemoDashboard::activity(). --}}
@props(['entry'])

@php
    $date = \Illuminate\Support\Carbon::parse($entry['date']);
@endphp

<li {{ $attributes->merge(['class' => 'grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 px-4 py-4 sm:grid-cols-[112px_minmax(0,1fr)_auto] sm:items-center sm:px-6']) }}>
    <p class="font-mono text-xs text-muted sm:text-sm">
        <time datetime="{{ $date->toDateString() }}T{{ $entry['time'] }}">{{ $date->format('j M Y') }}</time>
        <span class="sm:hidden"> · {{ $entry['time'] }}</span>
    </p>
    <div class="col-span-1 row-start-2 min-w-0 sm:row-start-auto">
        <p class="text-sm font-medium text-primary">{{ $entry['event'] }}</p>
        <p class="mt-0.5 truncate text-xs text-muted sm:text-sm">
            {{ $entry['detail'] }} <span aria-hidden="true">·</span> <span class="font-mono">{{ $entry['site'] }}</span>
        </p>
    </div>
    <div class="col-start-2 row-span-2 row-start-1 self-center sm:col-start-auto sm:row-span-1 sm:row-start-auto">
        <x-ui.status-badge :status="$entry['status']" variant="code" />
    </div>
</li>
