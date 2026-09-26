<?php

namespace App\Security;

/**
 * How bad a finding would be if it is what it appears to be. Independent
 * of confidence. Vulnerability severity comes from the source only; when
 * the source gives none, a finding has no severity (never invented).
 */
enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /** From a CVSS v3 base score, as the source reports it (FIRST.org rating bands). */
    public static function fromCvss(?float $score): ?self
    {
        return match (true) {
            $score === null || $score <= 0 => null,
            $score >= 9.0 => self::Critical,
            $score >= 7.0 => self::High,
            $score >= 4.0 => self::Medium,
            default => self::Low,
        };
    }

    public function label(): string
    {
        return __('security.severities.'.$this->value);
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
