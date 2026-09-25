{{--
    Status pill. Pass a App\Enums\Status via `status`, or a `tone` with slot text.
    variant: default (sentence case) | code (monospace, uppercase — for logs)
--}}
@props(['status' => null, 'tone' => null, 'variant' => 'default'])

@php
    $tone ??= $status?->tone() ?? 'neutral';

    $tones = [
        'positive' => 'border-accent/35 bg-accent-soft text-accent-ink',
        'warning' => 'border-warning/40 bg-warning-soft text-warning-ink',
        'danger' => 'border-danger/35 bg-danger-soft text-danger-ink',
        'neutral' => 'border-line bg-subtle text-ink',
    ];

    $variants = [
        'default' => 'text-xs font-medium',
        'code' => 'font-mono text-[11px] font-medium tracking-[0.06em] uppercase',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex h-6 shrink-0 items-center gap-1.5 rounded-full border px-2.5 whitespace-nowrap '.$tones[$tone].' '.$variants[$variant]]) }}>
    <x-ui.status-dot :tone="$tone" class="size-1.5" />
    {{ $slot->isEmpty() ? $status?->label() : $slot }}
</span>
