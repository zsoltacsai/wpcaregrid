<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

/** Customer-only localized copy for Laravel's existing password reset broker notification. */
final class CustomerPasswordReset extends ResetPassword
{
    public function __construct(#[\SensitiveParameter] string $token, private readonly string $mailLocale)
    {
        parent::__construct($token);
    }

    protected function buildMailMessage($url): MailMessage
    {
        $locale = $this->mailLocale;
        $expiry = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject(Lang::get('emails.password_reset.subject', [], $locale))
            ->greeting(Lang::get('emails.password_reset.greeting', [], $locale))
            ->line(Lang::get('emails.password_reset.intro', [], $locale))
            ->action(Lang::get('emails.password_reset.action', [], $locale), $url)
            ->line(Lang::get('emails.password_reset.expiry', ['count' => $expiry], $locale))
            ->line(Lang::get('emails.password_reset.ignore', [], $locale));
    }
}
