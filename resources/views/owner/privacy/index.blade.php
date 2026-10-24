<x-layouts.owner :title="__('privacy.owner.title')">
    <x-operator.page-header :title="__('privacy.owner.title')" :lead="__('privacy.owner.lead')" />

    <div class="mb-4 rounded-md border border-warning/40 bg-warning-soft p-4 text-sm text-warning-ink">
        {{ __('privacy.owner.non_destructive_notice') }}
    </div>

    <section class="overflow-hidden rounded-md border border-line bg-surface">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-canvas text-xs text-muted">
                    <tr>
                        <th class="px-4 py-3">{{ __('privacy.owner.request_id') }}</th>
                        <th class="px-4 py-3">{{ __('privacy.owner.customer_reference') }}</th>
                        <th class="px-4 py-3">{{ __('privacy.owner.type') }}</th>
                        <th class="px-4 py-3">{{ __('privacy.owner.status') }}</th>
                        <th class="px-4 py-3">{{ __('privacy.owner.requested_at') }}</th>
                        <th class="px-4 py-3">{{ __('privacy.owner.provider_tasks') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($requests as $request)
                        <tr>
                            <td class="px-4 py-3 font-mono"><a class="text-link underline" href="{{ route('owner.privacy-requests.show', $request) }}">{{ $request->uuid }}</a></td>
                            <td class="px-4 py-3 font-mono">{{ $request->customer?->uuid ?? '—' }}</td>
                            <td class="px-4 py-3">{{ __('privacy.types.'.$request->request_type->value) }}</td>
                            <td class="px-4 py-3">{{ __('privacy.request_statuses.'.$request->status->value) }}</td>
                            <td class="px-4 py-3">@displayDateTime($request->requested_at, 'Y-m-d H:i:s T')</td>
                            <td class="px-4 py-3">{{ $request->provider_tasks_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-muted">{{ __('privacy.owner.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-line p-4">{{ $requests->links() }}</div>
    </section>

    <section class="mt-5 overflow-hidden rounded-md border border-line bg-surface">
        <div class="border-b border-line p-4">
            <h2 class="font-semibold">{{ __('privacy.owner.provider_registry') }}</h2>
            <p class="mt-1 text-xs text-muted">{{ __('privacy.owner.provider_registry_notice') }}</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-canvas text-xs text-muted"><tr><th class="px-4 py-3">{{ __('privacy.owner.provider') }}</th><th class="px-4 py-3">{{ __('privacy.owner.service_scope') }}</th><th class="px-4 py-3">{{ __('privacy.owner.region_review') }}</th><th class="px-4 py-3">{{ __('privacy.owner.contract_review') }}</th><th class="px-4 py-3">{{ __('privacy.owner.deletion_capability') }}</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($providerFacts as $provider => $facts)
                        <tr><td class="px-4 py-3">{{ __('privacy.providers.'.$provider) }}</td><td class="px-4 py-3">{{ $facts['service_scope'] }}</td><td class="px-4 py-3">{{ __('privacy.fact_statuses.'.$facts['region_status']) }}</td><td class="px-4 py-3">{{ __('privacy.fact_statuses.'.$facts['contract_status']) }}</td><td class="px-4 py-3">{{ __('privacy.fact_statuses.'.$facts['deletion_capability_status']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.owner>
