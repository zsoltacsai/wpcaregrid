<x-mail::message>
{{ __('emails.greeting', ['name' => $name]) }}

{{ __('emails.activation.intro', ['amount' => $amount ?? '', 'plan' => $plan ?? '']) }}

{{ __('emails.activation.body') }}

<x-mail::button :url="$url" color="success">
{{ __('emails.activation.action') }}
</x-mail::button>

{{ __('emails.activation.expiry') }}

{{ __('emails.activation.ignore') }}

{{ __('emails.support') }}

{{ __('emails.signature') }}
</x-mail::message>
