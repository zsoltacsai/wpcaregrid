<x-layouts.marketing :title="__('checkout.meta.pricing_title')" :description="__('checkout.meta.pricing_description')" :seo="$seo" :indexable="true" :alternates="$alternates ?? []">
    @if (session('checkout_error'))
        <x-ui.container class="pt-8">
            <p role="alert" class="flex items-start gap-3 rounded-md border border-warning/40 bg-warning-soft px-4 py-3 text-sm text-warning-ink">
                <x-ui.icon name="alert-triangle" class="mt-px size-4" />
                {{ session('checkout_error') }}
            </p>
        </x-ui.container>
    @endif

    <x-sections.pricing :intro="$intro" :catalog="$pricing" heading-level="h1" />
</x-layouts.marketing>
