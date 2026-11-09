<?php

namespace App\Release;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * On staging-next no mail may reach a real customer. A message is sent only when the
 * mailer is a sink (log/array) or every recipient (To, Cc and Bcc) is on the
 * STAGING_MAIL_ALLOWLIST (exact addresses or @domain). Anything else is cancelled before
 * it leaves the application, in the web process and in queue workers alike.
 *
 * It applies to the whole staging-next deployment identity, not only to the active
 * profile, so a refused profile can never fall back to unrestricted mail.
 */
final class StagingMailGuard
{
    /** Returning false cancels the message (Illuminate\Mail\Mailer honours it). */
    public function handle(MessageSending $event): ?bool
    {
        if (! StagingActiveProfile::isStagingNextEnvironment()) {
            return null;
        }

        $default = (string) config('mail.default');
        $driver = (string) config("mail.mailers.{$default}.transport", $default);
        if (in_array($driver, ['log', 'array'], true)) {
            return null;
        }

        $addresses = [];
        foreach (['getTo', 'getCc', 'getBcc'] as $getter) {
            foreach ($event->message->{$getter}() as $address) {
                $addresses[] = strtolower($address->getAddress());
            }
        }
        $allowlist = (array) config('marketing_release.staging_mail_allowlist', []);

        if ($addresses !== [] && $allowlist !== [] && $this->allAllowed($addresses, $allowlist)) {
            return null;
        }

        // No recipient, subject or body in the log: only that a message was stopped and why.
        Log::warning('Staging mail blocked.', [
            'reason' => $allowlist === [] ? 'no_allowlist' : 'recipient_not_allowlisted',
            'recipients' => count($addresses),
        ]);

        return false;
    }

    /**
     * @param  list<string>  $addresses
     * @param  list<string>  $allowlist
     */
    private function allAllowed(array $addresses, array $allowlist): bool
    {
        foreach ($addresses as $address) {
            $allowed = false;
            foreach ($allowlist as $entry) {
                $entry = strtolower(trim((string) $entry));
                if ($entry === '') {
                    continue;
                }
                if ($address === $entry || (str_starts_with($entry, '@') && str_ends_with($address, $entry))) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                return false;
            }
        }

        return true;
    }
}
