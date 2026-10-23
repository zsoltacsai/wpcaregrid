@props(['website', 'snapshot' => null, 'operator' => false, 'requestRoute'])

@php
    $stale = ! $snapshot || ! $snapshot->isFresh() || ! $snapshot->matchesCurrent($website, $website->activeConnector);
    $capabilities = \App\Compatibility\Capability::cases();
    $reasonCodes = \App\Compatibility\ReasonCode::cases();
    $knownReasons = array_column(array_map(fn ($reason) => ['key' => $reason->value], $reasonCodes), 'key');
@endphp

<x-account.panel :title="__('compatibility.title')" heading-id="compatibility-title" {{ $attributes }}>
    <p class="text-sm text-muted">{{ __('compatibility.lead') }}</p>

    @if (session('compatibility_error'))
        <p role="alert" class="mt-3 rounded-sm border border-danger/30 bg-danger/5 p-3 text-sm text-danger-ink">{{ session('compatibility_error') }}</p>
    @endif

    <dl class="mt-4 grid gap-x-5 gap-y-3 text-sm sm:grid-cols-2">
        @foreach ($capabilities as $capability)
            @php
                $result = data_get($snapshot?->capabilities, $capability->value, []);
                $status = $stale ? \App\Compatibility\CapabilityStatus::Unknown : $snapshot->status($capability);
                $tone = match ($status) {
                    \App\Compatibility\CapabilityStatus::Pass => 'positive',
                    \App\Compatibility\CapabilityStatus::Warn => 'warning',
                    \App\Compatibility\CapabilityStatus::Block => 'danger',
                    default => 'neutral',
                };
                $reason = $stale ? 'FACT_STALE' : (data_get($result, 'reasons.0') ?? null);
                // A passing capability often has no reason code: do not describe it as needing a check.
                // Unknown/malformed reason codes and non-PASS states remain conservative.
                $reasonKey = in_array($reason, $knownReasons, true)
                    ? $reason
                    : ($status === \App\Compatibility\CapabilityStatus::Pass && $reason === null ? 'PASS_NO_ISSUES' : 'CHECK_REQUIRED');
            @endphp
            <div class="min-w-0 border-b border-line py-2">
                <dt class="font-medium text-primary">{{ __('compatibility.capabilities.'.$capability->value) }}</dt>
                <dd class="mt-1 flex items-center justify-between gap-3">
                    <span class="text-xs text-muted">{{ __('compatibility.reasons.'.$reasonKey) }}</span>
                    <x-ui.status-badge :tone="$tone">{{ __('compatibility.status.'.$status->value) }}</x-ui.status-badge>
                </dd>
            </div>
        @endforeach
    </dl>

    <p class="mt-3 text-xs text-muted">
        {{ __('compatibility.last_checked') }}:
        @if ($snapshot?->checked_at)<span class="font-mono">{{ $snapshot->checked_at->translatedFormat('j M Y H:i') }}</span>@else{{ __('compatibility.not_checked') }}@endif
    </p>

    @if ($operator && $snapshot)
        <details class="mt-4 rounded-sm border border-line p-3 text-xs">
            <summary class="cursor-pointer font-medium text-primary">{{ __('compatibility.operator_details') }}</summary>
            <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                <div><dt class="text-muted">{{ __('compatibility.connector_version') }}</dt><dd class="font-mono">{{ $snapshot->connector_version ?? '—' }}</dd></div>
                <div><dt class="text-muted">{{ __('compatibility.wordpress_version') }}</dt><dd class="font-mono">{{ $snapshot->wordpress_version ?? '—' }}</dd></div>
                <div><dt class="text-muted">{{ __('compatibility.php_version') }}</dt><dd class="font-mono">{{ $snapshot->php_version ?? '—' }}</dd></div>
                <div><dt class="text-muted">{{ __('compatibility.policy_version') }}</dt><dd class="font-mono">{{ $snapshot->policy_version }} · {{ $snapshot->evaluator_version }}</dd></div>
                <div><dt class="text-muted">{{ __('compatibility.layout') }}</dt><dd class="font-mono">{{ data_get($snapshot->facts, 'layout', 'unknown') }}</dd></div>
                <div><dt class="text-muted">{{ __('compatibility.disk_free') }}</dt><dd class="font-mono">{{ data_get($snapshot->facts, 'disk_free_bytes') !== null ? \Illuminate\Support\Number::fileSize((int) data_get($snapshot->facts, 'disk_free_bytes')) : '—' }}</dd></div>
                @foreach ($capabilities as $capability)
                    @foreach (data_get($snapshot->capabilities, $capability->value.'.reasons', []) as $reason)
                        <div><dt class="text-muted">{{ __('compatibility.capabilities.'.$capability->value) }}</dt><dd class="font-mono">{{ $reason }}</dd></div>
                    @endforeach
                @endforeach
            </dl>
        </details>
    @endif

    @if ($website->activeConnector)
        <form method="POST" action="{{ $requestRoute }}" class="mt-4">
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm">{{ __('compatibility.request_check') }}</x-ui.button>
        </form>
    @endif
</x-account.panel>
