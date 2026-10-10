<?php

namespace WPCareGrid\Connector\Backups;

/** Optional internal synchronization seam; the production registry supplies none. */
interface RestoreFaultHooks
{
    public function reach(string $point, string $restore_uuid): void;
}
