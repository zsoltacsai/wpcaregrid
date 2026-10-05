<x-mail::message>
{{ __('offers.mail.greeting', ['name' => $name]) }}

{{ __('offers.mail.sent.intro', ['host' => $host]) }}

**{{ $title }}**

{{ __('offers.mail.sent.price', ['price' => $price]) }}
{{ __('offers.mail.sent.valid', ['until' => $until]) }}

{{ __('offers.mail.sent.body') }}

<x-mail::button :url="$url" color="success">
{{ __('offers.mail.sent.action') }}
</x-mail::button>

{{ __('offers.mail.support') }}

{{ __('offers.mail.signature') }}
</x-mail::message>
