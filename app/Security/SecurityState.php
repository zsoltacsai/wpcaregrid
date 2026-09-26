<?php

namespace App\Security;

/** A website's security state, as reported by a scanner. Never inferred from anything else. */
enum SecurityState: string
{
    case NotConfigured = 'not_configured';
    case NotScanned = 'not_scanned';
    case NoIssues = 'no_issues';
    case Findings = 'findings';

    public function label(): string
    {
        return __('operator.security.states.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::NoIssues => 'positive',
            self::Findings => 'danger',
            self::NotConfigured, self::NotScanned => 'neutral',
        };
    }
}
