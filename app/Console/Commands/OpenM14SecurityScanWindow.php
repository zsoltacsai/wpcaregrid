<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\SyntheticFixtures\M14SecurityScanWindowService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class OpenM14SecurityScanWindow extends Command
{
    protected $signature = 'caregrid:m14-security-scan-window-open {website_uuid}';

    protected $description = 'Open one expiring, exclusive staging window for the configured M14 synthetic Integrity scan.';

    public function handle(M14SecurityScanWindowService $windows): int
    {
        $website = Website::query()->where('uuid', (string) $this->argument('website_uuid'))->first();
        if (! $website) {
            $this->components->error('The requested M14 site was not found.');

            return self::FAILURE;
        }

        try {
            $window = $windows->open($website, 'console:m14-security-window');
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('M14 scan window opened.');
        $this->line('EXPIRES_AT='.$window->expires_at?->toIso8601String());

        return self::SUCCESS;
    }
}
