<?php

namespace WPCareGrid\Connector\Tests\Support;

use RuntimeException;
use WPCareGrid\Connector\Backups\BackupFailure;
use WPCareGrid\Connector\Backups\RestoreFaultHooks;

/** Deterministic, test-only restore boundary hook. */
final class OneShotRestoreFault implements RestoreFaultHooks
{
    private bool $consumed = false;

    private array $reached = [];

    public function __construct(private readonly string $point, private readonly string $code = 'HARNESS_INJECTED_FAILURE')
    {
        RestoreHarnessGuard::activeRoot();
        if (! in_array($point, ['index_validated', 'objects_downloaded', 'files_prepared', 'database_prepared', 'before_destructive_mutation', 'after_database_swap', 'before_file_apply', 'after_file_apply', 'before_post_restore_health'], true)
            || ! preg_match('/^[A-Z0-9_]{1,64}$/', $code)) {
            throw new RuntimeException('Invalid deterministic restore-fault plan.');
        }
    }

    public function reach(string $point, string $restore_uuid): void
    {
        $this->reached[] = ['point' => $point, 'restore_uuid' => $restore_uuid];
        if (! $this->consumed && $point === $this->point) {
            $this->consumed = true;
            throw new BackupFailure($this->code, 'The test harness stopped at the requested restore boundary.');
        }
    }

    public function consumed(): bool
    {
        return $this->consumed;
    }

    public function reached(): array
    {
        return $this->reached;
    }
}
