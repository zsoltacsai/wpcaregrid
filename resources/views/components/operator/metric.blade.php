{{-- One dashboard figure. tone colours the number only when it signals a problem. --}}
@props(['label', 'value', 'note' => null, 'tone' => 'neutral', 'href' => null])

@php
    $colors = ['neutral' => 'text-primary', 'positive' => 'text-accent-ink', 'warning' => 'text-warning-ink', 'danger' => 'text-danger-ink'];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'flex min-w-0 flex-col rounded-md border border-line bg-surface px-4 py-3'.($href ? ' transition-colors hover:border-primary' : '')]) }}>
    <span class="text-xs font-medium text-muted">{{ $label }}</span>
    <span class="mt-1 text-2xl font-semibold tabular-nums {{ $value > 0 ? $colors[$tone] : 'text-primary' }}">{{ number_format($value) }}</span>
    @if ($note)
        <span class="mt-0.5 truncate text-[11px] text-muted">{{ $note }}</span>
    @endif
</{{ $tag }}>
