<x-mail::message>
{{ __('emails.greeting', ['name' => $name]) }}

{{ __('emails.staff_invitation.intro', ['area' => $area]) }}

{{ __('emails.staff_invitation.body') }}

<x-mail::button :url="$url" color="success">
{{ __('emails.staff_invitation.action') }}
</x-mail::button>

{{ __('emails.staff_invitation.expiry', ['hours' => $hours]) }}

{{ __('emails.staff_invitation.ignore') }}

{{ __('emails.signature') }}
</x-mail::message>
