<?php

namespace App\Console\Commands;

use App\SyntheticFixtures\SyntheticFixtureProvisioner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ProvisionSyntheticM8Site extends Command
{
    protected $signature = 'caregrid:provision-m8-fixture
        {--customer-name=WP CareGrid M8 E2E}
        {--email=m8-e2e@example.test}
        {--hostname=m8-e2e.staging.wpcaregrid.com}';

    protected $description = 'Create the one predeclared, non-billing M8 synthetic site on the verified staging deployment.';

    public function handle(SyntheticFixtureProvisioner $provisioner): int
    {
        try {
            $result = $provisioner->provision(
                (string) $this->option('customer-name'),
                (string) $this->option('email'),
                'https://'.(string) $this->option('hostname'),
            );
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Synthetic M8 site created; no user, subscription, invoice, Stripe object, or email was created.');
        $this->line('Customer UUID: '.$result['customer']->uuid);
        $this->line('Website UUID: '.$result['website']->uuid);
        $this->line('Generate a one-time pairing code through the normal authenticated Owner website workflow when ready to pair.');

        return self::SUCCESS;
    }
}
