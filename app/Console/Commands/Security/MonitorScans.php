<?php

namespace App\Console\Commands\Security;

use App\Models\SecurityScan;
use App\Security\ScanStatus;
use App\Security\SecurityScanService;
use Illuminate\Console\Command;

/** Scans that made no progress for security.scan.timeout_minutes end as SCAN_TIMEOUT. */
class MonitorScans extends Command
{
    protected $signature = 'security:monitor';

    protected $description = 'Time out security scans that stopped making progress';

    public function handle(SecurityScanService $scans): int
    {
        SecurityScan::whereIn('status', [ScanStatus::Queued->value, ScanStatus::Running->value])
            ->where('updated_at', '<', now()->subMinutes((int) config('security.scan.timeout_minutes')))
            ->each(function (SecurityScan $scan) use ($scans) {
                $scans->timeOut($scan);
                $this->components->warn("Timed out security scan {$scan->uuid}");
            });

        return self::SUCCESS;
    }
}
