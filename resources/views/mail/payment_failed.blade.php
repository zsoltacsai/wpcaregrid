<x-mail::message>
{{ __('emails.greeting', ['name' => $name]) }}

{{ __('emails.payment_failed.intro', ['amount' => $amount ?? '', 'plan' => $plan ?? '']) }}

{{ __('emails.payment_failed.body') }}

<x-mail::button :url="$url" color="success">
{{ __('emails.payment_failed.action') }}
</x-mail::button>

{{ __('emails.support') }}

{{ __('emails.signature') }}
</x-mail::message>
