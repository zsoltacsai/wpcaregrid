{{-- Security overview. Reads SecurityScanner only; without a scanner it says so and shows nothing else. --}}
<x-layouts.operator :title="__('operator.security.title')">
    <x-operator.page-header :title="__('operator.security.title')" :lead="__('operator.security.lead')" />

    @if (! $configured)
        <x-operator.section>
            <div class="flex items-start gap-3">
                <x-ui.icon name="shield" class="mt-0.5 size-5 text-muted" />
                <div>
                    <p class="text-sm font-semibold text-primary">{{ __('operator.security.not_configured') }}</p>
                    <p class="mt-1 text-sm text-muted">{{ __('operator.security.not_configured_text') }}</p>
                </div>
            </div>
        </x-operator.section>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-operator.metric :label="__('operator.security.open_findings')" :value="$openFindings" tone="danger" />
        </div>

        <div class="mt-4 relative overflow-x-auto rounded-md border border-line bg-surface">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="bg-canvas text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr>
                        <th scope="col" class="px-3 py-2">{{ __('operator.websites.columns.website') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('operator.websites.columns.security') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('operator.security.findings') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('operator.security.severity') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('operator.security.last_scan') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($websites as $website)
                        @php $summary = $summaries[$website->id]; @endphp
                        <tr>
                            <td class="px-3 py-2"><a href="{{ route('operator.websites.show', $website) }}#security" class="text-primary hover:underline">{{ $website->host() }}</a></td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$summary->state->tone()">{{ $summary->state->label() }}</x-ui.status-badge></td>
                            <td class="px-3 py-2 tabular-nums">{{ $summary->findings }}</td>
                            <td class="px-3 py-2">@if ($summary->highestSeverity)<x-ui.status-badge :tone="$summary->highestSeverity->tone()">{{ $summary->highestSeverity->label() }}</x-ui.status-badge>@endif</td>
                            <td class="px-3 py-2 font-mono text-xs text-muted">{{ $summary->lastScanAt?->format('Y-m-d H:i') ?? __('operator.never') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $websites->links() }}</div>
    @endif
</x-layouts.operator>
