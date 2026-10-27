{{--
    Currency is a display preference only. The server offers currencies with
    configured prices; checkout resolves the final price from billing country
    and the selected supported currency.
--}}
@use('App\Pricing\DisplayCurrency')
@props(['catalog', 'locale' => null])

@php
    $display = app(DisplayCurrency::class);
    $selected = $display->selected($locale ?? app()->getLocale());
@endphp

@if ($display->canChoose())
    <form x-data method="POST" action="{{ route('currency.update') }}" class="flex items-center gap-2">
        @csrf
        <label for="display-currency" class="text-sm text-muted">{{ __('pricing.currency_label') }}</label>
        <select
            id="display-currency"
            name="currency"
            class="h-10 rounded-sm border border-line-strong bg-surface px-3 text-sm text-primary"
            @change="$root.requestSubmit()"
        >
            @foreach ($display->available() as $code)
                <option value="{{ $code }}" @selected($code === $selected)>{{ $code }} ({{ $catalog->currency($code)->symbol }})</option>
            @endforeach
        </select>
        <noscript><button type="submit" class="h-10 rounded-sm border border-line-strong px-3 text-sm">{{ __('pricing.currency_apply') }}</button></noscript>
    </form>
@else
    <p class="text-sm text-muted">
        <span class="sr-only">{{ __('pricing.currency_label') }}: </span>
        <span class="font-medium text-primary">{{ $selected }} ({{ $catalog->currency($selected)->symbol }})</span>
        <span class="hidden sm:inline">· {{ __('pricing.currency_only', ['currency' => $selected]) }}</span>
    </p>
@endif
