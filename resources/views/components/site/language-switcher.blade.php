{{--
    Language selector (Magyar / English). Server-side: each button posts the
    chosen code to /locale, which accepts only supported languages and
    redirects back. It works without JavaScript.

    form: id of the page's own form (login, checkout, profile…). With
    JavaScript, what the visitor has already typed there is carried along and
    comes back after the switch; passwords, file fields and tokens never are.
    The selector keeps its own form (the buttons are not associated with the
    page form), so pressing Enter in a field still submits the page's form.
    tone: light (default) | dark (on the navy customer sidebar)
--}}
@use('App\Localization\Locales')
@props(['form' => null, 'tone' => 'light'])

@php $current = app()->getLocale(); @endphp

<form
    method="POST"
    action="{{ route('locale.update') }}"
    class="contents"
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
                    input.type = 'hidden';
                    input.name = field.name;
                    input.value = field.value;
                    input.dataset.carried = '';
                    this.$el.appendChild(input);
                }
            },
        }"
    @endif
>
    @csrf
    <div
        role="group"
        aria-label="{{ __('home.language.label') }}"
        {{ $attributes->class('inline-flex items-center divide-x text-sm '.($tone === 'dark' ? 'divide-white/20' : 'divide-line-strong')) }}
    >
        @foreach (Locales::supported() as $code => $name)
            <button
                type="submit"
                name="locale"
                value="{{ $code }}"
                lang="{{ $code }}"
                formnovalidate
                @if ($form !== null) @click="carry()" @endif
                @if ($code === $current) aria-current="true" @endif
                @class([
                    'rounded-sm px-3 py-1.5 font-medium transition-colors',
                    'text-muted hover:text-primary aria-[current=true]:font-semibold aria-[current=true]:text-primary aria-[current=true]:underline aria-[current=true]:underline-offset-4' => $tone !== 'dark',
                    'text-on-dark-muted hover:text-on-dark aria-[current=true]:font-semibold aria-[current=true]:text-on-dark aria-[current=true]:underline aria-[current=true]:underline-offset-4' => $tone === 'dark',
                ])
            >
                {{ $name }}
            </button>
        @endforeach
    </div>
</form>
