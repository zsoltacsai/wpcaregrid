<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Website;
use App\Operator\Permission;

/**
 * Operators and fully capable sites for operator console tests. Uses local
 * backup storage from CreatesBackups (call setUpBackups() in setUp()).
 */
trait OperatesConsole
{
    use CreatesBackups;

    /** @param  list<Permission>|null  $permissions  Null: every permission. */
    protected function operator(?array $permissions = null): User
    {
        return User::factory()->operator($permissions ?? Permission::cases())->create();
    }

    /** @param  list<Permission>|null  $permissions */
    protected function asOperator(?array $permissions = null): User
    {
        $operator = $this->operator($permissions);
        $this->actingAs($operator, 'operator');

        return $operator;
    }

    /** A paired, reporting website whose connector can do everything, with an inventory. */
    protected function consoleSite(string $url = 'https://company.example'): array
    {
        $site = $this->backupReadySite($url);
        $site['website']->activeConnector->update([
            'capabilities' => ['inventory', 'health', 'updates', 'license_check', 'backup_files', 'backup_database', 'backup_full', 'restore_files', 'restore_database', 'restore_full', 'admin_access'],
            'admin_access_url' => rtrim($url, '/').'/wp-login.php?action=wp_caregrid_access',
            'connector_version' => config('connector.latest_version'),
        ]);
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.inventory'), $this->inventoryPayload())->assertSuccessful();
        $site['website']->jobs()->delete();

        return ['website' => $site['website']->refresh(), 'credential' => $site['credential']];
    }

    protected function websiteOf(array $site): Website
    {
        return $site['website']->refresh();
    }
}
