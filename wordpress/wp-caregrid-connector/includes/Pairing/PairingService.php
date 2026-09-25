<?php

namespace WPCareGrid\Connector\Pairing;

use WPCareGrid\Connector\AdminAccess\AdminAccess;
use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Auth\InstallationId;
use WPCareGrid\Connector\Core\Connection;
use WPCareGrid\Connector\Core\Scheduler;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Jobs\JobRegistry;
use WPCareGrid\Connector\Security\Logger;

/**
 * Exchanges a one-time pairing code (from the customer's WP CareGrid
 * account) for this site's connector credential.
 */
final class PairingService
{
    public function __construct(
        private readonly Client $api,
        private readonly CredentialStore $credentials,
        private readonly Connection $connection,
    ) {}

    /** @return array{ok: bool, code: ?string, message: string} */
    public function pair(string $code): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        if (! preg_match('/^[A-Z0-9]{12}$/', $code)) {
            return ['ok' => false, 'code' => 'INVALID_FORMAT', 'message' => __('Enter the 12-character pairing code from your WP CareGrid account.', 'wp-caregrid')];
        }

        $response = $this->api->post(
            'pair',
            [
                'pairing_code' => $code,
                'installation_id' => InstallationId::get(),
                'site_url' => home_url('/'),
                'connector_version' => WP_CAREGRID_VERSION,
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'capabilities' => JobRegistry::capabilities(),
                'admin_access_url' => AdminAccess::login_url(),
            ],
            false
        );

        $data = $response->data;

        if ($response->status !== 201 || ! is_string($data['credential'] ?? null) || ! is_string($data['website_uuid'] ?? null)
            || ! preg_match('/^cgc_[A-Za-z0-9]{48}$/', $data['credential']) || ! preg_match('/^[0-9a-f-]{36}$/', $data['website_uuid'])) {
            Logger::warning('pairing.failed', ['status' => $response->status, 'code' => $response->error_code]);

            return [
                'ok' => false,
                'code' => $response->error_code ?? 'PAIRING_FAILED',
                'message' => $response->error_message ?? __('Pairing failed. Please try again.', 'wp-caregrid'),
            ];
        }

        $this->credentials->set($data['credential']);
        Settings::update(
            [
                'status' => Settings::CONNECTED,
                'website_uuid' => $data['website_uuid'],
                'connector_uuid' => $data['connector_uuid'] ?? null,
                'connected_at' => gmdate('c'),
                'last_success_at' => gmdate('c'),
                'capabilities' => array_values(array_intersect((array) ($data['capabilities'] ?? []), JobRegistry::capabilities())),
                'last_error' => null,
            ]
        );
        $this->connection->apply_schedule($data);
        Scheduler::schedule();

        Logger::info('pairing.succeeded', ['website' => $data['website_uuid']]);

        return ['ok' => true, 'code' => null, 'message' => __('Connected to WP CareGrid.', 'wp-caregrid')];
    }
}
