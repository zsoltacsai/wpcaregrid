<x-mail::message>
{{ __('offers.mail.greeting', ['name' => $name]) }}

{{ __('offers.mail.paid.intro', ['price' => $price, 'title' => $title, 'host' => $host]) }}

{{ __('offers.mail.paid.body') }}

<x-mail::button :url="$url" color="success">
{{ __('offers.mail.paid.action') }}
</x-mail::button>

{{ __('offers.mail.support') }}

{{ __('offers.mail.signature') }}
</x-mail::message>
