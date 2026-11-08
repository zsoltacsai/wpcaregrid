{{--
    Empty state for a list or panel: icon, short title, one helpful sentence and an
    optional action. `heading` sets the heading level so the page outline stays
    in order (h2 on a page whose only heading is the h1, h3 inside a panel).
--}}
@props(['title', 'text' => null, 'icon' => 'globe', 'heading' => 'h3'])

<div {{ $attributes->merge(['class' => 'm16-empty flex flex-col items-start gap-3 sm:items-center sm:text-center']) }}>
    <x-ui.icon-tile :name="$icon" tone="positive" />
    <{{ $heading }} class="text-base font-semibold text-primary">{{ $title }}</{{ $heading }}>
    @if ($text)
        <p class="max-w-md text-sm leading-relaxed text-muted">{{ $text }}</p>
    @endif
    @isset($action)
        <div class="mt-1">{{ $action }}</div>
    @endisset
</div>
