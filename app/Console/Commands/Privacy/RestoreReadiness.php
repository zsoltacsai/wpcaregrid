<?php

namespace App\Console\Commands\Privacy;

use App\Privacy\PrivacyRestoreSafety;
use Illuminate\Console\Command;

final class RestoreReadiness extends Command
{
    protected $signature = 'privacy:restore-readiness {--json : Print machine-readable status}';

    protected $description = 'Report whether privacy tombstone prerequisites permit exiting platform recovery quarantine; never changes state';

    public function handle(PrivacyRestoreSafety $safety): int
    {
        $status = $safety->status();
        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Value'], collect($status)->map(fn ($value, $key) => [$key, is_bool($value) ? ($value ? 'true' : 'false') : $value])->values()->all());
        }

        return $status['state'] === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
