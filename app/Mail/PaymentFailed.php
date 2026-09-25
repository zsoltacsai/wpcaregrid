<?php

namespace App\Mail;

use App\Models\Subscription;
use App\Pricing\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent when Stripe could not collect a payment (invoice.payment_failed). */
class PaymentFailed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Subscription $subscription, public readonly Money $amount)
    {
        // Only send once the webhook transaction that queued it has committed.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.payment_failed.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment_failed', with: $this->viewData());
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return ['name' => $this->subscription->customer->first_name, 'amount' => $this->amount->format(), 'plan' => $this->subscription->product_type->label().' '.$this->subscription->planName(), 'url' => route('account.subscription')];
    }
}
