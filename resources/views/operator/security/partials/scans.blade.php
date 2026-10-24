{{-- Scan history: type, status, coverage per scanner, counts, versions. --}}
@if ($scans->isEmpty())
    <p class="text-sm text-muted">{{ __('operator.security.no_scans') }}</p>
@else
    <div class="relative -mx-4 -my-2 overflow-x-auto">
        <table class="w-full min-w-[760px] text-left text-sm">
            <thead class="text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                <tr class="border-b border-line">
                    <th scope="col" class="px-4 py-2">{{ __('operator.security.scan_columns.time') }}</th>
                    @if ($showWebsite)<th scope="col" class="px-4 py-2">{{ __('operator.websites.columns.website') }}</th>@endif
                    <th scope="col" class="px-4 py-2">{{ __('operator.security.scan_columns.type') }}</th>
                    <th scope="col" class="px-4 py-2">{{ __('operator.security.scan_columns.status') }}</th>
                    <th scope="col" class="px-4 py-2">{{ __('operator.security.scan_columns.coverage') }}</th>
                    <th scope="col" class="px-4 py-2">{{ __('operator.security.findings') }}</th>
                    <th scope="col" class="px-4 py-2">{{ __('operator.security.scan_columns.versions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($scans as $scan)
                    <tr class="align-top">
                        <td class="px-4 py-2 font-mono text-xs whitespace-nowrap text-muted">@displayDateTime($scan->created_at)</td>
                        @if ($showWebsite)<td class="px-4 py-2"><a href="{{ route('operator.websites.security', $scan->website) }}" class="text-primary hover:underline">{{ $scan->website->host() }}</a></td>@endif
                        <td class="px-4 py-2 text-xs">{{ $scan->type->label() }}</td>
                        <td class="px-4 py-2">
                            <x-ui.status-badge :tone="$scan->status->tone()" variant="code">{{ $scan->status->label() }}</x-ui.status-badge>
                            @if ($scan->error_code)<span class="mt-1 block font-mono text-[11px] text-muted">{{ $scan->error_code }}</span>@endif
                        </td>
                        <td class="px-4 py-2 text-xs">
                            @foreach ($scan->type->scanners() as $scanner)
                                @php $coverage = $scan->coverage[$scanner] ?? null; @endphp
                                <span class="block">{{ __('security.scanners.'.$scanner) }}:
                                    <span @class(['font-medium', 'text-danger-ink' => ($coverage['status'] ?? null) === 'failed', 'text-warning-ink' => in_array($coverage['status'] ?? null, ['partial', 'unavailable'], true)])>{{ $coverage ? __('security.coverage.'.$coverage['status']) : '—' }}</span>
                                    @if (isset($coverage['reason']))<span class="text-muted">({{ __('security.unavailable_reasons.'.$coverage['reason']) }})</span>@endif
                                    @if (isset($coverage['files_scanned']))<span class="font-mono text-muted">· {{ number_format($coverage['files_scanned']) }} files</span>@endif
                                    @if ($scanner === 'integrity' && isset($coverage['verified']))
                                        <span class="font-mono text-muted">
                                            · {{ number_format((int) $coverage['verified']) }} {{ __('security.integrity_counts.verified') }},
                                            {{ number_format((int) ($coverage['modified'] ?? 0)) }} {{ __('security.integrity_counts.modified') }},
                                            {{ number_format((int) ($coverage['missing'] ?? 0)) }} {{ __('security.integrity_counts.missing') }},
                                            {{ number_format((int) ($coverage['extra'] ?? 0)) }} {{ __('security.integrity_counts.extra') }},
                                            {{ number_format((int) ($coverage['unavailable'] ?? 0)) }} {{ __('security.integrity_counts.unavailable') }}
                                        </span>
                                    @endif
                                </span>
                            @endforeach
                        </td>
                        <td class="px-4 py-2 text-xs tabular-nums">
                            @if ($scan->summary && isset($scan->summary['findings']))
                                {{ $scan->summary['findings'] }}
                                <span class="block text-muted">{{ collect($scan->summary['by_severity'] ?? [])->filter()->map(fn ($n, $s) => $n.' '.__('security.severities.'.$s))->implode(', ') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 font-mono text-[11px] text-muted">
                            {{ $scan->scanner_version }}@if ($scan->rules_version) · rules {{ $scan->rules_version }}@endif
                            @if ($scan->connector_version)<span class="block">connector {{ $scan->connector_version }}</span>@endif
                            @if ($scan->duration_ms !== null)<span class="block">{{ number_format($scan->duration_ms / 1000, 1) }} s</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
