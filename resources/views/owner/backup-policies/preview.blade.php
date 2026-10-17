<x-layouts.owner title="Backup policy impact preview">
    <x-operator.page-header title="Backup policy impact preview" lead="Review the exact proposed policy and source snapshot. Confirmation changes protection metadata only." />
    @if (session('status'))
        <div class="mb-4 rounded-md border border-line bg-surface p-4 text-sm">{{ session('status') }}</div>
    @endif
    <section class="rounded-md border border-line bg-surface p-5">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt class="text-xs text-muted">Subscription</dt><dd class="mt-1 font-mono">{{ $preview->subscription->uuid }}</dd></div>
            <div><dt class="text-xs text-muted">State / expiry</dt><dd class="mt-1">{{ strtoupper($preview->state) }} · {{ $preview->expires_at->utc()->format('Y-m-d H:i:s') }} UTC</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs text-muted">Preview hash</dt><dd class="mt-1 break-all font-mono">{{ $preview->preview_hash }}</dd></div>
        </dl>
    </section>
    <section class="mt-5 rounded-md border border-line bg-surface p-5">
        <h2 class="font-semibold">Impact</h2>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-xs text-muted">Purchased floor</dt><dd>{{ json_encode($preview->impact['base_entitlement'] ?? []) }}</dd></div>
            <div><dt class="text-xs text-muted">Current effective policy</dt><dd>{{ json_encode($preview->impact['current_effective_policy'] ?? []) }}</dd></div>
            <div><dt class="text-xs text-muted">Proposed effective policy</dt><dd>{{ json_encode($preview->impact['proposed_effective_policy'] ?? []) }}</dd></div>
            <div><dt class="text-xs text-muted">Desired post-service duration</dt><dd>{{ $preview->proposed_values['desired_post_service_days'] }} days · preference only, not legal approval</dd></div>
        </dl>
        <p class="mt-2 text-sm">Protected points: {{ count($preview->impact['points_still_protected_for_other_reasons'] ?? []) }} remain protected by other reasons. {{ count($preview->impact['points_losing_only_owner_extension'] ?? []) }} lose only the Owner-extension reason. {{ count($preview->impact['would_become_future_candidates'] ?? []) }} may become candidates for future cleanup.</p>
        <p class="mt-2 text-sm">These are future eligibility descriptions only. No backup will be deleted or queued by confirmation.</p>
        <p class="mt-2 text-sm font-medium">Cleanup remains blocked: WAIT_LEGAL · WAIT_ACCOUNTING · WAIT_FACT.</p>
    </section>
    @if ($preview->state === 'pending' && $preview->expires_at->isFuture())
        <form method="POST" action="{{ route('owner.backup-policies.confirm', $preview) }}" class="mt-5 rounded-md border border-line bg-surface p-5">
            @csrf
            <input type="hidden" name="preview_hash" value="{{ $preview->preview_hash }}">
            <label class="block text-sm">Confirm with current Owner password
                <input class="mt-1 w-full max-w-md rounded border border-line bg-surface px-3 py-2" type="password" name="current_password" autocomplete="current-password" required>
            </label>
            <button class="mt-4 rounded bg-ink px-4 py-2 text-sm font-medium text-white" type="submit">Save non-destructive policy</button>
        </form>
    @endif
</x-layouts.owner>
