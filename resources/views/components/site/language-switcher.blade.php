{{-- Marketing routes switch directly between translated URLs; all other pages retain POST /locale. --}}
@use('App\Localization\Locales')
@props(['form' => null, 'tone' => 'light'])

@php
    $current = app()->getLocale();
    $localizedMarketing = Locales::isLocalizedMarketingRoute(request());
    $marketingRoutes = app(\App\Localization\MarketingRoutes::class);
    $groupClass = 'inline-flex items-center divide-x text-sm '.($tone === 'dark' ? 'divide-white/20' : 'divide-line-strong');
    $itemClass = 'rounded-sm px-3 py-1.5 font-medium transition-colors';
@endphp

@if ($localizedMarketing)
    <div role="group" aria-label="{{ __('home.language.label') }}" {{ $attributes->class($groupClass) }}>
        @foreach (Locales::supported() as $code => $name)
            <a href="{{ $marketingRoutes->equivalent(request(), $code) }}" lang="{{ $code }}"
                @if ($code === $current) aria-current="page" @endif
                @class([
                    $itemClass,
                    'text-muted hover:text-primary aria-[current=page]:font-semibold aria-[current=page]:text-primary aria-[current=page]:underline aria-[current=page]:underline-offset-4' => $tone !== 'dark',
                    'text-on-dark-muted hover:text-on-dark aria-[current=page]:font-semibold aria-[current=page]:text-on-dark aria-[current=page]:underline aria-[current=page]:underline-offset-4' => $tone === 'dark',
                ])>{{ $name }}</a>
        @endforeach
    </div>
@else
    <form method="POST" action="{{ route('locale.update') }}" class="contents"
        @if ($form !== null)
            x-data="{
                carry() {
                    const source = document.getElementById(@js($form));
                    if (! source) { return; }
                    this.$el.querySelectorAll('input[data-carried]').forEach((input) => input.remove());
                    for (const field of source.elements) {
                        if (! field.name || field.disabled || ['password', 'file', 'submit', 'button', 'reset'].includes(field.type)
                            || ['_token', '_method', 'locale'].includes(field.name)) { continue; }
                        if (['checkbox', 'radio'].includes(field.type) && ! field.checked) { continue; }
                        const input = document.createElement('input');
                        input.type = 'hidden'; input.name = field.name; input.value = field.value; input.dataset.carried = '';
                        this.$el.appendChild(input);
                    }
                },
            }"
        @endif
    >
        @csrf
        <div role="group" aria-label="{{ __('home.language.label') }}" {{ $attributes->class($groupClass) }}>
            @foreach (Locales::supported() as $code => $name)
                <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}" formnovalidate
                    @if ($form !== null) @click="carry()" @endif
                    @if ($code === $current) aria-current="true" @endif
                    @class([
                        $itemClass,
                        'text-muted hover:text-primary aria-[current=true]:font-semibold aria-[current=true]:text-primary aria-[current=true]:underline aria-[current=true]:underline-offset-4' => $tone !== 'dark',
                        'text-on-dark-muted hover:text-on-dark aria-[current=true]:font-semibold aria-[current=true]:text-on-dark aria-[current=true]:underline aria-[current=true]:underline-offset-4' => $tone === 'dark',
                    ])>{{ $name }}</button>
            @endforeach
        </div>
    </form>
@endif
