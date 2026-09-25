{{-- Marks illustrative content so it cannot be mistaken for real customer data. --}}
@props(['text' => 'Demo data'])

<span {{ $attributes->merge(['class' => 'inline-flex h-6 items-center gap-1.5 rounded-full border border-dashed border-line-strong bg-surface px-2.5 font-mono text-[11px] font-medium tracking-[0.06em] text-muted uppercase']) }}>
    <x-ui.icon name="info" class="size-3.5" />
    {{ $text }}
</span>
