@use('App\Connector\HealthStatus')
@use('App\Connector\LicenseStatus')

<x-layouts.account :title="$website->host()">
    <x-account.page-header :title="$website->host()" :lead="$website->url" />

    <x-account.onboarding-steps :states="$website->onboardingStates()" />

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        {{-- Connection --}}
        <x-account.panel :title="__('connector.account.connection')" heading-id="connection-title">
            @php $presence = $website->presence(); @endphp
            <dl class="grid gap-5 text-sm sm:grid-cols-2">
                <x-account.detail :label="__('connector.account.status')">
                    <x-ui.status-badge :tone="$presence->tone()">{{ $presence->label() }}</x-ui.status-badge>
                </x-account.detail>
                <x-account.detail :label="__('connector.account.last_seen')">
                    @if ($connector?->last_seen_at)<span class="font-mono">{{ $connector->last_seen_at->translatedFormat('j M Y H:i') }}</span>@else{{ __('connector.account.never') }}@endif
                </x-account.detail>
                <x-account.detail :label="__('connector.account.version')">
                    @if ($connector?->connector_version)
                        <span class="font-mono">{{ $connector->connector_version }}</span>
                        @if ($connector->isOutdated())
                            <span class="mt-1 block text-xs font-normal text-warning-ink">{{ __('connector.account.outdated', ['version' => config('connector.latest_version')]) }}</span>
                        @endif
                    @endif
                </x-account.detail>
                <x-account.detail :label="__('account.websites.type')">{{ $website->type->label() }}</x-account.detail>
            </dl>

            @if ($connector)
                <div x-data="{ confirming: false }" class="mt-6 border-t border-line pt-6">
                    <x-ui.button variant="secondary" x-show="!confirming" x-on:click="confirming = true">{{ __('connector.account.disconnect') }}</x-ui.button>
                    <form method="POST" action="{{ route('account.websites.disconnect', $website) }}" x-show="confirming" x-cloak class="flex flex-col gap-3">
                        @csrf
                        <p class="text-sm text-ink">{{ __('connector.account.disconnect_confirm') }}</p>
                        <div class="flex gap-3">
                            <x-ui.button type="submit">{{ __('connector.account.disconnect_yes') }}</x-ui.button>
                            <x-ui.button variant="ghost" x-on:click="confirming = false">{{ __('connector.account.cancel') }}</x-ui.button>
                        </div>
                    </form>
                </div>
            @endif
        </x-account.panel>

        {{-- Pairing (only while not connected) --}}
        @unless ($connector)
            <x-account.panel :title="__('connector.account.pair_title')" heading-id="pair-title">
                <ol class="space-y-2 text-sm text-ink">
                    @foreach (__('connector.account.pair_steps') as $step)
                        <li class="flex gap-3">
                            <span class="text-xs font-semibold text-accent-ink tabular-nums">{{ sprintf('%02d', $loop->iteration) }}</span>
                            <span>{{ $step }}</span>
                        </li>
                    @endforeach
                </ol>
                <p class="mt-3 flex items-center gap-2 text-xs text-muted"><x-ui.icon name="lock" class="size-4 text-accent-ink" />{{ __('connector.account.no_password') }}</p>

                @if ($pairingCode)
                    <div class="mt-6 rounded-md border border-accent bg-accent-soft p-4">
                        <p class="text-xs font-semibold tracking-[0.12em] text-muted uppercase">{{ __('connector.account.code_label') }}</p>
                        <p class="mt-2 font-mono text-2xl font-medium tracking-[0.08em] text-primary select-all">{{ $pairingCode['code'] }}</p>
                        <p class="mt-2 text-xs text-ink">{{ __('connector.account.code_note', ['time' => $pairingCode['expires_at']->translatedFormat('H:i')]) }}</p>
                    </div>
                @endif

                <div class="mt-6 flex flex-wrap gap-3">
                    <x-ui.button :href="route('account.connector.download')" variant="secondary" icon="arrow-down">{{ __('connector.account.download') }}</x-ui.button>
                    @if ($canPair)
                        <form method="POST" action="{{ route('account.websites.pairing-code', $website) }}">
                            @csrf
                            <x-ui.button type="submit">{{ $pairingCode ? __('connector.account.new_code') : __('connector.account.generate_code') }}</x-ui.button>
                        </form>
                    @endif
                </div>
                @unless ($canPair)
                    <x-account.notice class="mt-4">{{ __('connector.account.pair_needs_plan') }}</x-account.notice>
                @endunless
            </x-account.panel>
        @endunless

        {{-- Health --}}
        <x-account.panel :title="__('connector.account.health')" heading-id="health-title">
            @if ($website->health_status)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <x-ui.status-badge :tone="$website->health_status->tone()">{{ $website->health_status->label() }}</x-ui.status-badge>
                    <span class="font-mono text-xs text-muted">{{ __('connector.account.checked_at', ['time' => $website->health_checked_at->translatedFormat('j M Y H:i')]) }}</span>
                </div>
                <ul class="mt-4 divide-y divide-line text-sm">
                    @foreach ($website->health_findings ?? [] as $finding)
                        @php $tone = ['pass' => 'positive', 'warning' => 'warning', 'fail' => 'danger', 'unknown' => 'neutral'][$finding['status']]; @endphp
                        <li class="flex items-start justify-between gap-4 py-2.5">
                            <span class="min-w-0">
                                <span class="block text-primary">{{ $finding['label'] ?? $finding['id'] }}</span>
                                @if (! empty($finding['message']))<span class="block text-xs text-muted">{{ $finding['message'] }}</span>@endif
                            </span>
                            <x-ui.status-badge :tone="$tone">{{ __('connector.finding_statuses.'.$finding['status']) }}</x-ui.status-badge>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-muted">{{ __('connector.account.health_none') }}</p>
            @endif
        </x-account.panel>
    </div>

    <x-backups.summary class="mt-6" :website="$website" :latest="$latestBackup" :verified="$latestVerified" :policy="$backupPolicy" :can-backup="$canBackup" />

    {{-- Inventory --}}
    <x-account.panel :title="__('connector.account.inventory')" heading-id="inventory-title" class="mt-6">
        @if ($inventory)
            <dl class="grid gap-5 text-sm sm:grid-cols-3">
                <x-account.detail :label="__('connector.account.wordpress')">
                    <span class="font-mono">{{ $inventory->core['version'] }}</span>
                    <span class="block text-xs font-normal text-muted">
                        @switch($inventory->core['update_available'] ?? null)
                            @case(true) {{ __('connector.account.update_to', ['version' => $inventory->core['latest_version'] ?? '']) }} @break
                            @case(false) {{ __('connector.account.up_to_date') }} @break
                            @default {{ __('connector.account.not_checked') }}
                        @endswitch
                    </span>
                </x-account.detail>
                <x-account.detail :label="__('connector.account.php')"><span class="font-mono">{{ $inventory->php_version }}</span></x-account.detail>
                <x-account.detail :label="__('connector.account.woocommerce')">
                    @if ($inventory->woocommerce['installed'] ?? false)
                        <span class="font-mono">{{ $inventory->woocommerce['version'] ?? '' }}</span>@unless ($inventory->woocommerce['active'] ?? false) · {{ __('connector.account.inactive') }}@endunless
                    @else
                        {{ __('connector.account.not_installed') }}
                    @endif
                </x-account.detail>
            </dl>

            @foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $group => $label)
                <h3 class="mt-8 text-sm font-semibold">{{ __('connector.account.'.$group) }} <span class="font-normal text-muted">({{ count($inventory->{$group}) }})</span></h3>
                <div class="mt-3 overflow-x-auto rounded-md border border-line">
                    <table class="w-full min-w-[560px] text-left text-sm">
                        <thead class="bg-canvas text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                            <tr>
                                <th scope="col" class="px-4 py-2">{{ __('connector.account.'.$label) }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('connector.account.installed_version') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('connector.account.update') }}</th>
                                @if ($group === 'plugins')<th scope="col" class="px-4 py-2">{{ __('connector.account.license') }}</th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($inventory->{$group} as $item)
                                <tr>
                                    <td class="px-4 py-2.5 text-primary">
                                        {{ $item['name'] }}
                                        @if ($item['active'])<span class="ml-1 text-xs text-accent-ink">· {{ __('connector.account.active') }}</span>@endif
                                    </td>
                                    <td class="px-4 py-2.5 font-mono text-xs">{{ $item['version'] ?? '' }}</td>
                                    <td class="px-4 py-2.5 text-xs">
                                        @if ($item['update_available'] === true)<span class="text-warning-ink">{{ $item['new_version'] ?? '' }}</span>@elseif ($item['update_available'] === false)<span class="text-muted">{{ __('connector.account.up_to_date') }}</span>@else<span class="text-muted">{{ __('connector.account.not_checked') }}</span>@endif
                                    </td>
                                    @if ($group === 'plugins')
                                        @php $license = LicenseStatus::tryFrom($item['license']['status'] ?? '') ?? LicenseStatus::Unknown; @endphp
                                        <td class="px-4 py-2.5"><x-ui.status-badge :tone="$license->tone()">{{ $license->label() }}</x-ui.status-badge></td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
            <p class="mt-3 text-xs text-muted">{{ __('connector.account.license_note') }}</p>
        @else
            <p class="text-sm text-muted">{{ __('connector.account.inventory_none') }}</p>
        @endif
    </x-account.panel>

    {{-- Jobs --}}
    <x-account.panel :title="__('connector.account.jobs')" heading-id="jobs-title" class="mt-6">
        @if ($jobs->isNotEmpty())
            <ul class="divide-y divide-line text-sm">
                @foreach ($jobs as $job)
                    @php $tone = ['success' => 'positive', 'failed' => 'danger', 'partial' => 'warning', 'expired' => 'warning'][$job->status->value] ?? 'neutral'; @endphp
                    <li class="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 py-2.5 sm:grid-cols-[1fr_auto_auto]">
                        <span class="text-primary">{{ __('connector.jobs.types.'.$job->type->value) }} @if ($job->result_code)<span class="font-mono text-xs text-muted">· {{ $job->result_code }}</span>@endif
                            @if ($job->type->isUpdate() && $job->backup?->status === \App\Backups\BackupStatus::Verified)<span class="mt-0.5 flex items-center gap-1 text-xs text-accent-ink"><x-ui.icon name="shield" class="size-3.5" />{{ __('backups.account.protected') }}</span>@endif
                        </span>
                        <span class="row-start-2 font-mono text-xs text-muted sm:row-start-auto">{{ ($job->completed_at ?? $job->created_at)->translatedFormat('j M Y H:i') }}</span>
                        <span class="row-span-2 sm:row-span-1"><x-ui.status-badge :tone="$tone" variant="code">{{ __('connector.jobs.statuses.'.$job->status->value) }}</x-ui.status-badge></span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm text-muted">{{ __('connector.account.jobs_none') }}</p>
        @endif
    </x-account.panel>
</x-layouts.account>
