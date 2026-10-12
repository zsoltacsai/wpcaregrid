<?php

namespace App\Monitoring;

use App\Connector\HealthStatus;
use Carbon\CarbonInterface;

final readonly class MonitoringObservation
{
    /** @param array<string, scalar|null> $evidence */
    public function __construct(
        public string $checkId,
        public MonitoringScope $scope,
        public string $subjectType,
        public string $subjectId,
        public HealthStatus $state,
        public ?MonitoringSeverity $severity,
        public string $reasonCode,
        public string $source,
        public array $evidence = [],
        public ?CarbonInterface $sourceAt = null,
        public int $freshnessSeconds = 900,
        public int $failureThreshold = 1,
        public int $recoveryThreshold = 1,
        public bool $notify = true,
        public ?string $correlationKey = null,
        public ?int $nextCheckSeconds = null,
    ) {}
}
