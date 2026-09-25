{{--
    Page section with consistent vertical rhythm.
    tone: canvas | surface | dark
    spacing: default | compact
--}}
@props(['tone' => 'canvas', 'spacing' => 'default', 'labelledby' => null])

@php
    $tones = [
        'canvas' => 'bg-canvas',
        'surface' => 'bg-surface border-y border-line',
        'dark' => 'on-dark bg-primary text-on-dark',
    ];

    $spacings = [
        'default' => 'py-16 sm:py-20 lg:py-24',
        'compact' => 'py-12 lg:py-16',
    ];
@endphp

<section
    {{ $attributes->merge(['class' => $spacings[$spacing].' '.$tones[$tone]]) }}
    @if ($labelledby) aria-labelledby="{{ $labelledby }}" @endif
>
    <x-ui.container>
        {{ $slot }}
    </x-ui.container>
</section>
