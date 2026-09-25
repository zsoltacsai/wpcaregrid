{{--
    Link or button styled as a call to action.
    variant: primary (green action) | secondary | ghost | inverse-outline (on dark)
    size: md | lg
--}}
@props(['href' => null, 'variant' => 'primary', 'size' => 'md', 'icon' => null])

@php
    $variants = [
        'primary' => 'bg-action text-white hover:bg-action-hover',
        'secondary' => 'border border-line-strong bg-surface text-primary hover:border-primary',
        'ghost' => 'text-primary hover:bg-subtle',
        // Destructive actions (restore). danger-ink on white text is 6:1.
        'danger' => 'bg-danger-ink text-white hover:bg-danger',
        'inverse-outline' => 'border border-on-dark/35 text-on-dark hover:border-on-dark hover:bg-white/5',
    ];

    $sizes = [
        'md' => 'h-10 px-4 text-sm',
        'lg' => 'h-12 px-6 text-[15px]',
    ];

    $classes = 'inline-flex items-center justify-center gap-2 rounded-sm font-medium whitespace-nowrap transition-colors duration-150 '
        .$variants[$variant].' '.$sizes[$size];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>
        {{ $slot }}
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
    </button>
@endif
