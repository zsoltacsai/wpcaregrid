<?php

namespace App\Platform\Updates;

use App\Mail\PlatformUpdateNotice;
use App\Models\PlatformAdvisory;
use App\Models\PlatformUpdateCheck;
use App\Models\PlatformUpdateNotification;
use App\Models\User;
use App\Release\StagingActiveProfile;
use App\Release\StagingMailGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Owner notifications about platform updates, built on the existing Owner mail path (verified owners,
 * the staging-next mail guard applies to every message). Rules:
 *  - a critical advisory: one prominent notice;
 *  - a new non-critical advisory: one notice;
 *  - a failed check: one system warning per day;
 *  - a daily summary when there is something open, the last check failed or results are stale.
 * De-duplication: an advisory is announced once (notified_at), an advisory that is the same vulnerability under another id
 * (CVE/GHSA alias) is not announced again, and every notice is claimed by a unique key before it is sent.
 * Unverifiable data (UNKNOWN/UNVERIFIED) never triggers a notice. Nothing here changes the system.
 */
final class PlatformUpdateNotifier
{
    public function afterCheck(PlatformUpdateCheck $check): void
    {
        if (config('platform_updates.enabled') !== true || ! $this->mailAllowed()) {
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
        [$critical, $other] = [$pending->where('severity', 'critical'), $pending->where('severity', '!=', 'critical')];
        $this->announce('critical', $critical);
        $this->announce('security', $other);
    }

    /** The daily summary. Returns true when a summary was sent. */
    public function digest(): bool
    {
        if (config('platform_updates.enabled') !== true || ! $this->mailAllowed()) {
            return false;
        }
        $overview = (new PlatformUpdateOverview)->summary();
        $stale = $overview['last_success'] === null || $overview['last_success']->finished_at->lt(now()->subDays(3));
        if ($overview['open'] === 0 && ! $stale && ($overview['last_check']?->status ?? null) !== 'failed') {
            return false;
        }
        $items = $this->openItems(PlatformAdvisory::query()->where('state', PlatformAdvisory::OPEN)
            ->whereHas('component', fn ($q) => $q->where('development_only', false))->with('component')->orderBy('id')->limit(50)->get());

        return $this->send('digest', 'digest:'.now()->utc()->format('Y-m-d'), $items, [
            'open' => $overview['open'], 'critical' => $overview['critical'], 'unverified' => $overview['unverified'],
            'last' => $overview['last_success'] ? $overview['last_success']->finished_at->utc()->format('Y-m-d H:i').' UTC' : __('platform_updates.never'),
        ]);
    }

    /** @return Collection<int, PlatformAdvisory> open, not yet announced, production components only; same-vulnerability duplicates are marked announced silently */
    private function unannounced(): Collection
    {
        $candidates = PlatformAdvisory::query()->where('state', PlatformAdvisory::OPEN)->whereNull('notified_at')
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
        $key = $kind.':'.substr(sha1($advisories->pluck('id')->sort()->implode(',')), 0, 24);
        if ($this->send($kind, $key, $this->openItems($advisories), [])) {
            PlatformAdvisory::query()->whereKey($advisories->pluck('id'))->update(['notified_at' => now()]);
        }
    }

    private function failureNotice(): void
    {
        $last = PlatformUpdateCheck::query()->whereIn('status', ['succeeded', 'partial'])->latest('finished_at')->first();
        $this->send('failure', 'failure:'.now()->utc()->format('Y-m-d'), [], [
            'open' => PlatformAdvisory::query()->where('state', PlatformAdvisory::OPEN)->whereHas('component', fn ($q) => $q->where('development_only', false))->count(),
            'critical' => PlatformAdvisory::query()->where('state', PlatformAdvisory::OPEN)->where('severity', 'critical')->whereHas('component', fn ($q) => $q->where('development_only', false))->count(),
            'unverified' => 0,
            'last' => $last ? $last->finished_at->utc()->format('Y-m-d H:i').' UTC' : __('platform_updates.never'),
        ]);
    }

    /** @param  Collection<int, PlatformAdvisory>  $advisories
     * @return list<array<string, scalar|null>> */
    private function openItems(Collection $advisories): array
    {
        return $advisories->map(fn (PlatformAdvisory $a) => [
            'component' => $a->component->name, 'installed' => $a->affected_version, 'advisory' => $a->advisory_id,
            'aliases' => implode(', ', array_slice($a->aliases ?? [], 0, 3)), 'severity' => $a->severity, 'fixed' => $a->fixed_version,
            'source' => $a->source_url,
        ])->values()->all();
    }

    /**
     * @param  list<array<string, scalar|null>>  $items
     * @param  array<string, scalar>  $summary
     */
    private function send(string $kind, string $key, array $items, array $summary): bool
    {
        $recipients = User::query()->where('role', User::ROLE_OWNER)->whereNotNull('email_verified_at')->whereDoesntHave('customer')->pluck('email')->all();
        if ($recipients === []) {
            return false;
        }
        try {
            $claim = PlatformUpdateNotification::create(['kind' => $kind, 'dedupe_key' => $key, 'status' => 'pending', 'items' => count($items), 'recipients' => count($recipients)]);
        } catch (UniqueConstraintViolationException) {
            return false;   // already claimed or sent: never twice
        }
        try {
            foreach ($recipients as $email) {
                Mail::to($email)->send(new PlatformUpdateNotice($kind, $items, $summary));
            }
        } catch (Throwable) {
            // dedupe_key is released so that the next check can try again; the failed row stays as history.
            $claim->forceFill(['status' => 'failed', 'failure_code' => 'mail_transport_failed', 'dedupe_key' => null])->save();

            return false;
        }
        $claim->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        return true;
    }

    /** On staging-next mail is sent only where the existing guard confines it (sink mailer or recipient allowlist). */
    private function mailAllowed(): bool
    {
        return ! StagingActiveProfile::isStagingNextEnvironment() || StagingMailGuard::restricted();
    }
}
