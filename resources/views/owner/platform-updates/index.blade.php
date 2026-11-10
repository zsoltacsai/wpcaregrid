@php
    $tone = fn (string $severity) => match ($severity) { 'critical' => 'danger', 'high' => 'danger', 'medium' => 'warning', default => 'neutral' };
    $checkTone = fn (string $status) => match ($status) { 'succeeded' => 'positive', 'partial', 'refused', 'running' => 'warning', default => 'danger' };
    $stateTone = fn (string $state) => match ($state) { 'CHECKED' => 'positive', default => 'warning' };
@endphp
<x-layouts.owner :title="__('platform_updates.title')">
    <x-operator.page-header :title="__('platform_updates.title')" :lead="__('platform_updates.lead')" />

    <div class="mb-4 flex flex-wrap items-center gap-3" data-section="summary">
        <x-ui.status-badge :tone="$summary['state']->tone()" data-testid="overall-state">{{ __('platform_updates.overall') }}: {{ __('platform_updates.states.'.$summary['state']->value) }}</x-ui.status-badge>
        <form method="post" action="{{ route('owner.platform-updates.check') }}">
            @csrf
            <x-ui.button type="submit" data-testid="check-updates" :disabled="! $summary['enabled']">{{ __('platform_updates.check_button') }}</x-ui.button>
        </form>
        <p class="min-w-0 text-xs text-muted">{{ __('platform_updates.check_note') }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-operator.metric :label="__('platform_updates.tiles.open')" :value="$summary['open']" tone="warning" data-tile="open" />
        <x-operator.metric :label="__('platform_updates.tiles.critical')" :value="$summary['critical']" tone="danger" data-tile="critical" />
        <x-operator.metric :label="__('platform_updates.tiles.fixes')" :value="$summary['fixes_available']" tone="warning" data-tile="fixes" />
        <x-operator.metric :label="__('platform_updates.tiles.unknown')" :value="$summary['unknown']" tone="warning" data-tile="unknown" />
        <x-operator.metric :label="__('platform_updates.tiles.unverified')" :value="$summary['unverified']" tone="warning" data-tile="unverified" />
    </div>

    <dl class="mt-4 grid gap-x-6 gap-y-1 text-xs sm:grid-cols-3" data-section="timing">
        <div><dt class="text-muted">{{ __('platform_updates.last_success') }}</dt><dd class="font-mono text-primary">@if ($summary['last_success']) @displayDateTime($summary['last_success']->finished_at, 'Y-m-d H:i T') @else {{ __('platform_updates.never') }} @endif</dd></div>
        <div><dt class="text-muted">{{ __('platform_updates.last_check') }}</dt><dd class="font-mono text-primary">@if ($summary['last_check']) @displayDateTime($summary['last_check']->started_at, 'Y-m-d H:i T') · {{ __('platform_updates.check_status.'.$summary['last_check']->status) }} @else {{ __('platform_updates.never') }} @endif</dd></div>
        <div><dt class="text-muted">{{ __('platform_updates.next_check') }}</dt><dd class="font-mono text-primary">@if ($summary['next_check']) @displayDateTime($summary['next_check'], 'Y-m-d H:i T') @else {{ __('platform_updates.scheduled_off') }} @endif</dd></div>
    </dl>
    @if ($summary['stale'])
        <p class="mt-3 text-xs text-warning-ink" data-note="stale">{{ __('platform_updates.stale_note') }}</p>
    @endif
    @if ($summary['unverified'] > 0)
        <p class="mt-3 text-xs text-muted" data-note="unverified">{{ __('platform_updates.unverified_note', ['count' => $summary['unverified']]) }}</p>
    @endif

    <x-operator.section class="mt-5" :title="__('platform_updates.advisories')" id="advisories">
        @forelse ($advisories as $advisory)
            <article class="border-b border-line py-3 last:border-0" data-advisory="{{ $advisory->advisory_id }}">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.status-badge :tone="$tone($advisory->severity)">{{ __('platform_updates.severity.'.$advisory->severity) }}</x-ui.status-badge>
                    <a class="font-mono text-sm font-semibold text-primary underline" href="{{ route('owner.platform-updates.advisory', $advisory->id) }}">{{ $advisory->advisory_id }}</a>
                    <span class="text-sm">{{ $advisory->component->name }} {{ $advisory->affected_version }}</span>
                    @if ($advisory->fixed_version)<span class="text-xs text-muted">→ {{ $advisory->fixed_version }}</span>@endif
                </div>
                @if ($advisory->summary)<p class="mt-1 break-words text-xs text-muted">{{ $advisory->summary }}</p>@endif
            </article>
        @empty
            <p class="text-sm text-muted" data-empty="advisories">{{ __('platform_updates.no_advisories') }}</p>
        @endforelse
    </x-operator.section>

    <x-operator.section class="mt-5" :title="__('platform_updates.components')" id="components">
        <nav class="mb-3 flex flex-wrap gap-2 text-xs" aria-label="{{ __('platform_updates.columns.kind') }}">
            <a href="{{ route('owner.platform-updates.index') }}" class="rounded-sm border border-line px-2 py-1 {{ $kind === null ? 'bg-subtle font-semibold' : '' }}">{{ __('platform_updates.filter_all') }}</a>
            @foreach ($kinds as $k)
                <a href="{{ route('owner.platform-updates.index', ['kind' => $k]) }}" class="rounded-sm border border-line px-2 py-1 {{ $kind === $k ? 'bg-subtle font-semibold' : '' }}">{{ $k }}</a>
            @endforeach
        </nav>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[34rem] text-left text-xs">
                <thead class="text-muted"><tr><th class="py-1 pr-3">{{ __('platform_updates.columns.component') }}</th><th class="py-1 pr-3">{{ __('platform_updates.columns.kind') }}</th><th class="py-1 pr-3">{{ __('platform_updates.columns.installed') }}</th><th class="py-1 pr-3">{{ __('platform_updates.columns.state') }}</th><th class="py-1">{{ __('platform_updates.tiles.open') }}</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($components as $part)
                        <tr data-component="{{ $part->component_key }}">
                            <td class="py-1.5 pr-3 font-medium text-primary break-all">{{ $part->name }}@if ($part->development_only) <span class="text-muted">(dev)</span>@endif</td>
                            <td class="py-1.5 pr-3">{{ $part->kind }}</td>
                            <td class="py-1.5 pr-3 font-mono">{{ $part->installed_version ?? __('platform_updates.version_states.UNKNOWN') }}</td>
                            <td class="py-1.5 pr-3"><x-ui.status-badge :tone="$stateTone($part->advisory_state)">{{ __('platform_updates.advisory_states.'.$part->advisory_state) }}</x-ui.status-badge>@if ($part->advisories_checked_at) <span class="text-muted">{{ __('platform_updates.checked_at') }} @displayDateTime($part->advisories_checked_at, 'Y-m-d H:i')</span>@endif</td>
                            <td class="py-1.5 tabular-nums">{{ $part->open_advisories_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $components->links() }}</div>
    </x-operator.section>

    <x-operator.section class="mt-5" :title="__('platform_updates.history')" id="history">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[30rem] text-left text-xs">
                <thead class="text-muted"><tr><th class="py-1 pr-3">{{ __('platform_updates.columns.started') }}</th><th class="py-1 pr-3">{{ __('platform_updates.columns.status') }}</th><th class="py-1 pr-3">{{ __('platform_updates.columns.code') }}</th><th class="py-1">{{ __('platform_updates.columns.counts') }}</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @forelse ($history as $run)
                        <tr data-check-run="{{ $run->uuid }}">
                            <td class="py-1.5 pr-3 font-mono">@displayDateTime($run->started_at, 'Y-m-d H:i T') · {{ $run->trigger }}</td>
                            <td class="py-1.5 pr-3"><x-ui.status-badge :tone="$checkTone($run->status)">{{ __('platform_updates.check_status.'.$run->status) }}</x-ui.status-badge></td>
                            <td class="py-1.5 pr-3 font-mono">{{ $run->reason_code ?? '—' }}</td>
                            <td class="py-1.5 tabular-nums">{{ $run->components_total }} / {{ $run->advisories_open }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-muted">{{ __('platform_updates.never') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-operator.section>

    <p class="mt-4 text-xs text-muted">{{ __('platform_updates.note') }}</p>
</x-layouts.owner>
