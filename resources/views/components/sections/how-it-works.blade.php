@props(['steps'])

<x-ui.section id="how-it-works" tone="surface" labelledby="how-title">
    <x-ui.section-heading
        id="how-title"
        eyebrow="How it works"
        title="Up and running in four steps."
    />

    <ol class="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
        @foreach ($steps as $step)
            <li class="border-t-2 border-primary pt-6">
                <p class="text-sm font-semibold text-accent-ink tabular-nums" aria-hidden="true">{{ $step['number'] }}</p>
                <h3 class="mt-3 text-lg font-semibold">{{ $step['title'] }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-muted">{{ $step['text'] }}</p>
            </li>
        @endforeach
    </ol>
</x-ui.section>
