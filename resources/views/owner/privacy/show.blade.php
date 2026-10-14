<x-layouts.owner :title="__('privacy.owner.request_detail')">
    <x-operator.page-header :title="__('privacy.owner.request_detail')" :lead="__('privacy.owner.detail_lead')" />

    <div class="mb-4 rounded-md border border-warning/40 bg-warning-soft p-4 text-sm text-warning-ink">
        {{ __('privacy.owner.non_destructive_notice') }}
    </div>

    <section class="rounded-md border border-line bg-surface p-5">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt class="text-xs text-muted">{{ __('privacy.owner.request_id') }}</dt><dd class="mt-1 break-all font-mono">{{ $privacyRequest->uuid }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('privacy.owner.customer_reference') }}</dt><dd class="mt-1 break-all font-mono">{{ $privacyRequest->customer?->uuid ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('privacy.owner.type') }}</dt><dd class="mt-1">{{ __('privacy.types.'.$privacyRequest->request_type->value) }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('privacy.owner.status') }}</dt><dd class="mt-1">{{ __('privacy.request_statuses.'.$privacyRequest->status->value) }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('privacy.owner.requested_at') }}</dt><dd class="mt-1">{{ $privacyRequest->requested_at ? $privacyRequest->requested_at->utc()->format('Y-m-d H:i:s').' UTC' : '—' }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('privacy.owner.reviewed_at') }}</dt><dd class="mt-1">{{ $privacyRequest->reviewed_at ? $privacyRequest->reviewed_at->utc()->format('Y-m-d H:i:s').' UTC' : '—' }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('privacy.owner.retention_gate') }}</dt><dd class="mt-1">{{ __('privacy.gate_states.'.app(\App\Privacy\DestructiveRetentionGate::class)->safeStatus()) }}</dd></div>
        </dl>
    </section>

    @if ($privacyRequest->request_type === \App\Privacy\PrivacyRequestType::AccountClosure)
        <section class="mt-5 rounded-md border border-line bg-surface p-5">
            <h2 class="font-semibold">{{ __('privacy.owner.account_access') }}</h2>
            <p class="mt-2 text-sm text-muted">{{ __('privacy.owner.account_access_notice') }}</p>
            @can('owner.privacy.requests.manage')
                @if (in_array($privacyRequest->status, [\App\Privacy\PrivacyRequestStatus::Requested, \App\Privacy\PrivacyRequestStatus::ManualReview], true))
                    @if ($privacyRequest->customer?->activeSubscriptions()->exists())
                        <p class="mt-3 text-sm text-warning-ink">{{ __('privacy.owner.active_subscription_blocks_closure') }}</p>
                    @else
                        <form class="mt-3" method="POST" action="{{ route('owner.privacy-requests.restrict-account-access', $privacyRequest) }}">
                            @csrf
                            <button class="rounded-sm bg-surface-inverse px-4 py-2 text-sm font-semibold text-white">{{ __('privacy.owner.restrict_account_access') }}</button>
                        </form>
                    @endif
                @elseif ($privacyRequest->status === \App\Privacy\PrivacyRequestStatus::AccessRestricted)
                    <form class="mt-3" method="POST" action="{{ route('owner.privacy-requests.restore-account-access', $privacyRequest) }}">
                        @csrf
                        <button class="rounded-sm border border-line-strong px-4 py-2 text-sm">{{ __('privacy.owner.restore_account_access') }}</button>
                    </form>
                @endif
            @endcan
        </section>
    @endif

    <section class="mt-5 rounded-md border border-line bg-surface p-5">
        @can('owner.privacy.requests.manage')
            @php
                $nextStatuses = array_filter(\App\Privacy\PrivacyRequestStatus::cases(), fn ($status) => $privacyRequest->status->canTransitionTo($status)
                    && ! in_array($status, [\App\Privacy\PrivacyRequestStatus::Verified, \App\Privacy\PrivacyRequestStatus::Failed, \App\Privacy\PrivacyRequestStatus::Completed], true)
                    && ! ($privacyRequest->request_type === \App\Privacy\PrivacyRequestType::AccountClosure && in_array($status, [\App\Privacy\PrivacyRequestStatus::CoolingOff, \App\Privacy\PrivacyRequestStatus::RetentionAnalysis], true)));
            @endphp
            @if (count($nextStatuses))
                <form class="mb-5 flex flex-wrap items-end gap-3 border-b border-line pb-5" method="POST" action="{{ route('owner.privacy-requests.transition', $privacyRequest) }}">
                    @csrf
                    <label class="text-sm">Record a non-destructive state change<select name="status" required class="mt-1 block rounded-sm border border-line-strong px-3 py-2">@foreach ($nextStatuses as $nextStatus)<option value="{{ $nextStatus->value }}">{{ __('privacy.request_statuses.'.$nextStatus->value) }}</option>@endforeach</select></label>
                    <button class="rounded-sm border border-line-strong px-4 py-2 text-sm">Save state</button>
                </form>
            @endif
        @endcan
        <h2 class="font-semibold">{{ __('privacy.owner.provider_tasks') }}</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-canvas text-xs text-muted"><tr><th class="px-3 py-2">{{ __('privacy.owner.provider') }}</th><th class="px-3 py-2">{{ __('privacy.owner.scope') }}</th><th class="px-3 py-2">{{ __('privacy.owner.status') }}</th><th class="px-3 py-2">{{ __('privacy.owner.attempts') }}</th><th class="px-3 py-2">{{ __('privacy.owner.error_category') }}</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @forelse ($privacyRequest->providerTasks as $task)
                        <tr><td class="px-3 py-2">{{ __('privacy.providers.'.$task->provider->value) }}</td><td class="px-3 py-2">{{ $task->scope_code }}</td><td class="px-3 py-2">{{ __('privacy.provider_task_statuses.'.$task->status->value) }}</td><td class="px-3 py-2">{{ $task->attempts }}</td><td class="px-3 py-2">{{ $task->error_category ?? '—' }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-4 text-muted">{{ __('privacy.owner.no_provider_tasks') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-5 rounded-md border border-line bg-surface p-5">
        <h2 class="font-semibold">{{ __('privacy.owner.history') }}</h2>
        <ol class="mt-3 space-y-3">
            @foreach ($privacyRequest->events as $event)
                @php($statusCatalog = str_starts_with($event->event_type, 'provider_task_') ? 'privacy.provider_task_statuses.' : 'privacy.request_statuses.')
                <li class="border-l-2 border-line pl-3 text-sm">
                    <p>{{ __('privacy.events.'.$event->event_type) }}</p>
                    <p class="text-xs text-muted">{{ $event->created_at?->utc()->format('Y-m-d H:i:s').' UTC' }} · {{ $event->from_status ? __($statusCatalog.$event->from_status).' → ' : '' }}{{ $event->to_status ? __($statusCatalog.$event->to_status) : '—' }}</p>
                </li>
            @endforeach
        </ol>
    </section>
</x-layouts.owner>
