{{--
    Security on the customer's website page: state, last scan and what the
    open findings mean. Never rule names, file paths, hashes or evidence;
    customers cannot change findings.
--}}
@use('App\Security\FindingType')
@props(['website'])

@php
    $state = $website->security_status?->value ?? 'unknown';
    $tone = ['healthy' => 'positive', 'attention' => 'warning', 'critical' => 'danger', 'unknown' => 'neutral'][$state];
    $active = $website->securityFindings()->active()->get(['type', 'evidence', 'component']);
    $byType = $active->groupBy(fn ($finding) => $finding->type->value);
    $vulnerable = ($byType['vulnerability'] ?? collect())->map(fn ($finding) => $finding->evidence)->unique(fn ($e) => ($e['component_name'] ?? '').($e['installed_version'] ?? ''))->take(10);
    $latest = $website->securityScans()->whereIn('status', ['completed', 'partial'])->latest('id')->first();
    $vulnerabilitiesChecked = $latest && ($latest->coverage['vulnerability']['status'] ?? null) === 'completed';
@endphp

<x-account.panel :title="__('security.customer.title')" heading-id="security-title" {{ $attributes }}>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-ui.status-badge :tone="$tone">{{ __('security.states.'.$state) }}</x-ui.status-badge>
        <span class="font-mono text-xs text-muted">{{ __('security.customer.last_scan') }}: {{ app(\App\Support\DisplayDateTime::class)->format($website->security_scanned_at) ?? __('security.customer.never') }}</span>
    </div>
    <p class="mt-3 text-sm text-ink">{{ $website->security_scanned_at === null && $state === 'unknown' ? __('security.customer.not_scanned') : __('security.customer.states.'.$state) }}</p>

    @if ($active->isNotEmpty())
        <h3 class="mt-5 text-sm font-semibold">{{ trans_choice('security.customer.issues', $active->count(), ['count' => $active->count()]) }}</h3>
        <ul class="mt-2 divide-y divide-line text-sm">
            @foreach ($byType as $type => $findings)
                <li class="flex items-start justify-between gap-4 py-2">
                    <span>{{ __('security.customer.types.'.$type) }}</span>
                    <span class="font-mono text-xs text-muted tabular-nums">{{ $findings->count() }}</span>
                </li>
            @endforeach
        </ul>

        @if ($vulnerable->isNotEmpty())
            <ul class="mt-3 space-y-1 text-xs text-ink">
                @foreach ($vulnerable as $evidence)
                    <li>{{ ! empty($evidence['patched_versions'])
                        ? __('security.customer.vulnerability_item', ['name' => $evidence['component_name'] ?? '', 'version' => $evidence['installed_version'] ?? '', 'patched' => $evidence['patched_versions']])
                        : __('security.customer.vulnerability_item_unpatched', ['name' => $evidence['component_name'] ?? '', 'version' => $evidence['installed_version'] ?? '']) }}</li>
                @endforeach
            </ul>
        @endif
    @endif

    @if ($latest && ! $vulnerabilitiesChecked)
        <p class="mt-4 text-xs text-muted">{{ __('security.customer.not_configured') }}</p>
    @endif
    <p class="mt-4 flex items-start gap-2 text-xs text-muted"><x-ui.icon name="shield" class="size-4 text-accent-ink" />{{ __('security.customer.disclaimer') }}</p>
</x-account.panel>
