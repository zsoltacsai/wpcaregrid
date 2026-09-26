{{-- Compact page heading for the operator console. --}}
@props(['title', 'lead' => null, 'eyebrow' => null])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        @if ($eyebrow)
            <div class="mb-1 text-xs text-muted">{{ $eyebrow }}</div>
        @endif
        <h1 class="text-2xl leading-tight font-semibold tracking-[-0.01em] break-words text-primary">{{ $title }}</h1>
        @if ($lead)
            <p class="mt-1 text-sm text-muted">{{ $lead }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap gap-2">{{ $actions }}</div>
    @endisset
</div>
