<?php

namespace App\Mail;

use App\Models\MonitoringAlertDelivery;
use App\Monitoring\MonitoringReason;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class MonitoringIncidentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly MonitoringAlertDelivery $delivery) {}

    public function build(): self
    {
        $incident = $this->delivery->incident;
        $subject = __('m10.mail.subject', ['reason' => MonitoringReason::label($incident->reason_code)]);

        return $this->subject($subject)->view('emails.monitoring-incident', [
            'incident' => $incident,
            'url' => route('owner.monitoring.index'),
        ]);
    }
}
