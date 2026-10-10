<?php

namespace WPCareGrid\Connector\Heartbeat;

use WPCareGrid\Connector\AdminAccess\AdminAccess;
use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Core\Connection;
use WPCareGrid\Connector\Core\Scheduler;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Health\HealthChecker;
use WPCareGrid\Connector\Inventory\InventoryCollector;
use WPCareGrid\Connector\Jobs\JobRegistry;

/**
 * Periodic "still here" report: versions, capabilities and the latest
 * health summary. No content, users, settings or secrets.
 */
final class HeartbeatService
{
    public function __construct(
        private readonly Client $api,
        private readonly Connection $connection,
        private readonly HealthChecker $health,
    ) {}

    public function payload(): array
    {
        $health = $this->health->last();

        return [
            'connector_version' => WP_CAREGRID_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'site_url' => home_url('/'),
            'woocommerce' => InventoryCollector::woocommerce(),
            'last_successful_poll_at' => Settings::get('last_poll_at'),
            'capabilities' => JobRegistry::capabilities(),
            'health' => $health ? ['status' => $health['status'], 'findings' => $health['findings']] : null,
            'sent_at' => gmdate('c'),
            'admin_access_url' => AdminAccess::login_url(),
        ];
    }

    public function send(): bool
    {
        if (! Settings::is_connected()) {
            return false;
        }

        $response = $this->api->post('heartbeat', $this->payload());

        if ($response->is_revoked()) {
            $this->connection->handle_revocation();

            return false;
        }

        if (! $response->ok()) {
            Settings::update(['last_error' => $response->error_code]);

            return false;
        }

        Settings::update(['last_heartbeat_at' => gmdate('c'), 'last_success_at' => gmdate('c'), 'last_error' => null]);
        $this->connection->apply_schedule($response->data);
        Scheduler::sync_poll_schedule();
        if (is_array($response->data['admin_access'] ?? null)) {
            AdminAccess::reconcile($response->data['admin_access']);
        }

        return true;
    }
}
