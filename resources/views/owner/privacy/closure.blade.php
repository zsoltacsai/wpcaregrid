<x-layouts.owner :title="'Account closure & erasure'">
    <x-operator.page-header title="Account closure & erasure" lead="Record a request or inspect a read-only impact preview. This page has no execution path." />

    @if (session('status'))<p role="status" class="mb-4 rounded-md border border-success/30 bg-success-soft p-3 text-sm text-success-ink">{{ session('status') }}</p>@endif
    @if ($errors->any())<p role="alert" class="mb-4 rounded-md border border-danger/30 bg-danger-soft p-3 text-sm text-danger-ink">{{ $errors->first() }}</p>@endif

    <div class="mb-5 rounded-md border border-success/30 bg-success-soft p-4 text-sm text-success-ink">
        <strong>Report only · Destructive retention disabled</strong>
        <div class="mt-1">Account closure does not automatically start erasure. No customer, subscription, website, backup, accounting, or provider data is changed.</div>
    </div>

    <section class="mb-5 rounded-md border border-line bg-surface p-5">
        <h2 class="font-semibold">Preview impact</h2>
        <p class="mt-1 text-sm text-muted">Use the customer's opaque UUID. Names, emails, site URLs and data values are not displayed.</p>
        <form class="mt-4 flex flex-wrap items-end gap-3" method="POST" action="{{ route('owner.privacy-requests.closure.preview') }}">
            @csrf
            <label class="text-sm">Customer UUID<input name="customer_uuid" value="{{ old('customer_uuid', $preview['customer_reference'] ?? '') }}" required class="mt-1 block w-96 max-w-full rounded-sm border border-line-strong px-3 py-2 font-mono" autocomplete="off"></label>
            <button class="rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white">Preview only</button>
        </form>
    </section>

    <section class="mb-5 rounded-md border border-line bg-surface p-5">
        <h2 class="font-semibold">Record a separate request</h2>
        <p class="mt-1 text-sm text-muted">This records case metadata and an audit event only. It does not close an account, cancel billing, revoke access, or run erasure.</p>
        <form class="mt-4 flex flex-wrap items-end gap-3" method="POST" action="{{ route('owner.privacy-requests.closure.store') }}">
            @csrf
            <label class="text-sm">Customer UUID<input name="customer_uuid" required class="mt-1 block w-96 max-w-full rounded-sm border border-line-strong px-3 py-2 font-mono" autocomplete="off"></label>
            <label class="text-sm">Request type<select name="request_type" class="mt-1 block rounded-sm border border-line-strong px-3 py-2"><option value="account_closure">Account closure</option><option value="erasure">Separate erasure request</option></select></label>
            <button class="rounded-sm border border-line-strong px-4 py-2 text-sm font-semibold">Record case</button>
        </form>
    </section>

    @isset($preview)
        <section class="rounded-md border border-line bg-surface p-5">
            <h2 class="font-semibold">Impact preview · {{ $preview['evaluated_at'] }}</h2>
            <p class="mt-1 text-xs text-muted">Customer reference: {{ $preview['customer_reference'] }} · Coverage: {{ $preview['coverage'] }} · Deleted: 0</p>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div class="rounded-sm border border-line p-4"><h3 class="font-medium">Profile plan (not executed)</h3><p class="mt-1 text-sm">{{ $preview['profile_result'] }} · Eligible at: {{ $preview['profile_eligible_at'] ?? 'not calculated' }}</p><p class="mt-1 text-xs text-warning-ink">Blockers: {{ implode(', ', $preview['profile_blockers']) }}</p></div>
                <div class="rounded-sm border border-line p-4"><h3 class="font-medium">Relations retained</h3><dl class="mt-2 space-y-1 text-sm">@foreach ($preview['retained_relations'] as $label => $count)<div class="flex justify-between"><dt>{{ str($label)->replace('_', ' ')->title() }}</dt><dd>{{ $count }}</dd></div>@endforeach</dl></div>
                <div class="rounded-sm border border-line p-4"><h3 class="font-medium">Customer backups</h3><p class="mt-1 text-sm">{{ $preview['customer_backups']['technical_candidates'] ?? 0 }} technical candidates of {{ $preview['customer_backups']['scanned'] ?? 0 }} · {{ $preview['customer_backups']['post_service_retention_days'] ?? 'unknown' }} post-service days</p><p class="mt-1 text-sm">Next technical eligibility: {{ $preview['customer_backups']['next_technical_eligibility_at'] ?? 'not calculated' }}</p><p class="mt-1 text-xs text-warning-ink">Blockers: {{ implode(', ', $preview['customer_backups']['blockers'] ?? []) ?: 'none observed' }}</p></div>
                <div class="rounded-sm border border-line p-4"><h3 class="font-medium">Protected categories</h3><p class="mt-1 text-sm">Accounting: {{ $preview['accounting_evidence']['state'] }} / {{ $preview['accounting_evidence']['approval'] }}<br>Provider: {{ $preview['provider_actions']['state'] }} / {{ $preview['provider_actions']['capability'] }}<br>Site data: {{ $preview['customer_site_data'] }}</p></div>
            </div>
            <details class="mt-4 text-sm"><summary class="cursor-pointer font-medium">Typed plan and blocker detail</summary><pre class="mt-2 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode(['anonymization_plan' => $preview['anonymization_plan'], 'profile_blockers' => $preview['profile_blockers'], 'backup' => $preview['customer_backups']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
        </section>
    @endisset
</x-layouts.owner>
