<?php

namespace WPCareGrid\Connector\Core;

use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Security\Logger;

/**
 * Connection lifecycle shared by pairing, polling and the admin page:
 * applying the server's schedule, disconnecting, and reacting to revocation.
 */
final class Connection
{
    public function __construct(
        private readonly Client $api,
        private readonly CredentialStore $credentials,
    ) {}

    public function apply_schedule(array $data): void
    {
        $values = [];

        foreach (['heartbeat_interval', 'poll_interval'] as $key) {
            if (isset($data[$key]) && is_int($data[$key])) {
                $values[$key] = $data[$key];
            }
        }

        if (isset($data['latest_connector_version']) && is_string($data['latest_connector_version'])) {
            $values['latest_version'] = substr($data['latest_connector_version'], 0, 32);
        }

        if ($values) {
            Settings::update($values);
        }
    }

    /**
     * Disconnect from this side: ask WP CareGrid to revoke the credential,
     * then forget it locally whether or not the request got through.
     */
    public function disconnect(string $reason): void
    {
        if ($this->credentials->has()) {
            $response = $this->api->post('disconnect');
            Logger::info('connection.disconnected', ['reason' => $reason, 'remote_revoked' => $response->ok()]);
        }

        $this->forget(Settings::DISCONNECTED);
    }

    /** WP CareGrid revoked this connector. No automatic reconnection: a new pairing is required. */
    public function handle_revocation(): void
    {
        Logger::warning('connection.revoked_by_server');
        $this->forget(Settings::REVOKED);
    }

    private function forget(string $status): void
    {
        $this->credentials->clear();
        Scheduler::unschedule();
        Settings::reset($status);
    }
}
