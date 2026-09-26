<?php

namespace App\Security;

/**
 *   open → investigating → containment → remediation → verification → resolved → closed
 *
 * Steps may be skipped forwards (nothing to contain), verification may go
 * back to remediation, and an incident that turned out to be nothing may be
 * closed from open or investigating.
 */
enum IncidentStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Containment = 'containment';
    case Remediation = 'remediation';
    case Verification = 'verification';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Open => [self::Investigating, self::Containment, self::Closed],
            self::Investigating => [self::Containment, self::Remediation, self::Verification, self::Closed],
            self::Containment => [self::Remediation, self::Verification, self::Investigating],
            self::Remediation => [self::Verification, self::Investigating],
            self::Verification => [self::Resolved, self::Remediation, self::Investigating],
            self::Resolved => [self::Closed, self::Investigating],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }

    public function label(): string
    {
        return __('security.incident_statuses.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open, self::Investigating, self::Containment => 'danger',
            self::Remediation, self::Verification => 'warning',
            self::Resolved, self::Closed => 'positive',
        };
    }
}
