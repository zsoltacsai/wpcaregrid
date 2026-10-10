<?php

namespace WPCareGrid\Connector\Tests\Support;

use RuntimeException;
use WPCareGrid\Connector\Backups\RestoreFaultHooks;

/** Test-only interprocess barrier for deterministic restore worker termination. */
final class ProcessRestoreBarrier implements RestoreFaultHooks
{
    private readonly string $root;

    public function __construct(private readonly string $directory, private readonly string $target)
    {
        $this->root = RestoreHarnessGuard::activeRoot();
        $realDirectory = realpath($directory);
        if (! in_array($target, ['before_destructive_mutation', 'after_database_swap'], true)
            || ! is_string($realDirectory)
            || ! str_starts_with($realDirectory.DIRECTORY_SEPARATOR, $this->root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Invalid process restore barrier.');
        }
    }

    public function reach(string $point, string $restore_uuid): void
    {
        if ($point !== $this->target) {
            return;
        }

        $marker = $this->directory.DIRECTORY_SEPARATOR.'reached.json';
        $written = file_put_contents($marker, json_encode([
            'pid' => getmypid(),
            'point' => $point,
            'restore_uuid' => $restore_uuid,
            'reached_at' => gmdate('c'),
            'poll_lock_value' => function_exists('get_option') ? get_option('wp_caregrid_poll_lock', null) : null,
        ], JSON_THROW_ON_ERROR), LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Could not publish restore barrier marker.');
        }

        $deadline = microtime(true) + 30;
        while (! is_file($this->directory.DIRECTORY_SEPARATOR.'release') && microtime(true) < $deadline) {
            usleep(10000);
        }

        if (! is_file($this->directory.DIRECTORY_SEPARATOR.'release')) {
            throw new RuntimeException('Restore process barrier timed out.');
        }
    }
}
