@props(['flow'])

<x-ui.section tone="surface" labelledby="woocommerce-title">
    <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <x-ui.section-heading
            id="woocommerce-title"
            eyebrow="WooCommerce care"
            title="For stores, we check more."
            lead="WooCommerce plans include additional checks around the purchasing process. After updates we walk through the path a customer takes, from product page to order confirmation email."
        />

        <x-ui.button
            href="#pricing"
            variant="secondary"
            size="lg"
            icon="arrow-right"
            class="self-start lg:self-auto"
            x-data
            x-on:click="$dispatch('pricing:select', 'woocommerce')"
        >
            See WooCommerce plans
        </x-ui.button>
    </div>

    <ol class="mt-12 grid gap-2 md:grid-cols-6 md:gap-4" aria-label="Checked purchase flow">
        @foreach ($flow as $step)
            <li class="relative flex items-center gap-4 rounded-md border border-line bg-canvas px-4 py-3 md:flex-col md:gap-3 md:px-2 md:py-6 md:text-center">
                <x-ui.icon-tile :name="$step['icon']" tone="plain" />
                <span class="flex-1">
                    <span class="block text-xs text-muted tabular-nums">{{ sprintf('%02d', $loop->iteration) }}</span>
                    <span class="block text-sm font-semibold text-primary">{{ $step['title'] }}</span>
                </span>
                <x-ui.icon name="check" class="size-4 text-accent-ink md:hidden" />

                @unless ($loop->last)
                    <x-ui.icon name="arrow-right" class="absolute top-1/2 -right-4 hidden size-4 -translate-y-1/2 text-muted md:block" />
                @endunless
            </li>
        @endforeach
    </ol>
</x-ui.section>
