<x-mail::message>
{{ __('emails.greeting', ['name' => $name]) }}

{{ __('emails.welcome.intro', ['amount' => $amount ?? '', 'plan' => $plan ?? '']) }}

{{ __('emails.welcome.body') }}

<x-mail::button :url="$url" color="success">
{{ __('emails.welcome.action') }}
</x-mail::button>

{{ __('emails.support') }}

{{ __('emails.signature') }}
</x-mail::message>
