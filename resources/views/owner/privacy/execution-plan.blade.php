<x-layouts.owner title="Erasure execution plan">
    <x-operator.page-header title="Erasure execution plan" lead="Exact-preview Owner review. This plan has no real execution path." />

    @if (session('status'))
        <div class="mb-4 rounded-md border border-line bg-surface p-4 text-sm">{{ session('status') }}</div>
    @endif
    <div class="mb-4 rounded-md border border-warning/40 bg-warning-soft p-4 text-sm text-warning-ink">
        Production destructive execution is disabled. Owner approval records review only; it does not clear WAIT_LEGAL, WAIT_ACCOUNTING or WAIT_FACT and does not authorize mutation.
    </div>
    <section class="rounded-md border border-line bg-surface p-5">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt class="text-xs text-muted">Plan</dt><dd class="mt-1 font-mono">{{ $plan->uuid }} · v{{ $plan->version }}</dd></div>
            <div><dt class="text-xs text-muted">Privacy request</dt><dd class="mt-1 font-mono">{{ $privacyRequest->uuid }}</dd></div>
            <div><dt class="text-xs text-muted">Customer reference</dt><dd class="mt-1 font-mono">{{ $plan->preview['customer_reference'] }}</dd></div>
            <div><dt class="text-xs text-muted">State</dt><dd class="mt-1">{{ strtoupper($plan->state->value) }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs text-muted">Preview hash (SHA-256)</dt><dd class="mt-1 break-all font-mono">{{ $plan->preview_hash }}</dd></div>
            <div><dt class="text-xs text-muted">Owner review</dt><dd class="mt-1">{{ strtoupper($plan->owner_approval_status) }}{{ $plan->approved_at ? ' · '.$plan->approved_at->utc()->format('Y-m-d H:i:s').' UTC' : '' }}</dd></div>
            <div><dt class="text-xs text-muted">Policy binding</dt><dd class="mt-1 break-all font-mono">{{ $plan->policy_version ?? 'No active retention policy version' }}</dd></div>
        </dl>
    </section>

    <section class="mt-5 rounded-md border border-line bg-surface p-5">
        <h2 class="font-semibold">Execution safety</h2>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-xs text-muted">Circuit breaker state</dt><dd>{{ ! $circuitStatus['available'] ? 'BLOCKED · state unavailable' : ($circuitStatus['is_open'] ? 'OPEN · '.$circuitStatus['reason_code'] : 'CLOSED') }}</dd></div>
            <div><dt class="text-xs text-muted">Production destructive execution</dt><dd>DISABLED</dd></div>
            <div><dt class="text-xs text-muted">Provider destructive adapter</dt><dd>NOT AVAILABLE · WAIT_FACT</dd></div>
            <div><dt class="text-xs text-muted">Bulk execution</dt><dd>DISABLED</dd></div>
        </dl>
    </section>

    <section class="mt-5 rounded-md border border-line bg-surface p-5">
        <h2 class="text-base font-semibold">Proposed actions</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="p-2">Action</th><th class="p-2">Scope</th><th class="p-2">State</th><th class="p-2">Blockers</th></tr></thead>
                <tbody>
                    @foreach ($plan->actions as $action)
                        <tr class="border-t border-line"><td class="p-2">{{ $action->action_code }}</td><td class="p-2">{{ $action->scope_code }}</td><td class="p-2">{{ strtoupper($action->state->value) }}</td><td class="p-2">{{ implode(', ', $action->blocker_codes ?? []) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <h3 class="mt-5 text-sm font-semibold">Decision blockers</h3>
        <p class="mt-1 text-sm">{{ implode(', ', $plan->blocker_codes ?? []) }}</p>
    </section>

    <details class="mt-5 rounded-md border border-line bg-surface p-5">
        <summary class="cursor-pointer font-semibold">Aggregate preview details</summary>
        <pre class="mt-3 overflow-x-auto whitespace-pre-wrap break-words text-xs">{{ json_encode($plan->preview['impact'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </details>

    @can('owner.privacy.requests.manage')
        @if ($plan->approved_at === null && $plan->state->value !== 'invalidated' && $plan->state->value !== 'cancelled')
            <form class="mt-5 grid gap-3 rounded-md border border-line bg-surface p-5" method="POST" action="{{ route('owner.privacy-execution-plans.approve', [$privacyRequest, $plan]) }}">
                @csrf
                <h2 class="font-semibold">Review this exact preview</h2>
                <p class="text-sm text-muted">Recent re-authentication is required by entering the current Owner password. Type the full phrase exactly. Approval remains blocked from execution by unresolved external gates.</p>
                <input type="hidden" name="preview_hash" value="{{ $plan->preview_hash }}">
                <label class="text-sm">Current Owner password<input class="mt-1 block w-full rounded border border-line p-2" type="password" name="current_password" autocomplete="current-password" required></label>
                <label class="text-sm">Typed confirmation<input class="mt-1 block w-full rounded border border-line p-2 font-mono" type="text" name="typed_confirmation" autocomplete="off" required></label>
                <code class="break-all text-xs">APPROVE {{ $plan->uuid }} {{ $plan->preview_hash }}</code>
                <div><x-ui.button type="submit" size="sm" variant="secondary">Record Owner review</x-ui.button></div>
            </form>
        @endif
        @if ($plan->executed_at === null && $plan->state->value !== 'cancelled')
            <form class="mt-3" method="POST" action="{{ route('owner.privacy-execution-plans.cancel', [$privacyRequest, $plan]) }}">@csrf<x-ui.button type="submit" size="sm" variant="secondary">Cancel plan</x-ui.button></form>
        @endif
    @endcan
</x-layouts.owner>
