@props(['title', 'lead' => null])

<div {{ $attributes->merge(['class' => 'mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div>
        <h1 class="text-[28px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[32px]">{{ $title }}</h1>
        @if ($lead)
            <p class="mt-2 text-base text-muted">{{ $lead }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 gap-3">{{ $actions }}</div>
    @endisset
</div>
