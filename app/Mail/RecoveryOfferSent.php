<?php

namespace App\Mail;

use App\Models\RecoveryOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the customer that an offer is waiting for them. In their language
 * (the language saved on their login), unlike the older emails. Holds the
 * title, price and validity only: the scope and everything else is read in
 * the signed-in portal, and no secret or payment link is sent.
 */
class RecoveryOfferSent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly RecoveryOffer $offer)
    {
        $this->locale($offer->customer->mailLocale());
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('offers.mail.sent.subject'));
    }

    public function content(): Content
    {
        $offer = $this->offer;

        return new Content(markdown: 'mail.recovery_offer_sent', with: [
            'name' => $offer->customer->first_name,
            'title' => $offer->title,
            'host' => $offer->website->host(),
            'price' => $offer->money()->format(),
            'until' => $offer->valid_until->translatedFormat('j M Y, H:i').' UTC',
            'url' => route('account.offers.show', $offer),
        ]);
    }
}
