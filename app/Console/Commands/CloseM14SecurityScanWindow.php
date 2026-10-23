<?php

namespace App\Console\Commands;

use App\SyntheticFixtures\M14SecurityScanWindowService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class CloseM14SecurityScanWindow extends Command
{
    protected $signature = 'caregrid:m14-security-scan-window-close {--reason=completed}';

    protected $description = 'Close the M14 scan window after its scan and Connector job reach terminal states.';

    public function handle(M14SecurityScanWindowService $windows): int
    {
        try {
            $windows->close('console:m14-security-window', (string) $this->option('reason'));
        } catch (ValidationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('M14 scan window closed.');

        return self::SUCCESS;
    }
}
