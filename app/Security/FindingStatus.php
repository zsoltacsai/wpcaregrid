<?php

namespace App\Security;

/**
 * Operator decisions (the only statuses anyone sets by hand):
 *   open → confirmed | false_positive | ignored
 *   confirmed → open (un-confirm) | false_positive | ignored
 *   false_positive | ignored → open (reopen)
 *
 * Set by the engine, never by hand:
 *   confirmed → quarantined               (completed quarantine)
 *   open | confirmed → remediated         (completed repair / deactivation)
 *   quarantined → open                    (quarantine undone: the file is back)
 *   open | confirmed → resolved           (a complete scan no longer sees it)
 *   resolved | quarantined | remediated → open   (seen again)
 *   false_positive | ignored → open       (seen again with a changed file)
 */
enum FindingStatus: string
{
    case Open = 'open';
    case Confirmed = 'confirmed';
    case Quarantined = 'quarantined';
    case Remediated = 'remediated';
    case FalsePositive = 'false_positive';
    case Ignored = 'ignored';
    case Resolved = 'resolved';

    /** Still needs attention. */
    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::Confirmed], true);
    }

    /** Statuses an operator may set directly (from some status). */
    public static function settableByOperator(): array
    {
        return [self::Open, self::Confirmed, self::FalsePositive, self::Ignored];
    }

    /** @return list<self> What an operator may change this status to. */
    public function operatorTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Confirmed, self::FalsePositive, self::Ignored],
            self::Confirmed => [self::Open, self::FalsePositive, self::Ignored],
            self::FalsePositive, self::Ignored => [self::Open],
            self::Quarantined, self::Remediated, self::Resolved => [],
        };
    }

    public function operatorMayChangeTo(self $next): bool
    {
        return in_array($next, $this->operatorTransitions(), true);
    }

    public function label(): string
    {
        return __('security.finding_statuses.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Confirmed => 'danger',
            self::Quarantined, self::Remediated, self::Resolved => 'positive',
            self::FalsePositive, self::Ignored => 'neutral',
        };
    }
}
