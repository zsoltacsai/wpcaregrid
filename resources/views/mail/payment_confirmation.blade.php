<x-mail::message>
{{ __('emails.greeting', ['name' => $name]) }}

{{ __('emails.payment_confirmation.intro', ['amount' => $amount ?? '', 'plan' => $plan ?? '']) }}

{{ __('emails.payment_confirmation.body') }}

<x-mail::button :url="$url" color="success">
{{ __('emails.payment_confirmation.action') }}
</x-mail::button>

{{ __('emails.support') }}

{{ __('emails.signature') }}
</x-mail::message>
