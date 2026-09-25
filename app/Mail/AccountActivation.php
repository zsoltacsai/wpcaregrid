<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent when a login is created after a confirmed payment. Contains a password setup link, never a password. */
class AccountActivation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Customer $customer, #[\SensitiveParameter] public readonly string $token)
    {
        // Only send once the webhook transaction that queued it has committed.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.activation.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.activation', with: $this->viewData());
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return ['name' => $this->customer->first_name, 'url' => route('account.activate', ['token' => $this->token, 'email' => $this->customer->email])];
    }
}
