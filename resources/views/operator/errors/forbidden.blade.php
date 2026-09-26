<x-layouts.operator :title="__('operator.errors.forbidden_title')">
    <div class="mx-auto max-w-xl rounded-md border border-line bg-surface p-6">
        <x-ui.status-badge tone="danger" variant="code">403</x-ui.status-badge>
        <h1 class="mt-4 text-xl font-semibold text-primary">{{ __('operator.errors.forbidden_title') }}</h1>
        <p class="mt-2 text-sm text-muted">{{ __('operator.errors.forbidden_text') }}</p>
        @can('operator.websites.view')
            <div class="mt-5">
                <x-ui.button :href="route('operator.dashboard')" variant="secondary" size="sm">{{ __('operator.errors.back') }}</x-ui.button>
            </div>
        @endcan
    </div>
</x-layouts.operator>
