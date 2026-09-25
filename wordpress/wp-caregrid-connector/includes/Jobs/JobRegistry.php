<?php

namespace WPCareGrid\Connector\Jobs;

/**
 * The fixed list of jobs this connector will run. Anything not listed here
 * is refused, whatever the server sends. There is deliberately no job for
 * running PHP, shell commands or WordPress hooks, and the list is not
 * filterable.
 */
final class JobRegistry
{
    /** @param array<string, JobHandler> $handlers */
    public function __construct(private readonly array $handlers) {}

    public const TYPES = ['GET_INVENTORY', 'SITE_HEALTH', 'LICENSE_CHECK', 'UPDATE_PLUGIN', 'UPDATE_THEME', 'UPDATE_CORE'];

    /** Capabilities advertised to WP CareGrid: only those with implemented jobs. */
    public const CAPABILITIES = ['inventory', 'health', 'updates', 'license_check'];

    public function handler(string $type): ?JobHandler
    {
        return in_array($type, self::TYPES, true) ? ($this->handlers[$type] ?? null) : null;
    }
}
