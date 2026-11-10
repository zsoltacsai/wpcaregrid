<?php

namespace App\Platform\Updates;

use App\Mail\PlatformUpdateNotice;
use App\Models\PlatformAdvisory;
use App\Models\PlatformUpdateCheck;
use App\Models\PlatformUpdateNotification;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owner notifications about platform updates, built on the existing Owner mail path (verified owners,
 * the staging-next mail guard applies to every message). Rules:
 *  - a critical advisory: one prominent notice;
 *  - a new non-critical advisory: one notice;
 *  - a failed check: one system warning per day;
 *  - a daily summary when there is something open, the last check failed or results are stale.
 * De-duplication: an advisory is announced once (notified_at; one that was resolved and came back is announced again),
 * an advisory that is the same vulnerability under another id (CVE/GHSA alias) is not announced again, and every
 * notice to every recipient is claimed by a unique key before it is sent. An advisory whose severity could not be read
 * is held back until it can (it may turn out to be critical). Unverifiable data (UNKNOWN/UNVERIFIED) and
 * development-only components never trigger a notice. Nothing here changes the system.
 */
final class PlatformUpdateNotifier
{
    /** Items listed in one notice; the rest is a count. */
    public const MAX_ITEMS = 50;

    /** A claim that was never completed (a killed worker) is released after this many minutes. */
    private const CLAIM_MINUTES = 15;

    /** The check that triggered the current notifications, for the correlation of log and audit entries. */
    private ?string $checkUuid = null;

    public function afterCheck(PlatformUpdateCheck $check): void
    {
        $this->checkUuid = $check->uuid;
        if (PlatformUpdateGate::mailRefusal() !== null) {
            return;
        }
        if ($check->status === 'failed') {
            $this->failureNotice();

            return;
        }
        if (! in_array($check->status, ['succeeded', 'partial'], true)) {
            return;
        }

        $pending = $this->unannounced();
        $this->announce('critical', $pending->where('severity', 'critical'));
        $this->announce('security', $pending->where('severity', '!=', 'critical'));
    }

    /** The daily summary. Returns true when a summary was sent. */
    public function digest(): bool
    {
        if (PlatformUpdateGate::mailRefusal() !== null) {
            return false;
        }
        $overview = (new PlatformUpdateOverview)->summary();
        $stale = $overview['last_success'] === null || $overview['last_success']->finished_at->lt(now()->subDays(PlatformUpdateOverview::STALE_DAYS));
        if ($overview['open'] === 0 && ! $stale && ($overview['last_check']?->status ?? null) !== 'failed') {
            return false;
        }
        $open = PlatformAdvisory::query()->where('state', PlatformAdvisory::OPEN)->whereHas('component', fn ($q) => $q->where('development_only', false))->with('component')->orderBy('id')->get();

        return $this->send('digest', 'digest:'.now()->utc()->format('Y-m-d'), $open, [
            'open' => $overview['open'], 'critical' => $overview['critical'], 'unverified' => $overview['unverified'],
            'last' => $overview['last_success'] ? $overview['last_success']->finished_at->utc()->format('Y-m-d H:i').' UTC' : __('platform_updates.never'),
        ])['sent'] > 0;
    }

    /** @return Collection<int, PlatformAdvisory> open, not yet announced, production components only; same-vulnerability duplicates are marked announced silently */
    private function unannounced(): Collection
    {
        $candidates = PlatformAdvisory::query()->where('state', PlatformAdvisory::OPEN)->whereNull('notified_at')
            ->where(fn ($q) => $q->where('severity', '!=', 'unknown')->orWhereNotNull('details_checked_at'))   // severity read, or read and really not stated
            ->whereHas('component', fn ($q) => $q->where('development_only', false))->with('component')->orderBy('id')->get();
        $fresh = collect();
        foreach ($candidates as $advisory) {
            $ids = array_values(array_unique([$advisory->advisory_id, ...($advisory->aliases ?? [])]));
            $announced = PlatformAdvisory::query()->where('platform_component_id', $advisory->platform_component_id)->whereNotNull('notified_at')
                ->whereKeyNot($advisory->id)->get(['advisory_id', 'aliases'])
                ->contains(fn ($other) => array_intersect($ids, [$other->advisory_id, ...($other->aliases ?? [])]) !== []);
            $sameInBatch = $fresh->contains(fn ($other) => $other->platform_component_id === $advisory->platform_component_id
                && array_intersect($ids, [$other->advisory_id, ...($other->aliases ?? [])]) !== []);
            if ($announced || $sameInBatch) {
                $advisory->forceFill(['notified_at' => now()])->save();   // the same vulnerability was announced under another id

                continue;
            }
            $fresh->push($advisory);
        }

        return $fresh->values();
    }

    /** @param  Collection<int, PlatformAdvisory>  $advisories */
    private function announce(string $kind, Collection $advisories): void
    {
        if ($advisories->isEmpty()) {
            return;
        }
        // The key names the set of advisories and the announcement round: an advisory that was resolved and came back
        // (notified_at cleared) is announced again, never swallowed by the key of its first announcement.
        $base = $kind.':'.substr(sha1($advisories->pluck('id')->sort()->implode(',')), 0, 20);
        // Rounds are counted from sent claims only; an unfinished round is retried under the same key.
        if ($this->send($kind, $base.':r'.$this->currentRound($base), $advisories, [])['complete']) {
            PlatformAdvisory::query()->whereKey($advisories->pluck('id'))->update(['notified_at' => now()]);
        }
    }

    /**
     * Round 1 until a round for this set of advisories has been sent to someone; then the next one. An advisory that
     * was resolved and came back has notified_at cleared, so it lands here again and gets a fresh round.
     */
    private function currentRound(string $base): int
    {
        $max = 0;
        foreach (PlatformUpdateNotification::query()->where('status', 'sent')->where('dedupe_key', 'like', $base.':r%')->pluck('dedupe_key') as $key) {
            if (preg_match('/:r(\d+):/', (string) $key, $match) === 1) {
                $max = max($max, (int) $match[1]);
            }
        }

        // A round that reached every recipient is closed (the advisories are marked announced); a partly delivered one stays open.
        return $max === 0 ? 1 : ($this->roundClosed($base, $max) ? $max + 1 : $max);
    }

    private function roundClosed(string $base, int $round): bool
    {
        $recipients = User::query()->where('role', User::ROLE_OWNER)->whereNotNull('email_verified_at')->whereDoesntHave('customer')->pluck('email');

        return $recipients->every(fn ($email) => PlatformUpdateNotification::query()->where('status', 'sent')
            ->where('dedupe_key', $base.':r'.$round.':'.substr(sha1($email), 0, 8))->exists());
    }

    private function failureNotice(): void
    {
        $overview = (new PlatformUpdateOverview)->summary();
        $this->send('failure', 'failure:'.now()->utc()->format('Y-m-d'), collect(), [
            'open' => $overview['open'], 'critical' => $overview['critical'], 'unverified' => $overview['unverified'],
            'last' => $overview['last_success'] ? $overview['last_success']->finished_at->utc()->format('Y-m-d H:i').' UTC' : __('platform_updates.never'),
        ]);
    }

    /**
     * One claim per recipient (a unique key, created before the mail is sent): a failure for one owner never causes a second
     * mail to another, and a retry only reaches those who did not get it. 'complete' = every recipient has it; 'sent' = mails sent now.
     *
     * @param  Collection<int, PlatformAdvisory>  $advisories
     * @param  array<string, scalar>  $summary
     * @return array{complete: bool, sent: int}
     */
    private function send(string $kind, string $key, Collection $advisories, array $summary): array
    {
        $recipients = User::query()->where('role', User::ROLE_OWNER)->whereNotNull('email_verified_at')->whereDoesntHave('customer')->orderBy('id')->pluck('email')->all();
        if ($recipients === []) {
            return ['complete' => false, 'sent' => 0];
        }
        // A claim that never completed (a killed worker) does not hold its key for ever.
        PlatformUpdateNotification::query()->where('status', 'pending')->where('created_at', '<', now()->subMinutes(self::CLAIM_MINUTES))
            ->update(['status' => 'failed', 'failure_code' => 'claim_abandoned', 'dedupe_key' => null]);

        $items = $this->items($advisories->take(self::MAX_ITEMS));
        $more = max(0, $advisories->count() - self::MAX_ITEMS);
        $complete = true;
        $sent = 0;
        $correlation = (string) Str::uuid();   // ties every log and audit entry of this round together
        foreach ($recipients as $email) {
            try {
                $claim = PlatformUpdateNotification::create(['kind' => $kind, 'dedupe_key' => $key.':'.substr(sha1($email), 0, 8), 'status' => 'pending', 'items' => $advisories->count(), 'recipients' => 1]);
            } catch (UniqueConstraintViolationException) {
                // Already sent to this recipient, or being sent right now by another worker: only a sent claim counts as delivered.
                $complete = $complete && PlatformUpdateNotification::query()->where('dedupe_key', $key.':'.substr(sha1($email), 0, 8))->where('status', 'sent')->exists();

                continue;
            }
            $failure = null;
            $exceptionClass = null;
            try {
                $message = Mail::to($email)->send(new PlatformUpdateNotice($kind, $items, $summary, $more));
                if ($message === null && ! Mail::isFake()) {
                    // No exception, but nothing was sent: a MessageSending listener (the staging mail guard) stopped the message.
                    // The faked mailer of the tests returns nothing as well, which is not a failure.
                    $failure = 'mail_not_sent';
                }
            } catch (Throwable $exception) {
                $failure = 'mail_transport_failed';
                $exceptionClass = $exception::class;   // never the message: transports put hosts, users and addresses in it
            }
            if ($failure !== null) {
                // The key is released so that the next check can try again; the failed row stays as history.
                $this->deliveryFailed($claim, $kind, $email, $failure, $exceptionClass, $correlation);
                $complete = false;

                continue;
            }
            try {
                $claim->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
            } catch (Throwable $exception) {
                // The message went out but the claim could not be marked: it counts as delivered (a retry would send a duplicate);
                // the pending claim is closed by the abandoned-claim cleanup later. The audit entry is the record of what happened.
                $this->record('platform_update.notification_unrecorded', 'error', 'Platform update notification was sent but could not be recorded.', $claim, $kind, $email, 'claim_not_marked_sent', $exception::class, $correlation);
            }
            $sent++;
        }

        return ['complete' => $complete, 'sent' => $sent];
    }

    /** A notice that could not be delivered: the claim is released for a retry, and the failure is logged and audited. Never throws. */
    private function deliveryFailed(PlatformUpdateNotification $claim, string $kind, string $email, string $code, ?string $exceptionClass, string $correlation): void
    {
        try {
            $claim->forceFill(['status' => 'failed', 'failure_code' => $code, 'dedupe_key' => null])->save();
        } catch (Throwable) {
            // The pending claim is closed later by the abandoned-claim cleanup; the failure is still reported below.
            $code .= '+claim_update_failed';
        }
        $this->record('platform_update.notification_failed', 'warning', 'Platform update notification could not be delivered.', $claim, $kind, $email, $code, $exceptionClass, $correlation);
    }

    /**
     * One structured log line and one audit entry (the Owner audit trail, actor "system"). Only identifiers, kinds, fixed codes, the class name
     * of the exception and a short hash of the recipient: no address, no exception message, no mail body, no credentials. A failure to log or
     * to audit never escapes (a notification problem must not stop the check) and is reported on the log channel as well.
     */
    private function record(string $event, string $level, string $message, PlatformUpdateNotification $claim, string $kind, string $email, string $code, ?string $exceptionClass, string $correlation): void
    {
        $context = array_filter([
            'event' => $event, 'kind' => $kind, 'code' => $code, 'exception' => $exceptionClass, 'notification_id' => $claim->id,
            'check' => $this->checkUuid, 'correlation' => $correlation, 'recipient' => substr(sha1($email), 0, 8),
        ], fn ($value) => $value !== null);
        try {
            Log::{$level}($message, $context);
        } catch (Throwable) {
            // nothing more can be done about a broken log channel
        }
        try {
            AdminAudit::record(null, $event, 'platform_update_notification', (string) $claim->id, $context);
        } catch (Throwable $exception) {
            try {
                Log::error('Platform update notification audit entry could not be written.', ['event' => $event, 'notification_id' => $claim->id, 'correlation' => $correlation, 'exception' => $exception::class]);
            } catch (Throwable) {
                // see above
            }
        }
    }

    /** @param  Collection<int, PlatformAdvisory>  $advisories
     * @return list<array<string, scalar|null>> */
    private function items(Collection $advisories): array
    {
        // The installed version is the component's current one; a fix that was proven for another installed version
        // (the check could not read the advisory text again yet) is not advice for this one and is not mailed.
        return $advisories->map(fn (PlatformAdvisory $a) => [
            'component' => $a->component->name, 'installed' => $a->component->installed_version ?? __('platform_updates.version_states.UNKNOWN'), 'advisory' => $a->advisory_id,
            'aliases' => implode(', ', array_slice($a->aliases ?? [], 0, 3)), 'severity' => $a->severity,
            'fixed' => PlatformUpdateOverview::fixNotConfirmed($a) ? null : $a->fixed_version,
            'source' => $a->source_url,
        ])->values()->all();
    }
}
