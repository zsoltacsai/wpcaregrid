@props(['services'])

<x-ui.section id="custom-development" labelledby="development-title">
    <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <x-ui.section-heading
            id="development-title"
            :eyebrow="__('home.development.eyebrow')"
            :title="__('home.development.title')"
            :lead="__('home.development.lead')"
        />

        <x-ui.button :href="config('marketing.links.contact')" size="lg" icon="arrow-right" class="self-start lg:self-auto">
            {{ __('home.development.cta') }}
        </x-ui.button>
    </div>

    <ul class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
        @foreach ($services as $service)
            <li>
                <x-ui.service-card :icon="$service['icon']" :title="$service['title']">
                    {{ $service['text'] }}
                </x-ui.service-card>
            </li>
        @endforeach
    </ul>

    <p class="mt-6 text-sm text-muted">{{ __('home.development.note') }}</p>
</x-ui.section>
