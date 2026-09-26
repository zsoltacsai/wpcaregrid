<?php

namespace App\Security;

/**
 * Kinds of finding, kept deliberately apart: a known vulnerability is not
 * an infection, a modified file is an integrity problem, a rule match is
 * a suspicion. Confirmed malware is a suspicious file an operator has
 * confirmed (status confirmed, confidence confirmed); no scanner reports it.
 */
enum FindingType: string
{
    case Vulnerability = 'vulnerability';
    case Integrity = 'integrity';
    case SuspiciousFile = 'suspicious_file';
    case SuspiciousDatabase = 'suspicious_database';
    case SuspiciousUser = 'suspicious_user';
    case SuspiciousCron = 'suspicious_cron';
    case Configuration = 'configuration';

    /** Which scanner produces (and may resolve) this type. */
    public function scanner(): string
    {
        return match ($this) {
            self::Vulnerability => 'vulnerability',
            self::Integrity => 'integrity',
            default => 'malware',
        };
    }

    /** Types the connector may report (vulnerabilities come from the server). */
    public static function reportable(): array
    {
        return [self::Integrity, self::SuspiciousFile, self::SuspiciousDatabase, self::SuspiciousUser, self::SuspiciousCron, self::Configuration];
    }

    /** A file on the site that quarantine or repair can act on. */
    public function concernsFile(): bool
    {
        return in_array($this, [self::Integrity, self::SuspiciousFile], true);
    }

    public function label(): string
    {
        return __('security.finding_types.'.$this->value);
    }
}
