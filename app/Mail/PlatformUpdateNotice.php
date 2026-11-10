<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/**
 * An Owner notice about platform updates. It carries advisory facts and a link to the Owner page,
 * never a secret and never an action that changes the system.
 */
final class PlatformUpdateNotice extends Mailable
{
    use Queueable;

    /** @param  list<array<string, scalar|null>>  $items */
    public function __construct(
        public readonly string $kind,
        public readonly array $items = [],
        public readonly array $summary = [],
        public readonly int $more = 0,
    ) {}

    public function build(): self
    {
        return $this->subject(__('platform_updates.mail.subject.'.$this->kind, ['count' => count($this->items) + $this->more]))
            ->view('emails.platform-update-notice', [
                'kind' => $this->kind,
                'items' => $this->items,
                'summary' => $this->summary,
                'more' => $this->more,
                'url' => route('owner.platform-updates.index'),
            ]);
    }
}
