{{--
    Application-window chrome for dashboard previews. Always shows the demo
    label so preview data is never mistaken for real customer data.
--}}
@props(['title', 'caption'])

<figure {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-line bg-surface shadow-raised']) }}>
    <figcaption class="sr-only">{{ $caption }}</figcaption>

    <div class="flex items-center justify-between gap-4 border-b border-line bg-canvas px-4 py-3 sm:px-6">
        <div class="flex min-w-0 items-center gap-3">
            <svg class="size-5 shrink-0" viewBox="0 0 28 28" aria-hidden="true" focusable="false">
                <rect width="28" height="28" rx="6" fill="#17212B" />
                <rect x="7" y="7" width="6" height="6" rx="1.25" fill="#F6F6F2" />
                <rect x="15" y="7" width="6" height="6" rx="1.25" fill="#F6F6F2" />
                <rect x="7" y="15" width="6" height="6" rx="1.25" fill="#F6F6F2" />
                <rect x="15" y="15" width="6" height="6" rx="1.25" fill="#5B8C72" />
            </svg>
            <p class="truncate text-sm font-medium text-primary">{{ $title }}</p>
        </div>
        <x-ui.demo-label />
    </div>

    {{ $slot }}
</figure>
