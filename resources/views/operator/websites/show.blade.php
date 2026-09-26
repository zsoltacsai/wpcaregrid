@use('App\AdminAccess\AccessLevel')
@use('App\Backups\BackupStatus')
@use('App\Backups\BackupText')
@use('App\Connector\HealthStatus')
@use('App\Connector\LicenseStatus')
@use('App\Connector\JobQueue')
@use('App\Operator\ConnectorState')
@use('App\Operator\Refusal')

<x-layouts.operator :title="$website->host()">
    @php
        $user = auth('operator')->user();
        $health = $website->health_status ?? HealthStatus::Unknown;
        $plugins = collect($inventory?->plugins);
        $themes = collect($inventory?->themes);
        $core = $inventory?->core ?? [];
        $woo = $inventory?->woocommerce ?? [];
        $pendingPlugins = $plugins->where('update_available', true);
        $pendingThemes = $themes->where('update_available', true);
        $lastUpdate = $updateJobs->first(fn ($job) => $job->status->isFinal());
        $time = fn ($at) => $at ? $at->format('Y-m-d H:i') : __('operator.never');
        // Jump links only for the sections this operator can see.
        $sections = array_keys(array_filter([
            'overview' => true, 'connector' => true, 'wordpress' => true, 'plugins' => true, 'themes' => true, 'woocommerce' => true,
            'backups' => $user->can('operator.backups.view'),
            'security' => $user->can('operator.security.view'),
            'admin_access' => true,
            'activity' => $user->can('operator.audit.view'),
        ]));
        $tableHead = 'bg-canvas text-[11px] font-semibold tracking-[0.08em] text-muted uppercase';
    @endphp

    <x-operator.page-header :title="$website->host()" :lead="$website->url">
        <x-slot:eyebrow>
            <a href="{{ route('operator.websites.index') }}" class="underline underline-offset-2 hover:text-primary">{{ __('operator.detail.back') }}</a>
            · <span class="font-mono">{{ $website->uuid }}</span>
        </x-slot:eyebrow>
        <x-slot:actions>
            <x-ui.status-badge :tone="$health->tone()">{{ $health->label() }}</x-ui.status-badge>
            <x-ui.status-badge :tone="$connectorState->tone()">{{ $connectorState->label() }}</x-ui.status-badge>
        </x-slot:actions>
    </x-operator.page-header>

    <nav aria-label="{{ __('operator.detail.sections.overview') }}" class="-mx-4 mb-5 relative overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <ul class="flex gap-1 text-sm whitespace-nowrap">
            @foreach ($sections as $section)
                <li><a href="#{{ str_replace('_', '-', $section) }}" class="inline-flex h-8 items-center rounded-sm border border-line bg-surface px-3 text-ink hover:border-primary">{{ __('operator.detail.sections.'.$section) }}</a></li>
            @endforeach
        </ul>
    </nav>

    <div class="grid gap-5">
        {{-- Overview: customer, plan, website and the state of everything at a glance --}}
        <x-operator.section :title="__('operator.detail.sections.overview')" id="overview">
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <x-account.detail :label="__('operator.detail.customer')">{{ $customer?->fullName() }}</x-account.detail>
                <x-account.detail :label="__('operator.detail.company')">{{ $customer?->company_name }}</x-account.detail>
                <x-account.detail :label="__('operator.detail.email')">{{ $customer?->email }}</x-account.detail>
                <x-account.detail :label="__('operator.detail.country')">@if ($customer){{ __('countries.'.$customer->country_code) !== 'countries.'.$customer->country_code ? __('countries.'.$customer->country_code) : $customer->country_code }}@endif</x-account.detail>
                <x-account.detail :label="__('operator.detail.plan')">{{ $subscription ? $subscription->product_type->label().' · '.$subscription->planName() : __('operator.detail.no_subscription') }}</x-account.detail>
                <x-account.detail :label="__('operator.detail.subscription_status')">
                    @if ($subscription)<x-ui.status-badge :tone="$subscription->status->tone()">{{ $subscription->status->label() }}</x-ui.status-badge>@endif
                </x-account.detail>
                <x-account.detail :label="__('operator.detail.website_type')">{{ $website->type->label() }}</x-account.detail>
                <x-account.detail :label="__('operator.detail.website_status')"><x-ui.status-badge :tone="$website->status->tone()">{{ $website->status->label() }}</x-ui.status-badge></x-account.detail>
                <x-account.detail :label="__('operator.detail.connector_version')">@if ($connector?->connector_version)<span class="font-mono">{{ $connector->connector_version }}</span>@endif</x-account.detail>
                <x-account.detail :label="__('operator.detail.connector_state')"><x-ui.status-badge :tone="$connectorState->tone()">{{ $connectorState->label() }}</x-ui.status-badge></x-account.detail>
                <x-account.detail :label="__('operator.detail.last_heartbeat')"><span class="font-mono">{{ $time($connector?->last_heartbeat_at) }}</span></x-account.detail>
                <x-account.detail :label="__('operator.detail.wordpress_version')">@if ($core['version'] ?? $website->wordpress_version)<span class="font-mono">{{ $core['version'] ?? $website->wordpress_version }}</span>@endif</x-account.detail>
                <x-account.detail :label="__('operator.detail.php_version')">@if ($inventory?->php_version ?? $website->php_version)<span class="font-mono">{{ $inventory?->php_version ?? $website->php_version }}</span>@endif</x-account.detail>
                <x-account.detail :label="__('operator.detail.woocommerce_version')">
                    @if ($woo['installed'] ?? false)<span class="font-mono">{{ $woo['version'] ?? '' }}</span>@else{{ __('operator.detail.not_installed') }}@endif
                </x-account.detail>
                <x-account.detail :label="__('operator.detail.last_verified_backup')">@if ($latestVerified)<span class="font-mono">{{ $time($latestVerified->verified_at) }}</span>@endif</x-account.detail>
                <x-account.detail :label="__('operator.detail.pending_updates')">{{ $inventory ? $inventory->updates_pending : __('operator.detail.none_reported') }}</x-account.detail>
                <x-account.detail :label="__('operator.detail.security_state')"><x-ui.status-badge :tone="$security->state->tone()">{{ $security->state->label() }}</x-ui.status-badge></x-account.detail>
            </dl>

            @if (! empty($website->health_findings))
                <h3 class="mt-5 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('operator.detail.health_findings') }}</h3>
                <ul class="mt-2 grid gap-1.5 text-sm sm:grid-cols-2">
                    @foreach ($website->health_findings as $finding)
                        @php $tone = ['pass' => 'positive', 'warning' => 'warning', 'fail' => 'danger'][$finding['status']] ?? 'neutral'; @endphp
                        <li class="flex items-start justify-between gap-3 rounded-sm border border-line px-3 py-2">
                            <span class="min-w-0">
                                <span class="block text-primary">{{ $finding['label'] ?? $finding['id'] }}</span>
                                @if (! empty($finding['message']))<span class="block text-xs text-muted">{{ $finding['message'] }}</span>@endif
                            </span>
                            <x-ui.status-badge :tone="$tone">{{ __('connector.finding_statuses.'.$finding['status']) }}</x-ui.status-badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-operator.section>

        {{-- Connector --}}
        <x-operator.section :title="__('operator.detail.sections.connector')" id="connector">
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <x-account.detail :label="__('operator.detail.connector_state')"><x-ui.status-badge :tone="$connectorState->tone()">{{ $connectorState->label() }}</x-ui.status-badge></x-account.detail>
                <x-account.detail :label="__('operator.detail.last_seen')"><span class="font-mono">{{ $time($connector?->last_seen_at) }}</span></x-account.detail>
                <x-account.detail :label="__('operator.detail.last_poll')"><span class="font-mono">{{ $time($connector?->last_successful_poll_at) }}</span></x-account.detail>
                <x-account.detail :label="__('operator.detail.connector_version')">@if ($connector?->connector_version)<span class="font-mono">{{ $connector->connector_version }}</span>@endif</x-account.detail>
            </dl>
            @if ($connector?->capabilities)
                <p class="mt-4 text-xs text-muted">{{ __('operator.connector.capabilities') }}:
                    @foreach ($connector->capabilities as $capability)<span class="ml-1 inline-block rounded-sm bg-subtle px-1.5 py-0.5 font-mono text-[11px] text-ink">{{ $capability }}</span>@endforeach
                </p>
            @endif
            @if ($connector?->isOutdated())
                <x-account.notice tone="warning" class="mt-4">{{ __('operator.connector.update_required', ['version' => $connector->connector_version, 'latest' => config('connector.latest_version')]) }}</x-account.notice>
            @endif
            <p class="mt-4 flex items-center gap-2 text-xs text-muted"><x-ui.icon name="lock" class="size-4 text-accent-ink" />{{ __('operator.connector.credential_note') }}</p>

            @can('operator.websites.manage')
                @if ($pairingCode)
                    <div class="mt-4 rounded-md border border-accent bg-accent-soft p-3">
                        <p class="text-xs font-semibold tracking-[0.12em] text-muted uppercase">{{ __('operator.connector.code_label') }}</p>
                        <p class="mt-1 font-mono text-xl font-medium tracking-[0.08em] text-primary select-all">{{ $pairingCode['code'] }}</p>
                        <p class="mt-1 text-xs text-ink">{{ __('operator.connector.code_note', ['time' => $pairingCode['expires_at']->format('H:i')]) }}</p>
                    </div>
                @endif
                <div class="mt-4 grid gap-3 border-t border-line pt-4 md:grid-cols-2">
                    <form method="POST" action="{{ route('operator.websites.pairing-code', $website) }}" class="flex flex-col gap-2 rounded-sm border border-line p-3">
                        @csrf
                        <p class="text-xs text-muted">{{ __('operator.connector.repair_text') }}</p>
                        <div><x-ui.button type="submit" variant="secondary" size="sm">{{ __('operator.connector.repair') }}</x-ui.button></div>
                    </form>
                    @if ($connector)
                        <form method="POST" action="{{ route('operator.websites.disconnect', $website) }}" class="flex flex-col gap-2 rounded-sm border border-line p-3">
                            @csrf
                            <p class="text-xs text-muted">{{ __('operator.connector.disconnect_text') }}</p>
                            <label class="flex items-start gap-2 text-xs text-ink">
                                <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 rounded-sm border-line-strong accent-action">
                                {{ __('operator.connector.disconnect_confirm') }}
                            </label>
                            <div><x-ui.button type="submit" variant="danger" size="sm">{{ __('operator.connector.disconnect_submit') }}</x-ui.button></div>
                        </form>
                    @endif
                </div>
            @endcan
        </x-operator.section>

        {{-- WordPress core --}}
        <x-operator.section :title="__('operator.detail.sections.wordpress')" id="wordpress">
            @if ($inventory)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span>
                        <span class="text-muted">{{ __('operator.updates.current') }}</span> <span class="font-mono text-primary">{{ $core['version'] ?? '' }}</span>
                        @if (($core['update_available'] ?? null) === true)
                            · <span class="text-muted">{{ __('operator.updates.available') }}</span> <span class="font-mono text-warning-ink">{{ $core['latest_version'] ?? '' }}</span>
                        @elseif (($core['update_available'] ?? null) === false)
                            · <span class="text-muted">{{ __('connector.account.up_to_date') }}</span>
                        @endif
                    </span>
                    @if (($core['update_available'] ?? null) === true)
                        @can('operator.updates.run')
                            <form method="POST" action="{{ route('operator.websites.updates.store', $website) }}">
                                @csrf
                                <input type="hidden" name="kind" value="core">
                                <x-ui.button type="submit" size="sm">{{ __('operator.updates.update_core') }}</x-ui.button>
                            </form>
                        @endcan
                    @endif
                </div>
            @else
                <p class="text-sm text-muted">{{ __('operator.detail.none_reported') }}</p>
            @endif

            @can('operator.updates.view')
                <h3 class="mt-5 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('operator.updates.history') }}</h3>
                @if ($lastUpdate)
                    <p class="mt-1 text-xs text-muted">{{ __('operator.updates.last_result') }}:
                        <span class="font-medium text-primary">{{ __('connector.jobs.types.'.$lastUpdate->type->value) }} · {{ JobQueue::updateTarget($lastUpdate->payload ?? []) }}</span>
                        · {{ __('connector.jobs.statuses.'.$lastUpdate->status->value) }}@if ($lastUpdate->result_code) <span class="font-mono">({{ $lastUpdate->result_code }})</span>@endif
                    </p>
                @endif
                @if ($updateJobs->isEmpty())
                    <p class="mt-2 text-sm text-muted">{{ __('operator.updates.history_none') }}</p>
                @else
                    <div class="mt-2 relative overflow-x-auto rounded-sm border border-line">
                        <table class="w-full min-w-[640px] text-left text-sm">
                            <thead class="{{ $tableHead }}">
                                <tr>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.updates.job') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.updates.target') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.updates.status') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.updates.requested_by') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.updates.time') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($updateJobs as $job)
                                    @php $tone = ['success' => 'positive', 'failed' => 'danger', 'partial' => 'warning', 'expired' => 'warning', 'cancelled' => 'neutral'][$job->status->value] ?? 'neutral'; @endphp
                                    <tr>
                                        <td class="px-3 py-2">{{ __('connector.jobs.types.'.$job->type->value) }}
                                            @if ($job->backup?->status === BackupStatus::Verified)<span class="mt-0.5 flex items-center gap-1 text-xs text-accent-ink"><x-ui.icon name="shield" class="size-3.5" />{{ __('operator.updates.protected') }}</span>@endif
                                        </td>
                                        <td class="px-3 py-2 font-mono text-xs break-all">{{ JobQueue::updateTarget($job->payload ?? []) }}</td>
                                        <td class="px-3 py-2"><x-ui.status-badge :tone="$tone" variant="code">{{ __('connector.jobs.statuses.'.$job->status->value) }}</x-ui.status-badge>
                                            @if ($job->result_code)<span class="mt-1 block font-mono text-[11px] text-muted">{{ $job->result_code }}</span>@endif
                                        </td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $job->requested_by }}</td>
                                        <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-muted">{{ $time($job->completed_at ?? $job->created_at) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endcan
        </x-operator.section>

        {{-- Plugins and themes: pending updates first, licenses (plugins) --}}
        @foreach (['plugins' => $plugins, 'themes' => $themes] as $group => $items)
            <x-operator.section :title="__('operator.detail.sections.'.$group).' ('.$items->count().')'" :id="$group">
                @if (! $inventory)
                    <p class="text-sm text-muted">{{ __('operator.detail.none_reported') }}</p>
                @elseif ($items->isEmpty())
                    <p class="text-sm text-muted">{{ __('operator.updates.none') }}</p>
                @else
                    @php $sorted = $items->sortByDesc(fn ($item) => $item['update_available'] === true)->values(); @endphp
                    <div class="relative overflow-x-auto rounded-sm border border-line">
                        <table class="w-full min-w-[640px] text-left text-sm">
                            <thead class="{{ $tableHead }}">
                                <tr>
                                    <th scope="col" class="px-3 py-2">{{ __('connector.account.'.($group === 'plugins' ? 'plugin' : 'theme')) }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.updates.current') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.updates.available') }}</th>
                                    @if ($group === 'plugins')
                                        @can('operator.licenses.view')<th scope="col" class="px-3 py-2">{{ __('operator.licenses.title') }}</th>@endcan
                                    @endif
                                    <th scope="col" class="px-3 py-2"><span class="sr-only">{{ __('operator.updates.update') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($sorted as $item)
                                    @php $identifier = $group === 'plugins' ? $item['file'] : $item['stylesheet']; @endphp
                                    <tr class="align-top">
                                        <td class="px-3 py-2">
                                            <span class="text-primary">{{ $item['name'] }}</span>
                                            @if ($item['active'])<span class="text-xs text-accent-ink"> · {{ __('connector.account.active') }}</span>@endif
                                            <span class="block font-mono text-[11px] break-all text-muted">{{ $identifier }}</span>
                                        </td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $item['version'] ?? '' }}</td>
                                        <td class="px-3 py-2 text-xs">
                                            @if ($item['update_available'] === true)<span class="font-mono font-medium text-warning-ink">{{ $item['new_version'] ?? '' }}</span>
                                            @elseif ($item['update_available'] === false)<span class="text-muted">{{ __('connector.account.up_to_date') }}</span>
                                            @else<span class="text-muted">{{ __('connector.account.not_checked') }}</span>@endif
                                        </td>
                                        @if ($group === 'plugins')
                                            @can('operator.licenses.view')
                                                @php $license = LicenseStatus::tryFrom($item['license']['status'] ?? '') ?? LicenseStatus::Unknown; @endphp
                                                <td class="px-3 py-2">
                                                    <details class="group">
                                                        <summary class="flex cursor-pointer list-none items-center gap-2">
                                                            <x-ui.status-badge :tone="$license->tone()">{{ __('operator.licenses.states.'.$license->value) }}</x-ui.status-badge>
                                                            <span class="text-[11px] text-muted underline underline-offset-2">{{ __('operator.licenses.details') }}</span>
                                                        </summary>
                                                        <dl class="mt-2 grid gap-1 text-xs">
                                                            <div><dt class="inline text-muted">{{ __('operator.licenses.status') }}:</dt> <dd class="inline font-mono">{{ $license->value }}</dd></div>
                                                            <div><dt class="inline text-muted">{{ __('operator.licenses.expires') }}:</dt> <dd class="inline font-mono">{{ $item['license']['expires_at'] ?? __('operator.none') }}</dd></div>
                                                            <div><dt class="inline text-muted">{{ __('operator.licenses.source') }}:</dt> <dd class="inline font-mono">{{ $item['license']['source'] ?? __('operator.none') }}</dd></div>
                                                        </dl>
                                                    </details>
                                                </td>
                                            @endcan
                                        @endif
                                        <td class="px-3 py-2 text-right">
                                            @if ($item['update_available'] === true)
                                                @can('operator.updates.run')
                                                    <form method="POST" action="{{ route('operator.websites.updates.store', $website) }}">
                                                        @csrf
                                                        <input type="hidden" name="kind" value="{{ $group === 'plugins' ? 'plugin' : 'theme' }}">
                                                        <input type="hidden" name="target" value="{{ $identifier }}">
                                                        <x-ui.button type="submit" size="sm">{{ __('operator.updates.update') }}</x-ui.button>
                                                    </form>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($group === 'plugins')
                        @can('operator.licenses.view')<p class="mt-2 text-xs text-muted">{{ __('operator.licenses.note') }}</p>@endcan
                    @endif
                @endif
            </x-operator.section>
        @endforeach

        {{-- WooCommerce --}}
        <x-operator.section :title="__('operator.detail.sections.woocommerce')" id="woocommerce">
            @if (! $inventory)
                <p class="text-sm text-muted">{{ __('operator.detail.none_reported') }}</p>
            @elseif ($woo['installed'] ?? false)
                <p class="text-sm"><span class="font-mono text-primary">{{ $woo['version'] ?? '' }}</span>@unless ($woo['active'] ?? false) · <span class="text-muted">{{ __('operator.detail.inactive') }}</span>@endunless</p>
            @else
                <p class="text-sm text-muted">{{ __('operator.detail.not_installed') }}</p>
            @endif
        </x-operator.section>

        {{-- Backups --}}
        @can('operator.backups.view')
            <x-operator.section :title="__('operator.detail.sections.backups')" id="backups">
                @can('operator.backups.run')
                    <x-slot:action>
                        <form method="POST" action="{{ route('operator.websites.backups.store', $website) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">{{ __('operator.backups.request') }}</x-ui.button>
                        </form>
                    </x-slot:action>
                @endcan

                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <x-account.detail :label="__('operator.backups.schedule')">{{ $backupPolicy->frequency ? __('backups.account.frequencies.'.$backupPolicy->frequency) : '' }}</x-account.detail>
                    <x-account.detail :label="__('operator.backups.retention')">
                        @if ($backupPolicy->retentionCount && $backupPolicy->retentionDays){{ __('operator.backups.retention_value', ['count' => $backupPolicy->retentionCount, 'days' => $backupPolicy->retentionDays]) }}@elseif ($backupPolicy->retentionCount){{ __('operator.backups.retention_count', ['count' => $backupPolicy->retentionCount]) }}@elseif ($backupPolicy->retentionDays){{ __('operator.backups.retention_days', ['days' => $backupPolicy->retentionDays]) }}@endif
                    </x-account.detail>
                    <x-account.detail :label="__('operator.backups.storage')">@if ($backupPolicy->storage)<span class="font-mono">{{ $backupPolicy->storage }}</span>@endif</x-account.detail>
                    <x-account.detail :label="__('operator.detail.last_verified_backup')">@if ($latestVerified)<span class="font-mono">{{ $time($latestVerified->verified_at) }}</span>@endif</x-account.detail>
                </dl>
                @unless ($backupPolicy->eligible)
                    <p class="mt-3 text-xs text-warning-ink">{{ Refusal::message($backupPolicy->refusal) }}</p>
                @endunless

                @if ($backups->isEmpty())
                    <p class="mt-4 text-sm text-muted">{{ __('operator.backups.none') }}</p>
                @else
                    <div class="mt-4 relative overflow-x-auto rounded-sm border border-line">
                        <table class="w-full min-w-[720px] text-left text-sm">
                            <thead class="{{ $tableHead }}">
                                <tr>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.backups.created') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.backups.type') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.backups.reason') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.backups.status') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.backups.size') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('operator.backups.expires') }}</th>
                                    <th scope="col" class="px-3 py-2"><span class="sr-only">{{ __('operator.backups.restore') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($backups as $backup)
                                    <tr class="align-top">
                                        <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ $time($backup->created_at) }}</td>
                                        <td class="px-3 py-2 text-xs">{{ $backup->type->label() }}</td>
                                        <td class="px-3 py-2 text-xs">{{ $backup->reason->label() }}</td>
                                        <td class="px-3 py-2">
                                            <x-ui.status-badge :tone="$backup->status->tone()" variant="code">{{ $backup->status->label() }}</x-ui.status-badge>
                                            @if ($backup->verified_at)<span class="mt-1 block font-mono text-[11px] text-muted">{{ __('operator.backups.verified') }} {{ $time($backup->verified_at) }}</span>@endif
                                            @if ($backup->error_code)<span class="mt-1 block text-[11px] text-danger-ink">{{ BackupText::error($backup->error_code) }} <span class="font-mono">({{ $backup->error_code }})</span></span>@endif
                                        </td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ BackupText::size($backup->total_size) }}</td>
                                        <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-muted">{{ $backup->expires_at?->format('Y-m-d') ?? __('operator.none') }}</td>
                                        <td class="px-3 py-2 text-right">
                                            @if (in_array($backup->id, $restorable, true))
                                                <x-ui.button :href="route('operator.websites.backups.restore', [$website, $backup])" variant="secondary" size="sm">{{ __('operator.backups.restore') }}</x-ui.button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @can('operator.backups.restore')<p class="mt-2 text-xs text-muted">{{ __('operator.backups.restore_when') }}</p>@endcan
                @endif

                @if ($restores->isNotEmpty())
                    <h3 class="mt-5 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('operator.backups.restores') }}</h3>
                    <ul class="mt-2 divide-y divide-line text-sm">
                        @foreach ($restores as $restore)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>{{ $restore->scope->label() }} · <span class="font-mono text-xs text-muted">{{ $time($restore->backup?->created_at) }}</span>
                                    @if ($restore->error_code)<span class="block font-mono text-[11px] text-danger-ink">{{ $restore->error_code }}</span>@endif
                                </span>
                                <span class="flex items-center gap-2">
                                    <span class="font-mono text-xs text-muted">{{ $restore->requested_by }}</span>
                                    <x-ui.status-badge :tone="$restore->status->tone()">{{ $restore->status->label() }}</x-ui.status-badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-operator.section>
        @endcan

        {{-- Security (placeholder until the Security/Malware milestone) --}}
        @can('operator.security.view')
            <x-operator.section :title="__('operator.detail.sections.security')" id="security">
                @if (! $securityConfigured)
                    <p class="text-sm font-medium text-primary">{{ __('operator.security.not_configured') }}</p>
                    <p class="mt-1 text-xs text-muted">{{ __('operator.security.not_configured_text') }}</p>
                @else
                    <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
                        <x-account.detail :label="__('operator.detail.security_state')"><x-ui.status-badge :tone="$security->state->tone()">{{ $security->state->label() }}</x-ui.status-badge></x-account.detail>
                        <x-account.detail :label="__('operator.security.findings')">{{ $security->findings }}</x-account.detail>
                        <x-account.detail :label="__('operator.security.severity')">@if ($security->highestSeverity)<x-ui.status-badge :tone="$security->highestSeverity->tone()">{{ $security->highestSeverity->label() }}</x-ui.status-badge>@endif</x-account.detail>
                        <x-account.detail :label="__('operator.security.last_scan')">@if ($security->lastScanAt)<span class="font-mono">{{ $time($security->lastScanAt) }}</span>@endif</x-account.detail>
                    </dl>
                @endif
            </x-operator.section>
        @endcan

        {{-- WordPress admin access --}}
        <x-operator.section :title="__('operator.detail.sections.admin_access')" id="admin-access">
            @php
                $live = $openAccess->filter(fn ($request) => $request->isLive());
                $waiting = $openAccess->reject(fn ($request) => $request->isLive());
            @endphp
            <div class="grid gap-2">
                @forelse ($live as $request)
                    @php $minutes = (int) floor(now()->diffInMinutes($request->session_expires_at)); @endphp
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-line px-3 py-2">
                        <span class="flex flex-wrap items-center gap-2 text-sm">
                            <x-ui.status-badge :tone="$request->access_level === AccessLevel::FullAdmin ? 'danger' : 'warning'">{{ $request->access_level === AccessLevel::FullAdmin ? __('operator.admin_access.full_active') : __('operator.admin_access.maintenance_active') }}</x-ui.status-badge>
                            <span class="font-mono text-xs text-muted">{{ $minutes >= 1 ? __('operator.admin_access.expires_in', ['minutes' => $minutes]) : __('operator.admin_access.expires_soon') }}</span>
                            <span class="text-xs text-muted">{{ __('operator.admin_access.requested_by', ['who' => __('operator.actors.'.$request->actor_type->value).($request->requestedBy ? ' '.$request->requestedBy->email : '')]) }}</span>
                        </span>
                        @can('operator.admin_access.request')
                            <form method="POST" action="{{ route('operator.websites.admin-access.revoke', [$website, $request]) }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm">{{ __('operator.admin_access.revoke') }}</x-ui.button>
                            </form>
                        @endcan
                    </div>
                @empty
                    @if ($waiting->isEmpty())
                        <p><x-ui.status-badge tone="neutral">{{ __('operator.admin_access.none') }}</x-ui.status-badge></p>
                    @endif
                @endforelse
                @foreach ($waiting as $request)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-dashed border-line px-3 py-2 text-xs text-muted">
                        <span>{{ $request->access_level->label() }}: {{ __('operator.admin_access.pending') }}</span>
                        @can('operator.admin_access.request')
                            <form method="POST" action="{{ route('operator.websites.admin-access.revoke', [$website, $request]) }}">
                                @csrf
                                <x-ui.button type="submit" variant="ghost" size="sm">{{ __('operator.admin_access.revoke') }}</x-ui.button>
                            </form>
                        @endcan
                    </div>
                @endforeach
            </div>

            @can('operator.admin_access.request')
                <div class="mt-4 flex flex-wrap items-start gap-3 border-t border-line pt-4">
                    @if ($maintenanceRefusal === null)
                        <form method="POST" action="{{ route('operator.websites.admin-access.maintenance', $website) }}" target="_blank">
                            @csrf
                            <x-ui.button type="submit" size="sm" icon="arrow-right">{{ __('operator.admin_access.open') }}</x-ui.button>
                        </form>
                        <p class="max-w-md text-xs text-muted">{{ __('operator.admin_access.open_text', ['minutes' => AccessLevel::Maintenance->minutes()]) }}</p>
                    @else
                        <p class="text-xs text-muted">{{ __('operator.admin_access.unavailable', ['reason' => Refusal::message($maintenanceRefusal)]) }}
                            <span class="font-mono">({{ __('operator.kinds.'.Refusal::kind($maintenanceRefusal)) }})</span></p>
                    @endif
                    @can('operator.admin_access.full')
                        <x-ui.button :href="route('operator.websites.admin-access.full-admin', $website)" variant="secondary" size="sm" icon="shield" class="ml-auto">{{ __('operator.admin_access.request_full') }}</x-ui.button>
                    @endcan
                </div>
            @endcan
            <p class="mt-3 flex items-center gap-2 text-xs text-muted"><x-ui.icon name="lock" class="size-4 text-accent-ink" />{{ __('operator.admin_access.no_password') }}</p>
        </x-operator.section>

        {{-- Activity --}}
        @can('operator.audit.view')
            <x-operator.section :title="__('operator.detail.sections.activity')" id="activity">
                @if ($events->isEmpty())
                    <p class="text-sm text-muted">{{ __('operator.activity.empty') }}</p>
                @else
                    <x-operator.events-table :events="$events" :show-website="false" class="-mx-4 -my-2" />
                @endif
            </x-operator.section>
        @endcan
    </div>
</x-layouts.operator>
