@props(['metric'])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-4 rounded-md border border-line bg-surface p-4 sm:p-6']) }}>
    <div class="flex items-start justify-between gap-4">
        <x-ui.icon-tile :name="$metric['icon']" :tone="$metric['tone']" />
    </div>
    <div>
        <p class="text-[32px] leading-none font-semibold tracking-[-0.02em] text-primary sm:text-[40px]">{{ $metric['value'] }}</p>
        <p class="mt-2 text-sm font-medium text-ink">{{ $metric['label'] }}</p>
        <p class="mt-1 truncate text-xs text-muted">{{ $metric['detail'] }}</p>
    </div>
</div>
