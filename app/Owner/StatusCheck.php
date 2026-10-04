<?php

namespace App\Owner;

use App\Connector\HealthStatus;

/** One line of the system page: a state, a short summary and safe details (never a secret). */
final readonly class StatusCheck
{
    /** @param  array<string, string>  $details  Label => value; states and counts only. */
    public function __construct(
        public string $key,
        public HealthStatus $status,
        public string $summary,
        public array $details = [],
    ) {}
}
