<?php

namespace App\Connector;

use App\Models\Website;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;

/**
 * Disconnect a website from WP CareGrid (customer area or operator
 * console). The connector's credential stops working at once; the site
 * needs a new pairing code to connect again.
 */
final class DisconnectWebsite
{
    /** @param  string  $reason  e.g. disconnected_by_customer, disconnected_by_operator */
    public function __invoke(Website $website, string $reason, string $actor): void
    {
        $website->connectors()->where('status', 'active')->get()->each->revoke($reason, $actor);
        $website->pairingCodes()->whereNull('used_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);
        $website->jobs()->whereIn('status', [JobStatus::Queued, JobStatus::Delivered])->update(['status' => JobStatus::Cancelled->value]);
        $website->update(['connector_status' => ConnectorStatus::Revoked, 'status' => WebsiteStatus::Disconnected]);
    }
}
