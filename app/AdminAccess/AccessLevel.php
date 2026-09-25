<?php

namespace App\AdminAccess;

enum AccessLevel: string
{
    /** Restricted role: updates and troubleshooting, no user or code management. */
    case Maintenance = 'maintenance';

    /** Administrator, only when explicitly confirmed, for a very short time. */
    case FullAdmin = 'full_admin';

    public function minutes(): int
    {
        return max(1, (int) config("admin_access.durations.{$this->value}"));
    }

    public function label(): string
    {
        return __('admin_access.levels.'.$this->value);
    }
}
