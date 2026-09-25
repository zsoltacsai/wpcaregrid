{{-- Icon, title and short text. Used for services, problems and development offerings. --}}
@props(['icon', 'title', 'tone' => 'neutral', 'background' => 'surface', 'level' => 'h3'])

<div {{ $attributes->merge(['class' => 'flex h-full flex-col gap-4 rounded-md border border-line p-6 '.($background === 'canvas' ? 'bg-canvas' : 'bg-surface')]) }}>
    <x-ui.icon-tile :name="$icon" :tone="$background === 'canvas' && $tone === 'neutral' ? 'plain' : $tone" />
    <div>
        <{{ $level }} class="text-base font-semibold">{{ $title }}</{{ $level }}>
        <p class="mt-2 text-sm leading-relaxed text-muted">{{ $slot }}</p>
    </div>
</div>
