{{-- A bordered block of the operator console, denser than the customer panel. --}}
@props(['title' => null, 'id' => null])

<section {{ $attributes->merge(['class' => 'min-w-0 rounded-md border border-line bg-surface']) }} @if ($id) id="{{ $id }}" aria-labelledby="{{ $id }}-title" @endif>
    @if ($title)
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
            <h2 @if ($id) id="{{ $id }}-title" @endif class="text-sm font-semibold text-primary">{{ $title }}</h2>
            {{ $action ?? '' }}
        </div>
    @endif
    <div class="p-4">
        {{ $slot }}
    </div>
</section>
