{{-- The editable rules of a coupon: validity, limits, restrictions, note. Used when creating and editing (@include; needs $productTypes, $tiers, $intervals, $timezone). --}}
@php
    $coupon ??= null;
    $stamp = fn ($date) => app(\App\Support\DisplayDateTime::class)->format($date, 'Y-m-d\TH:i');
    $selected = fn (string $field) => (array) old($field, $coupon?->{$field} ?? []);
@endphp

<div class="grid gap-4 md:grid-cols-2">
    <x-form.field name="starts_at" type="datetime-local" :label="__('owner.coupons.fields.starts_at')" :value="$stamp($coupon?->starts_at)" :optional="true" :hint="__('owner.coupons.timezone_hint', ['zone' => $timezone])" />
    <x-form.field name="expires_at" type="datetime-local" :label="__('owner.coupons.fields.expires_at')" :value="$stamp($coupon?->expires_at)" :optional="true" :hint="__('owner.coupons.timezone_hint', ['zone' => $timezone])" />
    <x-form.field name="max_redemptions" type="number" min="1" max="1000000" inputmode="numeric" :label="__('owner.coupons.fields.max_redemptions')" :value="$coupon?->max_redemptions" :optional="true" :hint="__('owner.coupons.hints.max_redemptions')" />
    <x-form.field name="max_redemptions_per_customer" type="number" min="1" max="1000" inputmode="numeric" :label="__('owner.coupons.fields.max_per_customer')" :value="$coupon?->max_redemptions_per_customer" :optional="true" :hint="__('owner.coupons.hints.max_per_customer')" />
</div>

<fieldset class="mt-4">
    <legend class="text-sm font-medium text-primary">{{ __('owner.coupons.fields.restrictions') }} <span class="font-normal text-muted">({{ __('owner.optional') }})</span></legend>
    <p class="mt-1 text-xs text-muted">{{ __('owner.coupons.hints.restrictions') }}</p>
    <div class="mt-3 max-w-md">
        @php
            $marketGroup = old('market_group', $coupon?->marketGroupValue() ?? 'all');
        @endphp
        <x-form.select name="market_group" :label="__('owner.coupons.fields.market_group')" :options="$marketGroups" :selected="$marketGroup" :hint="__('owner.coupons.hints.market_group')" />
    </div>
    <div class="mt-2 grid gap-4 sm:grid-cols-3">
        <div>
            <p class="text-xs font-medium text-muted">{{ __('owner.coupons.fields.product_types') }}</p>
            @foreach ($productTypes as $type)
                <label class="mt-1 flex items-center gap-2 text-sm"><input type="checkbox" name="product_types[]" value="{{ $type->value }}" class="size-4" @checked(in_array($type->value, $selected('product_types'), true))> {{ $type->label() }}</label>
            @endforeach
        </div>
        <div>
            <p class="text-xs font-medium text-muted">{{ __('owner.coupons.fields.plans') }}</p>
            @foreach ($tiers as $tier)
                <label class="mt-1 flex items-center gap-2 text-sm"><input type="checkbox" name="plans[]" value="{{ $tier->value }}" class="size-4" @checked(in_array($tier->value, $selected('plans'), true))> {{ __('owner.tiers.'.$tier->value) }}</label>
            @endforeach
        </div>
        <div>
            <p class="text-xs font-medium text-muted">{{ __('owner.coupons.fields.intervals') }}</p>
            @foreach ($intervals as $interval)
                <label class="mt-1 flex items-center gap-2 text-sm"><input type="checkbox" name="intervals[]" value="{{ $interval->value }}" class="size-4" @checked(in_array($interval->value, $selected('intervals'), true))> {{ $interval->label() }}</label>
            @endforeach
        </div>
    </div>
</fieldset>

<div class="mt-4">
    <label for="field-note" class="block text-sm font-medium text-primary">{{ __('owner.coupons.fields.note') }} <span class="font-normal text-muted">({{ __('owner.optional') }})</span></label>
    <textarea id="field-note" name="note" rows="3" maxlength="1000" class="mt-2 block w-full rounded-sm border border-line-strong bg-surface px-4 py-3 text-[15px] text-ink hover:border-muted">{{ old('note', $coupon?->note) }}</textarea>
    <p class="mt-2 text-xs text-muted">{{ __('owner.coupons.hints.note') }}</p>
</div>
