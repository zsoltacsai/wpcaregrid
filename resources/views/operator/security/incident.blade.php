{{--
    One security incident: workflow, findings, timeline, and what happened
    around it (backups, admin access, scans, remediations), each read from
    its own table.
--}}
@use('App\Security\IncidentStatus')

<x-layouts.operator :title="$incident->title">
    @php $select = 'h-9 rounded-sm border border-line-strong bg-surface px-2 text-sm text-ink'; @endphp

    <x-operator.page-header :title="$incident->title" :lead="$incident->summary">
        <x-slot:eyebrow>
            <a href="{{ route('operator.websites.security', $website) }}" class="underline underline-offset-2 hover:text-primary">{{ $website->host() }}</a>
            · <span class="font-mono">{{ $incident->uuid }}</span>
        </x-slot:eyebrow>
        <x-slot:actions>
            @if ($incident->severity)<x-ui.status-badge :tone="$incident->severity->tone()">{{ $incident->severity->label() }}</x-ui.status-badge>@endif
            <x-ui.status-badge :tone="$incident->status->tone()">{{ $incident->status->label() }}</x-ui.status-badge>
        </x-slot:actions>
    </x-operator.page-header>

    <ol class="mb-5 flex flex-wrap gap-1 text-xs" aria-label="{{ __('operator.security.workflow') }}">
        @foreach (IncidentStatus::cases() as $step)
            <li @class(['rounded-sm border px-2 py-1', 'border-primary bg-primary text-white' => $step === $incident->status, 'border-line bg-surface text-muted' => $step !== $incident->status])>{{ $step->label() }}</li>
        @endforeach
    </ol>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="grid content-start gap-5">
            <x-operator.section :title="__('operator.security.findings_title')">
                @if ($incident->findings->isEmpty())
                    <p class="text-sm text-muted">{{ __('operator.security.no_findings') }}</p>
                @else
                    <ul class="-my-2 divide-y divide-line text-sm">
                        @foreach ($incident->findings as $finding)
                            <li class="flex flex-wrap items-start justify-between gap-2 py-2">
                                <a href="{{ route('operator.websites.security', [$website, 'status' => $finding->status->value]) }}#finding-{{ $finding->uuid }}" class="min-w-0 text-primary hover:underline">{{ $finding->title }}
                                    <span class="block font-mono text-[11px] break-all text-muted">{{ $finding->path ?? $finding->component }}</span></a>
                                <span class="flex gap-1">
                                    @if ($finding->severity)<x-ui.status-badge :tone="$finding->severity->tone()">{{ $finding->severity->label() }}</x-ui.status-badge>@endif
                                    <x-ui.status-badge :tone="$finding->status->tone()">{{ $finding->status->label() }}</x-ui.status-badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-operator.section>

            <x-operator.section :title="__('operator.security.timeline')">
                <ol class="-my-2 divide-y divide-line text-sm">
                    @foreach ($incident->events->sortByDesc('id') as $event)
                        <li class="py-2">
                            <span class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-medium text-primary">{{ __('operator.security.incident_events.'.$event->type) }}</span>
                                <span class="font-mono text-[11px] text-muted">@displayDateTime($event->created_at) · {{ __('operator.actors.'.($event->actor_type ?? 'system')) }}@if ($event->actorUser) {{ $event->actorUser->email }}@endif</span>
                            </span>
                            @if ($event->data)<span class="block font-mono text-[11px] break-all text-muted">{{ collect($event->data)->map(fn ($v, $k) => $k.'='.(is_bool($v) ? ($v ? 'yes' : 'no') : $v))->implode(' · ') }}</span>@endif
                            @if ($event->note)<p class="mt-1 text-sm whitespace-pre-line text-ink">{{ $event->note }}</p>@endif
                        </li>
                    @endforeach
                </ol>
                @can('operator.security.manage')
                    <form method="POST" action="{{ route('operator.websites.security.incidents.notes', [$website, $incident]) }}" class="mt-4 grid gap-2 border-t border-line pt-4">
                        @csrf
                        <label for="note" class="text-xs text-muted">{{ __('operator.security.add_note') }}</label>
                        <textarea id="note" name="note" rows="3" maxlength="2000" required class="rounded-sm border border-line-strong bg-surface p-2 text-sm"></textarea>
                        <div><x-ui.button type="submit" size="sm" variant="secondary">{{ __('operator.security.add_note') }}</x-ui.button></div>
                    </form>
                @endcan
            </x-operator.section>
        </div>

        <div class="grid content-start gap-5">
            @can('operator.security.manage')
                @if ($transitions)
                    <x-operator.section :title="__('operator.security.change_status')">
                        <form method="POST" action="{{ route('operator.websites.security.incidents.status', [$website, $incident]) }}" class="grid gap-3">
                            @csrf
                            <label for="status" class="sr-only">{{ __('operator.security.change_status') }}</label>
                            <select id="status" name="status" class="{{ $select }}">
                                @foreach ($transitions as $next)<option value="{{ $next->value }}">{{ $next->label() }}</option>@endforeach
                            </select>
                            <label class="flex items-start gap-2 text-xs text-ink"><input type="checkbox" name="functional_check" value="1" class="mt-0.5 size-4 accent-[var(--color-action)]">{{ __('operator.security.functional_check') }}</label>
                            <textarea name="note" rows="2" maxlength="2000" placeholder="{{ __('operator.security.note_placeholder') }}" class="rounded-sm border border-line-strong bg-surface p-2 text-sm" aria-label="{{ __('operator.security.note_placeholder') }}"></textarea>
                            <div><x-ui.button type="submit" size="sm">{{ __('operator.security.change_status') }}</x-ui.button></div>
                            <p class="text-[11px] text-muted">{{ __('operator.security.resolve_rules') }}</p>
                        </form>
                    </x-operator.section>
                @endif
            @endcan

            <x-operator.section :title="__('operator.security.investigation')">
                <div class="flex flex-wrap gap-2">
                    @can('operator.backups.run')
                        <form method="POST" action="{{ route('operator.websites.security.incidents.backup', [$website, $incident]) }}">@csrf<x-ui.button type="submit" size="sm" variant="secondary">{{ __('operator.security.full_backup') }}</x-ui.button></form>
                    @endcan
                    @can('operator.security.scan')
                        <form method="POST" action="{{ route('operator.websites.security.scans.store', $website) }}">@csrf<input type="hidden" name="type" value="full"><x-ui.button type="submit" size="sm" variant="secondary">{{ __('operator.security.rescan') }}</x-ui.button></form>
                    @endcan
                    @can('operator.admin_access.request')
                        <form method="POST" action="{{ route('operator.websites.security.incidents.admin-access', [$website, $incident]) }}" target="_blank">@csrf<x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-right">{{ __('operator.admin_access.open') }}</x-ui.button></form>
                    @endcan
                </div>
                <p class="mt-2 text-[11px] text-muted">{{ __('operator.security.investigation_help') }}</p>

                <h3 class="mt-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('operator.backups.title') }}</h3>
                <ul class="mt-1 text-xs">
                    @forelse ($backups as $backup)
                        <li class="flex justify-between gap-2 py-1"><span>{{ $backup->reason->label() }} · <span class="font-mono">@displayDateTime($backup->created_at)</span></span><x-ui.status-badge :tone="$backup->status->tone()" variant="code">{{ $backup->status->label() }}</x-ui.status-badge></li>
                    @empty
                        <li class="text-muted">{{ __('operator.backups.none') }}</li>
                    @endforelse
                </ul>

                <h3 class="mt-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('operator.admin_access.title') }}</h3>
                <ul class="mt-1 text-xs">
                    @forelse ($accessRequests as $access)
                        <li class="flex justify-between gap-2 py-1"><span>{{ $access->access_level->label() }} · {{ __('operator.actors.'.$access->actor_type->value) }}@if ($access->requestedBy) {{ $access->requestedBy->email }}@endif</span><span class="font-mono">{{ $access->status->value }}</span></li>
                    @empty
                        <li class="text-muted">{{ __('operator.admin_access.none') }}</li>
                    @endforelse
                </ul>

                <h3 class="mt-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('operator.security.remediations_title') }}</h3>
                <ul class="mt-1 text-xs">
                    @forelse ($incident->remediations as $remediation)
                        <li class="flex justify-between gap-2 py-1"><span>{{ $remediation->type->label() }}@if ($remediation->backup) · backup {{ $remediation->backup->status->label() }}@endif</span><x-ui.status-badge :tone="$remediation->status->tone()">{{ $remediation->status->label() }}</x-ui.status-badge></li>
                    @empty
                        <li class="text-muted">{{ __('operator.security.no_remediations') }}</li>
                    @endforelse
                </ul>
            </x-operator.section>
        </div>
    </div>
</x-layouts.operator>
