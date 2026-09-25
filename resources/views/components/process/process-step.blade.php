{{--
    One step of the update verification process.
    Vertical list on small screens (connector on the left), a row of cards
    with arrows between them from lg up.
--}}
@props(['step', 'stepLabel', 'last' => false])

<li {{ $attributes->merge(['class' => 'relative flex gap-4 pb-6 last:pb-0 lg:pb-0']) }}>
    @unless ($last)
        <span aria-hidden="true" class="absolute top-12 bottom-0 left-5 w-px bg-line-strong lg:hidden"></span>
        <x-ui.icon name="arrow-right" class="absolute top-1/2 -right-6 hidden size-4 xl:-right-8 -translate-y-1/2 text-muted lg:block" />
    @endunless

    <div class="relative lg:hidden">
        <x-ui.icon-tile :name="$step['icon']" :tone="$last ? 'positive' : 'plain'" />
    </div>

    <div @class([
        'flex min-w-0 flex-1 flex-col rounded-md border p-4 xl:p-6',
        'border-accent bg-accent-soft' => $last,
        'border-line bg-canvas' => ! $last,
    ])>
        <div class="mb-4 hidden lg:block">
            <x-ui.icon-tile :name="$step['icon']" :tone="$last ? 'positive' : 'plain'" />
        </div>
        <p class="text-xs font-semibold tracking-[0.12em] text-muted uppercase">{{ $stepLabel }}</p>
        <h3 class="mt-1 text-lg font-semibold">{{ $step['title'] }}</h3>
        <p class="mt-2 text-sm leading-relaxed text-muted">{{ $step['text'] }}</p>
        <p class="mt-4 lg:mt-auto lg:pt-4">
            <x-ui.status-badge tone="positive" variant="code">{{ $step['result'] }}</x-ui.status-badge>
        </p>
    </div>
</li>
