@props(['steps'])

<x-ui.section tone="surface" labelledby="verification-title">
    <x-ui.section-heading
        id="verification-title"
        eyebrow="Update process"
        title="We don't just update. We check."
        lead="Every important update follows the same routine, so problems are caught by us instead of your visitors."
    />

    <ol class="mt-12 grid rounded-lg border border-line bg-canvas p-6 sm:p-8 lg:grid-cols-5 lg:gap-6">
        @foreach ($steps as $step)
            <li class="relative flex gap-4 pb-8 last:pb-0 lg:flex-col lg:pb-0">
                @unless ($loop->last)
                    <span aria-hidden="true" class="absolute top-12 bottom-0 left-5 w-px bg-line-strong lg:top-5 lg:right-[-16px] lg:bottom-auto lg:left-12 lg:h-px lg:w-auto"></span>
                @endunless

                <x-ui.icon-tile :name="$step['icon']" :tone="$loop->last ? 'positive' : 'plain'" class="relative" />

                <div class="pt-1 lg:pt-0">
                    <p class="font-mono text-xs text-muted">Step {{ sprintf('%02d', $loop->iteration) }}</p>
                    <h3 class="mt-1 text-base font-semibold">{{ $step['title'] }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-muted">{{ $step['text'] }}</p>
                </div>
            </li>
        @endforeach
    </ol>
</x-ui.section>
