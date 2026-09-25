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
     * Backups are a later milestone. The state is reported explicitly so no
     * one assumes a backup was taken.
     */
    public function backup_state(): array
    {
        return ['status' => 'not_available', 'reason' => 'The connector has no backup capability yet.'];
    }

    public function after(): array
    {
        return ['health' => $this->health->run(), 'inventory' => $this->inventory->collect()];
    }
}
