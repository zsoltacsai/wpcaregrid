<x-layouts.account :title="__('account.websites.title')">
    <x-account.page-header class="m16-dash-header" :title="__('account.websites.title')" :lead="__('account.websites.lead')">
        <x-slot:actions>
            @if ($websites->isNotEmpty())
                <x-ui.button :href="route('account.websites.create')" icon="plus">{{ __('account.websites.add_another') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-account.page-header>

    @unless ($hasAccess)
        <x-account.notice class="mb-6">{{ __('account.websites.no_access') }}</x-account.notice>
    @elseif (! $canAddWebsite && $websites->isNotEmpty())
        <x-account.notice class="mb-6">{{ __('account.websites.no_slot') }}</x-account.notice>
    @endunless

    @if ($websites->isEmpty())
        <x-account.panel class="m16-dash-panel">
            <x-account.empty-state heading="h2" :title="__('account.websites.empty_title')" :text="__('account.websites.empty_text')">
                <x-slot:action>
                    <x-ui.button :href="route('account.websites.create')" icon="plus">{{ __('account.websites.add_first') }}</x-ui.button>
                </x-slot:action>
            </x-account.empty-state>
        </x-account.panel>
    @else
        <ul class="m16-site-list__rows divide-y divide-line rounded-lg border border-line bg-surface">
            @foreach ($websites as $website)
                <li class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <a href="{{ route('account.websites.show', $website) }}" class="truncate rounded-sm font-medium text-primary hover:underline">{{ $website->host() }}</a>
                        <p class="mt-0.5 text-xs text-muted">
                            {{ $website->type->label() }} ·
                            {{ __('account.websites.connector') }}: {{ $website->isConnected() ? $website->connector_status->label() : __('account.onboarding.not_connected') }}
                        </p>
                    </div>
                    <x-account.status-chip :status="$website->status" />
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.account>
