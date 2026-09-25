{{-- Inline message. tone: positive | warning | danger | neutral --}}
@props(['tone' => 'neutral'])

@php
    $styles = [
        'positive' => ['border-accent/35 bg-accent-soft text-accent-ink', 'check-circle'],
        'warning' => ['border-warning/40 bg-warning-soft text-warning-ink', 'alert-triangle'],
        'danger' => ['border-danger/35 bg-danger-soft text-danger-ink', 'alert-circle'],
        'neutral' => ['border-line bg-surface text-ink', 'info'],
    ];
    [$classes, $icon] = $styles[$tone];
@endphp

<p role="{{ in_array($tone, ['warning', 'danger']) ? 'alert' : 'status' }}" {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-md border px-4 py-3 text-sm '.$classes]) }}>
    <x-ui.icon :name="$icon" class="mt-px size-4" />
    <span>{{ $slot }}</span>
</p>
