{{--
    The four onboarding steps. State per step: done | current | waiting | not_started.
    A step is never shown as done unless the data proves it (the connector
    step only completes when the connector has confirmed the connection).
--}}
@props(['states'])

@php
    $steps = ['connect', 'connection', 'initial_check', 'ready'];
    $tones = ['done' => 'positive', 'current' => 'warning', 'waiting' => 'neutral', 'not_started' => 'neutral'];
@endphp

<ol class="grid gap-3 md:grid-cols-4" aria-label="{{ __('account.onboarding.title') }}">
    @foreach ($steps as $step)
        @php $state = $states[$step]; @endphp
        <li @class([
            'flex flex-col gap-2 rounded-md border p-4',
            'border-accent bg-accent-soft' => $state === 'done',
            'border-primary bg-surface' => $state === 'current',
            'border-line bg-canvas' => in_array($state, ['waiting', 'not_started']),
        ]) @if ($state === 'current') aria-current="step" @endif>
            <span class="text-xs font-semibold tracking-[0.12em] text-muted uppercase tabular-nums">{{ sprintf('%02d', $loop->iteration) }}</span>
            <span class="text-sm font-semibold text-primary">{{ __("account.onboarding.steps.{$step}.title") }}</span>
            <span class="text-xs text-muted">{{ __("account.onboarding.steps.{$step}.text") }}</span>
            <span class="mt-auto pt-2">
                <x-ui.status-badge :tone="$tones[$state]">{{ __("account.onboarding.step_states.{$state}") }}</x-ui.status-badge>
            </span>
        </li>
    @endforeach
</ol>
