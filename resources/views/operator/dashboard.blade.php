@use('App\Operator\ConnectorState')

<x-layouts.operator :title="__('operator.dashboard.title')">
    <x-operator.page-header :title="__('operator.dashboard.title')" :lead="__('operator.dashboard.lead')" />

    @php
        $tiles = [
            'websites' => ['neutral', route('operator.websites.index')],
            'healthy' => ['positive', route('operator.websites.index', ['status' => 'healthy'])],
            'attention' => ['warning', route('operator.websites.index', ['status' => 'attention'])],
            'critical' => ['danger', route('operator.websites.index', ['status' => 'critical'])],
            'backups_failed' => ['danger', route('operator.websites.index', ['backup' => 'failed'])],
            'security_findings' => ['danger', null],
            'pending_updates' => ['warning', route('operator.websites.index', ['sort' => 'updates'])],
            'expired_licenses' => ['danger', null],
            'connectors_offline' => ['warning', route('operator.websites.index', ['connector' => 'stale'])],
        ];
    @endphp

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
        @foreach ($tiles as $key => [$tone, $href])
            @php $noteKey = 'operator.metric_notes.'.$key; @endphp
            <x-operator.metric
                :label="__('operator.metrics.'.$key)"
                :value="$metrics[$key]"
                :tone="$tone"
                :href="$href"
                :note="__($noteKey) !== $noteKey ? __($noteKey) : null"
                data-metric="{{ $key }}"
            />
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <x-operator.section :title="__('operator.dashboard.attention_title')" id="attention">
            <x-slot:action>
                <a href="{{ route('operator.websites.index') }}" class="text-xs font-medium text-primary underline underline-offset-2">{{ __('operator.dashboard.websites_all') }}</a>
            </x-slot:action>
            @if ($attention->isEmpty())
                <p class="text-sm text-muted">{{ __('operator.dashboard.attention_none') }}</p>
            @else
                <ul class="-my-2 divide-y divide-line">
                    @foreach ($attention as $website)
                        @php $state = ConnectorState::of($website); @endphp
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span class="min-w-0">
                                <a href="{{ route('operator.websites.show', $website) }}" class="block truncate text-sm font-medium text-primary hover:underline">{{ $website->host() }}</a>
                                <span class="block truncate text-xs text-muted">{{ $website->customer?->company_name ?: $website->customer?->email }}</span>
                            </span>
                            <span class="flex gap-2">
                                <x-ui.status-badge :tone="$website->health_status->tone()">{{ $website->health_status->label() }}</x-ui.status-badge>
                                <x-ui.status-badge :tone="$state->tone()">{{ $state->label() }}</x-ui.status-badge>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-operator.section>

        @if ($events !== null)
            <x-operator.section :title="__('operator.dashboard.activity_title')" id="recent-activity">
                <x-slot:action>
                    <a href="{{ route('operator.activity.index') }}" class="text-xs font-medium text-primary underline underline-offset-2">{{ __('operator.dashboard.activity_all') }}</a>
                </x-slot:action>
                @if ($events->isEmpty())
                    <p class="text-sm text-muted">{{ __('operator.activity.empty') }}</p>
                @else
                    <x-operator.events-table :events="$events" class="-mx-4 -my-2" />
                @endif
            </x-operator.section>
        @endif
    </div>
</x-layouts.operator>
