<?php

namespace App\Console\Commands\Monitoring;

use App\Connector\HealthStatus;
use App\Models\Website;
use App\Monitoring\MonitoringEngine;
use App\Monitoring\MonitoringObservation;
use App\Monitoring\MonitoringReason;
use App\Monitoring\MonitoringScope;
use App\Monitoring\MonitoringSeverity;
use App\SyntheticFixtures\SyntheticFixturePolicy;
use Illuminate\Console\Command;

/** Server-side staging-only incident-pipeline exercise for the exact synthetic site. */
final class SimulateSyntheticObservation extends Command
{
    protected $signature = 'monitoring:simulate-synthetic {website_uuid} {scenario : healthy|failure}';

    protected $description = 'Record a synthetic monitoring observation on the explicitly approved staging fixture';

    public function handle(SyntheticFixturePolicy $policy, MonitoringEngine $engine): int
    {
        if (config('app.deployment_environment') !== 'staging' || ! in_array($this->argument('scenario'), ['healthy', 'failure'], true)) {
            $this->error('This command is restricted to the staging deployment and a supported scenario.');

            return self::FAILURE;
        }
        $website = Website::query()->where('uuid', $this->argument('website_uuid'))->first();
        if (! $website || ! $policy->isEnabledFor($website) || ! $policy->ownerPairingAllowed($website)) {
            $this->error('The requested website is not the active synthetic staging fixture.');

            return self::FAILURE;
        }

        $failed = $this->argument('scenario') === 'failure';
        $engine->record(new MonitoringObservation(
            'website.http', MonitoringScope::Website, 'website', (string) $website->uuid,
            $failed ? HealthStatus::Critical : HealthStatus::Healthy,
            $failed ? MonitoringSeverity::Critical : null,
            $failed ? MonitoringReason::HTTP_UNREACHABLE : MonitoringReason::MONITORING_OK,
            'synthetic_test', ['scenario' => $failed ? 'synthetic_failure' : 'synthetic_recovery'], now(), 300, 3, 2,
        ));
        $this->info('Synthetic observation recorded.');

        return self::SUCCESS;
    }
}
