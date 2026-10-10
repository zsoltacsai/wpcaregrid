<?php

namespace WPCareGrid\Connector\Jobs;

use WPCareGrid\Connector\Core\ControlledExecution;
use WPCareGrid\Connector\Core\Plugin;

/** One bounded poll in a dedicated OS process; process control stays outside HTTP. */
final class ControlledPollCommand
{
    public function __construct(private readonly Plugin $plugin) {}

    /** WP-CLI: `wp caregrid jobs poll --once`. */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (($assocArgs['once'] ?? false) !== true && ($assocArgs['once'] ?? null) !== '1') {
            \WP_CLI::error('Pass --once; continuous polling is intentionally unsupported.');
        }
        try {
            $summary = $this->runOnce();
        } catch (\RuntimeException) {
            \WP_CLI::error('Controlled polling is not authorized for this paired synthetic site.');
        }
        \WP_CLI::log(sprintf('Single controlled poll complete; jobs executed: %d; results accepted: %d.', $summary['executed'], $summary['delivered']));
    }

    /** Shared bounded execution path for WP-CLI and the project-local PHP entrypoint. */
    public function runOnce(): array
    {
        if (! ControlledExecution::authorized()) {
            throw new \RuntimeException('Controlled polling is not authorized.');
        }

        return $this->plugin->runner()->poll(controlledExecution: true);
    }
}
