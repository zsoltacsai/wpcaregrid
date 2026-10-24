<x-layouts.owner :title="'Privacy & Retention'">
    <x-operator.page-header title="Privacy & Retention" lead="Owner-approved technical defaults and read-only retention eligibility. Legal and accounting approvals remain separate." />

    <div class="mb-5 rounded-md border border-success/30 bg-success-soft p-4 text-sm text-success-ink">
        <strong>Retention Engine: REPORT ONLY</strong>
        <div class="mt-1">Destructive retention: <strong>DISABLED BY POLICY</strong> · Actually deleted: <strong>0</strong> · Coverage is partial where sources are unsupported.</div>
    </div>

    @if (session('status'))<p role="status" class="mb-4 rounded-md border border-success/30 bg-success-soft p-3 text-sm text-success-ink">{{ session('status') }}</p>@endif
    @if ($errors->any())<p role="alert" class="mb-4 rounded-md border border-danger/30 bg-danger-soft p-3 text-sm text-danger-ink">{{ $errors->first() }}</p>@endif

    <section class="mb-6 rounded-md border border-line bg-surface p-5">
        <h2 class="text-base font-semibold">Policy Center</h2>
        <p class="mt-1 text-sm text-muted">Approved technical values support reporting only. They do not approve statutory periods or authorize lifecycle actions.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['Account Closure', 'Customer Data', 'WordPress Backups', 'Platform Backups', 'Exports', 'Webhooks', 'Email', 'Logs & Audit', 'Staging / Test Data', 'Accounting Evidence', 'Provider Deletion', 'Safety', 'Policy Versions', 'Impact Preview'] as $section)
                <div class="rounded-sm border border-line bg-canvas p-3 text-sm">{{ $section }}</div>
            @endforeach
        </div>
    </section>

    <section class="mb-6 rounded-md border border-line bg-surface p-5">
        <h2 class="text-base font-semibold">Backup deletion & provider reconciliation</h2>
        <p class="mt-1 text-sm text-muted">Read-only status. Customer-site backups and platform database backups are separate recovery planes. A provider acknowledgement is not physical-erasure proof; S3 versioning, Object Lock, replicas and provider retention remain unverified.</p>
        <div class="mt-3 grid gap-4 md:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold">Customer backup object states</h3>
                @forelse ($backupDeletionStates as $state)
                    <div class="mt-1 text-sm"><code>{{ $state->state }}</code>: {{ $state->total }}</div>
                @empty
                    <p class="mt-1 text-sm text-muted">No per-object deletion requests recorded.</p>
                @endforelse
            </div>
            <div>
                <h3 class="text-sm font-semibold">Provider task states</h3>
                @forelse ($providerTaskStates as $state)
                    <div class="mt-1 text-sm"><code>{{ $state->provider }} / {{ $state->status }}</code>: {{ $state->total }}</div>
                @empty
                    <p class="mt-1 text-sm text-muted">No provider reconciliation tasks recorded.</p>
                @endforelse
            </div>
        </div>
        <div class="mt-4 rounded-sm border border-warning/30 bg-warning-soft p-3 text-sm">
            <strong>Privacy restore safety: {{ strtoupper($restoreSafety['state']) }}</strong>
            <div>Reason: <code>{{ $restoreSafety['reason'] }}</code>. Independent encrypted journal integrity: {{ strtoupper($restoreSafety['journal_state']) }} · verified events: {{ $restoreSafety['verified_events'] }} · watermark: <code>{{ $restoreSafety['journal_watermark'] ?? 'empty' }}</code>.</div>
            <div>Recovery quarantine: {{ $restoreSafety['recovery_mode'] ? 'ACTIVE' : 'INACTIVE' }} · replay required: {{ $restoreSafety['replay_required'] ? 'YES' : 'NO' }} · replay verified for this recovery: {{ $restoreSafety['replay_verified'] ? 'YES' : 'NO' }} · destructive retention: OFF.</div>
        </div>
        <details class="mt-3 text-sm"><summary class="cursor-pointer">Provider facts (unknowns remain explicit)</summary>
            @foreach ($providerFacts as $provider => $facts)
                <div class="mt-2 border-t border-line pt-2"><strong>{{ $provider }}</strong> · delete: {{ $facts['deletion_capability_status'] }} · verification: {{ $facts['deletion_verification_status'] }} · version/lock: {{ $facts['version_retention_status'] }} · region: {{ $facts['region_status'] }} · contract: {{ $facts['contract_status'] }}</div>
            @endforeach
        </details>
    </section>

    <section class="mb-6 rounded-md border border-line bg-surface p-5">
        <h2 class="text-base font-semibold">Account closure & erasure cases</h2>
        <p class="mt-1 text-sm text-muted">Closure and erasure are separate recorded requests. Preview is aggregate-only. No account, subscription, website, backup, provider, or billing state is changed.</p>
        <a class="mt-3 inline-block rounded-sm border border-line-strong px-3 py-2 text-sm" href="{{ route('owner.privacy-requests.closure') }}">Open closure impact preview</a>
        <p class="mt-2 text-xs text-warning-ink">Erasure is not started by account closure. Legal/accounting/security approval is pending; destructive retention is disabled.</p>
    </section>

    <section class="overflow-hidden rounded-md border border-line bg-surface">
        <div class="border-b border-line p-5"><h2 class="text-base font-semibold">RET-01 through RET-33</h2><p class="mt-1 text-sm text-muted">Versions are immutable after activation for reporting. Edits create a draft; draft impact must be previewed before activation.</p></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1050px] text-left text-sm">
                <thead class="bg-canvas text-xs text-muted"><tr><th class="p-3">Policy</th><th class="p-3">Technical value</th><th class="p-3">Version / effective UTC</th><th class="p-3">Review states</th><th class="p-3">Approval / lock</th><th class="p-3">Actions</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($definitions as $key => $definition)
                        @php $policy = $policies->get($key); $value = $policy?->technical_value ?? $definition['value']; $duration = is_array($value) ? ($value['value'] ?? '') : ''; $unit = is_array($value) ? ($value['unit'] ?? '') : ''; @endphp
                        <tr>
                            <td class="p-3 align-top"><strong>{{ $key }}</strong><div class="text-xs text-muted">{{ $definition['category'] }} · {{ $definition['trigger'] }}</div></td>
                            <td class="p-3 align-top">{{ is_array($value) ? ($value['value'].' '.$value['unit']) : (is_bool($value) ? ($value ? 'ON' : 'OFF') : (is_scalar($value) ? (string) $value : 'Not set')) }}</td>
                            <td class="p-3 align-top">v{{ $policy?->version ?? '—' }}<div class="text-xs text-muted">{{ app(\App\Support\DisplayDateTime::class)->format($policy?->effective_at, 'Y-m-d H:i:s T') ?? 'No version' }}</div>
                                <div class="mt-1 text-xs text-muted">Changed by user #{{ $policy?->last_changed_by ?? 'migration' }} · {{ app(\App\Support\DisplayDateTime::class)->format($policy?->updated_at, 'Y-m-d H:i:s T') ?? '—' }}</div>
                                <details class="mt-2 text-xs"><summary class="cursor-pointer">Version history ({{ $versions->get($key, collect())->count() }})</summary>
                                    @foreach ($versions->get($key, collect()) as $version)
                                        <div class="mt-1">v{{ $version->version }} · {{ $version->approval_status?->value }} · @displayDateTime($version->effective_at, 'Y-m-d H:i:s T')</div>
                                    @endforeach
                                </details>
                            </td>
                            <td class="p-3 align-top text-xs">Owner: {{ strtoupper($policy?->owner_approval_status ?? 'OWNER_APPROVED') }}<br>Legal: {{ strtoupper($policy?->legal_review_status ?? ($definition['legal'] ? 'WAIT_LEGAL' : 'NOT_REQUIRED')) }}<br>Accounting: {{ strtoupper($policy?->accounting_review_status ?? ($definition['accounting'] ? 'WAIT_ACCOUNTING' : 'NOT_REQUIRED')) }}<br>Security: {{ strtoupper($policy?->security_review_status ?? ($definition['security'] ? 'WAIT_SECURITY' : 'NOT_REQUIRED')) }}
                                <div class="mt-1 text-muted">Owner decision: {{ $policy?->owner_decision_reference ?? '—' }}<br>Legal: {{ $policy?->legal_review_reference ?? '—' }} · Accounting: {{ $policy?->accounting_review_reference ?? '—' }} · Security: {{ $policy?->security_review_reference ?? '—' }}</div>
                            </td>
                            <td class="p-3 align-top">{{ $definition['compliance_lock'] ? 'COMPLIANCE / SAFETY LOCKED' : 'Owner-configurable draft' }}<div class="text-xs text-muted">Report-only; no deletion</div></td>
                            <td class="p-3 align-top">
                                @if ($definition['configurable'] && ! $definition['compliance_lock'])
                                    <form method="POST" action="{{ route('owner.privacy-retention.draft', $key) }}" class="flex items-center gap-2">
                                        @csrf
                                        <input type="number" min="0" max="36500" name="value[value]" value="{{ $duration }}" required aria-label="{{ $key }} duration" class="h-9 w-20 rounded-sm border border-line-strong px-2">
                                        <input type="hidden" name="value[unit]" value="{{ $unit ?: ($definition['unit'] ?? 'days') }}">
                                        <button class="rounded-sm bg-primary px-3 py-2 text-xs font-semibold text-white">Create draft</button>
                                    </form>
                                @else
                                    <span class="text-xs text-muted">Locked / non-editable</span>
                                @endif
                                @if ($policy && ($policy->approval_status?->value === 'draft' || ! $policy->enabled))
                                    <form method="POST" action="{{ route('owner.privacy-retention.preview', $policy) }}" class="mt-2">@csrf<button class="rounded-sm border border-line-strong px-3 py-2 text-xs">Generate impact preview</button></form>
                                    @php $latestPreview = $previews->firstWhere('policy_id', $policy->id); @endphp
                                    @if ($latestPreview && ! $latestPreview->activated_at)
                                        <form method="POST" action="{{ route('owner.privacy-retention.activate', $policy) }}" class="mt-2">@csrf<input type="hidden" name="preview_uuid" value="{{ $latestPreview->uuid }}"><button class="rounded-sm border border-warning/50 px-3 py-2 text-xs">Activate for reporting only</button></form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 rounded-md border border-line bg-surface p-5">
        <h2 class="text-base font-semibold">Recent Impact Previews</h2>
        <p class="mt-1 text-sm text-muted">Counts only; no names, email addresses, site URLs, raw payloads, or accounting content.</p>
        @forelse ($previews as $preview)
            <details class="mt-3 rounded-sm border border-line p-3">
                <summary class="cursor-pointer text-sm font-medium">{{ $preview->policy?->policy_key }} v{{ $preview->policy_version }} · @displayDateTime($preview->evaluated_at, 'Y-m-d H:i:s T') · {{ $preview->coverage_state }} · actually deleted 0</summary>
                <pre class="mt-3 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($preview->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @empty
            <p class="mt-3 text-sm text-muted">No previews recorded.</p>
        @endforelse
    </section>
</x-layouts.owner>
