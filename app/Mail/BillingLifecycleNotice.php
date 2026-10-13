<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class BillingLifecycleNotice extends Mailable
{
    public function __construct(
        private readonly Customer $customer,
        private readonly string $notification,
        private readonly ?string $plan,
        private readonly ?string $interval = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.lifecycle.'.$this->notification.'.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.billing_lifecycle_notice', with: [
            'name' => $this->customer->first_name,
            'message' => __('emails.lifecycle.'.$this->notification.'.body', [
                'plan' => $this->plan ?? '',
                'interval' => $this->interval ? __('emails.billing_intervals.'.$this->interval) : '',
            ]),
            'url' => route('account.subscription'),
            'action' => __('emails.lifecycle.action'),
        ]);
    }
}
