<x-layouts.owner :title="__('owner.errors.forbidden_title')">
    <div class="mx-auto max-w-xl rounded-md border border-line bg-surface p-6">
        <x-ui.status-badge tone="danger" variant="code">403</x-ui.status-badge>
        <h1 class="mt-4 text-xl font-semibold text-primary">{{ __('owner.errors.forbidden_title') }}</h1>
        <p class="mt-2 text-sm text-muted">{{ __('owner.errors.forbidden_text') }}</p>
        <div class="mt-5">
            <x-ui.button :href="route('owner.dashboard')" variant="secondary" size="sm">{{ __('owner.errors.back') }}</x-ui.button>
        </div>
    </div>
</x-layouts.owner>
