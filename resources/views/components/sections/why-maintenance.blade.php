@props(['problems'])

@php
    $tones = ['warning', 'danger', 'warning'];
@endphp

<x-ui.section labelledby="why-title">
    <div class="grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
        <x-ui.section-heading
            id="why-title"
            eyebrow="Why maintenance"
            title="A WordPress doesn't maintain itself."
            lead="Plugins, themes and WordPress itself change every week. Without regular care, small problems add up."
        />

        <ul class="grid gap-4 md:grid-cols-3 lg:grid-cols-1">
            @foreach ($problems as $problem)
                <li class="flex flex-col gap-4 rounded-md border border-line bg-surface p-6 lg:flex-row lg:items-start">
                    <x-ui.icon-tile :name="$problem['icon']" :tone="$tones[$loop->index] ?? 'warning'" />
                    <div>
                        <h3 class="text-base font-semibold">{{ $problem['title'] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-muted">{{ $problem['text'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</x-ui.section>
