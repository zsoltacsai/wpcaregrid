{{-- Marks illustrative content so it cannot be mistaken for real customer data. --}}
@props(['text' => null])

<span {{ $attributes->merge(['class' => 'inline-flex h-6 shrink-0 items-center gap-1.5 rounded-full border border-dashed border-line-strong bg-surface px-2.5 text-xs font-medium whitespace-nowrap text-muted']) }}>
    <x-ui.icon name="info" class="size-3.5" />
    {{ $text ?? __('dashboard.demo_label') }}
</span>
