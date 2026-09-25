@props(['steps'])

<x-ui.section tone="surface" labelledby="verification-title">
    <x-ui.section-heading
        id="verification-title"
        :eyebrow="__('home.verification.eyebrow')"
        :title="__('home.verification.title')"
        :lead="__('home.verification.lead')"
    />

    <ol class="mt-12 grid lg:grid-cols-5 lg:gap-8 xl:gap-12">
        @foreach ($steps as $step)
            <x-process.process-step
                :step="$step"
                :step-label="__('home.verification.step_label', ['number' => sprintf('%02d', $loop->iteration)])"
                :last="$loop->last"
            />
        @endforeach
    </ol>
</x-ui.section>
