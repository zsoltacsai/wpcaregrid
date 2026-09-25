{{-- Icon in a bordered square, used on feature cards. tone: neutral | plain | positive | warning | danger --}}
@props(['name', 'tone' => 'neutral'])

@php
    $tones = [
        'neutral' => 'border-line bg-canvas text-primary',
        'plain' => 'border-line bg-surface text-primary',
        'positive' => 'border-accent/30 bg-accent-soft text-accent-ink',
        'warning' => 'border-warning/30 bg-warning-soft text-warning-ink',
        'danger' => 'border-danger/30 bg-danger-soft text-danger-ink',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex size-10 shrink-0 items-center justify-center rounded-sm border '.$tones[$tone]]) }}>
    <x-ui.icon :name="$name" class="size-5" />
</span>
