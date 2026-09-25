{{--
    Dashboard preview built from the same components the customer dashboard
    will use. Always demo data, always labelled as such.

    variant: compact (hero: site list + latest update run)
             full (dashboard section: sidebar, sites table, updates and licenses)
--}}
@props([
    'variant' => 'compact',
    'sites',
    'updateRun' => null,
    'updates' => [],
    'licenses' => [],
])

@if ($variant === 'compact')
    @php
        $counts = collect($sites)->countBy(fn ($site) => $site['status']->tone());
        $summary = [
            'positive' => \App\Enums\Status::Healthy,
            'warning' => \App\Enums\Status::Attention,
            'danger' => \App\Enums\Status::Critical,
        ];
    @endphp

    <x-dashboard.frame :title="__('dashboard.preview.compact_title')" :caption="__('dashboard.preview.compact_caption')" {{ $attributes }}>
        <dl class="grid grid-cols-3 divide-x divide-line border-b border-line">
            @foreach ($summary as $tone => $status)
                <div class="px-4 py-4 sm:px-6">
                    <dt class="flex items-center gap-2 text-xs text-muted">
                        <x-ui.status-dot :tone="$tone" />
                        {{ $status->label() }}
                    </dt>
                    <dd class="mt-1 text-2xl font-semibold text-primary tabular-nums">{{ $counts->get($tone, 0) }}</dd>
                </div>
            @endforeach
        </dl>

        <ul class="divide-y divide-line">
            @foreach ($sites as $site)
                <x-dashboard.site-row :site="$site" />
            @endforeach
        </ul>

        @if ($updateRun)
            <div class="border-t border-line bg-canvas px-4 py-4 sm:px-6">
                <p class="text-xs text-muted">
                    {{ __('dashboard.latest_run') }} <span aria-hidden="true">·</span>
                    <span class="font-medium text-primary">{{ $updateRun['site'] }}</span>
                </p>
                <ol class="mt-3 grid grid-cols-2 gap-2">
                    @foreach ($updateRun['steps'] as $step)
                        <li class="flex items-center justify-between gap-2 rounded-sm border border-line bg-surface px-3 py-2">
                            <span class="flex min-w-0 items-center gap-1.5 text-xs leading-tight font-medium text-primary">
                                <x-ui.icon name="check" class="size-3.5 text-accent-ink" />
                                <span>{{ __("dashboard.run_steps.{$step['key']}") }}</span>
                                <span class="sr-only">— {{ $step['status']->label() }}</span>
                            </span>
                            <span class="font-mono text-[11px] text-muted">{{ $step['time'] }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </x-dashboard.frame>
@else
    @php
        $navigation = [
            'overview' => 'grid',
            'sites' => 'globe',
            'backups' => 'archive',
            'updates' => 'refresh',
            'security' => 'shield',
            'licenses' => 'key',
            'activity' => 'list',
        ];
    @endphp

    <x-dashboard.frame
        :title="__('dashboard.preview.full_title')"
        :caption="__('dashboard.preview.full_caption')"
        {{ $attributes->merge(['class' => '[--dashboard-columns:minmax(0,1.7fr)_minmax(0,1.15fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,0.9fr)_112px]']) }}
    >
        <div class="lg:grid lg:grid-cols-[200px_minmax(0,1fr)]">
            <div class="hidden bg-primary p-4 lg:block" aria-hidden="true">
                <ul class="space-y-1">
                    @foreach ($navigation as $key => $icon)
                        <li @class([
                            'flex h-9 items-center gap-3 rounded-sm px-3 text-sm',
                            'bg-action font-medium text-white' => $loop->first,
                            'text-on-dark-muted' => ! $loop->first,
                        ])>
                            <x-ui.icon :name="$icon" class="size-4" />
                            {{ __("dashboard.nav.{$key}") }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="min-w-0">
                <div class="flex items-center justify-between gap-4 px-4 pt-6 pb-4 md:px-6">
                    <p class="text-base font-semibold text-primary">{{ __('dashboard.sites_heading') }}</p>
                    <p class="text-xs text-muted">{{ trans_choice('dashboard.sites_count', count($sites)) }}</p>
                </div>

                <div class="hidden gap-x-4 border-y border-line bg-canvas px-6 py-2 text-[11px] font-semibold tracking-[0.08em] text-muted uppercase lg:grid lg:grid-cols-(--dashboard-columns)" aria-hidden="true">
                    @foreach (['site', 'last_backup', 'updates', 'security', 'license'] as $column)
                        <span>{{ __("dashboard.columns.{$column}") }}</span>
                    @endforeach
                    <span class="text-right">{{ __('dashboard.columns.status') }}</span>
                </div>

                <ul class="divide-y divide-line border-t border-line lg:border-t-0">
                    @foreach ($sites as $site)
                        <x-dashboard.site-row :site="$site" variant="full" />
                    @endforeach
                </ul>

                <div class="grid gap-4 border-t border-line bg-canvas p-4 md:grid-cols-2 md:p-6">
                    <section aria-label="{{ __('dashboard.pending_updates') }}" class="rounded-md border border-line bg-surface">
                        <p class="flex items-center justify-between border-b border-line px-4 py-3 text-sm font-semibold text-primary">
                            {{ __('dashboard.pending_updates') }}
                            <span class="text-xs font-normal text-muted">{{ __('dashboard.shown_count', ['count' => count($updates)]) }}</span>
                        </p>
                        <ul class="divide-y divide-line">
                            @foreach ($updates as $update)
                                <li class="flex items-center justify-between gap-4 px-4 py-2.5 text-sm">
                                    <span class="min-w-0 truncate">
                                        <span class="text-primary">{{ $update['name'] }}</span>
                                        <span class="text-xs text-muted"> · {{ $update['site'] }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs text-muted">{{ __("dashboard.update_types.{$update['type']}") }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    <section aria-label="{{ __('dashboard.licenses') }}" class="rounded-md border border-line bg-surface">
                        <p class="flex items-center justify-between border-b border-line px-4 py-3 text-sm font-semibold text-primary">
                            {{ __('dashboard.licenses') }}
                            <span class="text-xs font-normal text-muted">{{ __('dashboard.expired_count', ['count' => count($licenses)]) }}</span>
                        </p>
                        <ul class="divide-y divide-line">
                            @foreach ($licenses as $license)
                                <li class="flex items-center justify-between gap-4 px-4 py-2.5 text-sm">
                                    <span class="min-w-0 truncate">
                                        <span class="text-primary">{{ $license['name'] }}</span>
                                        <span class="text-xs text-muted"> · {{ $license['site'] }}</span>
                                    </span>
                                    <x-ui.status-badge :status="$license['status']" />
                                </li>
                            @endforeach
                        </ul>
                    </section>
                </div>
            </div>
        </div>
    </x-dashboard.frame>
@endif
