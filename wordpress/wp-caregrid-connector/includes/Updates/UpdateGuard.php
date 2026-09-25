<?php

namespace WPCareGrid\Connector\Updates;

use WPCareGrid\Connector\Health\HealthChecker;
use WPCareGrid\Connector\Inventory\InventoryCollector;

/**
 * Steps shared by every update: backup state before, health and a fresh
 * inventory after.
 */
final class UpdateGuard
{
    public function __construct(
        private readonly HealthChecker $health,
        private readonly InventoryCollector $inventory,
    ) {}

    /**
     * Whether a backup protects this update. WP CareGrid only sends an
     * update with a backup_uuid after it verified that backup in storage;
     * without one, the result says so explicitly.
     */
    public function backup_state(?string $backup_uuid = null): array
    {
        return $backup_uuid
            ? ['status' => 'verified_by_server', 'backup_uuid' => $backup_uuid]
            : ['status' => 'none', 'reason' => 'No backup was required for this update.'];
    }

    public function after(): array
    {
        return ['health' => $this->health->run(), 'inventory' => $this->inventory->collect()];
    }
}
