<?php

namespace App\Operator;

use App\Connector\HealthStatus;
use App\Models\Website;
use App\Models\WebsiteInventory;
use App\Security\SecurityScanner;

/** Dashboard figures. Every number is counted from stored data; nothing is estimated. */
final class Overview
{
    public function __construct(private readonly SecurityScanner $security) {}

    /** @return array<string, int> */
    public function metrics(): array
    {
        $count = function (callable $scope): int {
            $query = Website::query();
            $scope($query);

            return $query->count();
        };

        return [
            'websites' => Website::count(),
            'healthy' => Website::where('health_status', HealthStatus::Healthy->value)->count(),
            'attention' => Website::where('health_status', HealthStatus::Attention->value)->count(),
            'critical' => Website::where('health_status', HealthStatus::Critical->value)->count(),
            'backups_failed' => $count(fn ($q) => BackupState::Failed->scope($q)),
            'security_findings' => $this->security->openFindings(),
            'pending_updates' => (int) WebsiteInventory::sum('updates_pending'),
            'expired_licenses' => (int) WebsiteInventory::sum('licenses_expired'),
            'connectors_offline' => $count(fn ($q) => ConnectorState::Stale->scope($q)) + $count(fn ($q) => ConnectorState::Disconnected->scope($q)),
        ];
    }
}
