<?php

namespace App\Marketing\Analytics;

final readonly class MarketingAnalyticsAvailability
{
    public function __construct(
        public bool $enabled,
        public ?string $measurementId,
        public string $consentVersion,
    ) {}
}
