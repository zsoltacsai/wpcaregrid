<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent after the customer activates their account. */
class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Customer $customer)
    {
        // Only send once the webhook transaction that queued it has committed.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.welcome.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.welcome', with: $this->viewData());
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return ['name' => $this->customer->first_name, 'url' => route('account.dashboard')];
    }
}
