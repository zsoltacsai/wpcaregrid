<x-mail::message>
# {{ __('emails.greeting', ['name' => $name]) }}

{{ $message }}

<x-mail::button :url="$url">
{{ $action }}
</x-mail::button>

{{ __('emails.signature') }}
</x-mail::message>
