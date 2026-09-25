<x-mail::message>
{{ __('emails.greeting', ['name' => $name]) }}

{{ __('emails.subscription_activated.intro', ['amount' => $amount ?? '', 'plan' => $plan ?? '']) }}

{{ __('emails.subscription_activated.body') }}

<x-mail::button :url="$url" color="success">
{{ __('emails.subscription_activated.action') }}
</x-mail::button>

{{ __('emails.support') }}

{{ __('emails.signature') }}
</x-mail::message>
