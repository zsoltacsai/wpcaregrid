<x-layouts.account :title="__('account.subscription.title')">
    <x-account.page-header :title="__('account.subscription.title')" :lead="__('account.subscription.lead')" />

    @forelse ($subscriptions as $subscription)
        <x-account.panel :title="$subscription->planName().' — '.$subscription->product_type->label()" :heading-id="'subscription-'.$subscription->uuid" class="mb-6">
            <x-account.subscription-details :subscription="$subscription" />

            <div class="mt-6 flex flex-col gap-2 border-t border-line pt-6 sm:flex-row sm:items-center sm:gap-4">
                <x-ui.button variant="secondary" disabled aria-describedby="manage-note-{{ $subscription->uuid }}" class="cursor-not-allowed opacity-60">{{ __('account.subscription.manage') }}</x-ui.button>
                <p id="manage-note-{{ $subscription->uuid }}" class="text-sm text-muted">{{ __('account.subscription.manage_note') }}</p>
            </div>
        </x-account.panel>
    @empty
        <x-account.panel>
            <p class="text-sm text-muted">{{ __('account.subscription.none') }}</p>
            <x-ui.button :href="route('pricing')" variant="secondary" class="mt-4">{{ __('account.dashboard.view_plans') }}</x-ui.button>
        </x-account.panel>
    @endforelse
</x-layouts.account>
