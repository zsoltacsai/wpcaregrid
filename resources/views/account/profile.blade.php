<x-layouts.account locale-form="profile-form" :title="__('account.profile.title')">
    <x-account.page-header :title="__('account.profile.title')" :lead="__('account.profile.lead')" />

    @php $address = $customer->billing_address ?? []; @endphp

    @if ($rectificationRequest)
        <x-account.panel class="mb-4">
            <p class="text-sm text-primary">{{ __('account.privacy.rectification_notice') }}</p>
            <input type="hidden" form="profile-form" name="privacy_request" value="{{ $rectificationRequest->uuid }}">
        </x-account.panel>
    @endif

    @if ($continueCheckout)
        <x-account.panel class="mb-4">
            <p role="status" class="text-sm text-primary">{{ __('account.profile.checkout_required') }}</p>
            <p class="mt-2 text-sm text-muted">{{ __('account.profile.checkout_missing', ['fields' => collect($missingBillingFields)->map(fn ($field) => __('checkout.fields.'.$field))->join(', ')]) }}</p>
        </x-account.panel>
    @elseif ($missingBillingFields !== [])
        <x-account.panel class="mb-4">
            <p role="status" class="text-sm text-warning-ink">{{ __('account.profile.incomplete', ['fields' => collect($missingBillingFields)->map(fn ($field) => __('checkout.fields.'.$field))->join(', ')]) }}</p>
        </x-account.panel>
    @else
        <x-account.panel class="mb-4">
            <p role="status" class="text-sm text-primary">{{ __('account.profile.complete') }}</p>
        </x-account.panel>
    @endif

    <x-account.panel>
        <form id="profile-form" method="POST" action="{{ route('account.profile.update') }}" class="grid gap-5" novalidate x-data="{ type: @js(old('customer_type', $customer->customer_type ?? 'individual')) }">
            @csrf
            @method('PUT')

            @if ($continueCheckout && $checkoutSelection)
                <input type="hidden" name="continue_checkout" value="1">
                <input type="hidden" name="product" value="{{ $checkoutSelection->productType->value }}">
                <input type="hidden" name="plan" value="{{ $checkoutSelection->tier->value }}">
                <input type="hidden" name="interval" value="{{ $checkoutSelection->interval->value }}">
            @endif

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
                <x-form.field name="tax_id" :label="__('account.profile.tax_id')" :value="$customer->taxIdentifier()" optional maxlength="50" />
            </div>

            <x-form.select name="country_code" :label="__('account.profile.country')" :hint="__('account.profile.country_hint')" :options="__('countries')" :selected="$customer->country_code" autocomplete="country" class="sm:max-w-md" />

            <x-form.select name="locale" :label="__('account.profile.language')" :options="$locales" :selected="old('locale', auth()->user()->locale ?? app()->getLocale())" class="sm:max-w-md" />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.field name="address_line1" :label="__('account.profile.address_line1')" :value="$address['line1'] ?? null" autocomplete="address-line1" :optional="!$continueCheckout" @if($continueCheckout) required @endif maxlength="150" />
                <x-form.field name="address_line2" :label="__('account.profile.address_line2')" :value="$address['line2'] ?? null" autocomplete="address-line2" optional maxlength="150" />
                <x-form.field name="postal_code" :label="__('account.profile.postal_code')" :value="$address['postal_code'] ?? null" autocomplete="postal-code" :optional="!$continueCheckout" @if($continueCheckout) required @endif maxlength="20" />
                <x-form.field name="city" :label="__('account.profile.city')" :value="$address['city'] ?? null" autocomplete="address-level2" :optional="!$continueCheckout" @if($continueCheckout) required @endif maxlength="100" />
            </div>

            <x-ui.button type="submit" size="lg" class="self-start">{{ __('account.profile.save') }}</x-ui.button>
        </form>
    </x-account.panel>

    @if ($customer->customer_type === 'company')
        @php
            $vatStatus = $history->first()?->status?->value;
            $vatDisplayStatus = match ($vatStatus) {
                'valid' => 'valid',
                'invalid', 'malformed' => 'invalid',
                'unavailable' => 'unavailable',
                'pending_verification', 'manual_review' => 'pending_review',
                default => 'not_verified',
            };
        @endphp
        <x-account.panel class="mt-4">
            <div class="mb-4">
                <h2 class="text-base font-semibold text-primary">{{ __('account.profile.vies.title') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('account.profile.vies.lead') }}</p>
            </div>

            @if (session('vat_status'))
                @php
                    $resultStatus = match (session('vat_status')) {
                        'valid' => 'valid',
                        'invalid', 'malformed' => 'invalid',
                        'unavailable' => 'unavailable',
                        default => 'pending_review',
                    };
                @endphp
                <p role="status" class="mb-4 rounded-sm border border-line bg-canvas p-3 text-sm text-primary">{{ __('account.profile.vies.status.'.$resultStatus) }}</p>
            @endif

            <dl class="mb-4 grid gap-2 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-muted">{{ __('account.profile.vies.current_status') }}</dt><dd>{{ __('account.profile.vies.status.'.$vatDisplayStatus) }}</dd></div>
                <div><dt class="text-xs text-muted">{{ __('account.profile.vies.last_successful_check') }}</dt><dd>{{ app(\App\Support\DisplayDateTime::class)->format($lastSuccessful?->checked_at) ?? __('account.profile.vies.not_checked') }}</dd></div>
            </dl>

            <form method="POST" action="{{ route('account.profile.vat-verification') }}" class="grid gap-4 sm:grid-cols-2" novalidate>
                @csrf
                <x-form.select name="vat_country" :label="__('account.profile.vies.country')" :options="$countries" :selected="old('vat_country', $history->first()?->country_code ?? $customer->country_code)" required />
                <x-form.field name="vat_number" :label="__('account.profile.vies.number')" :value="old('vat_number', $customer->taxIdentifier())" maxlength="50" autocomplete="off" required />
                <p class="text-xs text-muted sm:col-span-2">{{ __('account.profile.vies.evidence_only') }}</p>
                <div class="sm:col-span-2"><x-ui.button type="submit" size="sm">{{ __('account.profile.vies.verify') }}</x-ui.button></div>
            </form>

            @if ($history->isNotEmpty())
                <div class="mt-5 border-t border-line pt-4">
                    <h3 class="text-sm font-semibold text-primary">{{ __('account.profile.vies.history') }}</h3>
                    <ul class="mt-2 divide-y divide-line text-xs">
                        @foreach ($history as $evidence)
                            @php
                                $itemStatus = match ($evidence->status->value) {
                                    'valid' => 'valid',
                                    'invalid', 'malformed' => 'invalid',
                                    'unavailable' => 'unavailable',
                                    default => 'pending_review',
                                };
                            @endphp
                            <li class="flex flex-wrap justify-between gap-2 py-2"><span>{{ $evidence->country_code }} · {{ $evidence->provider === 'eu_vies' ? __('account.profile.vies.source') : __('account.profile.vies.local_check') }} · {{ __('account.profile.vies.status.'.$itemStatus) }}</span><time datetime="{{ $evidence->checked_at?->toIso8601String() }}">@displayDateTime($evidence->checked_at)</time></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-account.panel>
    @endif
</x-layouts.account>
