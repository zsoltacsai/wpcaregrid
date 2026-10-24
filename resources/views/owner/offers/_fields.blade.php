{{-- Offer fields shared by create and edit (@include; needs $currencies, $timezone; optional $offer, $amount). --}}
@php
    $offer ??= null;
    $amount ??= '';
    $stamp = fn ($date) => app(\App\Support\DisplayDateTime::class)->format($date, 'Y-m-d\TH:i');
@endphp

<div class="grid gap-4 md:grid-cols-2">
    <x-form.field name="title" :label="__('owner.offers.fields.title')" :value="$offer?->title" required maxlength="150" class="md:col-span-2" :hint="__('owner.offers.hints.title')" />

    <div class="md:col-span-2">
        <label for="field-scope" class="block text-sm font-medium text-primary">{{ __('owner.offers.fields.scope') }}</label>
        <textarea id="field-scope" name="scope" rows="8" maxlength="5000" required class="mt-2 block w-full rounded-sm border border-line-strong bg-surface px-4 py-3 text-[15px] text-ink hover:border-muted @error('scope') border-danger @enderror">{{ old('scope', $offer?->scope) }}</textarea>
        <p class="mt-2 text-xs text-muted">{{ __('owner.offers.hints.scope') }}</p>
        @error('scope')<p class="mt-2 text-sm text-danger-ink">{{ $message }}</p>@enderror
    </div>

    <x-form.field name="amount" inputmode="decimal" :label="__('owner.offers.fields.amount')" :value="$amount" required maxlength="20" :hint="__('owner.offers.hints.amount')" />
    <x-form.select name="currency" :label="__('owner.offers.fields.currency')" :options="collect($currencies)->mapWithKeys(fn ($c) => [$c => $c])->all()" :selected="$offer?->currency ?? ($currencies[0] ?? 'HUF')" :hint="__('owner.offers.hints.currency')" />
    <x-form.field name="valid_until" type="datetime-local" :label="__('owner.offers.fields.valid_until')" :value="$stamp($offer?->valid_until)" required :hint="__('owner.offers.hints.valid_until', ['zone' => $timezone, 'days' => \App\Offers\RecoveryOffers::MAX_VALIDITY_DAYS])" />

    <div class="md:col-span-2">
        <label for="field-internal_notes" class="block text-sm font-medium text-primary">{{ __('owner.offers.fields.internal_notes') }} <span class="font-normal text-muted">({{ __('owner.optional') }})</span></label>
        <textarea id="field-internal_notes" name="internal_notes" rows="3" maxlength="2000" class="mt-2 block w-full rounded-sm border border-line-strong bg-surface px-4 py-3 text-[15px] text-ink hover:border-muted">{{ old('internal_notes', $offer?->internal_notes) }}</textarea>
        <p class="mt-2 text-xs text-muted">{{ __('owner.offers.hints.internal_notes') }}</p>
    </div>
</div>
