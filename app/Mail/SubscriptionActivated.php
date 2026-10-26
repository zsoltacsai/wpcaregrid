<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent once, the first time a subscription grants access. */
class SubscriptionActivated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Subscription $subscription)
    {
        // ProcessBillingEmailOutboxJob applies the outbox's stored locale before construction.
        $this->locale(app()->getLocale());
        // Only send once the webhook transaction that queued it has committed.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.subscription_activated.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.subscription_activated', with: $this->viewData());
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return ['name' => $this->subscription->customer->first_name, 'plan' => $this->subscription->product_type->label().' '.$this->subscription->planName(), 'url' => route('account.websites.create')];
    }
}
