<x-layouts.owner :title="$website->host()">
    <x-operator.page-header :title="$website->host()" :eyebrow="__('owner.websites.title')" :lead="$website->type->label()">
        <x-slot:actions>
            @if ($website->syntheticFixture)<x-ui.status-badge tone="warning">{{ __('owner.synthetic_fixture_badge') }}</x-ui.status-badge>@endif
            <x-ui.status-badge :tone="$report->overall->tone()">{{ __('owner.websites.overall') }}: {{ $report->overall->label() }}</x-ui.status-badge>
        </x-slot:actions>
    </x-operator.page-header>

    <x-operator.section :title="__('site_health.title')" id="site-health">
        <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($report->dimensions as $dimension)
                <li class="min-w-0 rounded-sm border border-line px-3 py-2">
                    <span class="block text-xs text-muted">{{ $dimension->dimension->label() }}</span>
                    <x-ui.status-badge :tone="$dimension->state->tone()" class="mt-1">{{ $dimension->state->label() }}</x-ui.status-badge>
                    <span class="mt-1 block font-mono text-[11px] break-all text-muted">{{ $dimension->reason }}</span>
                </li>
            @endforeach
        </ul>
    </x-operator.section>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <x-operator.section :title="__('owner.websites.sections.overview')" id="website-overview">
            <dl class="grid grid-cols-[minmax(0,140px)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted">{{ __('owner.websites.columns.url') }}</dt><dd class="break-all">{{ $website->url }}</dd>
                <dt class="text-muted">{{ __('owner.websites.columns.customer') }}</dt>
                <dd class="break-words">
                    @if ($website->customer)
                        <a href="{{ route('owner.customers.show', $website->customer) }}" class="text-primary hover:underline">{{ $website->customer->company_name ?: $website->customer->fullName() }}</a>
                    @endif
                </dd>
                <dt class="text-muted">{{ __('owner.websites.columns.plan') }}</dt>
                <dd>{{ $website->subscription ? $website->subscription->planName().' · '.$website->subscription->status->label() : '—' }}</dd>
                <dt class="text-muted">{{ __('owner.websites.columns.connector') }}</dt>
                <dd><x-ui.status-badge :tone="$connector->tone()">{{ $connector->label() }}</x-ui.status-badge> <span class="font-mono text-xs text-muted">{{ $website->activeConnector?->connector_version }}</span></dd>
                <dt class="text-muted">{{ __('owner.websites.columns.last_seen') }}</dt>
                <dd class="font-mono text-xs">{{ app(\App\Support\DisplayDateTime::class)->format($website->activeConnector?->last_seen_at ?? $website->last_seen_at) ?? __('owner.never') }}</dd>
                <dt class="text-muted">{{ __('owner.websites.wordpress') }}</dt>
                <dd class="font-mono text-xs">{{ $website->wordpress_version ?? '—' }} · PHP {{ $website->php_version ?? '—' }}</dd>
                <dt class="text-muted">{{ __('owner.websites.columns.updates') }}</dt>
                <dd>{{ $website->inventory ? $website->inventory->updates_pending : '—' }}</dd>
                <dt class="text-muted">{{ __('owner.websites.columns.security') }}</dt>
                <dd><x-ui.status-badge :tone="$security->state->tone()">{{ $security->state->label() }}</x-ui.status-badge> <span class="text-xs text-muted">{{ trans_choice('owner.websites.open_findings', $security->findings) }}</span></dd>
            </dl>
        </x-operator.section>

        <x-operator.section :title="__('owner.websites.sections.actions')" id="website-actions">
            <p class="text-sm text-muted">{{ __('owner.websites.actions_lead') }}</p>
            @if ($pairingCode)
                <div class="mt-4 rounded-md border border-warning/40 bg-surface p-4" role="status">
                    <p class="text-sm font-semibold">{{ __('owner.websites.pairing_code_once') }}</p>
                    <p class="mt-2 font-mono text-lg tracking-wider" data-testid="pairing-code">{{ $pairingCode['code'] }}</p>
                    <p class="mt-1 text-xs text-muted">{{ __('owner.websites.pairing_code_expires', ['time' => app(\App\Support\DisplayDateTime::class)->format($pairingCode['expires_at'], 'Y-m-d H:i:s T')]) }}</p>
                </div>
            @endif
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($canIssuePairingCode)
                    <form method="POST" action="{{ route('owner.websites.pairing-code', $website) }}">
                        @csrf
                        <x-ui.button type="submit" size="sm" variant="secondary">{{ __('owner.websites.generate_pairing_code') }}</x-ui.button>
                    </form>
                @endif
                @if ($canDownloadSyntheticConnector)
                    <a class="inline-flex min-h-9 items-center rounded-md border border-line px-3 text-sm font-medium text-primary hover:bg-surface" href="{{ route('owner.websites.connector-package', $website) }}">{{ __('owner.websites.download_synthetic_connector') }}</a>
                @endif
                <form method="POST" action="{{ route('owner.websites.backups.store', $website) }}">
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="secondary" icon="archive">{{ __('owner.websites.run_backup') }}</x-ui.button>
                </form>
                <form method="POST" action="{{ route('owner.websites.security-scans.store', $website) }}">
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="secondary" icon="shield">{{ __('owner.websites.run_scan') }}</x-ui.button>
                </form>
            </div>
            <p class="mt-4 text-xs text-muted">{{ __('owner.websites.operator_only') }}</p>

            <h3 class="mt-5 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('owner.websites.recent_backups') }}</h3>
            @if ($backups->isEmpty())
                <p class="mt-2 text-sm text-muted">{{ __('owner.websites.no_backups') }}</p>
            @else
                <ul class="mt-2 divide-y divide-line text-sm">
                    @foreach ($backups as $backup)
                        <li class="flex flex-wrap justify-between gap-2 py-1.5">
                            <span class="font-mono text-xs text-muted">@displayDateTime($backup->created_at) · {{ $backup->type->value }}</span>
                            <x-ui.status-badge :tone="\App\Operator\BackupState::fromStatus($backup->status)->tone()" variant="code">{{ $backup->status->value }}</x-ui.status-badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-operator.section>
    </div>

    <x-operator.section :title="__('owner.websites.readiness.title')" id="connector-readiness" class="mt-4">
        <p class="mb-4 text-sm text-muted">{{ __('owner.websites.readiness.explanation') }}</p>
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-muted">{{ __('owner.websites.readiness.activity') }}</dt><dd><x-ui.status-badge :tone="$readiness['connection']['activity_state'] === 'VERIFIED' ? 'positive' : ($readiness['connection']['activity_state'] === 'STALE' || $readiness['connection']['activity_state'] === 'REVOKED' ? 'warning' : 'neutral')">{{ __('owner.websites.readiness.states.'.$readiness['connection']['activity_state']) }}</x-ui.status-badge></dd></div>
            <div><dt class="text-muted">{{ __('owner.websites.readiness.heartbeat') }}</dt><dd><x-ui.status-badge :tone="$readiness['connection']['heartbeat_state'] === 'VERIFIED' ? 'positive' : ($readiness['connection']['heartbeat_state'] === 'STALE' ? 'warning' : 'neutral')">{{ __('owner.websites.readiness.states.'.$readiness['connection']['heartbeat_state']) }}</x-ui.status-badge> <span class="font-mono text-xs">{{ app(\App\Support\DisplayDateTime::class)->format($readiness['connection']['last_heartbeat_at']) ?? __('owner.never') }}</span></dd></div>
            <div><dt class="text-muted">{{ __('owner.websites.readiness.successful_poll') }}</dt><dd><x-ui.status-badge :tone="$readiness['connection']['poll_state'] === 'VERIFIED' ? 'positive' : ($readiness['connection']['poll_state'] === 'STALE' ? 'warning' : 'neutral')">{{ __('owner.websites.readiness.states.'.$readiness['connection']['poll_state']) }}</x-ui.status-badge> <span class="font-mono text-xs">{{ app(\App\Support\DisplayDateTime::class)->format($readiness['connection']['last_successful_poll_at']) ?? __('owner.never') }}</span></dd></div>
            <div><dt class="text-muted">{{ __('owner.websites.readiness.inventory_collected') }}</dt><dd><x-ui.status-badge :tone="$readiness['inventory']['collection_state'] === 'VERIFIED' ? 'positive' : ($readiness['inventory']['collection_state'] === 'STALE' ? 'warning' : 'neutral')">{{ __('owner.websites.readiness.states.'.$readiness['inventory']['collection_state']) }}</x-ui.status-badge> <span class="font-mono text-xs">{{ app(\App\Support\DisplayDateTime::class)->format($readiness['inventory']['collected_at']) ?? __('owner.never') }}</span></dd></div>
            <div><dt class="text-muted">{{ __('owner.websites.readiness.inventory_ingested') }}</dt><dd><x-ui.status-badge :tone="$readiness['inventory']['ingestion_state'] === 'VERIFIED' ? 'positive' : ($readiness['inventory']['ingestion_state'] === 'STALE' ? 'warning' : 'neutral')">{{ __('owner.websites.readiness.states.'.$readiness['inventory']['ingestion_state']) }}</x-ui.status-badge> <span class="font-mono text-xs">{{ app(\App\Support\DisplayDateTime::class)->format($readiness['inventory']['ingested_at']) ?? __('owner.never') }}</span></dd></div>
            <div><dt class="text-muted">{{ __('owner.websites.readiness.capability_snapshot') }}</dt><dd><x-ui.status-badge :tone="$readiness['capability_snapshot']['state'] === 'VERIFIED' ? 'positive' : ($readiness['capability_snapshot']['state'] === 'STALE' ? 'warning' : 'neutral')">{{ __('owner.websites.readiness.states.'.$readiness['capability_snapshot']['state']) }}</x-ui.status-badge></dd></div>
            <div><dt class="text-muted">{{ __('owner.websites.readiness.queued') }}</dt><dd>{{ $readiness['jobs']['queued'] }}</dd></div>
            <div><dt class="text-muted">{{ __('owner.websites.readiness.waiting') }}</dt><dd>{{ $readiness['jobs']['waiting'] }}</dd></div>
            <div><dt class="text-muted">{{ __('owner.websites.readiness.delivered') }}</dt><dd>{{ $readiness['jobs']['delivered'] }}</dd></div>
            <div><dt class="text-muted">{{ __('owner.websites.readiness.running') }}</dt><dd><x-ui.status-badge tone="neutral">{{ __('owner.websites.readiness.running_unknown') }}</x-ui.status-badge></dd></div>
        </dl>
        <h3 class="mt-5 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('owner.websites.readiness.operation_capabilities') }}</h3>
        <ul class="mt-2 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($readiness['capability_snapshot']['capabilities'] as $capability => $state)
                <li class="flex items-center justify-between gap-2 rounded-sm border border-line px-3 py-2"><span>{{ __('owner.websites.readiness.capabilities.'.$capability) }}</span><x-ui.status-badge :tone="$state === 'PASS' ? 'positive' : ($state === 'BLOCK' ? 'danger' : ($state === 'WARN' ? 'warning' : 'neutral'))">{{ __('owner.websites.readiness.capability_states.'.$state) }}</x-ui.status-badge></li>
            @endforeach
        </ul>
    </x-operator.section>

    <x-operator.section :title="__('owner.websites.sections.activity')" id="website-activity" class="mt-4">
        <x-owner.event-list :events="$events" :show-website="false" />
    </x-operator.section>
</x-layouts.owner>
