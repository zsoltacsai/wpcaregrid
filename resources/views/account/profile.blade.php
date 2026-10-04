<x-layouts.account locale-form="profile-form" :title="__('account.profile.title')">
    <x-account.page-header :title="__('account.profile.title')" :lead="__('account.profile.lead')" />

    @php $address = $customer->billing_address ?? []; @endphp

    <x-account.panel>
        <form id="profile-form" method="POST" action="{{ route('account.profile.update') }}" class="grid gap-5" novalidate x-data="{ type: @js(old('customer_type', $customer->customer_type ?? 'individual')) }">
            @csrf
            @method('PUT')

            <x-form.field name="email" type="email" :label="__('account.profile.email')" :value="$customer->email" disabled class="sm:max-w-md" />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.field name="first_name" :label="__('account.profile.first_name')" :value="$customer->first_name" autocomplete="given-name" required maxlength="100" />
                <x-form.field name="last_name" :label="__('account.profile.last_name')" :value="$customer->last_name" autocomplete="family-name" required maxlength="100" />
            </div>

            <fieldset>
                <legend class="text-sm font-medium text-primary">{{ __('account.profile.customer_type') }}</legend>
                <div class="mt-2 flex flex-wrap gap-3">
                    @foreach (__('account.profile.customer_types') as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-md border border-line-strong px-4 py-3 text-sm font-medium text-primary has-checked:border-accent has-checked:bg-accent-soft">
                            <input type="radio" name="customer_type" value="{{ $value }}" x-model="type" class="size-4 accent-action">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div x-show="type === 'company'" @if (old('customer_type', $customer->customer_type) !== 'company') x-cloak @endif class="grid gap-5 sm:grid-cols-2">
                <x-form.field name="company_name" :label="__('account.profile.company_name')" :value="$customer->company_name" autocomplete="organization" maxlength="150" />
                <x-form.field name="tax_id" :label="__('account.profile.tax_id')" :value="$customer->tax_id" optional maxlength="50" />
            </div>

            <x-form.select name="country_code" :label="__('account.profile.country')" :hint="__('account.profile.country_hint')" :options="__('countries')" :selected="$customer->country_code" autocomplete="country" class="sm:max-w-md" />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.field name="address_line1" :label="__('account.profile.address_line1')" :value="$address['line1'] ?? null" autocomplete="address-line1" optional maxlength="150" />
                <x-form.field name="address_line2" :label="__('account.profile.address_line2')" :value="$address['line2'] ?? null" autocomplete="address-line2" optional maxlength="150" />
                <x-form.field name="postal_code" :label="__('account.profile.postal_code')" :value="$address['postal_code'] ?? null" autocomplete="postal-code" optional maxlength="20" />
                <x-form.field name="city" :label="__('account.profile.city')" :value="$address['city'] ?? null" autocomplete="address-level2" optional maxlength="100" />
            </div>

            <x-ui.button type="submit" size="lg" class="self-start">{{ __('account.profile.save') }}</x-ui.button>
        </form>
    </x-account.panel>
</x-layouts.account>
