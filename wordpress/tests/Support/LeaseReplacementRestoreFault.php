<?php

namespace WPCareGrid\Connector\Tests\Support;

use RuntimeException;
use WPCareGrid\Connector\Backups\RestoreFaultHooks;
use WPCareGrid\Connector\Core\Lock;

/** Test-only lease replacement at a deterministic restore boundary. */
final class LeaseReplacementRestoreFault implements RestoreFaultHooks
{
    private bool $consumed = false;

    private int $statement_count = 0;

    private ?string $replacement_value = null;

    public function __construct(private readonly string $point, private readonly int $statement = 1)
    {
        RestoreHarnessGuard::activeRoot();
        if (! in_array($point, ['database_statement_imported', 'database_swap_failed'], true) || $statement < 1) {
            throw new RuntimeException('Invalid deterministic lease-replacement plan.');
        }
    }

    public function reach(string $point, string $restore_uuid): void
    {
        if ($this->consumed || $point !== $this->point) {
            return;
        }

        if ($point === 'database_statement_imported' && ++$this->statement_count !== $this->statement) {
            return;
        }

        global $wpdb;
        $this->replacement_value = sprintf('%010d:%s', time() + Lock::TTL, bin2hex(random_bytes(32)));
        $updated = $wpdb->update(
            $wpdb->options,
            ['option_value' => $this->replacement_value],
            ['option_name' => 'wp_caregrid_poll_lock'],
            ['%s'],
            ['%s']
        );
        if ($updated !== 1) {
            throw new RuntimeException('The test could not replace the synthetic poll lease.');
        }

        $this->consumed = true;
    }

    public function consumed(): bool
    {
        return $this->consumed;
    }
}
