<?php

namespace WPCareGrid\Connector\Core;

use WPCareGrid\Connector\Admin\SettingsPage;
use WPCareGrid\Connector\AdminAccess\Accounts;
use WPCareGrid\Connector\AdminAccess\AdminAccess;
use WPCareGrid\Connector\AdminAccess\Landing;
use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Backups\Downloader;
use WPCareGrid\Connector\Backups\Uploader;
use WPCareGrid\Connector\Health\HealthChecker;
use WPCareGrid\Connector\Heartbeat\HeartbeatService;
use WPCareGrid\Connector\Inventory\InventoryCollector;
use WPCareGrid\Connector\Jobs\Handlers;
use WPCareGrid\Connector\Jobs\JobRegistry;
use WPCareGrid\Connector\Jobs\JobRunner;
use WPCareGrid\Connector\Jobs\JobStore;
use WPCareGrid\Connector\Licenses\LicenseDetector;
use WPCareGrid\Connector\Pairing\PairingService;
use WPCareGrid\Connector\Updates\UpdateGuard;

/**
 * Builds the services and registers WordPress hooks. Entry points: two
 * cron events, the admin page, and the admin-access landing on
 * wp-login.php (POST only, useless without WP CareGrid's confirmation).
 * No REST routes, no public AJAX, no front-end output. Backups and restores run inside the
 * poll, never in a visitor's request.
 */
final class Plugin
{
    private static ?self $instance = null;

    private array $services = [];

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function boot(): void
    {
        $plugin = self::instance();

        Scheduler::register();
        add_action(Scheduler::HEARTBEAT, [$plugin, 'run_heartbeat']);
        add_action(Scheduler::POLL, [$plugin, 'run_poll']);

        // Admin access: the wp-login.php landing, the session guard, expiry and account protections.
        AdminAccess::use_api($plugin->api());
        Accounts::register_protections();
        (new Landing($plugin))->register();
        add_action('init', [AdminAccess::class, 'guard'], 1);
        add_action(AdminAccess::CRON, static fn ($level) => AdminAccess::end((string) $level, 'expired'));

        if (is_admin()) {
            (new SettingsPage($plugin))->register();
        }
    }

    public function run_heartbeat(): void
    {
        $this->heartbeat()->send();
    }

    public function run_poll(): void
    {
        $this->runner()->poll();
    }

    public function credentials(): CredentialStore
    {
        return $this->services['credentials'] ??= new CredentialStore;
    }

    public function api(): Client
    {
        return $this->services['api'] ??= new Client($this->credentials());
    }

    public function connection(): Connection
    {
        return $this->services['connection'] ??= new Connection($this->api(), $this->credentials());
    }

    public function pairing(): PairingService
    {
        return $this->services['pairing'] ??= new PairingService($this->api(), $this->credentials(), $this->connection());
    }

    public function health(): HealthChecker
    {
        return $this->services['health'] ??= new HealthChecker;
    }

    public function inventory(): InventoryCollector
    {
        return $this->services['inventory'] ??= new InventoryCollector(new LicenseDetector);
    }

    public function heartbeat(): HeartbeatService
    {
        return $this->services['heartbeat'] ??= new HeartbeatService($this->api(), $this->connection(), $this->health());
    }

    public function jobs(): JobStore
    {
        return $this->services['jobs'] ??= new JobStore;
    }

    public function registry(): JobRegistry
    {
        if (! isset($this->services['registry'])) {
            $guard = new UpdateGuard($this->health(), $this->inventory());

            $this->services['registry'] = new JobRegistry(
                [
                    'GET_INVENTORY' => new Handlers\GetInventoryHandler($this->inventory()),
                    'SITE_HEALTH' => new Handlers\SiteHealthHandler($this->health()),
                    'LICENSE_CHECK' => new Handlers\LicenseCheckHandler($this->inventory()),
                    'UPDATE_PLUGIN' => new Handlers\UpdatePluginHandler($guard),
                    'UPDATE_THEME' => new Handlers\UpdateThemeHandler($guard),
                    'UPDATE_CORE' => new Handlers\UpdateCoreHandler($guard),
                    'BACKUP_FILES' => new Handlers\BackupHandler('files', $this->api(), $this->uploader()),
                    'BACKUP_DATABASE' => new Handlers\BackupHandler('database', $this->api(), $this->uploader()),
                    'BACKUP_FULL' => new Handlers\BackupHandler('full', $this->api(), $this->uploader()),
                    'RESTORE' => new Handlers\RestoreHandler($this->downloader(), $this->health()),
                    'CREATE_ADMIN_SESSION' => new Handlers\CreateAdminSessionHandler,
                    'SECURITY_SCAN' => new Handlers\SecurityScanHandler,
                    'SECURITY_QUARANTINE' => new Handlers\SecurityQuarantineHandler,
                    'SECURITY_RESTORE_QUARANTINE' => new Handlers\SecurityRestoreQuarantineHandler,
                    'SECURITY_REPAIR_FILE' => new Handlers\SecurityRepairFileHandler,
                    'SECURITY_DISABLE_PLUGIN' => new Handlers\SecurityDisablePluginHandler,
                ]
            );
        }

        return $this->services['registry'];
    }

    public function uploader(): Uploader
    {
        return $this->services['uploader'] ??= new Uploader($this->api());
    }

    public function downloader(): Downloader
    {
        return $this->services['downloader'] ??= new Downloader($this->api());
    }

    public function runner(): JobRunner
    {
        return $this->services['runner'] ??= new JobRunner($this->api(), $this->registry(), $this->jobs(), $this->connection());
    }

    /** For tests: replace a service. */
    public function set(string $name, object $service): void
    {
        $this->services[$name] = $service;
    }

    public function reset(): void
    {
        $this->services = [];
    }
}
