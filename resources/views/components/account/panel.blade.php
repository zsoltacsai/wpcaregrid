{{-- Bordered section of an account page, with an optional heading row. --}}
@props(['title' => null, 'headingId' => null])

<section {{ $attributes->merge(['class' => 'rounded-lg border border-line bg-surface']) }} @if ($headingId) aria-labelledby="{{ $headingId }}" @endif>
    @if ($title)
        <div class="flex items-center justify-between gap-4 border-b border-line px-6 py-4">
            <h2 @if ($headingId) id="{{ $headingId }}" @endif class="text-base font-semibold">{{ $title }}</h2>
            {{ $action ?? '' }}
        </div>
    @endif
    <div class="p-6">
        {{ $slot }}
    </div>
</section>
