<?php

namespace App\Mail;

use App\Models\RecoveryOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Payment for an offer was confirmed by Stripe. Sent once, in the customer's language. */
class RecoveryOfferPaid extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly RecoveryOffer $offer)
    {
        $this->locale($offer->customer->mailLocale());
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('offers.mail.paid.subject'));
    }

    public function content(): Content
    {
        $offer = $this->offer;

        return new Content(markdown: 'mail.recovery_offer_paid', with: [
            'name' => $offer->customer->first_name,
            'title' => $offer->title,
            'host' => $offer->website->host(),
            'price' => $offer->money()->format(),
            'url' => route('account.offers.show', $offer),
        ]);
    }
}
