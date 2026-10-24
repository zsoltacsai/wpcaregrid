<x-layouts.operator :title="__('m10.site_incidents')">
    <x-operator.page-header :title="__('m10.site_incidents')" :lead="$website->host()">
        <x-slot:eyebrow><a href="{{ route('operator.websites.show', $website) }}" class="underline">{{ __('operator.detail.back') }}</a> · <span class="font-mono">{{ $website->uuid }}</span></x-slot:eyebrow>
    </x-operator.page-header>
    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <x-operator.section :title="__('m10.current_site_signals')">
            @forelse ($results as $result)
                <article class="flex justify-between gap-4 border-b border-line py-3 last:border-0">
                    <div><h3 class="text-sm font-semibold">{{ $result->check_id }}</h3><p class="text-xs text-muted">{{ \App\Monitoring\MonitoringReason::label($result->displayReasonCode()) }}</p><p class="text-xs text-muted">@displayDateTime($result->observed_at, 'Y-m-d H:i:s T') · {{ __('m10.fresh_until') }} {{ app(\App\Support\DisplayDateTime::class)->format($result->fresh_until, 'Y-m-d H:i:s T') }}</p></div>
                    <x-ui.status-badge :tone="$result->displayState()->tone()">{{ __('site_health.states.'.$result->displayState()->value) }}</x-ui.status-badge>
                </article>
            @empty <p class="text-sm text-muted">{{ __('m10.no_observations') }}</p> @endforelse
        </x-operator.section>
        <x-operator.section :title="__('m10.incident_history')">
            @forelse ($incidents as $incident)
                <article class="border-b border-line py-3 last:border-0">
                    <div class="flex gap-2"><x-ui.status-badge :tone="$incident->status === 'open' ? 'danger' : 'positive'">{{ __('m10.status.'.$incident->status) }}</x-ui.status-badge><strong class="text-sm">{{ \App\Monitoring\MonitoringReason::label($incident->reason_code) }}</strong></div>
                    <p class="mt-1 text-xs text-muted">@displayDateTime($incident->opened_at, 'Y-m-d H:i:s T') · {{ __('m10.last_seen') }} @displayDateTime($incident->last_observed_at, 'Y-m-d H:i:s T')</p>
                    @if ($incident->evidence)<dl class="mt-2 grid grid-cols-[minmax(0,1fr)_auto] text-xs">@foreach ($incident->evidence as $key => $value)<dt class="text-muted">{{ $key }}</dt><dd class="text-right font-mono">{{ is_scalar($value) ? $value : '—' }}</dd>@endforeach</dl>@endif
                </article>
            @empty <p class="text-sm text-muted">{{ __('m10.no_incidents') }}</p> @endforelse
            <div class="mt-3">{{ $incidents->links() }}</div>
        </x-operator.section>
    </div>
</x-layouts.operator>
