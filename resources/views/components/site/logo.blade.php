{{-- WP CareGrid wordmark. tone: light (on light backgrounds) | dark (on the primary colour) --}}
@props(['tone' => 'light'])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <svg class="size-7 shrink-0" viewBox="0 0 28 28" aria-hidden="true" focusable="false">
        <rect width="28" height="28" rx="6" fill="{{ $tone === 'dark' ? '#F6F6F2' : '#17212B' }}" />
        <rect x="7" y="7" width="6" height="6" rx="1.25" fill="{{ $tone === 'dark' ? '#17212B' : '#F6F6F2' }}" />
        <rect x="15" y="7" width="6" height="6" rx="1.25" fill="{{ $tone === 'dark' ? '#17212B' : '#F6F6F2' }}" />
        <rect x="7" y="15" width="6" height="6" rx="1.25" fill="{{ $tone === 'dark' ? '#17212B' : '#F6F6F2' }}" />
        <rect x="15" y="15" width="6" height="6" rx="1.25" fill="#5B8C72" />
    </svg>
    <span @class([
        'text-[17px] font-semibold tracking-[-0.01em]',
        'text-primary' => $tone === 'light',
        'text-on-dark' => $tone === 'dark',
    ])>WP CareGrid</span>
</span>
