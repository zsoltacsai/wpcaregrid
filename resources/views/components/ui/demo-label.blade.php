{{-- Marks illustrative content so it cannot be mistaken for real customer data. --}}
@props(['text' => 'Demo data'])

<span {{ $attributes->merge(['class' => 'inline-flex h-6 items-center gap-1.5 rounded-full border border-dashed border-line-strong bg-surface px-2.5 text-xs font-medium text-muted']) }}>
    <x-ui.icon name="info" class="size-3.5" />
    {{ $text }}
</span>
