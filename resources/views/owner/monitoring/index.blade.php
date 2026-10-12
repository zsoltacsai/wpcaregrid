<x-layouts.owner :title="__('m10.title')">
    <x-operator.page-header :title="__('m10.title')" :lead="__('m10.owner_lead')" />

    @if (session('status'))<p role="status" class="mt-4 rounded-sm border border-success/30 bg-success-soft p-3 text-sm text-success-ink">{{ session('status') }}</p>@endif
    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-operator.section :title="__('m10.active_incidents')">
            @forelse ($incidents as $incident)
                <article class="border-b border-line py-3 last:border-0" data-incident="{{ $incident->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.status-badge :tone="$incident->severity->value === 'critical' ? 'danger' : 'warning'">{{ __('m10.severity.'.$incident->severity->value) }}</x-ui.status-badge>
                    <strong class="text-sm">{{ \App\Monitoring\MonitoringReason::label($incident->reason_code) }}</strong>
                        <span class="font-mono text-xs text-muted">{{ $incident->check_id }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted">{{ $incident->scope->value }} · {{ $incident->subject_type }}: {{ $incident->subject_id }} · {{ __('m10.last_seen') }} {{ $incident->last_observed_at?->utc()->format('Y-m-d H:i:s') }} UTC</p>
                    @if ($incident->evidence)<dl class="mt-2 grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 text-xs">@foreach ($incident->evidence as $key => $value)<dt class="text-muted">{{ $key }}</dt><dd class="text-right font-mono break-all">{{ is_scalar($value) ? $value : '—' }}</dd>@endforeach</dl>@endif
                </article>
            @empty
                <p class="text-sm text-muted">{{ __('m10.no_active_incidents') }}</p>
            @endforelse
            <div class="mt-3">{{ $incidents->links() }}</div>
        </x-operator.section>

        <x-operator.section :title="__('m10.platform_checks')">
            <div class="divide-y divide-line">
                @forelse ($platformResults as $result)
                    <article class="flex items-start justify-between gap-4 py-3" data-check="{{ $result->check_id }}">
                        <div><h3 class="text-sm font-semibold">{{ $result->check_id }}</h3><p class="mt-1 font-mono text-xs text-muted">{{ \App\Monitoring\MonitoringReason::label($result->displayReasonCode()) }}</p><p class="text-xs text-muted">{{ $result->observed_at?->utc()->format('Y-m-d H:i:s') }} UTC · {{ __('m10.fresh_until') }} {{ $result->fresh_until?->utc()->format('Y-m-d H:i:s') }}</p></div>
                        <x-ui.status-badge :tone="$result->displayState()->tone()">{{ __('site_health.states.'.$result->displayState()->value) }}</x-ui.status-badge>
                    </article>
                @empty <p class="py-2 text-sm text-muted">{{ __('m10.no_observations') }}</p> @endforelse
            </div>
            <div class="mt-3">{{ $platformResults->links() }}</div>
        </x-operator.section>

        <x-operator.section :title="__('m10.maintenance')">
            @foreach ($windows as $window)
                <p class="border-b border-line py-2 text-xs">{{ $window->scope->value }} · {{ $window->subject_id ?? __('m10.all_subjects') }} · {{ $window->starts_at->utc()->format('Y-m-d H:i') }}–{{ $window->ends_at->utc()->format('Y-m-d H:i') }} UTC · {{ $window->reason }}</p>
            @endforeach
            <form method="post" action="{{ route('owner.monitoring.maintenance.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                @csrf
                <label class="text-xs">{{ __('m10.scope') }}<select name="scope" class="mt-1 w-full rounded border-line"><option value="platform">platform</option><option value="website">website</option></select></label>
                <label class="text-xs">{{ __('m10.website_uuid_optional') }}<input name="website_uuid" class="mt-1 w-full rounded border-line font-mono" maxlength="36"></label>
                <label class="text-xs">{{ __('m10.check_family_optional') }}<select name="check_family" class="mt-1 w-full rounded border-line"><option value="">All checks</option>@foreach (['website','connector','backup','security','platform','provider'] as $family)<option value="{{ $family }}">{{ $family }}</option>@endforeach</select></label>
                <label class="text-xs">{{ __('m10.reason') }}<input name="reason" required minlength="8" maxlength="255" class="mt-1 w-full rounded border-line"></label>
                <label class="text-xs">{{ __('m10.starts_utc') }}<input type="datetime-local" name="starts_at" required class="mt-1 w-full rounded border-line"></label>
                <label class="text-xs">{{ __('m10.ends_utc') }}<input type="datetime-local" name="ends_at" required class="mt-1 w-full rounded border-line"></label>
                <div class="sm:col-span-2"><x-ui.button>{{ __('m10.create_maintenance') }}</x-ui.button><p class="mt-2 text-xs text-muted">{{ __('m10.maintenance_limit') }}</p></div>
            </form>
            @if ($errors->any())<ul class="mt-3 text-sm text-danger">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        </x-operator.section>

        <x-operator.section :title="__('m10.recent_transitions')">
            @forelse ($transitions as $transition)
                <p class="border-b border-line py-2 text-xs"><span class="font-mono">{{ $transition->event_type }}</span> · {{ $transition->check_id }} · {{ $transition->subject_id }} · {{ $transition->created_at?->utc()->format('Y-m-d H:i:s') }} UTC</p>
            @empty <p class="text-sm text-muted">{{ __('m10.no_observations') }}</p> @endforelse
            <div class="mt-3">{{ $transitions->links() }}</div>
        </x-operator.section>

        <x-operator.section :title="__('m10.delivery_failures')">
            @forelse ($deliveryFailures as $delivery)
                <p class="border-b border-line py-2 text-xs">{{ $delivery->channel }} · {{ $delivery->failure_code }} · {{ $delivery->updated_at?->utc()->format('Y-m-d H:i:s') }} UTC</p>
            @empty <p class="text-sm text-muted">{{ __('m10.no_delivery_failures') }}</p> @endforelse
        </x-operator.section>
    </div>
</x-layouts.owner>
