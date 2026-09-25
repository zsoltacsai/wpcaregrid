@props(['services'])

<x-ui.section id="custom-development" labelledby="development-title">
    <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <x-ui.section-heading
            id="development-title"
            eyebrow="Custom development"
            title="Need more than maintenance?"
            lead="We also build WordPress and WooCommerce functionality to order, maintained to the same standard as the rest of your site."
        />

        <x-ui.button :href="config('marketing.links.contact')" size="lg" icon="arrow-right" class="self-start lg:self-auto">
            Talk about a project
        </x-ui.button>
    </div>

    <ul class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
        @foreach ($services as $service)
            <li>
                <x-ui.feature-card :icon="$service['icon']" :title="$service['title']">
                    {{ $service['text'] }}
                </x-ui.feature-card>
            </li>
        @endforeach
    </ul>
</x-ui.section>
