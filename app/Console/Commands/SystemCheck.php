<?php

namespace App\Console\Commands;

use App\Owner\StatusCheck;
use App\Owner\SystemStatus;
use Illuminate\Console\Command;

/**
 * The owner System page as a command, for deployments and monitoring:
 *
 *   php artisan system:check            table, exit 1 on any ERROR (and, in
 *                                       production, on required NOT CONFIGURED)
 *   php artisan system:check --json     machine-readable
 *   php artisan system:check --strict   also exit 1 on WARNING / NOT CONFIGURED
 *
 * Each part is OK, WARNING, ERROR or NOT CONFIGURED. The optional Cloudflare
 * check is read-only; no DNS mutations are performed. Secret values are never printed.
 */
class SystemCheck extends Command
{
    protected $signature = 'system:check
        {--json : Print JSON instead of a table}
        {--strict : Fail on warnings and missing configuration too}';

    protected $description = 'Check that each part of the system is configured and working (no secrets printed)';

    public function handle(SystemStatus $status): int
    {
        $checks = $status->checks();

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (StatusCheck $check) => [
                'check' => $check->key,
                'level' => $check->level(),
                'summary' => $check->summary,
                'details' => $check->details,
                'optional' => $check->optional,
            ], $checks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Part', 'Result', 'Summary'], array_map(fn (StatusCheck $check) => [
                __('owner.system.checks.'.$check->key),
                $this->colour($check->level()),
                $check->summary,
            ], $checks));
        }

        $levels = array_map(fn (StatusCheck $check) => $check->level(), $checks);
        // Optional integration gaps remain visible but do not block the normal production check.
        $requiredConfigurationMissing = self::hasRequiredConfigurationMissing($checks);
        $failed = in_array('ERROR', $levels, true)
            || (app()->environment('production') && $requiredConfigurationMissing)
            || ($this->option('strict') && array_diff($levels, ['OK']) !== []);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<StatusCheck> $checks */
    public static function hasRequiredConfigurationMissing(array $checks): bool
    {
        return collect($checks)->contains(fn (StatusCheck $check) => $check->level() === 'NOT CONFIGURED' && ! $check->optional);
    }

    private function colour(string $level): string
    {
        return match ($level) {
            'OK' => "<fg=green>{$level}</>",
            'ERROR' => "<fg=red>{$level}</>",
            default => "<fg=yellow>{$level}</>",
        };
    }
}
