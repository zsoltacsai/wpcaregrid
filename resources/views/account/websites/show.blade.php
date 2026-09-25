<x-layouts.account :title="$website->host()">
    <x-account.page-header :title="$website->host()" :lead="$website->url" />

    <x-account.onboarding-steps :states="$website->onboardingStates()" />

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-account.panel :title="__('account.onboarding.steps.connection.title')" heading-id="connection-title">
            <dl class="grid gap-5 text-sm sm:grid-cols-2">
                <x-account.detail :label="__('account.websites.type')">{{ $website->type->label() }}</x-account.detail>
                <x-account.detail :label="__('account.websites.status')">
                    <x-ui.status-badge :tone="$website->status->tone()">{{ $website->status->label() }}</x-ui.status-badge>
                </x-account.detail>
                <x-account.detail :label="__('account.websites.connector')">
                    {{ $website->isConnected() ? $website->connector_status->label() : __('account.onboarding.not_connected') }}
                </x-account.detail>
                <x-account.detail :label="__('account.websites.added')"><span class="font-mono">{{ $website->created_at->translatedFormat('j M Y') }}</span></x-account.detail>
            </dl>

            @unless ($website->isConnected())
                <x-account.notice class="mt-6">{{ __('account.onboarding.connection_placeholder') }}</x-account.notice>
            @endunless
        </x-account.panel>

        <x-account.panel :title="__('account.onboarding.steps.initial_check.title')" heading-id="check-title">
            <p class="text-sm text-muted">{{ __('account.onboarding.check_placeholder') }}</p>
        </x-account.panel>
    </div>
</x-layouts.account>
