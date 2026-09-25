<x-mail::message>
{{ __('emails.greeting', ['name' => $name]) }}

{{ __('emails.subscription_canceled.intro', ['amount' => $amount ?? '', 'plan' => $plan ?? '']) }}

{{ __('emails.subscription_canceled.body') }}

<x-mail::button :url="$url" color="success">
{{ __('emails.subscription_canceled.action') }}
</x-mail::button>

{{ __('emails.support') }}

{{ __('emails.signature') }}
</x-mail::message>
