<x-layouts.owner :title="__('owner.dashboard.title')">
    <x-operator.page-header :title="__('owner.dashboard.title')" :lead="__('owner.dashboard.lead')" />

    <section aria-labelledby="business-title">
        <h2 id="business-title" class="mb-3 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('owner.dashboard.business') }}</h2>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="col-span-2 flex min-w-0 flex-col rounded-md border border-line bg-surface px-4 py-3 md:col-span-1">
                <span class="text-xs font-medium text-muted">{{ __('owner.dashboard.metrics.mrr') }}</span>
                @foreach ($metrics['mrr'] as $money)
                    <span class="mt-1 text-2xl font-semibold break-words text-primary tabular-nums" data-metric="mrr">{{ $money->format() }}</span>
                @endforeach
                <span class="mt-0.5 text-[11px] text-muted">{{ __('owner.dashboard.mrr_note') }}</span>
            </div>
            <x-operator.metric :label="__('owner.dashboard.metrics.active_customers')" :value="$metrics['active_customers']" :href="route('owner.customers.index')" data-metric="active_customers" />
            <x-operator.metric :label="__('owner.dashboard.metrics.active_subscriptions')" :value="$metrics['active_subscriptions']" data-metric="active_subscriptions" />
            <x-operator.metric :label="__('owner.dashboard.metrics.active_websites')" :value="$metrics['active_websites']" :href="route('owner.websites.index')" data-metric="active_websites" />
        </div>
    </section>

    <section aria-labelledby="operations-title" class="mt-6">
        <h2 id="operations-title" class="mb-3 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('owner.dashboard.operations') }}</h2>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <x-operator.metric :label="__('owner.dashboard.metrics.healthy_websites')" :value="$metrics['healthy_websites']" tone="positive" data-metric="healthy_websites" />
            <x-operator.metric :label="__('owner.dashboard.metrics.attention_websites')" :value="$metrics['attention_websites']" tone="warning" data-metric="attention_websites" />
            <x-operator.metric :label="__('owner.dashboard.metrics.critical_websites')" :value="$metrics['critical_websites']" tone="danger" data-metric="critical_websites" />
            <x-operator.metric :label="__('owner.dashboard.metrics.failed_backups')" :value="$metrics['failed_backups']" tone="danger" :href="route('owner.websites.index', ['backup' => 'failed'])" data-metric="failed_backups" />
            <x-operator.metric :label="__('owner.dashboard.metrics.open_security_findings')" :value="$metrics['open_security_findings']" tone="warning" data-metric="open_security_findings" />
            <x-operator.metric :label="__('owner.dashboard.metrics.expired_licenses')" :value="$metrics['expired_licenses']" tone="warning" data-metric="expired_licenses" />
        </div>
        @if ($metrics['unknown_websites'] > 0)
            <p class="mt-2 text-xs text-muted">{{ __('owner.dashboard.unknown_websites', ['count' => $metrics['unknown_websites']]) }}</p>
        @endif
    </section>

    <x-operator.section :title="__('owner.dashboard.recent_changes')" id="recent-changes" class="mt-6">
        <x-owner.audit-list :events="$audit" />
    </x-operator.section>
</x-layouts.owner>
