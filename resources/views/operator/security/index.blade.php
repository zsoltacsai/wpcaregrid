{{-- Security overview. Reads SecurityScanner and scan results only; nothing here is inferred. --}}
<x-layouts.operator :title="__('operator.security.title')">
    <x-operator.page-header :title="__('operator.security.title')" :lead="__('operator.security.lead')" />

    @if (! $configured)
        <x-operator.section>
            <p class="text-sm font-semibold text-primary">{{ __('operator.security.not_configured') }}</p>
            <p class="mt-1 text-sm text-muted">{{ __('operator.security.not_configured_text') }}</p>
        </x-operator.section>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-operator.metric :label="__('operator.security.open_findings')" :value="$openFindings" tone="danger" data-metric="open_findings" />
            <x-operator.metric :label="__('operator.security.open_incidents')" :value="$incidents->count()" tone="danger" data-metric="open_incidents" />
            <x-operator.metric :label="__('operator.security.websites_with_findings')" :value="$websites->total()" tone="warning" data-metric="websites_with_findings" />
            <x-operator.metric :label="__('operator.security.not_scanned_websites')" :value="$unscanned" tone="neutral" data-metric="not_scanned" />
        </div>

        @unless ($vulnerabilitySource)
            <x-account.notice tone="warning" class="mt-4">{{ __('security.unavailable_reasons.VULNERABILITY_SOURCE_NOT_CONFIGURED') }} {{ __('operator.security.vulnerability_note') }}</x-account.notice>
        @endunless

        <x-operator.section :title="__('operator.security.incidents_title')" id="incidents" class="mt-5">
            @if ($incidents->isEmpty())
                <p class="text-sm text-muted">{{ __('operator.security.no_incidents') }}</p>
            @else
                <ul class="-my-2 divide-y divide-line">
                    @foreach ($incidents as $incident)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <a href="{{ route('operator.websites.security.incidents.show', [$incident->website, $incident]) }}" class="min-w-0 font-medium text-primary hover:underline">{{ $incident->title }}
                                <span class="block truncate text-xs font-normal text-muted">{{ $incident->website->host() }}</span></a>
                            <span class="flex items-center gap-2">
                                @if ($incident->severity)<x-ui.status-badge :tone="$incident->severity->tone()">{{ $incident->severity->label() }}</x-ui.status-badge>@endif
                                <x-ui.status-badge :tone="$incident->status->tone()">{{ $incident->status->label() }}</x-ui.status-badge>
                                <span class="font-mono text-xs text-muted">@displayDateTime($incident->created_at)</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-operator.section>

        <x-operator.section :title="__('operator.security.websites_title')" class="mt-5">
            @if ($websites->isEmpty())
                <p class="text-sm text-muted">{{ __('operator.security.no_open_findings') }}</p>
            @else
                <div class="relative -mx-4 -my-2 overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead class="text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                            <tr class="border-b border-line">
                                <th scope="col" class="px-4 py-2">{{ __('operator.websites.columns.website') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('operator.websites.columns.security') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('operator.security.findings') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('operator.security.severity') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('operator.security.last_scan') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($websites as $website)
                                @php $summary = $summaries[$website->id]; $state = $website->security_status; @endphp
                                <tr>
                                    <td class="px-4 py-2"><a href="{{ route('operator.websites.security', $website) }}" class="text-primary hover:underline">{{ $website->host() }}</a></td>
                                    <td class="px-4 py-2">@if ($state)<x-ui.status-badge :tone="$state->tone()">{{ __('security.states.'.$state->value) }}</x-ui.status-badge>@endif</td>
                                    <td class="px-4 py-2 tabular-nums">{{ $summary->findings }}</td>
                                    <td class="px-4 py-2">@if ($summary->highestSeverity)<x-ui.status-badge :tone="$summary->highestSeverity->tone()">{{ $summary->highestSeverity->label() }}</x-ui.status-badge>@endif</td>
                                    <td class="px-4 py-2 font-mono text-xs text-muted">{{ app(\App\Support\DisplayDateTime::class)->format($summary->lastScanAt) ?? __('operator.never') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $websites->links() }}</div>
            @endif
        </x-operator.section>

        <x-operator.section :title="__('operator.security.scans_title')" class="mt-5">
            @include('operator.security.partials.scans', ['scans' => $scans, 'showWebsite' => true])
        </x-operator.section>
    @endif
</x-layouts.operator>
