<?php

namespace App\Console\Commands;

use App\SyntheticFixtures\SyntheticFixtureProvisioner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ProvisionSyntheticM14SecuritySite extends Command
{
    protected $signature = 'caregrid:provision-m14-security-fixture';

    protected $description = 'Create the one predeclared, non-billing M14 security fixture on the verified staging deployment.';

    public function handle(SyntheticFixtureProvisioner $provisioner): int
    {
        try {
            $result = $provisioner->provisionM14SecurityFixture();
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Synthetic M14 security fixture created; no user, subscription, invoice, Stripe object, or email was created.');
        $this->line('Customer UUID: '.$result['customer']->uuid);
        $this->line('Website UUID: '.$result['website']->uuid);
        $this->line('Use the normal authenticated Owner pairing workflow after explicitly binding this UUID in the M14 security fixture configuration.');

        return self::SUCCESS;
    }
}
