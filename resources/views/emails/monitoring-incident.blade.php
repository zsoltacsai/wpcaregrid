<p>{{ __('m10.mail.greeting') }}</p>
<p>{{ __('m10.mail.body', ['reason' => \App\Monitoring\MonitoringReason::label($incident->reason_code), 'check' => $incident->check_id]) }}</p>
<p>{{ __('m10.mail.subject_id', ['id' => $incident->subject_id]) }}</p>
<p><a href="{{ $url }}">{{ __('m10.mail.open_console') }}</a></p>
