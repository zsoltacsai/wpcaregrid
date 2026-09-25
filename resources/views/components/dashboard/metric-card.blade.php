{{-- A single dashboard figure. `metric` matches App\Content\DemoDashboard::metrics(). --}}
@props(['metric'])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-4 rounded-md border border-line bg-surface p-4 sm:p-6']) }}>
    <x-ui.icon-tile :name="$metric['icon']" :tone="$metric['tone']" />
    <div>
        <p class="text-[32px] leading-none font-semibold tracking-[-0.02em] text-primary tabular-nums sm:text-[40px]">{{ $metric['value'] }}</p>
        <p class="mt-2 text-sm font-medium text-ink">{{ __("dashboard.metrics.{$metric['key']}.label") }}</p>
        <p class="mt-1 truncate text-xs text-muted">{{ __("dashboard.metrics.{$metric['key']}.detail") }}</p>
    </div>
</div>
