<?php

namespace App\Owner;

use App\Connector\HealthStatus;

/** One line of the system page: a state, a short summary and safe details (never a secret). */
final readonly class StatusCheck
{
    /**
     * @param  array<string, string>  $details  Label => value; states and counts only.
     * @param  bool  $notConfigured  Nothing is set up for this part yet (rather than set up and failing).
     * @param  bool  $optional  Missing setup is visible but does not block the standard production check.
     */
    public function __construct(
        public string $key,
        public HealthStatus $status,
        public string $summary,
        public array $details = [],
        public bool $notConfigured = false,
        public bool $optional = false,
    ) {}

    /** For php artisan system:check: OK, WARNING, ERROR or NOT CONFIGURED. */
    public function level(): string
    {
        return match (true) {
            $this->notConfigured => 'NOT CONFIGURED',
            $this->status === HealthStatus::Healthy => 'OK',
            $this->status === HealthStatus::Critical => 'ERROR',
            default => 'WARNING',
        };
    }
}
