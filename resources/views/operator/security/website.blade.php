{{--
    One website's security for operators: scans, findings (severity and
    confidence shown separately), decisions, remediation, incidents and
    quarantine. Evidence is metadata only; no file content is stored anywhere.
--}}
@use('App\Security\FindingStatus')
@use('App\Security\FindingType')
@use('App\Security\RemediationType')
@use('App\Security\ScanType')

<x-layouts.operator :title="__('operator.security.title').' · '.$website->host()">
    @php
        $user = auth('operator')->user();
        $state = $website->security_status;
        $select = 'h-8 rounded-sm border border-line-strong bg-surface px-2 text-xs text-ink';
    @endphp

    <x-operator.page-header :title="$website->host()" :lead="__('operator.security.website_lead')">
        <x-slot:eyebrow>
            <a href="{{ route('operator.websites.show', $website) }}#security" class="underline underline-offset-2 hover:text-primary">{{ __('operator.detail.back_to_website') }}</a>
            · <a href="{{ route('operator.security.index') }}" class="underline underline-offset-2 hover:text-primary">{{ __('operator.security.title') }}</a>
        </x-slot:eyebrow>
        <x-slot:actions>
            <x-ui.status-badge :tone="$state?->tone() ?? 'neutral'">{{ __('security.states.'.($state?->value ?? 'unknown')) }}</x-ui.status-badge>
        </x-slot:actions>
    </x-operator.page-header>

    <div class="grid gap-5">
        <x-operator.section :title="__('operator.security.scans_title')" id="scans">
            @can('operator.security.scan')
                <x-slot:action>
                    <form method="POST" action="{{ route('operator.websites.security.scans.store', $website) }}" class="flex items-center gap-2">
                        @csrf
                        <label for="scan-type" class="sr-only">{{ __('operator.security.scan_columns.type') }}</label>
                        <select id="scan-type" name="type" class="{{ $select }}">
                            @foreach (ScanType::cases() as $type)
                                <option value="{{ $type->value }}" @selected($type === ScanType::Full)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <x-ui.button type="submit" size="sm">{{ __('operator.security.run_scan') }}</x-ui.button>
                    </form>
                </x-slot:action>
            @endcan
            @include('operator.security.partials.scans', ['scans' => $scans, 'showWebsite' => false])
            @php $running = $scans->first(fn ($s) => ! $s->status->isFinished()); @endphp
            @if ($running)
                @can('operator.security.scan')
                    <form method="POST" action="{{ route('operator.websites.security.scans.cancel', [$website, $running]) }}" class="mt-3">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" size="sm">{{ __('operator.security.cancel_scan') }}</x-ui.button>
                    </form>
                @endcan
            @endif
        </x-operator.section>

        <x-operator.section :title="__('operator.security.findings_title')" id="findings">
            <form method="GET" action="{{ route('operator.websites.security', $website) }}" class="mb-3 flex flex-wrap items-end gap-2">
                <div>
                    <label for="f-status" class="block text-xs text-muted">{{ __('operator.security.finding_columns.status') }}</label>
                    <select id="f-status" name="status" class="{{ $select }} mt-1">
                        <option value="">{{ __('operator.security.active_only') }}</option>
                        @foreach (FindingStatus::cases() as $case)
                            <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-type" class="block text-xs text-muted">{{ __('operator.security.finding_columns.type') }}</label>
                    <select id="f-type" name="type" class="{{ $select }} mt-1">
                        <option value="">{{ __('operator.websites.all') }}</option>
                        @foreach (FindingType::cases() as $case)
                            <option value="{{ $case->value }}" @selected($type === $case)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operator.websites.filter') }}</x-ui.button>
            </form>

            @if ($findings->isEmpty())
                <p class="text-sm text-muted">{{ __('operator.security.no_findings') }}</p>
            @else
                <div class="relative -mx-4 overflow-x-auto">
                    <table class="w-full min-w-[1000px] text-left text-sm">
                        <thead class="text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                            <tr class="border-b border-line">
                                @can('operator.security.manage')<th scope="col" class="px-3 py-2"><span class="sr-only">{{ __('operator.security.select') }}</span></th>@endcan
                                <th scope="col" class="px-3 py-2">{{ __('operator.security.finding_columns.finding') }}</th>
                                <th scope="col" class="px-3 py-2">{{ __('operator.security.finding_columns.severity') }}</th>
                                <th scope="col" class="px-3 py-2">{{ __('operator.security.finding_columns.confidence') }}</th>
                                <th scope="col" class="px-3 py-2">{{ __('operator.security.finding_columns.status') }}</th>
                                <th scope="col" class="px-3 py-2">{{ __('operator.security.finding_columns.seen') }}</th>
                                <th scope="col" class="px-3 py-2">{{ __('operator.security.finding_columns.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($findings as $finding)
                                <tr class="align-top" id="finding-{{ $finding->uuid }}">
                                    @can('operator.security.manage')
                                        <td class="px-3 py-2"><input type="checkbox" name="findings[]" value="{{ $finding->uuid }}" form="incident-form" class="size-4 accent-[var(--color-action)]" aria-label="{{ __('operator.security.select') }}"></td>
                                    @endcan
                                    <td class="max-w-[360px] px-3 py-2">
                                        <span class="block text-xs text-muted">{{ $finding->type->label() }} · <span class="font-mono">{{ $finding->code }}</span></span>
                                        <span class="block font-medium text-primary">{{ $finding->title }}</span>
                                        @if ($finding->path)<span class="block font-mono text-[11px] break-all text-ink">{{ $finding->path }}</span>@elseif ($finding->component)<span class="block font-mono text-[11px] text-ink">{{ $finding->component }}</span>@endif
                                        @if ($finding->description)<span class="mt-0.5 block text-xs text-muted">{{ $finding->description }}</span>@endif
                                        @if ($finding->evidence)
                                            <details class="mt-1 text-[11px]">
                                                <summary class="cursor-pointer text-muted underline underline-offset-2">{{ __('operator.security.evidence') }}</summary>
                                                <dl class="mt-1 grid gap-0.5 font-mono">
                                                    @foreach ($finding->evidence as $key => $value)
                                                        <div><dt class="inline text-muted">{{ $key }}:</dt> <dd class="inline break-all">{{ is_array($value) ? implode(', ', $value) : (is_bool($value) ? ($value ? 'yes' : 'no') : $value) }}</dd></div>
                                                    @endforeach
                                                </dl>
                                            </details>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">@if ($finding->severity)<x-ui.status-badge :tone="$finding->severity->tone()">{{ $finding->severity->label() }}</x-ui.status-badge>@else<span class="text-xs text-muted">{{ __('security.severities.unrated') }}</span>@endif</td>
                                    <td class="px-3 py-2 text-xs whitespace-nowrap">{{ $finding->confidence->label() }}</td>
                                    <td class="px-3 py-2">
                                        <x-ui.status-badge :tone="$finding->status->tone()">{{ $finding->status->label() }}</x-ui.status-badge>
                                        @if ($finding->status_note)<span class="mt-1 block max-w-[160px] text-[11px] text-muted">{{ $finding->status_note }}</span>@endif
                                    </td>
                                    <td class="px-3 py-2 font-mono text-[11px] whitespace-nowrap text-muted">
                                        @displayDateTime($finding->first_seen_at, 'Y-m-d') → @displayDateTime($finding->last_seen_at, 'Y-m-d')
                                        <span class="block">×{{ $finding->occurrence_count }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        @can('operator.security.manage')
                                            <form method="POST" action="{{ route('operator.websites.security.findings.status', [$website, $finding]) }}" class="flex flex-wrap items-center gap-1">
                                                @csrf
                                                <label for="status-{{ $finding->uuid }}" class="sr-only">{{ __('operator.security.finding_columns.status') }}</label>
                                                <select id="status-{{ $finding->uuid }}" name="status" class="{{ $select }}">
                                                    @foreach (FindingStatus::settableByOperator() as $option)
                                                        @if ($option !== $finding->status)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endif
                                                    @endforeach
                                                </select>
                                                <input type="text" name="note" maxlength="500" placeholder="{{ __('operator.security.note_placeholder') }}" class="{{ $select }} w-28" aria-label="{{ __('operator.security.note_placeholder') }}">
                                                <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operator.security.set') }}</x-ui.button>
                                            </form>
                                        @endcan
                                        @can('operator.security.remediate')
                                            @if ($finding->status->isActive())
                                                <form method="POST" action="{{ route('operator.websites.security.findings.remediate', [$website, $finding]) }}" class="mt-1 flex flex-wrap items-center gap-1">
                                                    @csrf
                                                    <label for="rem-{{ $finding->uuid }}" class="sr-only">{{ __('operator.security.remediate') }}</label>
                                                    <select id="rem-{{ $finding->uuid }}" name="type" class="{{ $select }}">
                                                        @foreach (RemediationType::cases() as $option)
                                                            @if ($option !== RemediationType::RestoreQuarantinedFile)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endif
                                                        @endforeach
                                                    </select>
                                                    <label class="flex items-center gap-1 text-[11px] text-ink"><input type="checkbox" name="confirm" value="1" required class="size-3.5 accent-[var(--color-action)]">{{ __('operator.security.remediate_confirm') }}</label>
                                                    <x-ui.button type="submit" size="sm" variant="danger">{{ __('operator.security.remediate') }}</x-ui.button>
                                                </form>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $findings->links() }}</div>
            @endif

            @can('operator.security.manage')
                <form id="incident-form" method="POST" action="{{ route('operator.websites.security.incidents.store', $website) }}" class="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-4">
                    @csrf
                    <div class="min-w-0 flex-1 basis-56">
                        <label for="incident-title" class="block text-xs text-muted">{{ __('operator.security.incident_title') }}</label>
                        <input id="incident-title" name="title" required maxlength="191" class="{{ $select }} mt-1 w-full" value="{{ __('operator.security.incident_default_title') }}">
                    </div>
                    <div>
                        <label for="incident-severity" class="block text-xs text-muted">{{ __('operator.security.severity') }}</label>
                        <select id="incident-severity" name="severity" class="{{ $select }} mt-1">
                            <option value="">{{ __('security.severities.unrated') }}</option>
                            @foreach (\App\Security\Severity::cases() as $severity)<option value="{{ $severity->value }}">{{ $severity->label() }}</option>@endforeach
                        </select>
                    </div>
                    <x-ui.button type="submit" size="sm">{{ __('operator.security.open_incident') }}</x-ui.button>
                    <p class="w-full text-[11px] text-muted">{{ __('operator.security.incident_help') }}</p>
                </form>
            @endcan
        </x-operator.section>

        <x-operator.section :title="__('operator.security.incidents_title')" id="incidents">
            @if ($incidents->isEmpty())
                <p class="text-sm text-muted">{{ __('operator.security.no_incidents') }}</p>
            @else
                <ul class="-my-2 divide-y divide-line text-sm">
                    @foreach ($incidents as $incident)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <a href="{{ route('operator.websites.security.incidents.show', [$website, $incident]) }}" class="font-medium text-primary hover:underline">{{ $incident->title }}</a>
                            <span class="flex items-center gap-2"><x-ui.status-badge :tone="$incident->status->tone()">{{ $incident->status->label() }}</x-ui.status-badge><span class="font-mono text-xs text-muted">@displayDateTime($incident->created_at)</span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-operator.section>

        <x-operator.section :title="__('operator.security.remediations_title')" id="remediations">
            @if (! config('security.remediation.enabled'))
                <x-account.notice tone="warning" class="mb-3 [overflow-wrap:anywhere]">{{ __('operator.refusals.REMEDIATION_DISABLED') }}</x-account.notice>
            @endif
            @if ($remediations->isEmpty() && $quarantines->isEmpty())
                <p class="text-sm text-muted">{{ __('operator.security.no_remediations') }}</p>
            @endif
            @if ($remediations->isNotEmpty())
                <ul class="divide-y divide-line text-sm">
                    @foreach ($remediations as $remediation)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>{{ $remediation->type->label() }} <span class="font-mono text-[11px] break-all text-muted">{{ $remediation->before['path'] ?? $remediation->finding?->component }}</span>
                                @if ($remediation->error_code)<span class="block font-mono text-[11px] text-danger-ink">{{ $remediation->error_code }}</span>@endif</span>
                            <span class="flex items-center gap-2"><span class="font-mono text-[11px] text-muted">{{ $remediation->requested_by }}</span><x-ui.status-badge :tone="$remediation->status->tone()">{{ $remediation->status->label() }}</x-ui.status-badge></span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($quarantines->isNotEmpty())
                <h3 class="mt-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('operator.security.quarantine_title') }}</h3>
                <ul class="mt-2 divide-y divide-line text-sm">
                    @foreach ($quarantines as $quarantine)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span class="min-w-0"><span class="block font-mono text-[11px] break-all text-primary">{{ $quarantine->original_path }}</span>
                                <span class="block font-mono text-[11px] text-muted">sha256 {{ substr($quarantine->sha256, 0, 16) }}… · @displayDateTime($quarantine->quarantined_at)</span></span>
                            @if ($quarantine->status === 'quarantined')
                                @can('operator.security.remediate')
                                    <form method="POST" action="{{ route('operator.websites.security.quarantines.restore', [$website, $quarantine]) }}" class="flex items-center gap-2">
                                        @csrf
                                        <label class="flex items-center gap-1 text-[11px]"><input type="checkbox" name="confirm" value="1" required class="size-3.5 accent-[var(--color-action)]">{{ __('operator.security.undo_confirm') }}</label>
                                        <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operator.security.undo_quarantine') }}</x-ui.button>
                                    </form>
                                @endcan
                            @else
                                <x-ui.status-badge tone="neutral">{{ __('operator.security.restored') }}</x-ui.status-badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-operator.section>
    </div>
</x-layouts.operator>
