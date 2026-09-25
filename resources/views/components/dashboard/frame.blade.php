{{--
    Application-window chrome for dashboard previews. Always shows the demo
    label so preview data is never mistaken for real customer data.
--}}
@props(['title', 'caption', 'label' => null])

<figure {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-line bg-surface shadow-raised']) }}>
    <figcaption class="sr-only">{{ $caption }}</figcaption>

    <div class="flex items-center justify-between gap-4 border-b border-line bg-canvas px-4 py-3 sm:px-6">
        <div class="flex min-w-0 items-center gap-3">
            <x-brand.icon class="size-6" />
            <p class="truncate text-sm font-medium text-primary">{{ $title }}</p>
        </div>
        <x-ui.demo-label :text="$label ?? __('dashboard.demo_label')" />
    </div>

    {{ $slot }}
</figure>
