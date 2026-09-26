<?php

namespace App\Security;

use Carbon\CarbonInterface;

/** What a scanner knows about one website: counts and dates, never file contents. */
final readonly class SecuritySummary
{
    public function __construct(
        public SecurityState $state,
        public int $findings = 0,
        public ?Severity $highestSeverity = null,
        public ?CarbonInterface $lastScanAt = null,
    ) {}

}
