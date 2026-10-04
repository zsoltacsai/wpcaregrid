<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitation to the operator console or the owner area. Contains a link to
 * set a password (single use, expiring), never a password.
 */
class StaffInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly User $user, #[\SensitiveParameter] public readonly string $token)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.staff_invitation.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.staff_invitation', with: [
            'name' => $this->user->name,
            'area' => __('emails.staff_invitation.areas.'.($this->user->isOwner() ? 'owner' : 'operator')),
            'url' => self::url($this->user, $this->token),
            'hours' => (int) (config('auth.passwords.staff_invitations.expire') / 60),
        ]);
    }

    public static function url(User $user, #[\SensitiveParameter] string $token): string
    {
        return route('staff.activate', ['token' => $token, 'email' => $user->email]);
    }
}
