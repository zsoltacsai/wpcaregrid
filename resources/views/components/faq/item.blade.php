{{--
    Accordion item. Must be used inside an element whose Alpine scope defines
    `active` (the open item's id or null).
--}}
@props(['id', 'question'])

<div {{ $attributes->merge(['class' => 'border-b border-line']) }}>
    <h3>
        <button
            type="button"
            id="{{ $id }}-button"
            aria-controls="{{ $id }}-panel"
            aria-expanded="false"
            :aria-expanded="(active === '{{ $id }}').toString()"
            @click="active = active === '{{ $id }}' ? null : '{{ $id }}'"
            class="flex w-full items-center justify-between gap-6 rounded-sm py-5 text-left text-base font-medium text-primary transition-colors hover:text-accent-ink"
        >
            <span>{{ $question }}</span>
            <x-ui.icon
                name="chevron-down"
                class="size-5 text-muted transition-transform duration-200"
                x-bind:class="active === '{{ $id }}' && 'rotate-180'"
            />
        </button>
    </h3>
    <div
        id="{{ $id }}-panel"
        role="region"
        aria-labelledby="{{ $id }}-button"
        x-show="active === '{{ $id }}'"
        x-cloak
        class="pr-10 pb-6 text-[15px] leading-relaxed text-muted"
    >
        {{ $slot }}
    </div>
</div>
