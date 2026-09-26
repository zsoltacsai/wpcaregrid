<?php

namespace App\Connector;

use App\Models\Connector;
use App\Models\ConnectorPairingCode;
use App\Websites\AuditLog;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Exchanges a pairing code for a connector credential. The website becomes
 * connected here, because the connector itself has just proven it holds
 * the code and runs on the website's address.
 */
final class PairConnector
{
    public function __construct(private readonly JobQueue $jobs) {}

    /**
     * @param  array{pairing_code: string, installation_id: string, site_url: string, connector_version: string, capabilities: array, wp_version?: string, php_version?: string}  $data
     * @return array{0: Connector, 1: string} The connector and the raw credential (returned once).
     *
     * @throws ConnectorError
     */
    public function pair(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $code = ConnectorPairingCode::where('code_hash', PairingCodes::hash($data['pairing_code']))->lockForUpdate()->first();

            if (! $code?->isUsable()) {
                Log::notice('Connector pairing refused.', ['reason' => $code ? 'used_or_expired' : 'unknown_code']);

                throw ConnectorError::invalidPairingCode();
            }

            $website = $code->website;

            if (! $website->allowsServiceOperations()) {
                throw ConnectorError::serviceInactive();
            }

            if (! self::sameSite($website->url, $data['site_url'])) {
                Log::notice('Connector pairing refused.', ['reason' => 'site_url_mismatch', 'website' => $website->uuid]);

                throw ConnectorError::siteUrlMismatch();
            }

            $code->update(['used_at' => now()]);

            // Re-pairing replaces the previous installation's credential.
            $website->connectors()->where('status', Connector::ACTIVE)->get()->each->revoke('re_paired');

            $credential = 'cgc_'.Str::random(48);

            $connector = $website->connectors()->create([
                'installation_id' => $data['installation_id'],
                'credential_hash' => Connector::hashCredential($credential),
                'credential_hint' => substr($credential, 0, 8),
                'status' => Connector::ACTIVE,
                'connector_version' => $data['connector_version'],
                'site_url' => $data['site_url'],
                'capabilities' => Capability::listFrom($data['capabilities']),
                'last_seen_at' => now(),
            ]);

            $website->update([
                'connector_status' => ConnectorStatus::Connected,
                'status' => WebsiteStatus::Connected,
                'last_seen_at' => now(),
                'wordpress_version' => $data['wp_version'] ?? $website->wordpress_version,
                'php_version' => $data['php_version'] ?? $website->php_version,
            ]);

            // The initial check: inventory, health and licenses.
            foreach ([JobType::GetInventory, JobType::SiteHealth, JobType::LicenseCheck] as $type) {
                if ($connector->can($type->requiredCapability())) {
                    $this->jobs->queue($website->setRelation('activeConnector', $connector), $type, [], 'pairing');
                }
            }

            AuditLog::record($website, 'connector.paired', data: ['connector_version' => $connector->connector_version]);
            Log::info('Connector paired.', ['website' => $website->uuid, 'connector' => $connector->uuid, 'version' => $connector->connector_version]);

            return [$connector, $credential];
        });
    }

    /** Same host, ignoring scheme, "www." and a trailing slash. Never fetched. */
    public static function sameSite(string $registered, string $reported): bool
    {
        $host = fn (string $url) => preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));

        return $host($registered) !== '' && $host($registered) === $host($reported);
    }
}
