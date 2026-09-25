@props(['tone' => 'neutral'])

@php
    $tones = [
        'positive' => 'bg-accent',
        'warning' => 'bg-warning',
        'danger' => 'bg-danger',
        'neutral' => 'bg-muted',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-block size-2 shrink-0 rounded-full '.$tones[$tone]]) }} aria-hidden="true"></span>
