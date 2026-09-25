<?php

namespace App\Enums;

/**
 * Statuses shown for sites, checks and log entries.
 *
 * Shared by the marketing previews now and intended for the customer
 * dashboard later, so labels and colours stay consistent.
 */
enum Status: string
{
    case Healthy = 'healthy';
    case Attention = 'attention';
    case Critical = 'critical';
    case Verified = 'verified';
    case Success = 'success';
    case Warning = 'warning';
    case Pending = 'pending';
    case LicenseExpired = 'license_expired';

    public function label(): string
    {
        return __("status.{$this->value}");
    }

    /** Visual tone used by the status badge and indicator dots. */
    public function tone(): string
    {
        return match ($this) {
            self::Healthy, self::Verified, self::Success => 'positive',
            self::Attention, self::Warning => 'warning',
            self::Critical, self::LicenseExpired => 'danger',
            self::Pending => 'neutral',
        };
    }
}
