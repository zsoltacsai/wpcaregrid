<?php

namespace WPCareGrid\Connector\Health\Checks;

use WPCareGrid\Connector\Health\Check;
use WPCareGrid\Connector\Health\Finding;

/** WordPress's own Site Health summary, when WordPress has calculated one. */
final class SiteHealthStatusCheck implements Check
{
    public function run(): Finding
    {
        $result = json_decode((string) get_transient('health-check-site-status-result'), true);

        if (! is_array($result)) {
            return new Finding('site_health', Finding::UNKNOWN, 'WordPress Site Health', 'No Site Health result available yet.');
        }

        $critical = (int) ($result['critical'] ?? 0);
        $recommended = (int) ($result['recommended'] ?? 0);
        $summary = "{$critical} critical, {$recommended} recommended";

        return new Finding('site_health', $critical > 0 ? Finding::WARNING : Finding::PASS, 'WordPress Site Health', $summary);
    }
}
