<?php

namespace App\Console\Commands;

use App\Marketing\MarketingClaimAuditor;
use Illuminate\Console\Command;

/** Read-only report of public copy phrases that should receive human claim review. */
final class MarketingClaimsAudit extends Command
{
    protected $signature = 'marketing:claims-audit';

    protected $description = 'Report potentially high-risk marketing claim phrases for human review; makes no content changes';

    public function handle(MarketingClaimAuditor $auditor): int
    {
        $findings = $auditor->findings();
        if ($findings === []) {
            $this->info('No review-pattern matches found in built-in or published marketing content.');

            return self::SUCCESS;
        }

        $this->table(['Section', 'Locale', 'Source', 'Review rule'], array_map(
            fn (array $finding) => array_values($finding),
            $findings,
        ));
        $this->comment('Matches are review candidates, not automatic violations. Inspect context and product evidence before changing copy.');

        return self::SUCCESS;
    }
}
