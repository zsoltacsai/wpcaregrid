<x-layouts.owner title="Commercial backup protection">
    <x-operator.page-header title="Commercial backup protection" lead="Versioned plan floors and non-destructive Owner extensions. This is operational policy, not public pricing or a deletion control." />

    @if (session('status'))
        <div class="mb-4 rounded-md border border-line bg-surface p-4 text-sm">{{ session('status') }}</div>
    @endif
    <div class="mb-5 rounded-md border border-warning/40 bg-warning-soft p-4 text-sm text-warning-ink">
        WAIT_LEGAL · WAIT_ACCOUNTING · WAIT_FACT remain unresolved. Backup cleanup is not authorized here. The desired post-service duration is not a legal retention decision.
    </div>

    <section class="mb-6 rounded-md border border-line bg-surface p-5">
        <h2 class="font-semibold">Engineering catalogue · {{ config('pricing.backup_entitlements.version') }}</h2>
        <p class="mt-1 text-sm text-muted">Target policies implemented by the backend. Publication as a customer commercial promise is controlled separately.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead><tr class="border-b border-line text-xs text-muted"><th class="p-2">Plan</th><th class="p-2">Cadence</th><th class="p-2">Daily</th><th class="p-2">Weekly</th><th class="p-2">Monthly</th><th class="p-2">Safety</th></tr></thead>
                <tbody>
                    @foreach ($targetPolicies as $target)
                        <tr class="border-b border-line/60"><td class="p-2">{{ $target->productType->value }}.{{ $target->tier->value }}</td><td class="p-2">{{ $target->cadence }}</td><td class="p-2">{{ $target->daily }}</td><td class="p-2">{{ $target->weekly }}</td><td class="p-2">{{ $target->monthly }}</td><td class="p-2">Separate, required when applicable</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-md border border-line bg-surface p-5">
        <h2 class="font-semibold">Site subscription policy</h2>
        <div class="mt-4 space-y-5">
            @foreach ($subscriptions as $subscription)
                @php
                    $entitlement = $subscription->backupEntitlements->where('resolution', 'known')->where('effective_at', '<=', now('UTC'))->sortByDesc('effective_at')->first();
                    $baseDaily = (int) ($entitlement?->daily_recovery_points ?? 0);
                    $baseWeekly = (int) ($entitlement?->weekly_recovery_points ?? 0);
                    $baseMonthly = (int) ($entitlement?->monthly_recovery_points ?? 0);
                    $ownerPolicy = $subscription->backupOwnerPolicy;
                @endphp
                <article class="rounded-md border border-line p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 class="font-medium">{{ $subscription->product_type?->value ?? 'unknown' }}.{{ $subscription->plan?->value ?? 'unknown' }} · {{ $subscription->customer?->name ?? 'Customer' }}</h3>
                            <p class="mt-1 text-xs text-muted">Site {{ $subscription->website?->uuid }} · entitlement {{ $entitlement?->catalogue_version ?? 'UNKNOWN / REVIEW_REQUIRED' }} · Owner policy v{{ $ownerPolicy?->version ?? 0 }}</p>
                        </div>
                        <span class="text-xs">{{ $entitlement ? 'Known plan floor' : 'Protected: entitlement mapping unknown' }}</span>
                    </div>
                    @if ($entitlement)
                        <form method="POST" action="{{ route('owner.backup-policies.preview', $subscription) }}" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            @csrf
                            <label class="text-sm">Daily points
                                <input class="mt-1 w-full rounded border border-line bg-surface px-3 py-2" type="number" name="daily_recovery_points" min="{{ $baseDaily }}" max="365" value="{{ max($baseDaily, (int) ($ownerPolicy?->daily_recovery_points ?? $baseDaily)) }}" required>
                            </label>
                            <label class="text-sm">Weekly points
                                <input class="mt-1 w-full rounded border border-line bg-surface px-3 py-2" type="number" name="weekly_recovery_points" min="{{ $baseWeekly }}" max="520" value="{{ max($baseWeekly, (int) ($ownerPolicy?->weekly_recovery_points ?? $baseWeekly)) }}" required>
                            </label>
                            <label class="text-sm">Monthly points
                                <input class="mt-1 w-full rounded border border-line bg-surface px-3 py-2" type="number" name="monthly_recovery_points" min="{{ $baseMonthly }}" max="240" value="{{ max($baseMonthly, (int) ($ownerPolicy?->monthly_recovery_points ?? $baseMonthly)) }}" required>
                            </label>
                            <label class="text-sm">Desired post-service days
                                <input class="mt-1 w-full rounded border border-line bg-surface px-3 py-2" type="number" name="desired_post_service_days" min="0" max="3650" value="{{ $ownerPolicy?->desired_post_service_days ?? 30 }}" required>
                            </label>
                            <div class="flex items-end"><button class="w-full rounded bg-ink px-3 py-2 text-sm font-medium text-white" type="submit">Preview change</button></div>
                        </form>
                    @endif
                </article>
            @endforeach
        </div>
        <div class="mt-5">{{ $subscriptions->links() }}</div>
    </section>
</x-layouts.owner>
