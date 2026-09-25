@props(['services'])

<x-ui.section id="services" tone="surface" spacing="compact" labelledby="services-title">
    <h2 id="services-title" class="sr-only">{{ __('home.services.title') }}</h2>

    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
        @foreach ($services as $service)
            <li>
                <x-ui.service-card :icon="$service['icon']" :title="$service['title']" background="canvas">
                    {{ $service['text'] }}
                </x-ui.service-card>
            </li>
        @endforeach
    </ul>
</x-ui.section>
