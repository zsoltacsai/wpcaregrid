@props(['steps'])

<x-ui.section tone="surface" labelledby="verification-title">
    <x-ui.section-heading
        id="verification-title"
        eyebrow="Update process"
        title="We don't just update. We check."
        lead="Updating plugins is the easy part. Every important update follows the same routine: a backup first, then the update, then checks that the site and its key functions still work."
    />

    <ol class="mt-12 grid lg:grid-cols-5 lg:gap-8 xl:gap-12">
        @foreach ($steps as $step)
            <x-process.step :step="$step" :number="sprintf('%02d', $loop->iteration)" :last="$loop->last" />
        @endforeach
    </ol>
</x-ui.section>
