<x-layouts.account :title="__('account.billing.title')">
    <x-account.page-header :title="__('account.billing.title')" :lead="__('account.billing.lead')">
        <x-slot:actions>
            <x-ui.button :href="route('account.profile')" variant="secondary">{{ __('account.billing.edit') }}</x-ui.button>
        </x-slot:actions>
    </x-account.page-header>

    <div class="grid gap-6">
        <x-account.panel :title="__('account.billing.details')" heading-id="billing-details">
            @php $address = $customer->billing_address ?? []; @endphp
            <dl class="grid gap-x-8 gap-y-5 text-sm sm:grid-cols-2">
                <x-account.detail :label="__('account.billing.name')">{{ $customer->fullName() }}</x-account.detail>
                <x-account.detail :label="__('account.billing.email')">{{ $customer->email }}</x-account.detail>
                <x-account.detail :label="__('account.billing.customer_type')">{{ $customer->customer_type ? __('account.profile.customer_types.'.$customer->customer_type) : '' }}</x-account.detail>
                <x-account.detail :label="__('account.billing.company')">{{ $customer->company_name }}</x-account.detail>
                <x-account.detail :label="__('account.billing.country')">{{ __('countries.'.$customer->country_code) }}</x-account.detail>
                <x-account.detail :label="__('account.billing.tax_id')">{{ $customer->tax_id }}</x-account.detail>
                <x-account.detail :label="__('account.billing.address')" class="sm:col-span-2">{{ implode(', ', array_filter([$address['line1'] ?? null, $address['line2'] ?? null, trim(($address['postal_code'] ?? '').' '.($address['city'] ?? ''))])) }}</x-account.detail>
                <x-account.detail :label="__('account.billing.currency')">{{ $subscription?->currency }}</x-account.detail>
                <x-account.detail :label="__('account.billing.current_subscription')">
                    @if ($subscription){{ $subscription->planName() }} — {{ $subscription->product_type->label() }} · {{ $subscription->status->label() }}@endif
                </x-account.detail>
            </dl>
        </x-account.panel>

        <x-account.panel :title="__('account.billing.invoices')" heading-id="billing-invoices">
            <p class="text-sm text-muted">{{ __('account.billing.invoices_placeholder') }}</p>
        </x-account.panel>
    </div>
</x-layouts.account>
