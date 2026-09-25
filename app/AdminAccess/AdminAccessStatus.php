<?php

namespace App\AdminAccess;

/**
 *   pending ─(site presents the token)→ authorized ─(session created)→ active
 *   pending|authorized|active → expired | revoked
 *   pending|authorized → failed
 */
enum AdminAccessStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Failed = 'failed';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Authorized, self::Expired, self::Revoked, self::Failed],
            self::Authorized => [self::Active, self::Expired, self::Revoked, self::Failed],
            self::Active => [self::Expired, self::Revoked],
            default => [],
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Authorized, self::Active], true);
    }

    public function label(): string
    {
        return __('admin_access.statuses.'.$this->value);
    }
}
