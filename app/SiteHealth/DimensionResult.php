<?php

namespace App\SiteHealth;

use App\Connector\HealthStatus;

/** One dimension's state and the reason code behind it. */
final readonly class DimensionResult
{
    /** @param  array<string, scalar>  $details */
    public function __construct(
        public Dimension $dimension,
        public HealthStatus $state,
        public string $reason,
        public array $details = [],
    ) {}
}
