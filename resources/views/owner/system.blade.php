<x-layouts.owner :title="__('owner.system.title')">
    <x-operator.page-header :title="__('owner.system.title')" :lead="__('owner.system.lead')" />

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($checks as $check)
            <section class="min-w-0 rounded-md border border-line bg-surface p-4" aria-labelledby="check-{{ $check->key }}" data-check="{{ $check->key }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="check-{{ $check->key }}" class="text-sm font-semibold text-primary">{{ __('owner.system.checks.'.$check->key) }}</h2>
                    <x-ui.status-badge :tone="$check->status->tone()">{{ __('site_health.states.'.$check->status->value) }}</x-ui.status-badge>
                </div>
                <p class="mt-2 text-sm text-ink">{{ $check->summary }}</p>
                @if ($check->details)
                    <dl class="mt-3 grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-1 border-t border-line pt-3 text-xs">
                        @foreach ($check->details as $label => $value)
                            <dt class="text-muted">{{ $label }}</dt>
                            <dd class="text-right font-mono break-all text-primary">{{ $value }}</dd>
                        @endforeach
                    </dl>
                @endif
            </section>
        @endforeach
    </div>

    <p class="mt-4 text-xs text-muted">{{ __('owner.system.note') }}</p>
</x-layouts.owner>
