<?php

namespace App\Security;

/** Finding severity, for the Security/Malware milestone's scanner to report. */
enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return __('operator.security.severities.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Low => 'neutral',
            self::Medium => 'warning',
            self::High, self::Critical => 'danger',
        };
    }
}
